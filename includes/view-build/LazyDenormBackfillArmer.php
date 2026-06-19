<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: exercised by RedirectsSingleTableLiveResolveTest (arming on admin view read)

/**
 * Arms the lazy denorm backfills when the admin views the redirects/captured
 * table.
 *
 * Two derived-column backlogs are populated lazily by a once-daily maintenance
 * cron: wp_abj404_logsv2.canonical_url and the narrow url/dest sort keys on
 * wp_abj404_redirects. Their read-path index gates (and the canonical-url JOIN
 * optimization) only open once each backlog is drained, so on a freshly-upgraded
 * site a manual URL/Destination sort on a captured-heavy table filesorts the
 * majority until the daily cron catches up -- up to a 24h window.
 *
 * This collaborator arms (schedules, never runs inline) those drains from
 * non-AJAX admin redirects-table reads, shrinking the window to within seconds
 * of the first visit. Admin-ajax table reads are intentionally left to the
 * browser post-load lazy-backfill endpoint, because server loopback cron can be
 * blocked while browser admin-ajax is still reachable. It is invoked from
 * ABJ_404_Solution_ViewReadService::getRedirectsForView(), the single seam both
 * admin tabs and the REST read actually hit.
 *
 * It exists as its own collaborator (rather than folded into the read
 * coordinator) because arming background DB-maintenance is a distinct concern
 * from reading rows: it reaches the database_upgrades subsystem the read path
 * otherwise never touches. Keeping it separate lets the read coordinator stay
 * single-responsibility and makes the arming independently testable.
 *
 * The database_upgrades service is resolved lazily through an injectable
 * provider (defaulting to abj_service_optional) rather than constructor-injected
 * into the view-read stack: that subsystem is composed after the view-read
 * service, so pulling it in eagerly would risk a construction cycle. Tests pass
 * a provider that returns a test-configured upgrades object.
 */
class ABJ_404_Solution_LazyDenormBackfillArmer {

    /** @var ABJ_404_Solution_Logging|null */
    private $logger;

    /** @var callable():(ABJ_404_Solution_DatabaseUpgradesEtc|null) Resolves the database_upgrades service. */
    private $upgradesProvider;

    /**
     * @param ABJ_404_Solution_Logging|null $logger Used only to debug-log an
     *   arming failure; arming is best-effort and never propagates errors.
     * @param (callable():(ABJ_404_Solution_DatabaseUpgradesEtc|null))|null $upgradesProvider
     *   Returns the database_upgrades service, or null when unavailable. Defaults
     *   to the optional service-locator lookup. Tests inject a provider that
     *   returns a test-configured upgrades object.
     */
    public function __construct($logger = null, ?callable $upgradesProvider = null) {
        $this->logger = $logger;
        $this->upgradesProvider = $upgradesProvider !== null
            ? $upgradesProvider
            : static function (): ?ABJ_404_Solution_DatabaseUpgradesEtc {
                $service = function_exists('abj_service_optional')
                    ? abj_service_optional('database_upgrades')
                    : null;
                return $service instanceof ABJ_404_Solution_DatabaseUpgradesEtc ? $service : null;
            };
    }

    /**
     * Schedule (never run inline) the lazy denorm backfills so their read-path
     * index gates open within seconds of the first admin visit instead of
     * waiting for the daily maintenance cron. Cheap and request-deduped inside
     * each scheduler, so it is safe to call on every admin view read including
     * the background change-detection poll. Never throws into the read path.
     *
     * @return void
     */
    public function armOnAdminViewRead(): void {
        try {
            if ($this->isAdminAjaxRequest()) {
                return;
            }
            $upgrades = ($this->upgradesProvider)();
            if ($upgrades === null) {
                return;
            }
            $components = $upgrades->components();
            $components->canonicalUrlBackfillUpgrade()->scheduleLogsv2CanonicalUrlBackfill();
            $components->redirectsDenormBackfillUpgrade()->scheduleRedirectsDenormBackfill();
            $components->redirectsSortKeyBackfillUpgrade()->scheduleRedirectsSortKeyBackfill();
        } catch (\Throwable $e) {
            // Arming is best-effort maintenance: a failure here must never break
            // an admin view read, and the daily maintenance cron remains the
            // backstop that converges both backlogs. Surface it at debug level.
            if ($this->logger !== null && method_exists($this->logger, 'debugMessage')) {
                $this->logger->debugMessage(
                    'LazyDenormBackfillArmer: backfill arming skipped (' . $e->getMessage() . ').'
                );
            }
        }
    }

    private function isAdminAjaxRequest(): bool {
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return true;
        }
        $scriptName = isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME'])
            ? basename($_SERVER['SCRIPT_NAME']) : '';
        if ($scriptName === 'admin-ajax.php') {
            return true;
        }
        $pagenow = isset($GLOBALS['pagenow']) && is_string($GLOBALS['pagenow'])
            ? $GLOBALS['pagenow'] : '';
        return $pagenow === 'admin-ajax.php';
    }
}
