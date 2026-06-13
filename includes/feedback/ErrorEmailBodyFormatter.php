<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Presentation layer for the developer error/heartbeat email sent by
 * ABJ_404_Solution_Logging::emailLogFileToDeveloper().
 *
 * Pure transform from a FeedbackTransport payload to the strings that go to
 * wp_mail(): subject line and HTML body. No file I/O, no DB writes, no
 * wp_mail() dispatch, no service container lookups. The result is therefore
 * unit-testable without mocking the WordPress mail surface, and the body
 * shape lives in one place rather than being intertwined with zip creation
 * and dispatch.
 *
 * The body format is plain text separated by literal "<BR/>\n" because the
 * Content-Type header on the wp_mail() send is text/html; consumers of this
 * email (the plugin developer's inbox) have been rendering it that way since
 * well before this refactor and the format is preserved verbatim.
 */
class ABJ_404_Solution_ErrorEmailBodyFormatter {

    /**
     * Build the wp_mail() subject line.
     *
     * @param array<string, mixed> $payload FeedbackTransport payload.
     * @return string
     */
    public function buildSubject(array $payload): string {
        $isHeartbeat = $this->isHeartbeat($payload);
        $pluginVersion = $this->scalarString($payload, 'plugin_version',
            defined('ABJ404_VERSION') ? ABJ404_VERSION : '');
        $brand = defined('ABJ404_PP') ? ABJ404_PP : '404 Solution';
        return $brand . ($isHeartbeat ? ' heartbeat' : ' error') . ' log file. Plugin version: ' . $pluginVersion;
    }

    /**
     * Build the wp_mail() HTML body.
     *
     * @param array<string, mixed> $payload FeedbackTransport payload.
     * @param string $subject Pre-built subject line (kept as the first body
     *        line for forwarding-friendliness).
     * @param string $debugFilename Name of the on-disk debug file the zip
     *        was built from. Included so the developer can correlate which
     *        rotated file the attached log came from.
     * @return string
     */
    public function buildBody(array $payload, string $subject, string $debugFilename): string {
        $errorLineMessage = $this->scalarString($payload, 'error_signature', '');
        $totalErrorCount = $this->scalarInt($payload, 'error_count_in_log', 0);

        $logTableSizeMB = round($this->scalarInt($payload, 'log_table_size_bytes', 0) / (1024 * 1024), 2);
        $debugFileSizeMB = round($this->scalarInt($payload, 'debug_file_size_bytes', 0) / (1024 * 1024), 2);
        $extensions = isset($payload['extensions']) && is_array($payload['extensions']) ? $payload['extensions'] : array();
        $activePlugins = isset($payload['active_plugins']) && is_array($payload['active_plugins']) ? $payload['active_plugins'] : array();
        $isMultisite = !empty($payload['is_multisite']);

        $lines = array();
        $lines[] = $subject . ". Sent " . date('Y/m/d h:i:s T', abj_clock()->now());
        $lines[] = " ";
        $lines[] = "Error: " . $errorLineMessage;
        $lines[] = " ";
        $lines[] = "PHP version: " . $this->scalarString($payload, 'php_version', PHP_VERSION);
        $lines[] = "WordPress version: " . $this->scalarString($payload, 'wp_version', '');
        $lines[] = "Plugin version: " . $this->scalarString($payload, 'plugin_version',
            defined('ABJ404_VERSION') ? ABJ404_VERSION : '');
        $lines[] = "MySQL version: " . $this->scalarString($payload, 'db_version', '');
        $lines[] = "Site URL: " . $this->scalarString($payload, 'site_url', '');
        $lines[] = "Multisite: " . ($isMultisite ? 'yes' : 'no');
        if ($isMultisite && function_exists('is_plugin_active_for_network') && defined('ABJ404_FILE')
            && function_exists('plugin_basename')) {
            $lines[] = "Network activated: " .
                (is_plugin_active_for_network(plugin_basename(ABJ404_FILE)) ? 'yes' : 'no');
        }
        $lines[] = "WP_MEMORY_LIMIT: " . (defined('WP_MEMORY_LIMIT') ? WP_MEMORY_LIMIT : '');

        $extensionStrings = array_map(static function ($v): string {
            return is_scalar($v) ? (string)$v : '';
        }, $extensions);
        $lines[] = "Extensions: " . implode(", ", $extensionStrings);

        $lines[] = " ";
        $lines[] = "--- WordPress Content Counts ---";
        $lines[] = "Published posts: " . $this->scalarString($payload, 'published_posts_count', '0');
        $lines[] = "Published pages: " . $this->scalarString($payload, 'published_pages_count', '0');
        $lines[] = "Categories: " . $this->scalarString($payload, 'categories_count', '0');
        $lines[] = "Tags: " . $this->scalarString($payload, 'tags_count', '0');

        $lines[] = " ";
        $lines[] = "--- 404 Solution Counts ---";
        $lines[] = "Total redirects (active): " . $this->scalarString($payload, 'redirects_active_total', '0');
        $lines[] = "  - Manual redirects: " . $this->scalarString($payload, 'redirects_manual_count', '0');
        $lines[] = "  - Automatic redirects: " . $this->scalarString($payload, 'redirects_automatic_count', '0');
        $lines[] = "  - Regex redirects: " . $this->scalarString($payload, 'redirects_regex_count', '0');
        $lines[] = "  - Trashed redirects: " . $this->scalarString($payload, 'redirects_trashed_count', '0');
        $lines[] = "Captured 404s (active): " . $this->scalarString($payload, 'captured_404s_active_total', '0');
        $lines[] = "  - Captured (new): " . $this->scalarString($payload, 'captured_404s_new_count', '0');
        $lines[] = "  - Ignored: " . $this->scalarString($payload, 'captured_404s_ignored_count', '0');
        $lines[] = "  - Later: " . $this->scalarString($payload, 'captured_404s_later_count', '0');
        $lines[] = "  - Trashed: " . $this->scalarString($payload, 'captured_404s_trashed_count', '0');
        $lines[] = "Log entries in database: " . $this->scalarString($payload, 'log_entries_count', '0');
        $lines[] = "Log table size: " . $logTableSizeMB . " MB";

        $lines[] = " ";
        $lines[] = "Total error count in log file: " . $totalErrorCount;
        $lines[] = "Debug file name: " . $debugFilename;
        $lines[] = "Debug file size: " . $debugFileSizeMB . " MB";
        $lines[] = "Active plugins: <pre>" .
            json_encode($activePlugins, JSON_PRETTY_PRINT) . "</pre>";

        return implode("<BR/>\n", $lines);
    }

    /** @param array<string, mixed> $payload */
    private function isHeartbeat(array $payload): bool {
        return isset($payload['report_type']) && $payload['report_type'] === 'heartbeat';
    }

    /**
     * @param array<string, mixed> $payload
     * @param string $key
     * @param string $fallback
     * @return string
     */
    private function scalarString(array $payload, string $key, string $fallback): string {
        if (!array_key_exists($key, $payload)) {
            return $fallback;
        }
        return is_scalar($payload[$key]) ? (string)$payload[$key] : $fallback;
    }

    /**
     * @param array<string, mixed> $payload
     * @param string $key
     * @param int $fallback
     * @return int
     */
    private function scalarInt(array $payload, string $key, int $fallback): int {
        if (!array_key_exists($key, $payload)) {
            return $fallback;
        }
        return is_scalar($payload[$key]) ? (int)$payload[$key] : $fallback;
    }
}
