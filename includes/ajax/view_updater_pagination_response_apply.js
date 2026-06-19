/**
 * Pagination success-response DOM apply.
 *
 * Mutates the live page DOM with new pagination strips and table HTML
 * returned by ajaxUpdatePaginationLinks. Called by view_updater_pagination.js
 * exactly once per successful foreground or hydrate response, after the
 * orchestrator confirms the visible table is allowed to be replaced.
 *
 * Pure DOM mutation: no AJAX, no telemetry, no orchestration. Reads
 * `result.paginationLinksTop`, `result.paginationLinksBottom`,
 * `result.table`, and `result.tabCounts` from the server response, swaps
 * them into the page, and rebinds the table-level event listeners
 * (checkbox interactions, search filter, trash link, time-ago labels).
 *
 * Globals defined: abj404ApplyPaginationSuccessResponse.
 *
 * Depends on view_updater.js (bindSearchFieldListeners),
 * view_updater_table_init.js (refreshHealthBarIfNeeded), and
 * trash_link_ajax.js (bindTrashLinkListeners).
 */
function abj404ApplyPaginationSuccessResponse(result) {
    // Preserve any user-typed text in the search box across the table swap
    // so a mid-typed filter is not lost when the AJAX response arrives.
    var currentFieldValue = jQuery('input[name=searchFilter]').val();

    // Replace the pagination strips - support both old (.wp-list-table) and new (.abj404-table) table classes
    var pageLinks = jQuery('.abj404-pagination-right');
    if (pageLinks.length > 1) {
        // Two pagination bars: top gets search filter, bottom doesn't.
        var $topPagination = jQuery(result.paginationLinksTop);
        $topPagination.addClass('abj404-pagination-top');
        jQuery(pageLinks[0]).replaceWith($topPagination);
        var $bottomPagination = jQuery(result.paginationLinksBottom);
        $bottomPagination.addClass('abj404-pagination-bottom');
        jQuery(pageLinks[1]).replaceWith($bottomPagination);
    } else if (pageLinks.length === 1) {
        // Single pagination bar: use bottom variant (no search filter).
        jQuery(pageLinks[0]).replaceWith(result.paginationLinksBottom);
    }

    // Replace the table - try both class names
    if (jQuery('.wp-list-table').length > 0) {
        jQuery('.wp-list-table').replaceWith(result.table);
    } else if (jQuery('.abj404-table').length > 0) {
        jQuery('.abj404-table').replaceWith(result.table);
    }

    // Update filter-row counts from AJAX response.
    if (result.tabCounts) {
        jQuery('.subsubsub a[data-tab-filter]').each(function() {
            var filterVal = jQuery(this).attr('data-tab-filter');
            if (filterVal in result.tabCounts) {
                jQuery(this).find('.count').text('(' + result.tabCounts[filterVal] + ')');
            }
        });
        jQuery('.subsubsub').removeAttr('data-tab-counts-placeholder');
    }

    // Health bar is hydrated by a separate AJAX call (refreshHealthBarIfNeeded)
    // so the slow getHighImpactCapturedCount() query never blocks first paint
    // of the redirects table.
    refreshHealthBarIfNeeded();

    // Reinitialize table interactions (checkboxes, bulk actions) after AJAX refresh
    if (typeof window.abj404InitTableInteractions === 'function') {
        window.abj404InitTableInteractions();
    }
    jQuery('.abj404-filter-bar').attr('data-pagination-initial-load', '0');
    bindSearchFieldListeners();
    jQuery('input[name=searchFilter]').val(currentFieldValue);
    jQuery('input[name=searchFilter]').attr("data-previous-value", currentFieldValue);

    bindTrashLinkListeners();
    if (typeof window.abj404RunLazyBackfillPoll === 'function') {
        window.abj404RunLazyBackfillPoll(1);
    }
}
