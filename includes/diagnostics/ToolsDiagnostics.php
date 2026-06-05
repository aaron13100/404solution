<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: covered through Tools page entry-point tests in tests/ViewToolsPageTest.php

/**
 * Collects the read-only environment diagnostics shown on the Tools page.
 */
class ABJ_404_Solution_ToolsDiagnostics {

    /**
     * Return diagnostics rows for the Tools page.
     *
     * Rows are structured data only. View_Tools is responsible for rendering
     * any HTML controls attached to a row.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRows(): array {
        $uploadDir = '';
        if (function_exists('abj404_getUploadsDir')) {
            $uploadDir = (string)abj404_getUploadsDir();
        }
        $uploadDirReadable = ($uploadDir !== '' && is_dir($uploadDir));
        $uploadDirWritable = ($uploadDir !== '' && is_writable($uploadDir));

        $pluginVersion = defined('ABJ404_VERSION') ? ABJ404_VERSION : __('Unknown', '404-solution');
        $rows = array(
            array('label' => __('Plugin Version', '404-solution'), 'value' => $pluginVersion, 'status' => 'info'),
            array('label' => __('WordPress Version', '404-solution'), 'value' => $this->getWordPressVersion(), 'status' => 'info'),
            array('label' => __('PHP Version', '404-solution'), 'value' => PHP_VERSION, 'status' => 'info'),
            array(
                'label' => __('Uploads Directory', '404-solution'),
                'value' => ($uploadDir !== '') ? $uploadDir : __('Not available', '404-solution'),
                'status' => $uploadDirReadable ? 'ok' : 'warn',
            ),
            array(
                'label' => __('Uploads Writable', '404-solution'),
                'value' => $uploadDirWritable ? __('Yes', '404-solution') : __('No', '404-solution'),
                'status' => $uploadDirWritable ? 'ok' : 'warn',
            ),
            array(
                'label' => __('mbstring Extension', '404-solution'),
                'value' => extension_loaded('mbstring') ? __('Loaded', '404-solution') : __('Missing', '404-solution'),
                'status' => extension_loaded('mbstring') ? 'ok' : 'warn',
            ),
            array(
                'label' => __('ZipArchive Support', '404-solution'),
                'value' => class_exists('ZipArchive') ? __('Available', '404-solution') : __('Missing', '404-solution'),
                'status' => class_exists('ZipArchive') ? 'ok' : 'warn',
            ),
            array(
                'label' => __('WP_DEBUG', '404-solution'),
                'value' => (defined('WP_DEBUG') && WP_DEBUG) ? __('Enabled', '404-solution') : __('Disabled', '404-solution'),
                'status' => 'info',
            ),
        );

        $latencyRow = $this->getSimulatedLatencyRow();
        if ($latencyRow !== null) {
            $rows[] = $latencyRow;
        }

        return $rows;
    }

    /** @return string */
    private function getWordPressVersion(): string {
        $wpVersion = get_bloginfo('version');
        if (!is_string($wpVersion) || trim($wpVersion) === '') {
            return __('Unknown', '404-solution');
        }

        return $wpVersion;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getSimulatedLatencyRow(): ?array {
        if (!function_exists('abj404_is_local_debug_host') ||
                !function_exists('abj404_get_simulated_db_latency_ms') ||
                !abj404_is_local_debug_host()) {
            return null;
        }

        $latencyMs = absint(abj404_get_simulated_db_latency_ms());
        $valueText = ($latencyMs > 0)
            ? sprintf(__('ON (%d ms per plugin query)', '404-solution'), $latencyMs)
            : __('OFF', '404-solution');

        return array(
            'label' => __('Simulated DB Latency', '404-solution'),
            'value' => $valueText,
            'status' => ($latencyMs > 0) ? 'warn' : 'info',
            'controls' => array(
                'kind' => 'simulated-db-latency',
                'value_text' => $valueText,
                'urls' => array(
                    250 => wp_nonce_url(admin_url('options-general.php?page=' . ABJ404_PP . '&subpage=abj404_tools&abj404_set_sim_db_ms=250'), 'abj404_set_sim_db_ms'),
                    500 => wp_nonce_url(admin_url('options-general.php?page=' . ABJ404_PP . '&subpage=abj404_tools&abj404_set_sim_db_ms=500'), 'abj404_set_sim_db_ms'),
                    900 => wp_nonce_url(admin_url('options-general.php?page=' . ABJ404_PP . '&subpage=abj404_tools&abj404_set_sim_db_ms=900'), 'abj404_set_sim_db_ms'),
                    0   => wp_nonce_url(admin_url('options-general.php?page=' . ABJ404_PP . '&subpage=abj404_tools&abj404_set_sim_db_ms=0'), 'abj404_set_sim_db_ms'),
                ),
                'labels' => array(
                    250 => __('250ms', '404-solution'),
                    500 => __('500ms', '404-solution'),
                    900 => __('900ms', '404-solution'),
                    0   => __('Disable', '404-solution'),
                ),
            ),
        );
    }
}
