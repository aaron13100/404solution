/**
 * paginationLinksChange orchestrator.
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
 * On error, the actual notice rendering + inflight-stage follow-up live
 * in view_updater_pagination_error_notice.js. The DOM replacement on a
 * successful response lives in view_updater_pagination_response_apply.js.
 * Request payload assembly lives in view_updater_pagination_request.js.
 * This file owns the AJAX lifecycle and the cross-cutting state machine
 * (loading overlay, stage-progress polling, detect-only baseline guard,
 * background-refresh telemetry, mayReplaceVisibleTable check, success/
 * error dispatch).
 *
 * Globals defined: paginationLinksChange.
 *
 * Depends on view_updater.js (abj404UpdateAjaxDebugLog),
 * view_updater_compare.js (hasBackgroundRefreshUpdateWithBaseline),
 * view_updater_stage_diagnostics.js (abj404AjaxStageDiagnostics),
 * view_updater_stage_progress.js (abj404StartStageProgressPolling),
 * view_updater_table_init.js (isDetectOnlyRefreshInFlight,
 * setDetectOnlyRefreshInFlight, refreshHealthBarIfNeeded,
 * triggerBackgroundTableRefreshIfEnabled,
 * abj404CollapseEmptyPaginationStrips), view_updater_table_warmup.js
 * (tablePlaceholderStillAwaitingLoad), view_updater_toast.js
 * (hideRefreshAvailablePill), view_updater_nonce_refresh.js
 * (abj404AjaxWithNonceRetry), view_updater_pagination_request.js
 * (abj404BuildPaginationRequest), view_updater_pagination_response_apply.js
 * (abj404ApplyPaginationSuccessResponse), and
 * view_updater_pagination_error_notice.js (abj404HandlePaginationAjaxError).
 */

