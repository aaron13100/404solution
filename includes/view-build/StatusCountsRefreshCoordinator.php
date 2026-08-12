<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keeps foreground status-count reads cache-only and coordinates refreshes.
 *
 * The aggregate SUM(CASE ...) queries behind the redirect/captured tab counts
 * must never run inline in a page render, so a foreground read serves whatever
 * the cache holds and enqueues the recomputation. WP-Cron is the preferred
 * place for that work, but it cannot be the ONLY writer: on a host with
 * DISABLE_WP_CRON set and an out-of-band runner draining wp-cron.php every few
 * minutes, a freshly enqueued single event lands behind whatever backlog is
 * already due, so a site with a cold cache renders "-" in every tab and ships
 * NULL counts in its support reports for as long as the backlog lasts.
 *
 * A cold non-AJAX read therefore also arms a deferred recompute on the
 * `shutdown` hook, which runs after the response body has been produced.
 * AJAX reads use only the already-enqueued cron writer so they cannot retain
 * an LSAPI/FPM worker after detaching. The non-AJAX backstop is bounded on
 * four axes so it can never become a foreground cost:
 *   1. one arming per scope per request (instance flag);
 *   2. a re-read at shutdown, so a refresh another request already completed is
 *      not repeated;
 *   3. a cross-request cooldown transient, so a scope whose query keeps failing
 *      is retried at most once per cooldown window rather than once per admin
 *      page view;
 *   4. a shorter query budget than the cron path gets.
 *
 * Unlike the bounded status-count backstop here, the logs/hits rollup rebuild
 * is unbounded full-table work and therefore runs only from its WP-Cron hook.
 *
 * The foreground read methods are named `refreshing*` rather than `get*` for
 * that reason: they are not pure queries, and a `get*` name at a call site
 * hides the fact that reading can schedule cron work and register a shutdown
 * hook. `refresh()` and `performRefresh()` are the write side proper.
 */
class ABJ_404_Solution_StatusCountsRefreshCoordinator {

    const SCOPE_REDIRECTS = 'redirects';
    const SCOPE_CAPTURED = 'captured';
    const SCOPE_HIGH_IMPACT = 'high-impact';

    /** Counts came from the current-generation cache. */
    const STATE_FRESH = 'fresh';
    /** Counts came from the last-known cache; a refresh is pending. */
    const STATE_STALE = 'stale';
    /** No count has ever been computed on this site, so there is nothing to serve. */
    const STATE_UNCOMPUTED = 'uncomputed';

    /**
     * Minimum wall-clock gap between two deferred (shutdown) recomputes of the
     * same scope. Matched to the typical out-of-band wp-cron.php cadence on
     * cPanel/CloudLinux hosts: a site whose recompute keeps failing pays at
     * most one extra aggregate per window no matter how many admin page views
     * happen inside it.
     */
    const DEFERRED_REFRESH_COOLDOWN_SECONDS = 300;

    /** Transient key prefix for the per-scope deferred-refresh cooldown. */
    const DEFERRED_REFRESH_COOLDOWN_KEY_PREFIX = 'abj404_status_counts_deferred_';

    /**
     * Query budget for the deferred path. Deliberately smaller than the cron
     * budgets: a recompute that cannot finish inside this belongs to cron, and
     * the non-AJAX shutdown hook runs while the client connection is still open.
     */
    const DEFERRED_REFRESH_QUERY_TIMEOUT_SECONDS = 10;

    /** @var callable(string,array<string,mixed>,callable):mixed|null */
    private static $operationTracer = null;

    /** @var ABJ_404_Solution_StatusCountsRepository */
    private $statusCounts;

    /** @var ABJ_404_Solution_StatsRefreshLock */
    private $refreshLock;

    /** @var callable(string):void */
    private $warn;

    /** @var array<string, bool> Scopes whose shutdown backstop is armed for this request. */
    private $deferredArmed = array();

    public function __construct(
        ABJ_404_Solution_StatusCountsRepository $statusCounts,
        ABJ_404_Solution_StatsRefreshLock $refreshLock,
        callable $warn
    ) {
        $this->statusCounts = $statusCounts;
        $this->refreshLock = $refreshLock;
        $this->warn = $warn;
    }

    /** @param callable(string,array<string,mixed>,callable):mixed|null $tracer */
    public static function setOperationTracer($tracer): void {
        self::$operationTracer = $tracer;
    }

    /**
     * Counts plus the freshness state that produced them, so a caller can tell
     * "never computed" apart from "computed and genuinely zero".
     *
     * NOT a plain getter, which is why it is not named like one: a read that
     * finds the cache stale ENQUEUES a recomputation (a cron single event, plus
     * a `shutdown` backstop on non-AJAX requests). The enqueue deliberately
     * lives inside the read rather than in a separate command a caller has to
     * remember, because a caller who forgets it leaves the site rendering "-"
     * in every tab for as long as the cache stays cold. Nothing is ever
     * recomputed inline here; see the class doc block for the four bounds.
     *
     * @return array{counts: array<string, int>, state: string}
     */
    public function refreshingRedirectStatusCountsResult(): array {
        return self::trace('status_count_scope', array('scope' => self::SCOPE_REDIRECTS),
            function (): array {
                return $this->resolveRead(
                    self::SCOPE_REDIRECTS,
                    $this->statusCounts->readRedirectStatusCountsCache()
                );
            }
        );
    }

