/**
 * Table-cache warmup, view-build retry, and placeholder hydration.
 *
 * Three orchestrators sit between the initial-load entry point and the
 * paginationLinksChange fetcher:
 *
 *   - warmTableCacheStage: posts ajaxWarmTableCache, which iterates the
 *     row + count snapshot stages. Surfaces viewBuildPending up to the
 *     caller without trying to build inline.
 *   - startViewBuildPollingThenRetry: bridges a viewBuildPending response
 *     to abj404PollViewBuildAdvance and re-issues the fetch on `ready`.
 *     Single-flight via window.abj404ViewBuildAdvanceRunning. Coordinates
 *     across tabs via the shared-build owner helpers.
 *   - startPlaceholderTableHydration: drives the warm/retry loop while
 *     the placeholder rows are still on screen, eventually replacing
 *     them with real data via paginationLinksChange.
 *
 * Plus showTableWarmupFailure: the terminal "could not finish refreshing
 * data" notice that lands in both the status line and the placeholder
 * tbody when every retry has been exhausted.
 *
 * Globals defined: warmTableCacheStage, startViewBuildPollingThenRetry,
 * tablePlaceholderStillAwaitingLoad, startPlaceholderTableHydration,
 * showTableWarmupFailure.
 *
 * Depends on view_updater.js (abj404UpdateAjaxDebugLog, abj404GenerateRequestId,
 * getURLParameter, paginationLinksChange), view_updater_stage_diagnostics.js
 * (abj404FormatRefreshingStageMessage), view_updater_build_advance.js
 * (abj404StartStageProgressPolling, abj404PollViewBuildAdvance,
 * abj404TryClaimSharedBuildOwner, abj404FollowSharedBuildThenRetry,
 * abj404UpdateSharedBuildOwner) and view_updater_table_init.js
 * (getRefreshStatusHost).
 */

function startViewBuildPollingThenRetry(triggerItem, $config, fetchAttemptNumber) {
    if (window.abj404ViewBuildAdvanceRunning === true) {
        return;
    }
    window.abj404ViewBuildAdvanceRunning = true;

    var $ajaxConfigEl = jQuery('[data-pagination-ajax-url]').first();
    if ($ajaxConfigEl.length === 0) {
        $ajaxConfigEl = jQuery('.abj404-filter-bar').first();
    }
    var url = $ajaxConfigEl.attr('data-pagination-ajax-url') || window.ajaxurl;
    var baseUrl = url ? url.split('?')[0] : '';
    var inflightNonce = $ajaxConfigEl.attr('data-pagination-inflight-nonce') || '';
    var subpage = $ajaxConfigEl.attr('data-pagination-ajax-subpage') || getURLParameter('subpage');
    var buildRequestId = abj404GenerateRequestId();
    var sharedOwnerId = abj404GenerateRequestId();

    if (!baseUrl || !inflightNonce) {
        // No advance endpoint config: drop placeholders so the page is usable.
        window.abj404ViewBuildAdvanceRunning = false;
        if ($config && $config.length > 0) {
            $config.attr('data-pagination-initial-load', '0');
        }
        showTableWarmupFailure({lastError: 'View build advance endpoint config missing'});
        return;
    }

    if (!abj404TryClaimSharedBuildOwner(sharedOwnerId)) {
        abj404UpdateAjaxDebugLog('View build advance: following another active tab', {});
        abj404FollowSharedBuildThenRetry(triggerItem, $config);
        return;
    }

    var stopBuildStageProgressPolling = abj404StartStageProgressPolling({
        baseUrl: baseUrl,
        nonce: inflightNonce,
        requestId: buildRequestId,
        subpage: subpage,
        message: 'Preparing redirects view'
    });

    abj404PollViewBuildAdvance({
        baseUrl: baseUrl,
        nonce: inflightNonce,
        requestId: buildRequestId,
        subpage: subpage,
        page: getURLParameter('page') || '',
        intervalMs: 1000,
        lockedIntervalMs: 3500,
        onProgress: function(progress) {
            abj404UpdateSharedBuildOwner(sharedOwnerId, 'running', progress || {});
        },
        onReady: function() {
            stopBuildStageProgressPolling(true);
            window.abj404ViewBuildAdvanceRunning = false;
            abj404UpdateSharedBuildOwner(sharedOwnerId, 'ready', {progress_text: 'ready'});
            jQuery('.abj404-refresh-status').text('');
            // Re-issue the fetch now that view_done is ready.  Use the same
            // cache_or_pending mode so a cold snapshot triggers the existing
            // warm-and-hydrate path (which is now safe because view_done
            // exists, so getRedirectsForView is a fast read).
            paginationLinksChange(triggerItem, {
                backgroundRefresh: false,
                detectOnly: false,
                cacheMode: 'cache_or_pending',
                onComplete: function(meta) {
                    if (meta && meta.cachePending) {
                        startPlaceholderTableHydration(triggerItem);
                        return;
                    }
                    if ($config && $config.length > 0) {
                        $config.attr('data-pagination-initial-load', '0');
                    }
                },
                onError: function(errorMeta) {
                    if ($config && $config.length > 0) {
                        $config.attr('data-pagination-initial-load', '0');
                    }
                    showTableWarmupFailure(errorMeta || {});
                }
            });
        },
        onError: function(errorMeta) {
            stopBuildStageProgressPolling(true);
            window.abj404ViewBuildAdvanceRunning = false;
            abj404UpdateSharedBuildOwner(sharedOwnerId, 'error', {progress_text: (errorMeta && errorMeta.lastError) || 'error'});
            if ($config && $config.length > 0) {
                $config.attr('data-pagination-initial-load', '0');
            }
            showTableWarmupFailure(errorMeta || {});
        }
    });
}

