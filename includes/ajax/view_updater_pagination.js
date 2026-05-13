/**
 * Core paginationLinksChange fetch + success/error handling.
 *
 * One AJAX call per user-driven table action (search, sort, perpage,
 * pagination link, force-rebuild follow-up, background detect-only
 * refresh). The action is named ajaxUpdatePaginationLinks server-side.
 *
 * Three orthogonal modes share this code path:
 *
 *   - Foreground (default): shows a loading overlay, replaces the table
 *     and pagination markup on success, surfaces an admin notice on
 *     error. Triggers a follow-up detect-only background refresh so the
 *     "Refresh available" pill stays accurate.
 *   - Background detect-only (`backgroundRefresh:true, detectOnly:true`):
 *     never overwrites the visible table; only sets onComplete({hasUpdate})
 *     so the toast/pill code can react.
 *   - Background hydrate (`backgroundRefresh:true, autoHydratePlaceholder:true`):
 *     the placeholder hydration loop calls into this with permission to
 *     replace the table only if `data-table-awaiting-load="1"` is still set.
 *
 * On error, emits a non-blocking `<div class="notice notice-error">` with
 * a redacted last-query line and (when the failure was a pure client-side
 * timeout) follows up with `ajaxFetchInflightStage` so the inflight stage
 * label arrives a moment later.
 *
 * Globals defined: paginationLinksChange.
 *
 * Depends on view_updater.js (abj404UpdateAjaxDebugLog, abj404GenerateRequestId,
 * getURLParameter, extractPagedFromTrigger, bindSearchFieldListeners),
 * view_updater_compare.js (buildComparableTableSignature,
 * hasBackgroundRefreshUpdateWithBaseline), view_updater_stage_diagnostics.js
 * (abj404AjaxStageDiagnostics), view_updater_build_advance.js
 * (abj404StartStageProgressPolling), view_updater_table_init.js
 * (abj404FormatAjaxFailureDetails, isDetectOnlyRefreshInFlight,
 * setDetectOnlyRefreshInFlight, refreshHealthBarIfNeeded,
 * triggerBackgroundTableRefreshIfEnabled), view_updater_table_warmup.js
 * (tablePlaceholderStillAwaitingLoad), view_updater_toast.js
 * (hideRefreshAvailablePill) and trash_link_ajax.js (bindTrashLinkListeners).
 */