    /**
     * Flat legacy shape of refreshingRedirectStatusCountsResult(), and it
     * enqueues a refresh on the same terms.
     *
     * @return array<string, int>
     */
    public function refreshingRedirectStatusCounts(): array {
        return self::flatten($this->refreshingRedirectStatusCountsResult());
    }

    /**
     * Captured-tab counts plus freshness state. Enqueues a recomputation when
     * the cache is stale, on the same terms as the redirect scope above.
     *
     * @return array{counts: array<string, int>, state: string}
     */
    public function refreshingCapturedStatusCountsResult(): array {
        return self::trace('status_count_scope', array('scope' => self::SCOPE_CAPTURED),
            function (): array {
                return $this->resolveRead(
                    self::SCOPE_CAPTURED,
                    $this->statusCounts->readCapturedStatusCountsCache()
                );
            }
        );
    }

    /**
     * Flat legacy shape of refreshingCapturedStatusCountsResult(), and it
     * enqueues a refresh on the same terms.
     *
     * @return array<string, int>
     */
    public function refreshingCapturedStatusCounts(): array {
        return self::flatten($this->refreshingCapturedStatusCountsResult());
    }

    /**
     * The high-impact captured count, enqueuing a recomputation when its cache
     * is stale. Same read-enqueues-a-write contract as the two scopes above.
     *
     * @return int|null
     */
    public function refreshingHighImpactCapturedCount(): ?int {
        return self::trace('status_count_scope', array('scope' => self::SCOPE_HIGH_IMPACT),
            function (): ?int {
                $state = $this->statusCounts->readHighImpactCapturedCountCache();
                if ($state['needs_refresh']) {
                    $this->scheduleRefresh(self::SCOPE_HIGH_IMPACT);
                }
                return $state['count'];
            }
        );
    }

    /**
     * Background recomputation entry point. A direct foreground call can only
     * enqueue work; the cron listener and the shutdown backstop are the two
     * places the aggregate actually runs.
     */
    public function refresh(string $scope): void {
        $refresher = $this->refresherFor($scope);
        if ($refresher === null) {
            call_user_func($this->warn, 'Ignoring unknown status-count refresh scope: ' . $scope);
            return;
        }
        if (!$this->isCronRequest()) {
            $this->scheduleRefresh($scope);
            return;
        }

        $this->performRefresh($scope, $refresher['cron_timeout']);
    }

    /**
     * Run one scope's aggregate under the distributed refresh lock.
     */
    private function performRefresh(string $scope, int $timeoutSeconds): void {
        $refresh = $this->refresherFor($scope);
        if ($refresh === null) {
            return;
        }
        if (!$this->refreshLock->acquire($refresh['cache_key'])) {
            return;
        }
        try {
            $refreshed = ($refresh['recompute'])($timeoutSeconds);
            if ($refreshed !== true) {
                call_user_func($this->warn,
                    'Status-count refresh failed for scope ' . $scope . '; retaining the last-known cache.'
                );
            }
        } finally {
            $this->refreshLock->release($refresh['cache_key']);
        }
    }

    /**
     * Everything that differs between the three cron-written scopes, in one
     * place: where the cache lives, how to recompute it, how to ask whether it
     * still needs recomputing, and what the unattended query budget is.
     *
     * @return array{cache_key: string, recompute: callable(int):bool, needs_refresh: callable():bool, cron_timeout: int}|null
     */
    private function refresherFor(string $scope): ?array {
        $statusCounts = $this->statusCounts;
        $refreshers = array(
            self::SCOPE_REDIRECTS => array(
                'cache_key' => ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_REDIRECT_STATUS,
                'recompute' => static fn(int $timeout): bool
                    => $statusCounts->recomputeRedirectStatusCounts($timeout),
                'needs_refresh' => static fn(): bool
                    => $statusCounts->readRedirectStatusCountsCache()['needs_refresh'],
                'cron_timeout' => ABJ_404_Solution_StatusCountsRepository::STATUS_QUERY_TIMEOUT_SECONDS,
            ),
            self::SCOPE_CAPTURED => array(
                'cache_key' => ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_CAPTURED_STATUS,
                'recompute' => static fn(int $timeout): bool
                    => $statusCounts->recomputeCapturedStatusCounts($timeout),
                'needs_refresh' => static fn(): bool
                    => $statusCounts->readCapturedStatusCountsCache()['needs_refresh'],
                'cron_timeout' => ABJ_404_Solution_StatusCountsRepository::STATUS_QUERY_TIMEOUT_SECONDS,
            ),
            self::SCOPE_HIGH_IMPACT => array(
                'cache_key' => ABJ_404_Solution_ViewReadRuntimeState::CACHE_KEY_HIGH_IMPACT_CAPTURED,
                'recompute' => static fn(int $timeout): bool
                    => $statusCounts->recomputeHighImpactCapturedCount($timeout),
                'needs_refresh' => static fn(): bool
                    => $statusCounts->readHighImpactCapturedCountCache()['needs_refresh'],
                'cron_timeout' => ABJ_404_Solution_StatusCountsRepository::HIGH_IMPACT_QUERY_TIMEOUT_SECONDS,
            ),
        );
        return $refreshers[$scope] ?? null;
    }