function paginationLinksChange(triggerItem, options) {
    options = options || {};
    var req = abj404BuildPaginationRequest(triggerItem, options);
    if (req === null) {
        return;
    }
    var isBackgroundRefresh = req.isBackgroundRefresh;
    var detectOnly = req.detectOnly;
    var subpage = req.subpage;
    var action = req.action;
    var baseUrl = req.baseUrl;
    var requestStartedAt = req.requestStartedAt;
    var requestId = req.requestId;
    var baselineComparison = req.baselineComparison;
    var inflightNonce = req.inflightNonce;
    var ajaxTimeoutMs = req.ajaxTimeoutMs;

    if (req.isDetectOnlyBackground && isDetectOnlyRefreshInFlight()) {
        if (typeof options.onComplete === 'function') {
            options.onComplete({hasUpdate: false, skipped: true});
        }
        return;
    }
    if (req.isDetectOnlyBackground) {
        setDetectOnlyRefreshInFlight(true);
    }
    if (window.abj404BackgroundRefreshState && isBackgroundRefresh) {
        window.abj404BackgroundRefreshState.requestCount = (window.abj404BackgroundRefreshState.requestCount || 0) + 1;
        window.abj404BackgroundRefreshState.lastSubpage = subpage;
        window.abj404BackgroundRefreshState.lastAction = action;
        window.abj404BackgroundRefreshState.lastRowsPerPage = parseInt(req.rowsPerPage, 10) || 0;
        window.abj404BackgroundRefreshState.lastFilterTextLength = (req.filterText || '').length;
        window.abj404BackgroundRefreshState.lastError = null;
        window.abj404BackgroundRefreshState.lastStatusCode = null;
        window.abj404BackgroundRefreshState.lastResponseBytes = null;
        window.abj404BackgroundRefreshState.hasUpdateAvailable = false;
    }

    if (!isBackgroundRefresh) {
        hideRefreshAvailablePill();
        // Show loading overlay on the table for explicit user actions only.
        var $table = jQuery(req.tableSelector);
        if (!$table.parent().hasClass('abj404-table-wrapper')) {
            $table.wrap('<div class="abj404-table-wrapper"></div>');
        }
        var $wrapper = $table.parent();
        $wrapper.find('.abj404-loading-overlay').remove();
        $wrapper.append('<div class="abj404-loading-overlay"><div class="abj404-spinner-container"><div class="abj404-spinner"></div></div></div>');
    }

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
        paged: req.paged,
        filter: req.filter,
        filterText: req.filterText,
        rowsPerPage: req.rowsPerPage,
        detectOnly: detectOnly,
        cacheMode: req.cacheMode
    });

    var errorCtx = {
        baseUrl: baseUrl,
        action: action,
        subpage: subpage,
        isBackgroundRefresh: isBackgroundRefresh,
        inflightNonce: inflightNonce,
        requestId: requestId,
        requestStartedAt: requestStartedAt,
        ajaxTimeoutMs: ajaxTimeoutMs
    };

    var ajaxRunner = (typeof abj404AjaxWithNonceRetry === 'function')
        ? abj404AjaxWithNonceRetry : jQuery.ajax; // ajax-direct-approved: documented fallback when view_updater_nonce_refresh.js is not yet loaded; canonical pattern in every view_updater_*.js dispatch site, preserved verbatim from view_updater_pagination.js pre-i352 split
    ajaxRunner({
        url: baseUrl,
        type: 'POST',
        dataType: "json",
        // Without a client-side timeout, a slow server (e.g. while
        // attemptMissingTableRepairAndRetry runs createDatabaseTables) can leave
        // the table stuck on its loading placeholder forever. onError never
        // fires and the retry/fallback path never engages.
        timeout: ajaxTimeoutMs,
        data: req.payload,
        success: function (result) {
            // Stop WITHOUT flushing here too. The flushed final stage read
            // re-writes the stage label into .abj404-refresh-status after the
            // clear below; on a foreground load the strip replace wipes it, but
            // a background detect-only refresh returns without replacing the
            // strips, so the re-written "(stage 4)" badge stays stuck.
            stopStageProgressPolling(false);
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
                durationMs: Date.now() - requestStartedAt, // allow-direct-time: AJAX wall-clock duration for the success debug log entry; preserved verbatim from view_updater_pagination.js pre-i352 split
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
                    var bgDurationMs = Date.now() - requestStartedAt; // allow-direct-time: background-refresh duration telemetry; preserved verbatim from view_updater_pagination.js pre-i352 split
                    var bgResultSize = 0;
                    if (result) {
                        try {
                            bgResultSize = JSON.stringify(result).length;
                        } catch (e) {
                            bgResultSize = 0;
                        }
                    }
                    window.abj404BackgroundRefreshState.finishedAt = Date.now(); // allow-direct-time: telemetry finishedAt timestamp; preserved verbatim from view_updater_pagination.js pre-i352 split
                    window.abj404BackgroundRefreshState.durationMs = bgDurationMs;
                    window.abj404BackgroundRefreshState.difference = bgDurationMs;
                    window.abj404BackgroundRefreshState.lastStatusCode = 200;
                    window.abj404BackgroundRefreshState.lastResponseBytes = bgResultSize;
                    window.abj404BackgroundRefreshState.hasUpdateAvailable = hasUpdate;
                }
                return;
            }

            var mayReplaceVisibleTable = !isBackgroundRefresh ||
                (options.autoHydratePlaceholder === true && tablePlaceholderStillAwaitingLoad());
            if (!mayReplaceVisibleTable) {
                if (typeof options.onComplete === 'function') {
                    options.onComplete({skippedReplace: true});
                }
                return;
            }

            abj404ApplyPaginationSuccessResponse(result);

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
                var durationMs = Date.now() - requestStartedAt; // allow-direct-time: hydrate-path duration telemetry; preserved verbatim from view_updater_pagination.js pre-i352 split
                var resultSize = 0;
                if (result) {
                    try {
                        resultSize = JSON.stringify(result).length;
                    } catch (e) {
                        resultSize = 0;
                    }
                }
                window.abj404BackgroundRefreshState.finishedAt = Date.now(); // allow-direct-time: telemetry finishedAt timestamp; preserved verbatim from view_updater_pagination.js pre-i352 split
                window.abj404BackgroundRefreshState.durationMs = durationMs;
                window.abj404BackgroundRefreshState.difference = durationMs;
                window.abj404BackgroundRefreshState.lastStatusCode = 200;
                window.abj404BackgroundRefreshState.lastResponseBytes = resultSize;
            }
        },
        error: function (jqXHR, textStatus, errorThrown) {
            // Stop WITHOUT flushing. flushFinalEvents=true fires one more async
            // ajaxFetchInflightStage read whose success handler re-writes the
            // "stage N" label into .abj404-refresh-status AFTER we clear it just
            // below, which is what left the "Refreshing data... (stage 4)"
            // message spinning forever once the fetch failed.
            stopStageProgressPolling(false);
            jQuery('.abj404-refresh-status').text('');
            if (typeof abj404CollapseEmptyPaginationStrips === 'function') {
                abj404CollapseEmptyPaginationStrips();
            }

            if (isBackgroundRefresh && detectOnly) {
                setDetectOnlyRefreshInFlight(false);
            }
            // Remove the loading overlay on error
            jQuery('.abj404-loading-overlay').remove();

            var parsed = abj404HandlePaginationAjaxError(errorCtx, jqXHR, textStatus, errorThrown);

            if (typeof options.onError === 'function') {
                var inferred = abj404AjaxStageDiagnostics(parsed.stageFromServer, subpage);
                options.onError({
                    status: parsed.status,
                    textStatus: textStatus,
                    errorThrown: errorThrown,
                    message: parsed.messageFromServer,
                    action: action,
                    subpage: subpage,
                    elapsedMs: Date.now() - requestStartedAt, // allow-direct-time: elapsed-ms reported to the onError callback; preserved verbatim from view_updater_pagination.js pre-i352 split
                    timeoutMs: ajaxTimeoutMs,
                    stage: parsed.stageFromServer,
                    queryLabel: parsed.queryLabelFromServer || inferred.queryLabel,
                    whatsHappening: parsed.whatsHappeningFromServer || inferred.whatsHappening,
                    lastQueryRedacted: parsed.lastQueryRedacted
                });
            }
            if (window.abj404BackgroundRefreshState && isBackgroundRefresh) {
                var durationMs = Date.now() - requestStartedAt; // allow-direct-time: failure-path duration telemetry; preserved verbatim from view_updater_pagination.js pre-i352 split
                window.abj404BackgroundRefreshState.finishedAt = Date.now(); // allow-direct-time: telemetry finishedAt timestamp; preserved verbatim from view_updater_pagination.js pre-i352 split
                window.abj404BackgroundRefreshState.durationMs = durationMs;
                window.abj404BackgroundRefreshState.difference = durationMs;
                window.abj404BackgroundRefreshState.lastStatusCode = parsed.status || null;
                window.abj404BackgroundRefreshState.lastError = textStatus || errorThrown || 'ajax-error';
                window.abj404BackgroundRefreshState.lastResponseBytes = parsed.responseText ? parsed.responseText.length : 0;
            }
        }
    });
}
