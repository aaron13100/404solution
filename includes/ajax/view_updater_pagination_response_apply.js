/**
 * Progressive pagination-response DOM application.
 *
 * Each ajaxUpdatePaginationLinks response updates only its own section:
 * table HTML, filter counts, or pagination controls. The legacy all-in-one
 * entry point remains available for older callers.
 *
 * Globals defined: abj404ApplyPaginationSuccessResponse,
 * abj404ApplyPaginationPartResponse.
 */

/** @param {object} result @returns {void} */
function abj404ApplyPaginationSuccessResponse(result) {
    abj404ApplyPaginationPartResponse('pagination', result);
    abj404ApplyPaginationPartResponse('table', result);
    abj404ApplyPaginationPartResponse('counts', result);
}

/**
 * Apply one response part through a fixed strategy registry.
 *
 * @param {string} part
 * @param {object} result
 * @returns {void}
 */
function abj404ApplyPaginationPartResponse(part, result) {
    var appliers = {
        table: abj404ApplyPaginationTablePart,
        counts: abj404ApplyPaginationCountsPart,
        pagination: abj404ApplyPaginationLinksPart
    };
    if (Object.prototype.hasOwnProperty.call(appliers, part)) {
        appliers[part](result || {});
    }
}

/** @param {object} result @returns {void} */
function abj404ApplyPaginationLinksPart(result) {
    if (typeof result.paginationLinksBottom !== 'string') {
        return;
    }
    var pageLinks = jQuery('.abj404-pagination-right');
    if (pageLinks.length > 1) {
        var $topPagination = jQuery(result.paginationLinksTop || '');
        $topPagination.addClass('abj404-pagination-top');
        jQuery(pageLinks[0]).replaceWith($topPagination);
        var $bottomPagination = jQuery(result.paginationLinksBottom);
        $bottomPagination.addClass('abj404-pagination-bottom');
        jQuery(pageLinks[1]).replaceWith($bottomPagination);
    } else if (pageLinks.length === 1) {
        jQuery(pageLinks[0]).replaceWith(result.paginationLinksBottom);
    }
}

/** @param {object} result @returns {void} */
function abj404ApplyPaginationTablePart(result) {
    if (typeof result.table !== 'string' || result.table.trim() === '') {
        throw new TypeError('Pagination table response did not include non-empty table HTML.');
    }
    var currentFieldValue = jQuery('input[name=searchFilter]').val();

    if (jQuery('.wp-list-table').length > 0) {
        jQuery('.wp-list-table').replaceWith(result.table);
    } else if (jQuery('.abj404-table').length > 0) {
        jQuery('.abj404-table').replaceWith(result.table);
    }

    // The health bar is already a separate request, so table first paint does
    // not inherit its aggregate query latency.
    refreshHealthBarIfNeeded();
    if (typeof window.abj404InitTableInteractions === 'function') {
        window.abj404InitTableInteractions();
    }
    jQuery('.abj404-filter-bar').attr('data-pagination-initial-load', '0');
    bindSearchFieldListeners();
    jQuery('input[name=searchFilter]').val(currentFieldValue);
    jQuery('input[name=searchFilter]').attr('data-previous-value', currentFieldValue);
    bindTrashLinkListeners();
    if (typeof window.abj404RunLazyBackfillPoll === 'function') {
        window.abj404RunLazyBackfillPoll(1);
    }
}

/** @param {object} result @returns {void} */
function abj404ApplyPaginationCountsPart(result) {
    if (!result.tabCounts) {
        if (result.countsIncomplete) {
            jQuery('.subsubsub').removeAttr('data-tab-counts-placeholder');
        }
        return;
    }
    jQuery('.subsubsub a[data-tab-filter]').each(function() {
        var filterVal = jQuery(this).attr('data-tab-filter');
        if (filterVal in result.tabCounts) {
            jQuery(this).find('.count').text('(' + result.tabCounts[filterVal] + ')');
        }
    });
    jQuery('.subsubsub').removeAttr('data-tab-counts-placeholder');
}
