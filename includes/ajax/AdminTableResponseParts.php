<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds the body of an admin table AJAX response: the table HTML, the status
 * tab counts, the pagination links, and the data signature the browser compares
 * against to decide whether anything changed.
 *
 * Two registries live here, which is why this is a module rather than a
 * paragraph of the endpoint. The requested `part` selects a builder, and within
 * the table builder the subpage selects a renderer -- a dispatch with real logic
 * in each branch, and one that grows every time a tab or a part is added. The
 * endpoint that hosted it was not the thing changing when a new tab appeared.
 *
 * Nothing here touches the request boundary. There is no $_POST read, no nonce
 * check, no rate limit and no response emission: the inputs are a normalized
 * subpage, the view, the view-read service, and the diagnostics context. That is
 * what lets the same assembly run from anywhere the endpoint is not -- a preview,
 * a future CLI dump -- and it is what makes the part builders directly testable
 * without fabricating an authorized admin request.
 *
 * ABJ_404_Solution_Ajax_GetPaginationLinks owns serving the endpoint;
 * ABJ_404_Solution_View and the view-read service own producing the data. This
 * class owns only the shape of the response body and the query budget that
 * shape is read under.
 */
class ABJ_404_Solution_AdminTableResponseParts {

    /**
     * Per-query time budget for a foreground table build. The browser is
     * waiting on this, so a query that cannot finish inside the budget must
     * fail rather than hold the worker.
     */
    private const QUERY_TIMEOUT_SECONDS = 20;

    /**
     * Per-query budget for the background "did anything change?" poll. Tighter
     * than the foreground one on purpose: nobody is waiting on it, and a slow
     * poll competing for a worker slot is worse than a poll that gives up.
     */
    private const DETECT_ONLY_QUERY_TIMEOUT_SECONDS = 10;

    /**
     * Which subpage renders which table, and under which trace stage.
     *
     * A constant rather than a local, because the set is also the answer to
     * "is there a table here at all" -- asked by
     * ABJ_404_Solution_MeasuredTableResponseSize before it builds one to
     * measure. Two copies of this list would let a new tab be renderable and
     * unmeasurable, or measurable as the error sentinel's byte count.
     */
    private const TABLE_RENDERERS = array(
        'abj404_redirects' => array('stage' => 'table_redirects', 'method' => 'getAdminRedirectsPageTable'),
        'abj404_captured' => array('stage' => 'table_captured', 'method' => 'getCapturedURLSPageTable'),
        'abj404_logs' => array('stage' => 'table_logs', 'method' => 'getAdminLogsPageTable'),
    );

    /**
     * Whether this subpage has a table renderer at all.
     *
     * Callers that intend to BUILD a table part ask first: buildTablePart()
     * answers an unknown subpage with an error sentinel, and a caller that
     * measures the response would otherwise measure that sentinel.
     */
    public static function rendersTablePart(string $subpage): bool {
        return isset(self::TABLE_RENDERERS[$subpage]);
    }

    /** @return array<string, int> */
    public static function queryBudgetOptions(bool $detectOnly = false): array {
        return self::queryBudgetOptionsForSeconds($detectOnly
            ? self::DETECT_ONLY_QUERY_TIMEOUT_SECONDS
            : self::QUERY_TIMEOUT_SECONDS);
    }

    /**
     * The same budget under a caller-supplied ceiling.
     *
     * The two constants above are the budgets for the two callers that were
     * here first; they are not properties of the query. A caller whose own
     * client deadline is SHORTER than the foreground budget (the canary
     * ladder's measurement step gives up at 15 seconds) would otherwise leave
     * a query holding a worker for seconds after the only party waiting on it
     * has gone, which is the opposite of what the foreground budget is for.
     *
     * @return array<string, int>
     */
    public static function queryBudgetOptionsForSeconds(int $timeoutSeconds): array {
        return array(
            '_abj404_query_timeout' => max(1, $timeoutSeconds),
            // This endpoint owns a structured, admin-only failure envelope.
            // Let view-query failures reach it instead of converting them to
            // an HTTP 200 empty/pending table that discards index diagnostics.
            '_abj404_throw_on_view_query_error' => 1,
        );
    }