function tablePlaceholderStillAwaitingLoad() {
    return jQuery('.abj404-table[data-table-awaiting-load="1"]').length > 0;
}

function startPlaceholderTableHydration(triggerItem) {
    if (window.abj404PlaceholderHydrationRunning === true) {
        return;
    }
    window.abj404PlaceholderHydrationRunning = true;

    var maxAttempts = 8;
    var runAttempt = function(attemptNumber) {
        if (!tablePlaceholderStillAwaitingLoad()) {
            window.abj404PlaceholderHydrationRunning = false;
            return;
        }
        warmTableCacheStage(triggerItem, {
            stageProgressMessage: 'Currently refreshing data',
            onComplete: function(meta) {
                if (meta && meta.viewBuildPending) {
                    // The snapshot warm cannot run yet because view_done is
                    // missing / invalidated.  Hand off to the bounded build
                    // poller; on ready it re-issues a fetch which falls into
                    // the warm-and-hydrate path naturally.
                    window.abj404PlaceholderHydrationRunning = false;
                    startViewBuildPollingThenRetry(triggerItem, getRefreshStatusHost(), 1);
                    return;
                }
                if (meta && meta.status === 'blocked') {
                    showTableWarmupFailure(meta);
                    window.abj404PlaceholderHydrationRunning = false;
                    getRefreshStatusHost().attr('data-pagination-initial-load', '0');
                    return;
                }
                if (meta && meta.ready) {
                    paginationLinksChange(triggerItem, {
                        backgroundRefresh: true,
                        detectOnly: false,
                        cacheMode: 'normal',
                        autoHydratePlaceholder: true,
                        onComplete: function() {
                            window.abj404PlaceholderHydrationRunning = false;
                            getRefreshStatusHost().attr('data-pagination-initial-load', '0');
                        },
                        onError: function(errorMeta) {
                            showTableWarmupFailure(errorMeta || meta);
                            window.abj404PlaceholderHydrationRunning = false;
                        }
                    });
                    return;
                }
                if (attemptNumber < maxAttempts) {
                    window.setTimeout(function() {
                        runAttempt(attemptNumber + 1);
                    }, meta && meta.locked ? (parseInt(meta.retryAfterMs, 10) || 2500) : 700);
                    return;
                }
                showTableWarmupFailure(meta || {});
                window.abj404PlaceholderHydrationRunning = false;
            },
            onError: function(errorMeta) {
                if (attemptNumber < maxAttempts && tablePlaceholderStillAwaitingLoad()) {
                    window.setTimeout(function() {
                        runAttempt(attemptNumber + 1);
                    }, 1200);
                    return;
                }
                showTableWarmupFailure(errorMeta || {});
                window.abj404PlaceholderHydrationRunning = false;
            }
        });
    };

    window.setTimeout(function() {
        runAttempt(1);
    }, 250);
}

function showTableWarmupFailure(meta) {
    meta = meta || {};
    var stage = meta.stage || 'rows';
    var stageNumber = meta.stageNumber || (stage === 'count' ? 2 : 1);
    var queryLabel = meta.queryLabel || (stage === 'count' ? 'getRedirectsForViewCount' : 'getRedirectsForView');
    var message = 'Could not finish refreshing data (stage ' + stageNumber + ', ' + queryLabel + ')';
    if (meta.lastError) {
        message += '. ' + meta.lastError;
    }
    jQuery('.abj404-refresh-status').text(message);
    abj404UpdateAjaxDebugLog('Table Warmup Failure: ' + message, meta);
    if (tablePlaceholderStillAwaitingLoad()) {
        jQuery('.abj404-table[data-table-awaiting-load] tbody').html(
            '<tr><td class="abj404-empty-message abj404-error">' +
            jQuery('<div/>').text(message).html() +
            '</td></tr>'
        );
    }
}

