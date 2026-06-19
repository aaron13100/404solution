/**
 * Pagination request builder for paginationLinksChange.
 *
 * Reads the page DOM (the filter-bar data-attributes with the
 * .abj404-pagination-right fallback), the URL query string, and the
 * user-triggered element into a single request descriptor consumed by
 * view_updater_pagination.js. Pure read; no DOM mutation and no AJAX.
 *
 * The descriptor returned contains both the raw AJAX payload sent to
 * ajaxUpdatePaginationLinks and the cross-cutting fields the
 * orchestrator needs for telemetry, the detect-only baseline
 * comparison, and the inflight-stage follow-up (baseUrl, action,
 * subpage, nonces, requestId, requestStartedAt, baselineComparison,
 * ajaxTimeoutMs, mode flags).
 *
 * Returns null when no AJAX URL can be located on the page; the caller
 * treats that as an abort and emits the same console warning the legacy
 * inline code did.
 *
 * Globals defined: abj404BuildPaginationRequest.
 *
 * Depends on view_updater.js (getURLParameter, extractPagedFromTrigger,
 * abj404GenerateRequestId) and view_updater_compare.js
 * (buildComparableTableSignature).
 */
function abj404BuildPaginationRequest(triggerItem, options) {
    options = options || {};
    var isBackgroundRefresh = options.backgroundRefresh === true;
    var detectOnly = options.detectOnly === true;
    var cacheMode = options.cacheMode || 'normal';

    // The rows-per-page select and the search box both live in the list-top
    // row (next to the filter links), outside the .tablenav, so read them
    // globally rather than within the triggering row. There is exactly one of
    // each per list page.
    var rowsPerPage = jQuery('select[name=perpage]').first().val();
    var filterText = jQuery('input[name=searchFilter]').first().val();

    // Only show loading on the table itself, not the filter bar or pagination
    var tableSelector = jQuery('.abj404-table').length > 0 ? '.abj404-table' : '.wp-list-table';

    // Get AJAX config from the page (supports both new data-attrs and legacy URL-with-query).
    var $ajaxConfigEl = jQuery("[data-pagination-ajax-url]").first();
    if ($ajaxConfigEl.length === 0) {
        $ajaxConfigEl = jQuery(".abj404-filter-bar").first();
    }
    if ($ajaxConfigEl.length === 0) {
        $ajaxConfigEl = jQuery(".abj404-pagination-right").first();
    }
    var url = $ajaxConfigEl.attr("data-pagination-ajax-url") || window.ajaxurl;
    if (!url) {
        console.warn('404 Solution: data-pagination-ajax-url attribute not found');
        return null;
    }
    var action = $ajaxConfigEl.attr("data-pagination-ajax-action") || 'ajaxUpdatePaginationLinks';
    var subpage = $ajaxConfigEl.attr("data-pagination-ajax-subpage") || getURLParameter('subpage');
    var page = getURLParameter('page');
    var trashFilter = $ajaxConfigEl.attr('data-pagination-current-filter');
    if (typeof trashFilter === 'undefined' || trashFilter === null || trashFilter === '') {
        trashFilter = getURLParameter('filter');
    }
    var orderby = $ajaxConfigEl.attr('data-pagination-current-orderby');
    if (!orderby) {
        orderby = getURLParameter('orderby');
    }
    var order = $ajaxConfigEl.attr('data-pagination-current-order');
    if (!order) {
        order = getURLParameter('order');
    }
    var paged = $ajaxConfigEl.attr('data-pagination-current-paged');
    if (!paged) {
        paged = getURLParameter('paged');
    }
    var clickedPaged = extractPagedFromTrigger(triggerItem);
    if (clickedPaged !== '') {
        paged = clickedPaged;
    }
    var id = $ajaxConfigEl.attr('data-pagination-current-logsid');
    if (!id) {
        id = getURLParameter('id');
    }
    var scoreRange = $ajaxConfigEl.attr('data-pagination-current-score-range');
    if (typeof scoreRange === 'undefined' || scoreRange === null || scoreRange === '') {
        scoreRange = getURLParameter('score_range');
    }
    if (!scoreRange) {
        scoreRange = 'all';
    }

    // Prefer nonce from attribute; fall back to legacy parsing from URL.
    var nonce = $ajaxConfigEl.attr("data-pagination-ajax-nonce") || '';
    if (!nonce) {
        var nonceMatch = url.match(/[?&]nonce=([^&]+)/);
        nonce = nonceMatch ? nonceMatch[1] : '';
    }
    // Inflight-stage nonce is optional: older page renders won't have it,
    // and the timeout follow-up call simply skips when missing.
    var inflightNonce = $ajaxConfigEl.attr('data-pagination-inflight-nonce') || '';

    // Use a clean admin-ajax base URL; always send 'action' in the payload for
    // compatibility with security plugins.
    var baseUrl = url.split('?')[0];
    var requestStartedAt = Date.now(); // allow-direct-time: wall-clock telemetry baseline for elapsed-ms diagnostics, preserved verbatim from view_updater_pagination.js pre-i352 split
    var requestId = abj404GenerateRequestId();

    var baselineComparison = null;
    var isDetectOnlyBackground = (isBackgroundRefresh && detectOnly);
    if (isDetectOnlyBackground) {
        var tableAtRequestStart = jQuery('.abj404-table, .wp-list-table').first();
        baselineComparison = {
            table: buildComparableTableSignature(
                tableAtRequestStart.length > 0 ? (tableAtRequestStart.prop('outerHTML') || '') : ''
            ),
            serverSignature: ($ajaxConfigEl.attr('data-pagination-current-signature') || '')
        };
    }

    // Background detect-only refreshes use a tight 15s budget so a stalled
    // silent poll never lingers in the background. Explicit user actions use
    // 45s so a cold-cache table query (large redirects/logs tables) has time
    // to complete before the placeholder turns into an error notice. The
    // foreground overlay is pointer-transparent and always removed on AJAX
    // complete, so a longer budget can no longer block clicks regardless of
    // how long the request runs.
    var ajaxTimeoutMs = isDetectOnlyBackground ? 15000 : 45000;

    var payload = {
        action: action,
        page: page,
        rowsPerPage: rowsPerPage,
        filterText: filterText,
        filter: trashFilter,
        subpage: subpage,
        nonce: nonce,
        orderby: orderby,
        order: order,
        paged: paged,
        id: id,
        score_range: scoreRange,
        detectOnly: detectOnly ? '1' : '0',
        cacheMode: cacheMode,
        currentSignature: (detectOnly && baselineComparison && baselineComparison.serverSignature)
            ? baselineComparison.serverSignature : '',
        requestId: requestId
    };

    return {
        baseUrl: baseUrl,
        payload: payload,
        subpage: subpage,
        action: action,
        nonce: nonce,
        inflightNonce: inflightNonce,
        requestStartedAt: requestStartedAt,
        requestId: requestId,
        baselineComparison: baselineComparison,
        ajaxTimeoutMs: ajaxTimeoutMs,
        rowsPerPage: rowsPerPage,
        filterText: filterText,
        tableSelector: tableSelector,
        isBackgroundRefresh: isBackgroundRefresh,
        detectOnly: detectOnly,
        cacheMode: cacheMode,
        isDetectOnlyBackground: isDetectOnlyBackground,
        // Surface the per-request paged / filter values on the descriptor so the
        // orchestrator can read them directly without descending into req.payload
        // (which would otherwise alias into the unrelated payload.* regex scan in
        // ViewUpdaterResponseContractWiringTest::testJsStatsPayloadPropertyReadsMatchPhpResponseKeys).
        paged: paged,
        filter: trashFilter
    };
}