    /**
     * Whether a subpage supports the cheap detect-only signature path. The
     * redirects and captured tabs both read through getRedirectsForView, so the
     * View can compute their signature from a single one-page read. The logs tab
     * reads a different source and keeps the full path.
     */
    public static function canComputeCheapSignature(string $subpage): bool {
        return $subpage === 'abj404_redirects' || $subpage === 'abj404_captured';
    }

    /**
     * Whether the browser's signature is stale against the one just computed.
     *
     * Either side being empty means "cannot tell", which is reported as no
     * update rather than as a change: a spurious change forces a full table
     * fetch on every poll of a site whose signature could not be computed.
     */
    public static function hasSignatureUpdate(string $currentSignature, string $tableSignature): bool {
        if ($currentSignature === '' || $tableSignature === '') {
            return false;
        }
        if (function_exists('hash_equals')) {
            return !hash_equals($currentSignature, $tableSignature);
        }
        return $currentSignature !== $tableSignature;
    }

    /**
     * The requested part, or every part when 'all' was asked for.
     *
     * @param array{part: string, subpage: string, view: ABJ_404_Solution_View,
     *   viewReadService: ABJ_404_Solution_ViewReadServiceInterface,
     *   queryTimeoutSeconds?: int} $request One keyed bag rather than four
     *   positional arguments. It carried two adjacent strings (the part and the
     *   subpage) and two adjacent objects (the view and the view-read service),
     *   and both pairs transpose without a type error: a swapped part/subpage
     *   builds nothing and returns an empty response the client renders as an
     *   empty table, and a swapped view pair reaches a method_exists() guard
     *   that answers false and silently drops the sort-readiness metadata.
     *   `queryTimeoutSeconds` defaults to the foreground budget; pass it when
     *   the caller's own deadline is shorter.
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function build(array $request, array &$context): array {
        $builders = array(
            'table' => 'buildTablePart',
            'counts' => 'buildCountsPart',
            'pagination' => 'buildPaginationPart',
        );
        $parts = $request['part'] === 'all' ? array_keys($builders) : array($request['part']);
        $data = array();
        foreach ($parts as $part) {
            $method = $builders[$part] ?? '';
            if ($method === '') {
                continue;
            }
            $data = array_merge($data, self::$method($request, $context));
        }
        return $data;
    }

    /**
     * The query budget this request runs under.
     *
     * @param array{part: string, subpage: string, view: ABJ_404_Solution_View,
     *   viewReadService: ABJ_404_Solution_ViewReadServiceInterface,
     *   queryTimeoutSeconds?: int} $request
     * @return array<string, int>
     */
    private static function budgetFor(array $request): array {
        return isset($request['queryTimeoutSeconds'])
            ? self::queryBudgetOptionsForSeconds((int)$request['queryTimeoutSeconds'])
            : self::queryBudgetOptions();
    }

