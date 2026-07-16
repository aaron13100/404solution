/**
 * Stage diagnostics for the admin table reads.
 *
 * Maps server-emitted stage codes (e.g. table_redirects, paginationLinksTop)
 * to UI-friendly diagnostic objects with queryLabel + whatsHappening + stageNumber.
 * The live AJAX failure notice renders text from this lookup, so a missed
 * mapping shows up to the admin as "(stage ?, unknown)".
 *
 * Loaded as a sibling of view_updater.js (see WordPress_Connector::my_wp_enq_scrpt).
 * abj404AjaxStageDiagnostics is consumed by view_updater_pagination.js and
 * view_updater_pagination_error_notice.js for the live table-read error path.
 */

function abj404AjaxStageDiagnostics(stage, subpage) {
    var map = {
        table_redirects: {
            queryLabel: 'getAdminRedirectsPageTable() -> read redirects rows from wp_abj404_redirects (single-table live read)',
            whatsHappening: 'Loading Redirects table rows',
            stageNumber: 1
        },
        redirect_status_counts: {
            queryLabel: 'getRedirectStatusCounts()',
            whatsHappening: 'Counting Redirects status tabs',
            stageNumber: 2
        },
        table_captured: {
            queryLabel: 'getCapturedURLSPageTable() -> read captured rows from wp_abj404_redirects (single-table live read)',
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
            queryLabel: 'getPaginationLinks(top) -> read top pagination count from wp_abj404_redirects (single-table live read)',
            whatsHappening: 'Rendering top pagination links',
            stageNumber: 3
        },
        paginationLinksBottom: {
            queryLabel: 'getPaginationLinks(bottom) -> read bottom pagination count from wp_abj404_redirects (single-table live read)',
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
        }
    };
    if (stage && map[stage]) {
        return map[stage];
    }
    // Server may emit `<base>:<detail>` for mid-stage progress. Resolve to the
    // base entry and append the detail so the polled status line shows it.
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
            queryLabel: 'getCapturedURLSPageTable() -> read captured rows from wp_abj404_redirects (single-table live read)',
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
        queryLabel: 'getAdminRedirectsPageTable() -> read redirects rows from wp_abj404_redirects (single-table live read)',
        whatsHappening: 'Loading Redirects table rows',
        stageNumber: 1
    };
}
