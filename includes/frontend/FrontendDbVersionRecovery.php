<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Self-heals a stale DB_VERSION on the frontend so end users get redirects
 * without needing an admin visit (task 233). Throttled by a transient so
 * concurrent 404s don't all queue on the synchronizer lock inside
 * PluginLogicVersionUpgrader::upgradeIfNeeded(). If recovery cannot close
 * the gap (lock held, cooldown active, migration repeatedly throws), the
 * caller falls through to a degraded redirect lookup (task 234) so manual
 * redirects keep serving instead of every 404 falling to the theme 404 page.
 */
class ABJ_404_Solution_FrontendDbVersionRecovery {

    /** Number of consecutive recovery attempts that ended with DB_VERSION still
     * stale. Stored as an option (not a transient) so an external object cache
     * cannot silently drop the streak and hide a long-running wedge.
     * @var string */
    const CONSECUTIVE_FAILURE_OPTION = 'abj404_frontend_db_recovery_failures';

    /** Escalate from warning to error once a streak reaches this length.
     * With the 5-minute cooldown that is roughly 25 minutes of a site running
     * new code against an old schema -- well past "transient hiccup" and into
     * "something is stuck and a human needs to see it".
     * @var int */
    const ESCALATE_AFTER_CONSECUTIVE_FAILURES = 5;

    /** @var ABJ_404_Solution_PluginLogic */
    private $logic;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_PluginLogic $logic
     * @param ABJ_404_Solution_Logging $logger
     */
    function __construct($logic, $logger) {
        $this->logic = $logic;
        $this->logger = $logger;
    }

    /**
     * @param array<string, mixed> $options Current options as returned by getOptions(true).
     * @return array<string, mixed> Options after attempted recovery.
     */
    function recoverIfStale(array $options): array {
        $cooldownKey = 'abj404_frontend_db_recovery_cooldown';

        if (function_exists('get_transient') && get_transient($cooldownKey)) {
            return $options;
        }

        // Cooldown is set BEFORE attempting recovery so concurrent requests
        // bail immediately rather than piling onto the lock.
        if (function_exists('set_transient')) {
            set_transient($cooldownKey, '1', 5 * 60);
        }

        try {
            $upgraded = $this->logic->versionUpgrader()->upgradeIfNeeded($options);
            if (is_array($upgraded)) {
                $options = $upgraded;
            }
        } catch (\Throwable $e) {
            $failureCount = $this->recordFailedAttempt();
            $this->logger->warn(sprintf(
                'Frontend DB version recovery failed (consecutive failed attempts: %d): %s',
                $failureCount,
                $e->getMessage()
            ));
            $this->escalateIfStreakJustReachedTheThreshold($failureCount,
                'The upgrade threw: ' . $e->getMessage());
            return $options;
        }

        // upgradeIfNeeded ends in updateOptions() which clears the resolved-
        // options cache, so getOptions(true) returns fresh values from the DB.
        $fresh = $this->getOptions();
        if (isset($fresh['DB_VERSION']) && defined('ABJ404_VERSION') && $fresh['DB_VERSION'] == ABJ404_VERSION) {
            $this->clearFailureStreak();
            return $fresh;
        }

        $observed = (isset($fresh['DB_VERSION']) && is_scalar($fresh['DB_VERSION']))
            ? (string)$fresh['DB_VERSION']
            : '(missing)';
        $expected = defined('ABJ404_VERSION') ? ABJ404_VERSION : '(unknown)';
        $failureCount = $this->recordFailedAttempt();
        $this->logger->warn(sprintf(
            'Frontend DB_VERSION still stale after recovery attempt: have=%s expected=%s ' .
            '(consecutive failed attempts: %d)',
            $observed,
            $expected,
            $failureCount
        ));
        $this->escalateIfStreakJustReachedTheThreshold($failureCount, sprintf(
            'DB_VERSION is stuck at %s while the running code expects %s.',
            $observed,
            $expected
        ));
        return $fresh;
    }

    /**
     * Record one more consecutive failure and return the new streak length.
     *
     * @return int
     */
    private function recordFailedAttempt(): int {
        if (!function_exists('get_option') || !function_exists('update_option')) {
            return 1;
        }

        $stored = get_option(self::CONSECUTIVE_FAILURE_OPTION, 0);
        $failureCount = (is_scalar($stored) ? (int)$stored : 0) + 1;

        // @cache-write-audit: opt-out - stores a failure-streak counter, not a query result
        update_option(self::CONSECUTIVE_FAILURE_OPTION, $failureCount, false);

        return $failureCount;
    }

    /** @return void */
    private function clearFailureStreak(): void {
        if (function_exists('delete_option')) {
            delete_option(self::CONSECUTIVE_FAILURE_OPTION);
        }
    }

    /**
     * Surface a persistent wedge exactly once per streak.
     *
     * Warnings are the right level for a single failed attempt: the plugin
     * degrades to a manual-redirect lookup and the next visitor retries. A
     * streak is different -- the site keeps running new code against an old
     * schema indefinitely, which is the "plugin cannot do its job" case that
     * the defensive-coding rules put at error level. Escalating on exact
     * equality (rather than >=) means the streak itself is the dedupe: one
     * report per wedge, not one per visitor, and no separate dedupe transient
     * that an object cache could drop.
     *
     * @param int $failureCount
     * @param string $detail
     * @return void
     */
    private function escalateIfStreakJustReachedTheThreshold(int $failureCount, string $detail): void {
        if ($failureCount !== self::ESCALATE_AFTER_CONSECUTIVE_FAILURES) {
            return;
        }

        $this->logger->errorMessage(sprintf(
            'Frontend database upgrade has now failed %d consecutive times, so this site is ' .
            'serving degraded redirect lookups against an out-of-date schema. %s',
            $failureCount,
            $detail
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function getOptions(): array {
        $options = $this->logic->optionsResolver()->getOptions(true);
        return is_array($options) ? $options : array();
    }
}
