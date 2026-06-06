<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin self-maintenance metadata: wordpress.org version lookup, version-gap
 * heuristic for error email throttling, and the legacy Redirectioner import.
 *
 * Extracted from the DataAccess monolith to keep DataAccess.php under the
 * file size limit (FileSizeLimitsTest::MAX_LINES). Methods on the DAO remain
 * as thin delegates so existing call sites and test doubles continue to work.
 */
class ABJ_404_Solution_PluginUpdateMetadataRepository {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_Logging|null $logging
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        $functions = null,
        $logging = null
    ) {
        $this->dbCore = $dbCore;
        $this->f = $functions !== null ? $functions : abj_service('functions');
        $this->logger = $logging !== null ? $logging : abj_service('logging');
    }

    /** @return array{version: string, last_updated: string|null} */
    public function getLatestPluginVersion(): array {
        // Cache version info to avoid repeated slow wordpress.org API calls.
        $cacheKey = 'abj404_latest_plugin_version_info';
        if (function_exists('get_transient')) {
            $cached = get_transient($cacheKey);
            if (is_array($cached) && isset($cached['version'])) {
                /** @var array{version: string, last_updated: string|null} $cached */
                return $cached;
            }
        }

        if (!function_exists('plugins_api')) {
            $pluginInstallPath = ABSPATH . 'wp-admin/includes/plugin-install.php';
            if (is_readable($pluginInstallPath)) {
                require_once($pluginInstallPath);
            }
        }
        if (!function_exists('plugins_api')) {
            $this->logger->infoMessage("I couldn't find the plugins_api function to check for the latest version.");
            $fallback = array('version' => ABJ404_VERSION, 'last_updated' => null);
            return $fallback;
        }

        $pluginSlug = dirname(ABJ404_NAME);

        $args = array(
            'slug' => $pluginSlug,
            'fields' => array(
                'version' => true,
                'last_updated' => true,
            )
        );

        $call_api = plugins_api('plugin_information', $args);

        if (is_wp_error($call_api)) {
            $api_error = $call_api->get_error_message();
            $this->logger->infoMessage("There was an API issue checking the latest plugin version ("
                    . $api_error . ")");

            $fallback = array('version' => ABJ404_VERSION, 'last_updated' => null);
            return $fallback;
        }

        /** @var object $call_api */
        $apiVersion = property_exists($call_api, 'version') ? (string)$call_api->version : ABJ404_VERSION;
        $apiLastUpdated = property_exists($call_api, 'last_updated') ? (string)$call_api->last_updated : null;
        $result = array('version' => $apiVersion, 'last_updated' => $apiLastUpdated);
        if (function_exists('set_transient')) {
            $ttl = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
            // allow-cache-empty: $result always carries a version string (fallback to ABJ404_VERSION when plugins_api omits it); is_wp_error early-returns above
            set_transient($cacheKey, $result, $ttl);
        }
        return $result;
    }

    /**
     * Decide whether the error file should be emailed based on the gap between
     * the installed plugin version and the latest published version. Accepts
     * the version info as an argument so the DAO can route the lookup through
     * its own (potentially mocked) `getLatestPluginVersion()`.
     *
     * @param array{version: string, last_updated: string|null} $pluginInfo
     * @return bool
     */
    public function shouldEmailErrorFileFor(array $pluginInfo): bool {
        $latestVersion = $pluginInfo['version'];
        $currentVersion = ABJ404_VERSION;
        if ($latestVersion == $currentVersion) {
            return true;
        }

        if (version_compare(ABJ404_VERSION, $latestVersion) == 1) {
            $this->logger->infoMessage("Development version: A more recent version is installed than " .
                    "what is available on the WordPress site (" . ABJ404_VERSION . " / " .
                     $latestVersion . ").");
            return true;
        }

        $currentArray = explode(".", $currentVersion);
        $latestArray = explode(".", $latestVersion);

        if (count($currentArray) != 3 || count($latestArray) != 3) {
            $this->logger->errorMessage("Issue parsing version numbers. " .
                    $currentVersion . ' / ' . $latestVersion);

        } else if ($currentArray[0] == $latestArray[0] && $currentArray[1] == $latestArray[1]) {
            $difference = absint(absint($latestArray[2]) - absint($currentArray[2]));

            if ($difference <= 1) {
                return true;
            }
        }

        return (ABJ404_VERSION == $pluginInfo['version']);
    }

    /** @return array<string, mixed> */
    public function importDataFromPluginRedirectioner(): array {
        global $wpdb;

        $oldTable = $wpdb->prefix . 'wbz404_redirects';
        $newTable = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');

        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/importDataFromPluginRedirectioner.sql");
        $query = $this->f->str_replace('{OLD_TABLE}', $oldTable, $query);
        $query = $this->f->str_replace('{NEW_TABLE}', $newTable, $query);

        $result = $this->dbCore->queryAndGetResults($query);

        $this->logger->infoMessage("Importing redirectioner SQL result: " .
                wp_kses_post((string)json_encode($result)));

        return $result;
    }
}