    /**
     * The table HTML for one subpage, plus the signature of the data it was
     * rendered from.
     *
     * @param array{part: string, subpage: string, view: ABJ_404_Solution_View,
     *   viewReadService: ABJ_404_Solution_ViewReadServiceInterface,
     *   queryTimeoutSeconds?: int} $request
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function buildTablePart(array $request, array &$context): array {
        $subpage = (string)$request['subpage'];
        $view = $request['view'];
        $viewReadService = $request['viewReadService'];
        $budget = self::budgetFor($request);
        if (!isset(self::TABLE_RENDERERS[$subpage])) {
            return array('table' => 'Error: Unexpected subpage requested.');
        }
        $renderer = self::TABLE_RENDERERS[$subpage];
        $method = $renderer['method'];
        return ABJ_404_Solution_AjaxStageDiagnostics::runStage(
            $context,
            $renderer['stage'],
            static function () use ($subpage, $view, $viewReadService, $method, $budget, &$context) {
                if (($subpage === 'abj404_redirects' || $subpage === 'abj404_captured')
                        && is_object($viewReadService)
                        && method_exists($viewReadService, 'sortReadinessStatusForOrderby')) {
                    $orderby = is_scalar($context['orderby'] ?? null) ? (string)$context['orderby'] : '';
                    ABJ_404_Solution_AjaxStageDiagnostics::addStageMetadata(array(
                        'sort_readiness' => $viewReadService->sortReadinessStatusForOrderby($orderby),
                    ));
                }
                return array(
                    'table' => $view->$method($subpage, $budget),
                    'tableSignature' => self::currentTableSignature($view, $subpage),
                );
            }
        );
    }

    /**
     * The status tab counts for one subpage. An incomplete read reports
     * countsIncomplete rather than zeros: a zero count is a finding and a
     * missing count is not, and the tabs must not claim an empty site.
     *
     * @param array{part: string, subpage: string, view: ABJ_404_Solution_View,
     *   viewReadService: ABJ_404_Solution_ViewReadServiceInterface,
     *   queryTimeoutSeconds?: int} $request
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function buildCountsPart(array $request, array &$context): array {
        $subpage = (string)$request['subpage'];
        $viewReadService = $request['viewReadService'];
        $queryOptions = self::budgetFor($request);
        if ($subpage === 'abj404_redirects') {
            $counts = ABJ_404_Solution_AjaxStageDiagnostics::runStage(
                $context,
                'redirect_status_counts',
                static function () use ($viewReadService, $queryOptions) {
                    $result = $viewReadService->getRedirectStatusCounts(false, $queryOptions);
                    ABJ_404_Solution_AjaxStageDiagnostics::addStageMetadata(array(
                        'cache' => !empty($result['_incomplete']) ? 'miss' : 'hit',
                    ));
                    return $result;
                }
            );
            if (!empty($counts['_incomplete'])) {
                return array('countsIncomplete' => true);
            }
            return array('tabCounts' => array(
                '0' => $counts['all'] ?? 0,
                (string)ABJ404_STATUS_MANUAL => $counts['manual'] ?? 0,
                (string)ABJ404_STATUS_AUTO => $counts['auto'] ?? 0,
                (string)ABJ404_TRASH_FILTER => $counts['trash'] ?? 0,
            ));
        }
        if ($subpage !== 'abj404_captured') {
            return array();
        }
        $counts = ABJ_404_Solution_AjaxStageDiagnostics::runStage(
            $context,
            'captured_status_counts',
            static function () use ($viewReadService, $queryOptions) {
                $result = $viewReadService->getCapturedStatusCounts(false, $queryOptions);
                ABJ_404_Solution_AjaxStageDiagnostics::addStageMetadata(array(
                    'cache' => !empty($result['_incomplete']) ? 'miss' : 'hit',
                ));
                return $result;
            }
        );
        if (!empty($counts['_incomplete'])) {
            return array('countsIncomplete' => true);
        }
        return array(
            'statusCounts' => $counts,
            'tabCounts' => array(
                '0' => $counts['all'] ?? 0,
                (string)ABJ404_STATUS_CAPTURED => $counts['captured'] ?? 0,
                (string)ABJ404_STATUS_IGNORED => $counts['ignored'] ?? 0,
                (string)ABJ404_STATUS_LATER => $counts['later'] ?? 0,
                (string)ABJ404_TRASH_FILTER => $counts['trash'] ?? 0,
                (string)ABJ404_HANDLED_FILTER => ($counts['ignored'] ?? 0) + ($counts['later'] ?? 0) + ($counts['trash'] ?? 0),
            ),
        );
    }

    /**
     * The pagination strip, for both slots.
     *
     * The response carries two keys because the page has two strips, above
     * and below the table. It does NOT carry two renders: the renderer takes
     * no top/bottom argument and reads the same row count either way, so a
     * per-slot render can only reproduce the first one -- at the price of a
     * second count query and a second read of paginationLinks.html on every
     * admin table request, on every tab, for every user.
     *
     * @param array{part: string, subpage: string, view: ABJ_404_Solution_View,
     *   viewReadService: ABJ_404_Solution_ViewReadServiceInterface,
     *   queryTimeoutSeconds?: int} $request
     * @param array<string, mixed> $context
     * @return array<string, string>
     */
    private static function buildPaginationPart(array $request, array &$context): array {
        $subpage = (string)$request['subpage'];
        $view = $request['view'];
        $queryOptions = self::budgetFor($request);
        $links = ABJ_404_Solution_AjaxStageDiagnostics::runStage(
            $context,
            'paginationLinks',
            static fn() => $view->getPaginationLinks($subpage, $queryOptions)
        );
        return array(
            'paginationLinksTop' => $links,
            'paginationLinksBottom' => $links,
        );
    }

    /**
     * @param ABJ_404_Solution_View $view
     */
    private static function currentTableSignature($view, string $subpage): string {
        if (is_object($view) && method_exists($view, 'getCurrentTableDataSignature')) {
            return (string)$view->getCurrentTableDataSignature($subpage);
        }
        return '';
    }
}
