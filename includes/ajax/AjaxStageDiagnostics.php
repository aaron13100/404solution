<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tracks which phase an AJAX request is in for timeout diagnostics.
 *
 * The current stage/query-label/what-happening trio is threaded through
 * $context and mirrored onto $GLOBALS['abj404_ajax_context'] so that if the
 * request ends in an error response, ajaxUpdatePaginationLinks can attach
 * it under data.details.context for the admin-facing failure notice
 * (see view_updater_pagination_error_notice.js).
 */
class ABJ_404_Solution_AjaxStageDiagnostics {

    /**
     * Update the in-flight stage marker for the current AJAX request: sets
     * `$context['stage']` (plus query_label/what_happening) and mirrors it
     * onto $GLOBALS['abj404_ajax_context'] so an error response emitted
     * later in the same request can attach the last-known stage.
     *
     * @param array<string, mixed> $context  Passed by reference; mutated in place.
     * @param string $stage  Stage label (e.g. 'table_captured', 'paginationLinksTop').
     * @return void
     */
    public static function setStage(&$context, $stage) {
        if (!is_array($context)) {
            $context = array();
        }
        $diagnostics = self::getStageDiagnostics($stage);
        $context['stage'] = $stage;
        $context['query_label'] = $diagnostics['query_label'];
        $context['what_happening'] = $diagnostics['what_happening'];
        if (isset($GLOBALS['abj404_ajax_context']) && is_array($GLOBALS['abj404_ajax_context'])) {
            $GLOBALS['abj404_ajax_context']['stage'] = $stage;
            $GLOBALS['abj404_ajax_context']['query_label'] = $diagnostics['query_label'];
            $GLOBALS['abj404_ajax_context']['what_happening'] = $diagnostics['what_happening'];
        }
    }

    /**
     * @param string $stage
     * @return array{query_label: string, what_happening: string}
     */
    public static function getStageDiagnostics($stage) {
        $map = array(
            'table_redirects' => array(
                'query_label' => 'getAdminRedirectsPageTable() -> read redirects rows from staged view snapshot',
                'what_happening' => 'Loading Redirects table rows',
            ),
            'redirect_status_counts' => array(
                'query_label' => 'getRedirectStatusCounts()',
                'what_happening' => 'Counting Redirects status tabs',
            ),
            'table_captured' => array(
                'query_label' => 'getCapturedURLSPageTable() -> read captured rows from staged view snapshot',
                'what_happening' => 'Loading Captured 404 URLs table rows',
            ),
            'captured_status_counts' => array(
                'query_label' => 'getCapturedStatusCounts()',
                'what_happening' => 'Counting Captured 404 URLs status tabs',
            ),
            'table_logs' => array(
                'query_label' => 'getAdminLogsPageTable() -> getLogRecords()',
                'what_happening' => 'Loading Logs table rows',
            ),
            'paginationLinksTop' => array(
                'query_label' => 'getPaginationLinks(top) -> read top pagination count from staged view snapshot',
                'what_happening' => 'Rendering top pagination links',
            ),
            'paginationLinksBottom' => array(
                'query_label' => 'getPaginationLinks(bottom) -> read bottom pagination count from staged view snapshot',
                'what_happening' => 'Rendering bottom pagination links',
            ),
            'table_cache_rows' => array(
                'query_label' => 'getRedirectsForView',
                'what_happening' => 'Warming table row snapshot',
            ),
            'table_cache_count' => array(
                'query_label' => 'getRedirectsForViewCount',
                'what_happening' => 'Warming table count snapshot',
            ),
            'high_impact_count' => array(
                'query_label' => 'getHighImpactCapturedCount()',
                'what_happening' => 'Counting high-impact captured URLs',
            ),
            'staged_build_s1_create' => array(
                'query_label' => 'CREATE TABLE wp_abj404_view_build', // allow-prefix-literal: display-only diagnostic label.
                'what_happening' => 'Creating build buffer (1/11)',
            ),
            'staged_build_s2_insert' => array(
                'query_label' => 'INSERT INTO wp_abj404_view_build SELECT FROM wp_abj404_redirects', // allow-prefix-literal: display-only diagnostic label.
                'what_happening' => 'Bulk-loading redirects into build buffer (2/11)',
            ),
            'staged_build_s3_index_fd' => array(
                'query_label' => 'ALTER TABLE wp_abj404_view_build ADD INDEX idx_fd_int', // allow-prefix-literal: display-only diagnostic label.
                'what_happening' => 'Adding pre-join indexes (3/11)',
            ),
            'staged_build_s4_update_posts' => array(
                'query_label' => 'UPDATE wp_abj404_view_build LEFT JOIN wp_posts', // allow-prefix-literal: display-only diagnostic label.
                'what_happening' => 'Filling published-status from wp_posts (4/11)',
            ),
            'staged_build_s5_update_terms' => array(
                'query_label' => 'UPDATE wp_abj404_view_build LEFT JOIN wp_terms', // allow-prefix-literal: display-only diagnostic label.
                'what_happening' => 'Filling published-status from wp_terms (5/11)',
            ),
            'staged_build_s6_update_home' => array(
                'query_label' => 'UPDATE wp_abj404_view_build (HOME)', // allow-prefix-literal: display-only diagnostic label.
                'what_happening' => 'Filling HOME-typed redirects (6/11)',
            ),
            'staged_build_s7_update_external' => array(
                'query_label' => 'UPDATE wp_abj404_view_build (EXTERNAL)', // allow-prefix-literal: display-only diagnostic label.
                'what_happening' => 'Filling EXTERNAL-typed redirects (7/11)',
            ),
            'staged_build_s8_update_special' => array(
                'query_label' => 'UPDATE wp_abj404_view_build (404-displayed)', // allow-prefix-literal: display-only diagnostic label.
                'what_happening' => 'Filling 404-displayed redirects (8/11)',
            ),
            'staged_build_s9_update_hits' => array(
                'query_label' => 'UPDATE wp_abj404_view_build LEFT JOIN wp_abj404_logs_hits', // allow-prefix-literal: display-only diagnostic label.
                'what_happening' => 'Filling hit counts (9/11)',
            ),
            'staged_build_s10_index_sort' => array(
                'query_label' => 'ALTER TABLE wp_abj404_view_build ADD INDEX (sort indexes)', // allow-prefix-literal: display-only diagnostic label.
                'what_happening' => 'Adding read-side sort indexes (10/11)',
            ),
            'staged_build_s11_swap' => array(
                'query_label' => 'Finalize admin table refresh',
                'what_happening' => 'Finalizing admin table refresh (11/11)',
            ),
        );
        if (array_key_exists($stage, $map)) {
            return $map[$stage];
        }
        $colonPos = is_string($stage) ? strpos((string)$stage, ':') : false;
        if ($colonPos !== false) {
            $base = substr((string)$stage, 0, $colonPos);
            $detail = trim(substr((string)$stage, $colonPos + 1));
            if (array_key_exists($base, $map)) {
                $entry = $map[$base];
                if ($detail !== '') {
                    $entry['what_happening'] = $entry['what_happening'] . ' — ' . $detail; // allow-em-dash: stage label separator in user-visible AJAX diagnostics
                }
                return $entry;
            }
        }
        return array(
            'query_label' => (string)$stage,
            'what_happening' => 'Running AJAX stage ' . (string)$stage,
        );
    }
}