    /**
     * @param array{counts: array<string, int>, needs_refresh: bool, incomplete: bool} $state
     * @return array{counts: array<string, int>, state: string}
     */
    private function resolveRead(string $scope, array $state): array {
        if ($state['needs_refresh']) {
            $this->scheduleRefresh($scope);
        }
        if ($state['incomplete']) {
            return array('counts' => $state['counts'], 'state' => self::STATE_UNCOMPUTED);
        }
        return array(
            'counts' => $state['counts'],
            'state' => $state['needs_refresh'] ? self::STATE_STALE : self::STATE_FRESH,
        );
    }

    /**
     * Legacy flat shape: counts plus the `_incomplete` marker that the admin
     * tab renderer and the pagination/trash AJAX responses already understand.
     *
     * @param array{counts: array<string, int>, state: string} $result
     * @return array<string, int>
     */
    private static function flatten(array $result): array {
        $counts = $result['counts'];
        if ($result['state'] === self::STATE_UNCOMPUTED) {
            $counts['_incomplete'] = 1;
        }
        return $counts;
    }

    private function scheduleRefresh(string $scope): void {
        $scheduler = self::trace(
            'scheduler_resolution',
            array('scope' => $scope),
            static fn() => abj_cron_scheduler()
        );
        self::trace(
            'schedule_if_missing',
            array('scope' => $scope),
            static fn() => $scheduler->scheduleSingleIfMissing(
                ABJ_404_Solution_CronScheduler::HOOK_REFRESH_STATUS_COUNTS,
                0,
                array($scope)
            )
        );
        $this->armDeferredRefresh($scope);
    }

    /**
     * Register the post-response backstop for one scope. Cron requests are
     * excluded because refresh() runs the aggregate inline there. AJAX is
     * excluded because scheduleRefresh() already enqueued the cron writer;
     * an aggregate on shutdown would retain the request's PHP worker.
     */
    private function armDeferredRefresh(string $scope): void {
        if ($this->isCronRequest() || $this->isAjaxRequest() || !empty($this->deferredArmed[$scope])) {
            return;
        }
        if ($this->refresherFor($scope) === null || !function_exists('add_action')) {
            return;
        }
        $this->deferredArmed[$scope] = true;
        add_action('shutdown', function () use ($scope): void {
            $this->runDeferredRefresh($scope);
        });
    }

    /**
     * The shutdown backstop itself. Never lets a failure escape: this runs
     * after the response, where an exception would only corrupt the tail of
     * the output.
     */
    private function runDeferredRefresh(string $scope): void {
        try {
            $refresher = $this->refresherFor($scope);
            // Re-read the cache here: between the foreground read and this
            // point the cron backlog may have drained, or a sibling request
            // may have filled it.
            if ($refresher === null || !($refresher['needs_refresh'])()) {
                return;
            }
            if (!$this->claimDeferredRefreshWindow($scope)) {
                return;
            }
            $this->performRefresh($scope, self::DEFERRED_REFRESH_QUERY_TIMEOUT_SECONDS);
        } catch (\Throwable $e) {
            call_user_func($this->warn,
                'Deferred status-count refresh failed for scope ' . $scope . ': '
                . get_class($e) . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * Cross-request rate limit. Recorded BEFORE the aggregate runs so a query
     * that fails (or is killed) still consumes the window.
     */
    private function claimDeferredRefreshWindow(string $scope): bool {
        if (!function_exists('get_transient') || !function_exists('set_transient')) {
            return true;
        }
        $key = self::DEFERRED_REFRESH_COOLDOWN_KEY_PREFIX . $scope;
        if (get_transient($key) !== false) {
            return false;
        }
        set_transient($key, 1, self::DEFERRED_REFRESH_COOLDOWN_SECONDS);
        return true;
    }

    private function isCronRequest(): bool {
        return function_exists('wp_doing_cron') && wp_doing_cron();
    }

    private function isAjaxRequest(): bool {
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return true;
        }
        $scriptName = isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME'])
            ? $_SERVER['SCRIPT_NAME'] : '';
        if ($scriptName !== '' && basename($scriptName) === 'admin-ajax.php') {
            return true;
        }
        return isset($GLOBALS['pagenow']) && $GLOBALS['pagenow'] === 'admin-ajax.php';
    }

    /**
     * @template T
     * @param array<string,mixed> $fields
     * @param callable():T $work
     * @return T
     */
    private static function trace(string $operation, array $fields, callable $work) {
        if (self::$operationTracer === null) {
            return $work();
        }
        return (self::$operationTracer)($operation, $fields, $work);
    }
}
