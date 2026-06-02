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
            $this->logger->warn('Frontend DB version recovery failed: ' . $e->getMessage());
            return $options;
        }

        // upgradeIfNeeded ends in updateOptions() which clears the resolved-
        // options cache, so getOptions(true) returns fresh values from the DB.
        $fresh = $this->getOptions();
        if (isset($fresh['DB_VERSION']) && defined('ABJ404_VERSION') && $fresh['DB_VERSION'] == ABJ404_VERSION) {
            return $fresh;
        }

        $observed = (isset($fresh['DB_VERSION']) && is_scalar($fresh['DB_VERSION']))
            ? (string)$fresh['DB_VERSION']
            : '(missing)';
        $expected = defined('ABJ404_VERSION') ? ABJ404_VERSION : '(unknown)';
        $this->logger->warn(sprintf(
            'Frontend DB_VERSION still stale after recovery attempt: have=%s expected=%s',
            $observed,
            $expected
        ));
        return $fresh;
    }

    /**
     * @return array<string, mixed>
     */
    private function getOptions(): array {
        $options = $this->logic->optionsResolver()->getOptions(true);
        return is_array($options) ? $options : array();
    }
}