function warmTableCacheStage(triggerItem, options) {
    options = options || {};
    var rowThatChanged = jQuery(triggerItem).parentsUntil('.tablenav').parent();
    var rowsPerPage = jQuery(rowThatChanged).find('select[name=perpage]').val();
    var filterText = jQuery(rowThatChanged).find('input[name=searchFilter]').val();
    var $ajaxConfigEl = jQuery("[data-pagination-ajax-url]").first();
    if ($ajaxConfigEl.length === 0) {
        $ajaxConfigEl = jQuery(".abj404-filter-bar").first();
    }
    var url = $ajaxConfigEl.attr("data-pagination-ajax-url") || window.ajaxurl;
    if (!url) {
        if (typeof options.onError === 'function') {
            options.onError({lastError: 'Missing AJAX URL'});
        }
        return;
    }
    var baseUrl = url.split('?')[0];
    var subpage = $ajaxConfigEl.attr("data-pagination-ajax-subpage") || getURLParameter('subpage');
    var page = getURLParameter('page');
    var trashFilter = $ajaxConfigEl.attr('data-pagination-current-filter') || getURLParameter('filter');
    var orderby = $ajaxConfigEl.attr('data-pagination-current-orderby') || getURLParameter('orderby');
    var order = $ajaxConfigEl.attr('data-pagination-current-order') || getURLParameter('order');
    var paged = $ajaxConfigEl.attr('data-pagination-current-paged') || getURLParameter('paged');
    var scoreRange = $ajaxConfigEl.attr('data-pagination-current-score-range');
    if (typeof scoreRange === 'undefined' || scoreRange === null || scoreRange === '') {
        scoreRange = getURLParameter('score_range');
    }
    if (!scoreRange) {
        scoreRange = 'all';
    }
    var nonce = $ajaxConfigEl.attr("data-pagination-ajax-nonce") || '';
    var inflightNonce = $ajaxConfigEl.attr('data-pagination-inflight-nonce') || '';
    var requestId = abj404GenerateRequestId();
    var requestStartedAt = Date.now();
    var stopStageProgressPolling = abj404StartStageProgressPolling({
        baseUrl: baseUrl,
        nonce: inflightNonce,
        requestId: requestId,
        subpage: subpage,
        message: options.stageProgressMessage || 'Currently refreshing data'
    });

    var warmAjaxRunner = (typeof abj404AjaxWithNonceRetry === 'function')
        ? abj404AjaxWithNonceRetry : jQuery.ajax;
    warmAjaxRunner({
        url: baseUrl,
        type: 'POST',
        dataType: 'json',
        timeout: 45000,
        data: {
            action: 'ajaxWarmTableCache',
            page: page,
            rowsPerPage: rowsPerPage,
            filterText: filterText,
            filter: trashFilter,
            subpage: subpage,
            nonce: nonce,
            orderby: orderby,
            order: order,
            paged: paged,
            score_range: scoreRange,
            requestId: requestId
        },
        success: function(result) {
            stopStageProgressPolling(true);
            if (result && result.viewBuildPending) {
                // The staged view_done table is missing or invalidated.
                // Don't try to warm the snapshot cache (which would call
                // getRedirectsForView and trigger an inline build): pass the
                // pending state up so the caller can poll ajaxAdvanceViewBuild.
                abj404UpdateAjaxDebugLog('Warmup deferred (view build pending)', {
                    progress: result.progress
                });
                if (typeof options.onComplete === 'function') {
                    options.onComplete(result);
                }
                return;
            }
            if (result && result.stage && result.queryLabel) {
                var completedStage = (result.stage === 'count' && !result.ready) ? 'rows' : (result.ready ? 'count' : '');
                var timingMs = 0;
                if (completedStage && result.timingsByStage && result.timingsByStage[completedStage]) {
                    timingMs = result.timingsByStage[completedStage].last_ms;
                }

                var message = abj404FormatRefreshingStageMessage(
                    options.stageProgressMessage || 'Currently refreshing data',
                    result.stage === 'count' ? 'table_cache_count' : 'table_cache_rows',
                    result.queryLabel,
                    subpage,
                    timingMs,
                    completedStage
                );

                if (result.ready) {
                    jQuery('.abj404-refresh-status').text('');
                } else {
                    jQuery('.abj404-refresh-status').text(message);
                }

                // Log details to debug footer
                abj404UpdateAjaxDebugLog('Warmup stage completed: ' + message, {
                    stage: result.stage,
                    queryLabel: result.queryLabel,
                    timingMs: timingMs,
                    ready: result.ready
                });

                if (completedStage) {
                    console.log('[abj404 warmup]', {
                        stage: completedStage,
                        ms: timingMs,
                        attempts: (result.attemptsByStage && result.attemptsByStage[completedStage]) || 0,
                        error: (result.timingsByStage && result.timingsByStage[completedStage] && result.timingsByStage[completedStage].last_error) || ''
                    });
                }
            }
            if (typeof options.onComplete === 'function') {
                options.onComplete(result || {});
            }
        },
        error: function(jqXHR, textStatus, errorThrown) {
            stopStageProgressPolling(true);
            if (typeof options.onError === 'function') {
                options.onError({
                    status: jqXHR && jqXHR.status ? jqXHR.status : '',
                    textStatus: textStatus,
                    errorThrown: errorThrown,
                    elapsedMs: Date.now() - requestStartedAt,
                    timeoutMs: 45000,
                    stage: '',
                    queryLabel: '',
                    lastError: textStatus || errorThrown || 'ajax-error'
                });
            }
        }
    });
}