function paginationLinksChange(triggerItem, options) {
    options = options || {};
    var isBackgroundRefresh = options.backgroundRefresh === true;
    var detectOnly = options.detectOnly === true;
    var cacheMode = options.cacheMode || 'normal';
    var rowThatChanged = jQuery(triggerItem).parentsUntil('.tablenav').parent();
    var rowsPerPage = jQuery(rowThatChanged).find('select[name=perpage]').val();
    var filterText = jQuery(rowThatChanged).find('input[name=searchFilter]').val();

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
        return;
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

    // Prefer nonce from attribute; fall back to legacy parsing from URL.
    var nonce = $ajaxConfigEl.attr("data-pagination-ajax-nonce") || '';
    if (!nonce) {
        var nonceMatch = url.match(/[?&]nonce=([^&]+)/);
        nonce = nonceMatch ? nonceMatch[1] : '';
    }
    // Inflight-stage nonce is optional: older page renders won't have it,
    // and the timeout follow-up call simply skips when missing.
    var inflightNonce = $ajaxConfigEl.attr('data-pagination-inflight-nonce') || '';

    // Use a clean admin-ajax base URL; always send 'action' in the payload for compatibility with security plugins.
    var baseUrl = url.split('?')[0];
    var requestStartedAt = Date.now();
    var requestId = abj404GenerateRequestId();
    var baselineComparison = null;
    var isDetectOnlyBackground = (isBackgroundRefresh && detectOnly);
    if (isBackgroundRefresh && detectOnly) {
        var tableAtRequestStart = jQuery('.abj404-table, .wp-list-table').first();
        baselineComparison = {
            table: buildComparableTableSignature(
                tableAtRequestStart.length > 0 ? (tableAtRequestStart.prop('outerHTML') || '') : ''
            ),
            serverSignature: ($ajaxConfigEl.attr('data-pagination-current-signature') || '')
        };
    }
    if (isDetectOnlyBackground && isDetectOnlyRefreshInFlight()) {
        if (typeof options.onComplete === 'function') {
            options.onComplete({hasUpdate: false, skipped: true});
        }
        return;
    }
    if (isDetectOnlyBackground) {
        setDetectOnlyRefreshInFlight(true);
    }
    if (window.abj404BackgroundRefreshState && isBackgroundRefresh) {
        window.abj404BackgroundRefreshState.requestCount = (window.abj404BackgroundRefreshState.requestCount || 0) + 1;
        window.abj404BackgroundRefreshState.lastSubpage = subpage;
        window.abj404BackgroundRefreshState.lastAction = action;
        window.abj404BackgroundRefreshState.lastRowsPerPage = parseInt(rowsPerPage, 10) || 0;
        window.abj404BackgroundRefreshState.lastFilterTextLength = (filterText || '').length;
        window.abj404BackgroundRefreshState.lastError = null;
        window.abj404BackgroundRefreshState.lastStatusCode = null;
        window.abj404BackgroundRefreshState.lastResponseBytes = null;
        window.abj404BackgroundRefreshState.hasUpdateAvailable = false;
    }

    if (!isBackgroundRefresh) {
        hideRefreshAvailablePill();
        // Show loading overlay on the table for explicit user actions only.
        var $table = jQuery(tableSelector);
        if (!$table.parent().hasClass('abj404-table-wrapper')) {
            $table.wrap('<div class="abj404-table-wrapper"></div>');
        }
        var $wrapper = $table.parent();
        $wrapper.find('.abj404-loading-overlay').remove();
        $wrapper.append('<div class="abj404-loading-overlay"><div class="abj404-spinner-container"><div class="abj404-spinner"></div></div></div>');
    }

    // do an ajax call to update the data
    // Background detect-only refreshes use a tight 15s budget so a stalled
    // refresh never lingers in the background; explicit user actions use 45s
    // so a cold-cache table query (large redirects/logs tables) has time to
    // complete before the placeholder turns into an error notice.
    var ajaxTimeoutMs = (isBackgroundRefresh && detectOnly) ? 15000 : 45000;
    var stopStageProgressPolling = function() {};
    if (options.showStageProgress === true) {
        stopStageProgressPolling = abj404StartStageProgressPolling({
            baseUrl: baseUrl,
            nonce: inflightNonce,
            requestId: requestId,
            subpage: subpage,
            message: options.stageProgressMessage || 'Currently refreshing data'
        });
    }

    abj404UpdateAjaxDebugLog('Starting AJAX: ' + action + ' for subpage ' + subpage, {
        paged: paged,
        filter: trashFilter,
        filterText: filterText,
        rowsPerPage: rowsPerPage,
        detectOnly: detectOnly,
        cacheMode: cacheMode
    });

    var ajaxRunner = (typeof abj404AjaxWithNonceRetry === 'function')
        ? abj404AjaxWithNonceRetry : jQuery.ajax;
    ajaxRunner({
        url: baseUrl,
        type: 'POST',
        dataType: "json",
        // Without a client-side timeout, a slow server (e.g. while
        // attemptMissingTableRepairAndRetry runs createDatabaseTables) can leave
        // the table stuck on its loading placeholder forever. onError never
        // fires and the retry/fallback path never engages.
        timeout: ajaxTimeoutMs,
        data: {
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
            detectOnly: detectOnly ? '1' : '0',
            cacheMode: cacheMode,
            currentSignature: (detectOnly && baselineComparison && baselineComparison.serverSignature)
                ? baselineComparison.serverSignature : '',
            requestId: requestId
        },
        success: function (result) {
            stopStageProgressPolling(true);
            jQuery('.abj404-refresh-status').text('');

            if (result && result.viewBuildPending) {
                jQuery('.abj404-loading-overlay').remove();
                var pendingMsg = result.message || 'Preparing the redirects view table. Please wait.';
                jQuery('.abj404-refresh-status').text(pendingMsg);
                abj404UpdateAjaxDebugLog('AJAX Success (View Build Pending): ' + pendingMsg, {
                    progress: result.progress
                });
                if (typeof options.onComplete === 'function') {
                    options.onComplete({
                        viewBuildPending: true,
                        progress: result.progress || null
                    });
                }
                return;
            }

            if (result && result.cachePending) {
                jQuery('.abj404-loading-overlay').remove();
                var cachePendingMsg = result.message || 'Preparing table data in the background.';
                jQuery('.abj404-refresh-status').text(cachePendingMsg);
                abj404UpdateAjaxDebugLog('AJAX Success (Cache Pending): ' + cachePendingMsg);
                if (typeof options.onComplete === 'function') {
                    options.onComplete({cachePending: true});
                }
                return;
            }

            abj404UpdateAjaxDebugLog('AJAX Success: ' + action, {
                durationMs: Date.now() - requestStartedAt,
                tableLength: (result && result.table) ? result.table.length : 0,
                hasUpdate: result && result.hasUpdate
            });

            if (isBackgroundRefresh && detectOnly) {
                setDetectOnlyRefreshInFlight(false);
                var hasUpdate;
                if (result && typeof result.hasUpdate === 'boolean') {
                    hasUpdate = !!result.hasUpdate;
                } else {
                    // Backward-compatible fallback for older server responses.
                    hasUpdate = hasBackgroundRefreshUpdateWithBaseline(result, baselineComparison);
                }
                if (typeof options.onComplete === 'function') {
                    options.onComplete({hasUpdate: hasUpdate});
                }
                if (window.abj404BackgroundRefreshState) {
                    var bgDurationMs = Date.now() - requestStartedAt;
                    var bgResultSize = 0;
                    if (result) {
                        try {
                            bgResultSize = JSON.stringify(result).length;
                        } catch (e) {
                            bgResultSize = 0;
                        }
                    }
                    window.abj404BackgroundRefreshState.finishedAt = Date.now();
                    window.abj404BackgroundRefreshState.durationMs = bgDurationMs;
                    window.abj404BackgroundRefreshState.difference = bgDurationMs;
                    window.abj404BackgroundRefreshState.lastStatusCode = 200;
                    window.abj404BackgroundRefreshState.lastResponseBytes = bgResultSize;
                    window.abj404BackgroundRefreshState.hasUpdateAvailable = hasUpdate;
                }
                return;
            }

            // get the current text value
            var currentFieldValue = jQuery('input[name=searchFilter]').val();
            var mayReplaceVisibleTable = !isBackgroundRefresh ||
                (options.autoHydratePlaceholder === true && tablePlaceholderStillAwaitingLoad());
            if (!mayReplaceVisibleTable) {
                if (typeof options.onComplete === 'function') {
                    options.onComplete({skippedReplace: true});
                }
                return;
            }

            // replace the tables - support both old (.wp-list-table) and new (.abj404-table) table classes
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
            // Update tab counts from AJAX response
            if (result.tabCounts) {
                jQuery('.abj404-content-tab[data-tab-filter]').each(function() {
                    var filterVal = jQuery(this).attr('data-tab-filter');
                    if (filterVal in result.tabCounts) {
                        jQuery(this).find('.abj404-tab-count').text(result.tabCounts[filterVal]);
                    }
                });
                jQuery('.abj404-content-tabs').removeAttr('data-tab-counts-placeholder');
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
            if (typeof window.abj404InitTimeAgo === 'function') {
                window.abj404InitTimeAgo();
            }
            jQuery('input[name=searchFilter]').val(currentFieldValue);
            jQuery('input[name=searchFilter]').attr("data-previous-value", currentFieldValue);

            // Remove the loading overlay
            jQuery('.abj404-loading-overlay').fadeOut(200, function() {
                jQuery(this).remove();
            });

            bindTrashLinkListeners();
            if (typeof options.onComplete === 'function') {
                options.onComplete();
            }
            if (!isBackgroundRefresh && typeof triggerBackgroundTableRefreshIfEnabled === 'function') {
                // Re-arm one detect-only refresh for the newly loaded table state.
                // Without this, manual AJAX navigation can suppress update detection
                // for the rest of the current page session.
                window.abj404InitialTableRefreshTriggered = false;
                window.setTimeout(function() {
                    triggerBackgroundTableRefreshIfEnabled();
                }, 0);
            }
            if (window.abj404BackgroundRefreshState && isBackgroundRefresh) {
                var durationMs = Date.now() - requestStartedAt;
                var resultSize = 0;
                if (result) {
                    try {
                        resultSize = JSON.stringify(result).length;
                    } catch (e) {
                        resultSize = 0;
                    }
                }
                window.abj404BackgroundRefreshState.finishedAt = Date.now();
                window.abj404BackgroundRefreshState.durationMs = durationMs;
                window.abj404BackgroundRefreshState.difference = durationMs;
                window.abj404BackgroundRefreshState.lastStatusCode = 200;
                window.abj404BackgroundRefreshState.lastResponseBytes = resultSize;
            }
        },
        error: function (jqXHR, textStatus, errorThrown) {
            stopStageProgressPolling(true);
            jQuery('.abj404-refresh-status').text('');

            if (isBackgroundRefresh && detectOnly) {
                setDetectOnlyRefreshInFlight(false);
            }
            // Remove the loading overlay on error
            jQuery('.abj404-loading-overlay').remove();
            var status = jqXHR && jqXHR.status ? jqXHR.status : '';
            var responseText = jqXHR && jqXHR.responseText ? String(jqXHR.responseText) : '';
            var responseJson = jqXHR && jqXHR.responseJSON ? jqXHR.responseJSON : null;
            var responsePreview = responseText;
            if (responsePreview.length > 2000) {
                // allow-em-dash: visible truncation marker preserved verbatim from the original debug-log preview
                responsePreview = responsePreview.slice(0, 2000) + "\n…(truncated)…";
            }

            abj404UpdateAjaxDebugLog('AJAX Error: ' + action, {
                status: status,
                textStatus: textStatus,
                errorThrown: errorThrown,
                durationMs: Date.now() - requestStartedAt
            });

            // Always log full details to the console for easier debugging.
            if (window && window.console && window.console.error) {
                window.console.error('404 Solution AJAX error', {
                    context: 'Updating table',
                    status: status,
                    textStatus: textStatus,
                    errorThrown: errorThrown,
                    url: baseUrl,
                    action: action,
                    subpage: subpage,
                    responseJson: responseJson,
                    responseText: responseText
                });
            }

            var messageFromServer = '';
            var stageFromServer = '';
            var queryLabelFromServer = '';
            var whatsHappeningFromServer = '';
            var lastQueryRedacted = '';
            if (responseJson && responseJson.data) {
                if (responseJson.data.message) {
                    messageFromServer = String(responseJson.data.message);
                }
                // ViewUpdater::ajaxUpdatePaginationLinks attaches a debug payload under
                // data.details when the caller is a plugin admin. context.stage names the
                // phase that was running (e.g. 'table_captured', 'captured_status_counts');
                // wpdb.last_query_redacted is the most recent SQL with literal values masked.
                // Surfacing both makes admin-side timeout/500 reports actionable without a
                // server-side debug log dump.
                if (responseJson.data.details && typeof responseJson.data.details === 'object') {
                    var details = responseJson.data.details;
                    if (details.context && details.context.stage) {
                        stageFromServer = String(details.context.stage);
                    }
                    if (details.context && details.context.query_label) {
                        queryLabelFromServer = String(details.context.query_label);
                    }
                    if (details.context && details.context.what_happening) {
                        whatsHappeningFromServer = String(details.context.what_happening);
                    }
                    if (details.wpdb && details.wpdb.last_query_redacted) {
                        lastQueryRedacted = String(details.wpdb.last_query_redacted);
                    }
                }
            }

            if (!isBackgroundRefresh) {
                // Render a non-blocking admin notice instead of a native alert().
                // Native alert() blocks the page, breaks browser automation tests,
                // and forces the admin to dismiss before they can refresh.
                var noticeTitle = '404 Solution: AJAX error while updating the table.';
                var $notice = jQuery('<div class="notice notice-error abj404-ajax-error-notice is-dismissible"></div>');
                var $titleEl = jQuery('<p></p>').css('font-weight', 'bold').text(noticeTitle);
                // Wall-clock elapsed since AJAX dispatch.  Distinguishes
                // "instant network drop" (small elapsed) from "real slow query"
                // (close to the timeout budget) on pure client-timeout errors
                // where no responseJson is available.
                var elapsedMs = Date.now() - requestStartedAt;
                var inferredDiagnostics = abj404AjaxStageDiagnostics(stageFromServer, subpage);
                var detailMeta = {
                    whatsHappening: whatsHappeningFromServer || inferredDiagnostics.whatsHappening,
                    queryLabel: queryLabelFromServer || inferredDiagnostics.queryLabel,
                    status: status,
                    textStatus: textStatus,
                    errorThrown: errorThrown,
                    action: action,
                    subpage: subpage,
                    elapsedMs: elapsedMs,
                    timeoutMs: ajaxTimeoutMs,
                    stage: stageFromServer,
                    message: messageFromServer,
                    lastQueryRedacted: lastQueryRedacted
                };
                var detailLines = abj404FormatAjaxFailureDetails(detailMeta);
                // On a pure client timeout the response never arrived, so
                // stageFromServer/messageFromServer/lastQueryRedacted are all
                // empty.  Fire one small follow-up call to the inflight-stage
                // endpoint to read the transient the server stamped before
                // the client gave up.  Adds a "Inflight stage:" line to the
                // notice as soon as the lookup returns.
                var shouldFetchInflightStage = (
                    textStatus === 'timeout' && !stageFromServer && !!inflightNonce
                );
                if (shouldFetchInflightStage) {
                    // allow-em-dash: visible ellipsis preserved verbatim from original placeholder line
                    detailLines.push('Inflight stage: (looking up…)');
                }
                var $detailsEl = jQuery('<pre></pre>')
                    .css({whiteSpace: 'pre-wrap', margin: '0 0 8px 0'})
                    .text(detailLines.join('\n'));
                $notice.append($titleEl).append($detailsEl);
                if (shouldFetchInflightStage) {
                    var inflightAjaxRunner = (typeof abj404AjaxWithNonceRetry === 'function')
                        ? abj404AjaxWithNonceRetry : jQuery.ajax;
                    inflightAjaxRunner({
                        url: baseUrl,
                        type: 'POST',
                        dataType: 'json',
                        timeout: 5000,
                        data: {
                            action: 'ajaxFetchInflightStage',
                            nonce: inflightNonce,
                            requestId: requestId
                        }
                    }).done(function(stageResult) {
                        var inflightStage = '';
                        var inflightQueryLabel = '';
                        var inflightwhatsHappening = '';
                        if (stageResult && typeof stageResult.stage === 'string' && stageResult.stage !== '') {
                            inflightStage = stageResult.stage;
                        }
                        if (stageResult && typeof stageResult.queryLabel === 'string' && stageResult.queryLabel !== '') {
                            inflightQueryLabel = stageResult.queryLabel;
                        }
                        if (stageResult && typeof stageResult.whatsHappening === 'string' && stageResult.whatsHappening !== '') {
                            inflightwhatsHappening = stageResult.whatsHappening;
                        }
                        var lookupLine = inflightStage
                            ? 'Inflight stage: ' + inflightStage
                            : 'Inflight stage: (unknown)';
                        var lookupDiagnostics = abj404AjaxStageDiagnostics(inflightStage, subpage);
                        var updated = detailLines.slice();
                        for (var i = 0; i < updated.length; i++) {
                            if (updated[i].indexOf('What was happening:') === 0) {
                                updated[i] = 'What was happening: ' + (inflightwhatsHappening || lookupDiagnostics.whatsHappening);
                            }
                            if (updated[i].indexOf('Query:') === 0) {
                                updated[i] = 'Query: ' + (inflightQueryLabel || lookupDiagnostics.queryLabel);
                            }
                            if (updated[i].indexOf('Inflight stage:') === 0) {
                                updated[i] = lookupLine;
                            }
                        }
                        $detailsEl.text(updated.join('\n'));
                    }).fail(function() {
                        var updated = detailLines.slice();
                        for (var i = 0; i < updated.length; i++) {
                            if (updated[i].indexOf('Inflight stage:') === 0) {
                                updated[i] = 'Inflight stage: (lookup failed)';
                                break;
                            }
                        }
                        $detailsEl.text(updated.join('\n'));
                    });
                }
                jQuery('.abj404-ajax-error-notice').remove();
                var $tableContainer = jQuery('.abj404-table-container').first();
                if ($tableContainer.length > 0) {
                    $tableContainer.before($notice);
                } else {
                    jQuery('.wrap').first().prepend($notice);
                }

                // Mount the reusable "Send debug log to developer" button
                // inside the error notice. The trigger source is the
                // current admin tab (redirects_page or captured_404s_page)
                // so the server log can attribute the click. The context
                // summary is the AJAX error one-liner so the modal shows
                // the admin which failure they are reporting. Both slugs
                // are in Ajax_SupportRequest::ALLOWED_TRIGGER_SOURCES so
                // the AJAX handler will accept them.
                var triggeredFromSlug = (subpage === 'abj404_captured')
                    ? 'captured_404s_page' : 'redirects_page';
                var contextSummary = noticeTitle;
                if (messageFromServer) {
                    contextSummary += ' ' + String(messageFromServer).slice(0, 200);
                } else if (textStatus) {
                    contextSummary += ' (' + String(textStatus) + ')';
                }
                var mountDiv = document.createElement('div');
                mountDiv.className = 'abj404-support-request-mount';
                mountDiv.setAttribute('data-triggered-from', triggeredFromSlug);
                mountDiv.setAttribute('data-context-summary', contextSummary);
                $notice.append(mountDiv);
                if (window.ABJ404 && window.ABJ404.SupportRequestButton &&
                    typeof window.ABJ404.SupportRequestButton.mountAll === 'function') {
                    window.ABJ404.SupportRequestButton.mountAll();
                }
            }
            if (typeof options.onError === 'function') {
                options.onError({
                    status: status,
                    textStatus: textStatus,
                    errorThrown: errorThrown,
                    message: messageFromServer,
                    action: action,
                    subpage: subpage,
                    elapsedMs: Date.now() - requestStartedAt,
                    timeoutMs: ajaxTimeoutMs,
                    stage: stageFromServer,
                    queryLabel: queryLabelFromServer || abj404AjaxStageDiagnostics(stageFromServer, subpage).queryLabel,
                    whatsHappening: whatsHappeningFromServer || abj404AjaxStageDiagnostics(stageFromServer, subpage).whatsHappening,
                    lastQueryRedacted: lastQueryRedacted
                });
            }
            if (window.abj404BackgroundRefreshState && isBackgroundRefresh) {
                var durationMs = Date.now() - requestStartedAt;
                window.abj404BackgroundRefreshState.finishedAt = Date.now();
                window.abj404BackgroundRefreshState.durationMs = durationMs;
                window.abj404BackgroundRefreshState.difference = durationMs;
                window.abj404BackgroundRefreshState.lastStatusCode = status || null;
                window.abj404BackgroundRefreshState.lastError = textStatus || errorThrown || 'ajax-error';
                window.abj404BackgroundRefreshState.lastResponseBytes = responseText ? responseText.length : 0;
            }
        }
    });
}
