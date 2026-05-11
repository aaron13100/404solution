/**
 * Stage diagnostics for the view-build pipeline.
 *
 * Maps server-emitted stage codes (e.g. table_redirects, staged_build_s2_insert)
 * to UI-friendly diagnostic objects with queryLabel + whatsHappening + stageNumber.
 * The progress poller and AJAX failure notice both render text from this lookup,
 * so a missed mapping shows up to the admin as "(stage ?, unknown)".
 *
 * Loaded as a sibling of view_updater.js (see WordPress_Connector::my_wp_enq_scrpt).
 * Globals defined here are consumed by view_updater_build_advance.js,
 * view_updater_table_init.js, view_updater_table_warmup.js and
 * view_updater_pagination.js.
 */

function abj404AjaxStageDiagnostics(stage, subpage) {
    var map = {
        table_redirects: {
            queryLabel: 'getAdminRedirectsPageTable() -> read redirects rows from staged view snapshot',
            whatsHappening: 'Loading Redirects table rows',
            stageNumber: 1
        },
        redirect_status_counts: {
            queryLabel: 'getRedirectStatusCounts()',
            whatsHappening: 'Counting Redirects status tabs',
            stageNumber: 2
        },
        table_captured: {
            queryLabel: 'getCapturedURLSPageTable() -> read captured rows from staged view snapshot',
            whatsHappening: 'Loading Captured 404 URLs table rows',
            stageNumber: 1
        },
        captured_status_counts: {
            queryLabel: 'getCapturedStatusCounts()',
            whatsHappening: 'Counting Captured 404 URLs status tabs',
            stageNumber: 2
        },
        table_logs: {
            queryLabel: 'getAdminLogsPageTable() -> getLogRecords()',
            whatsHappening: 'Loading Logs table rows',
            stageNumber: 1
        },
        paginationLinksTop: {
            queryLabel: 'getPaginationLinks(top) -> read top pagination count from staged view snapshot',
            whatsHappening: 'Rendering top pagination links',
            stageNumber: 3
        },
        paginationLinksBottom: {
            queryLabel: 'getPaginationLinks(bottom) -> read bottom pagination count from staged view snapshot',
            whatsHappening: 'Rendering bottom pagination links',
            stageNumber: 4
        },
        table_cache_rows: {
            queryLabel: 'getRedirectsForView',
            whatsHappening: 'Warming table row snapshot',
            stageNumber: 1
        },
        table_cache_count: {
            queryLabel: 'getRedirectsForViewCount',
            whatsHappening: 'Warming table count snapshot',
            stageNumber: 2
        },
        // Sub-stages of the staged view-build pipeline.  These render under
        // the outer AJAX stage as steps 1..11 of the cold-cache build.
        staged_build_s1_create: {
            queryLabel: 'CREATE TABLE wp_abj404_view_build',
            whatsHappening: 'Creating build buffer (1/11)',
            stageNumber: 1
        },
        staged_build_s2_insert: {
            queryLabel: 'INSERT INTO wp_abj404_view_build SELECT FROM wp_abj404_redirects',
            whatsHappening: 'Bulk-loading redirects into build buffer (2/11)',
            stageNumber: 2
        },
        staged_build_s3_index_fd: {
            queryLabel: 'ALTER TABLE wp_abj404_view_build ADD INDEX idx_fd_int',
            whatsHappening: 'Adding pre-join indexes (3/11)',
            stageNumber: 3
        },
        staged_build_s4_update_posts: {
            queryLabel: 'UPDATE wp_abj404_view_build LEFT JOIN wp_posts',
            whatsHappening: 'Filling published-status from wp_posts (4/11)',
            stageNumber: 4
        },
        staged_build_s5_update_terms: {
            queryLabel: 'UPDATE wp_abj404_view_build LEFT JOIN wp_terms',
            whatsHappening: 'Filling published-status from wp_terms (5/11)',
            stageNumber: 5
        },
        staged_build_s6_update_home: {
            queryLabel: 'UPDATE wp_abj404_view_build (HOME)',
            whatsHappening: 'Filling HOME-typed redirects (6/11)',
            stageNumber: 6
        },
        staged_build_s7_update_external: {
            queryLabel: 'UPDATE wp_abj404_view_build (EXTERNAL)',
            whatsHappening: 'Filling EXTERNAL-typed redirects (7/11)',
            stageNumber: 7
        },
        staged_build_s8_update_special: {
            queryLabel: 'UPDATE wp_abj404_view_build (404-displayed)',
            whatsHappening: 'Filling 404-displayed redirects (8/11)',
            stageNumber: 8
        },
        staged_build_s9_update_hits: {
            queryLabel: 'UPDATE wp_abj404_view_build LEFT JOIN wp_abj404_logs_hits',
            whatsHappening: 'Filling hit counts (9/11)',
            stageNumber: 9
        },
        staged_build_s10_index_sort: {
            queryLabel: 'ALTER TABLE wp_abj404_view_build ADD INDEX (sort indexes)',
            whatsHappening: 'Adding read-side sort indexes (10/11)',
            stageNumber: 10
        },
        staged_build_s11_swap: {
            queryLabel: 'RENAME TABLE wp_abj404_view_build TO wp_abj404_view_done',
            whatsHappening: 'Atomic table swap (11/11)',
            stageNumber: 11
        }
    };
    if (stage && map[stage]) {
        return map[stage];
    }
    // Server may emit `<base>:<detail>` for mid-stage progress
    // (e.g. 'staged_build_s2_insert:batch 4/12').  Resolve to the base entry
    // and append the detail to whatsHappening so the polled status line shows
    // batch progress.
    if (typeof stage === 'string') {
        var colonPos = stage.indexOf(':');
        if (colonPos > 0) {
            var base = stage.substring(0, colonPos);
            var detail = stage.substring(colonPos + 1);
            if (map[base]) {
                return {
                    queryLabel: map[base].queryLabel,
                    // allow-em-dash: visible UI separator preserved verbatim from original mid-stage progress label
                    whatsHappening: detail ? map[base].whatsHappening + ' — ' + detail : map[base].whatsHappening,
                    stageNumber: map[base].stageNumber
                };
            }
        }
    }
    if (subpage === 'abj404_captured') {
        return {
            queryLabel: 'getCapturedURLSPageTable() -> read captured rows from staged view snapshot',
            whatsHappening: 'Loading Captured 404 URLs table rows',
            stageNumber: 1
        };
    }
    if (subpage === 'abj404_logs') {
        return {
            queryLabel: 'getAdminLogsPageTable() -> getLogRecords()',
            whatsHappening: 'Loading Logs table rows',
            stageNumber: 1
        };
    }
    return {
        queryLabel: 'getAdminRedirectsPageTable() -> read redirects rows from staged view snapshot',
        whatsHappening: 'Loading Redirects table rows',
        stageNumber: 1
    };
}

function abj404FormatRefreshingStageMessage(baseMessage, stage, queryLabel, subpage, timingMs, completedStage) {
    var diagnostics = abj404AjaxStageDiagnostics(stage, subpage);
    var stageNumber = diagnostics.stageNumber || '?';
    // Prefer the human-readable whatsHappening text (includes mid-stage detail
    // like "batch 4/12" for the staged build).  Fall back to queryLabel,
    // which is what older callers used, when no diagnostics lookup matches
    // (e.g. stage names emitted by other code paths).
    var label = diagnostics.whatsHappening
        || queryLabel
        || diagnostics.queryLabel
        || stage
        || 'unknown';
    var completedText = '';
    if (completedStage && timingMs > 0) {
        var completedDiag = abj404AjaxStageDiagnostics(completedStage === 'rows' ? 'table_cache_rows' : 'table_cache_count', subpage);
        completedText = 'Stage ' + (completedDiag.stageNumber || '?') + ' complete in ' + timingMs + ' ms. ';
    }
    return completedText + (baseMessage || 'Currently refreshing data') + ' (stage ' + stageNumber + ', ' + label + ')';
}
