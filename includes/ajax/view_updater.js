
if (typeof(getURLParameter) !== "function") {
    function getURLParameter(name) {
        return (location.search.split('?' + name + '=')[1] ||
                location.search.split('&' + name + '=')[1] ||
                '').split('&')[0];
    }
}

/**
 * Stores AJAX interaction details for the footer debug section.
 * @type {string[]}
 */
window.abj404AjaxInteractionLogs = [];

/**
 * Append a message to the AJAX debug log in the footer.
 *
 * @param {string} message
 * @param {object|null} details
 * @returns {void}
 */
function abj404UpdateAjaxDebugLog(message, details) {
    if (!message) {
        return;
    }
    var d = new Date();
    var hours = String(d.getHours());
    if (hours.length < 2) { hours = '0' + hours; }
    var minutes = String(d.getMinutes());
    if (minutes.length < 2) { minutes = '0' + minutes; }
    var seconds = String(d.getSeconds());
    if (seconds.length < 2) { seconds = '0' + seconds; }
    var ms = String(d.getMilliseconds());
    while (ms.length < 3) { ms = '0' + ms; }
    
    var timestamp = hours + ':' + minutes + ':' + seconds + '.' + ms;
    var logEntry = '[' + timestamp + '] ' + message;
    if (details && typeof details === 'object') {
        try {
            logEntry += ' ' + JSON.stringify(details);
        } catch (e) {
            // ignore serialization errors
        }
    }
    
    window.abj404AjaxInteractionLogs.push(logEntry);
    
    var $container = jQuery('#abj404-ajax-debug-info');
    var $log = jQuery('#abj404-ajax-debug-log');
    
    if ($container.length > 0) {
        $container.show();
    }
    
    if ($log.length > 0) {
        var $entry = jQuery('<div>').text(logEntry).css({
            marginBottom: '4px',
            borderBottom: '1px solid #e0e0e0',
            paddingBottom: '2px'
        });
        $log.append($entry);
        // auto-scroll to bottom
        $log.scrollTop($log[0].scrollHeight);
    }
}

/**
 * Generate a short alphanumeric id used by the server to key an in-flight
 * stage transient (see ViewUpdater::setStage).  Generated client-side so the
 * id is known to the JS error handler even when a pure network timeout means
 * no response, header, or body ever arrives — which is the only path the
 * follow-up `ajaxFetchInflightStage` call can recover diagnostics for.
 *
 * Server validates: `[a-zA-Z0-9]{8,64}`.  16 hex-ish chars is plenty of
 * entropy to avoid transient-key collisions across concurrent admin tabs.
 *
 * @returns {string}
 */
function abj404GenerateRequestId() {
    var alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
    var out = '';
    for (var i = 0; i < 16; i++) {
        out += alphabet.charAt(Math.floor(Math.random() * alphabet.length));
    }
    return out;
}

function abj404AjaxStageDiagnostics(stage, subpage) {
    var map = {
        table_redirects: {
            queryLabel: 'getAdminRedirectsPageTable() -> getRedirectsForView() / getRedirectsForView.sql',
            whatHappening: 'Loading Redirects table rows',
            stageNumber: 1
        },
        redirect_status_counts: {
            queryLabel: 'getRedirectStatusCounts()',
            whatHappening: 'Counting Redirects status tabs',
            stageNumber: 2
        },
        table_captured: {
            queryLabel: 'getCapturedURLSPageTable() -> getRedirectsForView() / getRedirectsForView.sql',
            whatHappening: 'Loading Captured 404 URLs table rows',
            stageNumber: 1
        },
        captured_status_counts: {
            queryLabel: 'getCapturedStatusCounts()',
            whatHappening: 'Counting Captured 404 URLs status tabs',
            stageNumber: 2
        },
        table_logs: {
            queryLabel: 'getAdminLogsPageTable() -> getLogRecords()',
            whatHappening: 'Loading Logs table rows',
            stageNumber: 1
        },
        paginationLinksTop: {
            queryLabel: 'getPaginationLinks(top) -> getRedirectsForViewCount() / getRedirectsForView.sql',
            whatHappening: 'Rendering top pagination links',
            stageNumber: 3
        },
        paginationLinksBottom: {
            queryLabel: 'getPaginationLinks(bottom) -> getRedirectsForViewCount() / getRedirectsForView.sql',
            whatHappening: 'Rendering bottom pagination links',
            stageNumber: 4
        },
        table_cache_rows: {
            queryLabel: 'getRedirectsForView',
            whatHappening: 'Warming table row snapshot',
            stageNumber: 1
        },
        table_cache_count: {
            queryLabel: 'getRedirectsForViewCount',
            whatHappening: 'Warming table count snapshot',
            stageNumber: 2
        }
    };
    if (stage && map[stage]) {
        return map[stage];
    }
    if (subpage === 'abj404_captured') {
        return {
            queryLabel: 'getCapturedURLSPageTable() -> getRedirectsForView() / getRedirectsForView.sql',
            whatHappening: 'Loading Captured 404 URLs table rows',
            stageNumber: 1
        };
    }
    if (subpage === 'abj404_logs') {
        return {
            queryLabel: 'getAdminLogsPageTable() -> getLogRecords()',
            whatHappening: 'Loading Logs table rows',
            stageNumber: 1
        };
    }
    return {
        queryLabel: 'getAdminRedirectsPageTable() -> getRedirectsForView() / getRedirectsForView.sql',
        whatHappening: 'Loading Redirects table rows',
        stageNumber: 1
    };
}

function abj404FormatRefreshingStageMessage(baseMessage, stage, queryLabel, subpage, timingMs, completedStage) {
    var diagnostics = abj404AjaxStageDiagnostics(stage, subpage);
    var stageNumber = diagnostics.stageNumber || '?';
    var label = queryLabel || diagnostics.queryLabel || stage || 'unknown';
    var completedText = '';
    if (completedStage && timingMs > 0) {
        var completedDiag = abj404AjaxStageDiagnostics(completedStage === 'rows' ? 'table_cache_rows' : 'table_cache_count', subpage);
        completedText = 'Stage ' + (completedDiag.stageNumber || '?') + ' complete in ' + timingMs + ' ms. ';
    }
    return completedText + (baseMessage || 'Currently refreshing data') + ' (stage ' + stageNumber + ', ' + label + ')';
}

function abj404StartStageProgressPolling(config) {
    config = config || {};
    if (!config.baseUrl || !config.nonce || !config.requestId) {
        return function() {};
    }
    var stopped = false;
    var baseMessage = config.message || 'Currently refreshing data';
    var updateStage = function() {
        if (stopped) {
            return;
        }
        jQuery.ajax({
            url: config.baseUrl,
            type: 'POST',
            dataType: 'json',
            timeout: 5000,
            data: {
                action: 'ajaxFetchInflightStage',
                nonce: config.nonce,
                requestId: config.requestId
            }
        }).done(function(stageResult) {
            if (stopped || !stageResult) {
                return;
            }
            var stage = typeof stageResult.stage === 'string' ? stageResult.stage : '';
            var queryLabel = typeof stageResult.queryLabel === 'string' ? stageResult.queryLabel : '';
            if (stage || queryLabel) {
                var message = abj404FormatRefreshingStageMessage(baseMessage, stage, queryLabel, config.subpage || '');
                jQuery('.abj404-refresh-status').text(message);
                
                // Also log to the footer debug section
                abj404UpdateAjaxDebugLog('Polling: ' + message, {
                    stage: stage,
                    queryLabel: queryLabel,
                    whatHappening: stageResult.whatHappening || ''
                });

                var toast = document.getElementById('abj404-background-refresh-toast');
                if (toast) {
                    var label = toast.querySelector('.abj404-refresh-label');
                    if (label) {
                        label.textContent = message;
                    }
                }
            }
        });
    };
    jQuery('.abj404-refresh-status').text(baseMessage + ' (...)');
    updateStage();
    var intervalId = window.setInterval(updateStage, 2500);
    return function() {
        stopped = true;
        window.clearInterval(intervalId);
    };
}

function abj404FormatAjaxFailureDetails(meta) {
    meta = meta || {};
    var elapsed = parseInt(meta.elapsedMs, 10);
    var timeout = parseInt(meta.timeoutMs, 10);
    var lines = [
        'What was happening: ' + (meta.whatHappening || 'Updating table data'),
        'Query: ' + (meta.queryLabel || 'unknown'),
        'HTTP status: ' + (meta.status || ''),
        'textStatus: ' + (meta.textStatus || ''),
        'errorThrown: ' + (meta.errorThrown || ''),
        'action: ' + (meta.action || ''),
        'subpage: ' + (meta.subpage || '')
    ];
    if (!isNaN(elapsed)) {
        lines.push('Elapsed: ' + elapsed + 'ms');
    }
    if (!isNaN(timeout)) {
        lines.push('Timeout budget: ' + timeout + 'ms');
    }
    if (meta.stage) {
        lines.push('Server stage: ' + meta.stage);
    }
    if (meta.message) {
        lines.push('Server message: ' + meta.message);
    }
    if (meta.lastQueryRedacted) {
        lines.push('Last query (redacted): ' + meta.lastQueryRedacted);
    }
    return lines;
}

// when the user presses enter on the filter text input then update the table
jQuery(document).ready(function($) {
    bindSearchFieldListeners();
    bindPaginationLinkListeners();
    triggerInitialTableLoadIfNeeded();
    triggerBackgroundTableRefreshIfEnabled();
    triggerStatsBackgroundRefreshIfEnabled();
    // The health bar is rendered as an empty placeholder by PHP and hydrated
    // here so the slow getHighImpactCapturedCount() query never blocks first
    // paint of the redirects table.  Safe to call on every page — it returns
    // early when no placeholder is in the DOM.
    refreshHealthBarIfNeeded();
});

/**
 * Hydrate the redirects-page health bar via a dedicated AJAX call so the
 * slow getHighImpactCapturedCount() query never blocks the table render.
 *
 * Reads endpoint URL, action, and nonce from data-attrs on the placeholder
 * div emitted by ViewTrait_RedirectsTable.  No-op when no placeholder is
 * present (other admin pages, or when the bar is already hydrated).
 *
 * Idempotent: a `data-health-bar-loading` flag prevents duplicate concurrent
 * requests when this function is invoked from both jQuery.ready and the
 * pagination success handler on the same page load.
 */
function refreshHealthBarIfNeeded() {
    var $bar = jQuery('.abj404-health-bar[data-health-bar-placeholder]');
    if ($bar.length === 0) {
        return;
    }
    if ($bar.attr('data-health-bar-loading') === '1') {
        return;
    }

    var url = $bar.attr('data-health-bar-ajax-url') || window.ajaxurl;
    var action = $bar.attr('data-health-bar-ajax-action') || 'ajaxRefreshHealthBar';
    var nonce = $bar.attr('data-health-bar-nonce') || '';
    if (!url || !nonce) {
        // Endpoint config missing — drop the placeholder so the page is usable.
        $bar.removeAttr('data-health-bar-placeholder');
        $bar.empty();
        return;
    }

    $bar.attr('data-health-bar-loading', '1');

    abj404UpdateAjaxDebugLog('Starting Health Bar AJAX: ' + action);

    jQuery.ajax({
        url: url,
        type: 'POST',
        dataType: 'json',
        data: {
            action: action,
            nonce: nonce,
            page: getURLParameter('page') || '',
            subpage: getURLParameter('subpage') || ''
        },
        success: function(result) {
            $bar.removeAttr('data-health-bar-loading');
            abj404UpdateAjaxDebugLog('Health Bar AJAX Success: ' + action, {
                highImpactCapturedCount: result ? result.highImpactCapturedCount : null
            });
            if (!result || typeof result.highImpactCapturedCount === 'undefined' || !result.statusCounts) {
                $bar.removeAttr('data-health-bar-placeholder');
                $bar.empty();
                return;
            }
            var active = (result.statusCounts.all || 0) - (result.statusCounts.trash || 0);
            var rollupAvailable = result.rollupAvailable !== false && result.highImpactCapturedCount !== null;
            var high = result.highImpactCapturedCount || 0;
            var html;
            if (!rollupAvailable) {
                html = '<span class="abj404-health-dot abj404-health-gray"></span>' +
                    jQuery('<span>').text(active + ' redirects active, URL attention status unavailable while logs rebuild').html();
            } else if (high === 0) {
                html = '<span class="abj404-health-dot abj404-health-green"></span>' +
                    jQuery('<span>').text(active + ' redirects active, no URLs need attention').html();
            } else {
                html = '<span class="abj404-health-dot abj404-health-yellow"></span>' +
                    jQuery('<span>').text(active + ' redirects active — ' + high + ' captured URLs have repeat visitors').html() +
                    ' <a href="?page=' + (getURLParameter('page') || 'abj404_solution') + '&subpage=abj404_captured&filter=' +
                    (result.statusCounts._capturedFilter || '') + '">View</a>';
            }
            $bar.html(html);
            $bar.removeAttr('data-health-bar-placeholder');
        },
        error: function(jqXHR, textStatus, errorThrown) {
            // On error, drop the placeholder so the UI doesn't get stuck on
            // "Loading status…" forever.  The failure has already been logged
            // server-side via the ajaxRefreshHealthBar exception handler.
            $bar.removeAttr('data-health-bar-loading');
            $bar.removeAttr('data-health-bar-placeholder');
            $bar.empty();
            
            abj404UpdateAjaxDebugLog('Health Bar AJAX Error: ' + action, {
                status: jqXHR ? jqXHR.status : '',
                textStatus: textStatus,
                errorThrown: errorThrown
            });
        }
    });
}

function bindPaginationLinkListeners() {
    // Delegate to document so handlers survive table HTML replacement after AJAX refresh.
    jQuery(document)
        .off('click.abj404Pagination', '.abj404-pagination-controls a')
        .on('click.abj404Pagination', '.abj404-pagination-controls a', function(event) {
            event.preventDefault();
            event.stopPropagation();
            paginationLinksChange(event.currentTarget || event.target || this);
        });
}

function getRefreshStatusHost() {
    // Prefer the element that actually carries AJAX config attributes.
    var $host = jQuery('[data-pagination-ajax-url]').first();
    if ($host.length === 0) {
        $host = jQuery('.abj404-filter-bar').first();
    }
    if ($host.length === 0) {
        $host = jQuery('.abj404-pagination-right').first();
    }
    return $host;
}

function isDetectOnlyRefreshInFlight() {
    return window.abj404DetectOnlyRefreshInFlight === true;
}

function setDetectOnlyRefreshInFlight(isRunning) {
    window.abj404DetectOnlyRefreshInFlight = !!isRunning;
}

function triggerBackgroundTableRefreshIfEnabled() {
    var $config = getRefreshStatusHost();
    if ($config.length === 0) {
        return;
    }
    if ($config.attr('data-pagination-auto-refresh') !== '1') {
        return;
    }
    if ($config.attr('data-pagination-initial-load') === '1' &&
        jQuery('.abj404-table[data-table-awaiting-load="1"]').length > 0) {
        return;
    }
    if (!shouldRunAutoRefreshNow($config)) {
        return;
    }
    if (window.abj404InitialTableRefreshTriggered) {
        return;
    }
    window.abj404InitialTableRefreshTriggered = true;
    window.abj404BackgroundRefreshState = {
        enabled: true,
        startedAt: Date.now(),
        finishedAt: null,
        difference: null,
        durationMs: null,
        requestCount: 0,
        hasUpdateAvailable: false,
        lastStatusCode: null,
        lastResponseBytes: null,
        lastSubpage: null,
        lastAction: null,
        lastRowsPerPage: null,
        lastFilterTextLength: 0,
        lastError: null
    };

    var perpageElements = document.querySelectorAll('.perpage');
    if (perpageElements == null || perpageElements.length === 0) {
        return;
    }

    var startedText = $config.attr('data-pagination-refresh-started-text') || 'Refreshing data in background\u2026';
    showRefreshToastStart(startedText);
    var refreshStartedAt = Date.now();

    // Run a detect-only check in the background. Never overwrite the visible table automatically.
    var runRefresh = function() {
        if (isDetectOnlyRefreshInFlight()) {
            return;
        }
        paginationLinksChange(perpageElements[0], {
            backgroundRefresh: true,
            detectOnly: true,
            showStageProgress: true,
            stageProgressMessage: startedText,
            onComplete: function(meta) {
                var $latestConfig = getRefreshStatusHost();
                var hasUpdate = !!(meta && meta.hasUpdate);
                var finishedText = $latestConfig.attr('data-pagination-refresh-finished-text') || 'Data refreshed';
                var elapsed = Date.now() - refreshStartedAt;
                var minimumVisibleMs = 850;
                var showFinished = function() {
                    if (hasUpdate) {
                        var availableText = $latestConfig.attr('data-pagination-refresh-available-text') || 'Refresh available';
                        showRefreshAvailablePill(availableText, 5000);
                    }
                    showRefreshToastComplete(finishedText);
                    window.setTimeout(hideRefreshToast, 3500);
                };
                if (elapsed < minimumVisibleMs) {
                    window.setTimeout(showFinished, minimumVisibleMs - elapsed);
                } else {
                    showFinished();
                }
                if (window.abj404BackgroundRefreshState) {
                    window.abj404BackgroundRefreshState.finishedAt = Date.now();
                    window.abj404BackgroundRefreshState.hasUpdateAvailable = hasUpdate;
                }
                markAutoRefreshCompleted($latestConfig);
            },
            onError: function() {
                hideRefreshToast();
                if (window.abj404BackgroundRefreshState) {
                    window.abj404BackgroundRefreshState.lastError = 'background-refresh-failed';
                    window.abj404BackgroundRefreshState.finishedAt = Date.now();
                    window.abj404BackgroundRefreshState.hasUpdateAvailable = false;
                }
            }
        });
    };
    if (typeof window.requestIdleCallback === 'function') {
        window.requestIdleCallback(runRefresh, {timeout: 2000});
    } else {
        setTimeout(runRefresh, 900);
    }
}

function triggerInitialTableLoadIfNeeded() {
    var $config = getRefreshStatusHost();
    if ($config.length === 0) {
        return;
    }
    if ($config.attr('data-pagination-initial-load') !== '1') {
        return;
    }
    if (jQuery('.abj404-table[data-table-awaiting-load="1"]').length === 0) {
        $config.attr('data-pagination-initial-load', '0');
        return;
    }

    var perpageElements = document.querySelectorAll('.perpage');
    if (perpageElements == null || perpageElements.length === 0) {
        return;
    }

    var maxInitialLoadAttempts = 3;
    var triggerInitialLoadAttempt = function(attemptNumber) {
        paginationLinksChange(perpageElements[0], {
            backgroundRefresh: false,
            detectOnly: false,
            cacheMode: 'cache_or_pending',
            onComplete: function(meta) {
                if (meta && meta.cachePending) {
                    startPlaceholderTableHydration(perpageElements[0]);
                    return;
                }
                $config.attr('data-pagination-initial-load', '0');
            },
            onError: function(errorMeta) {
                var status = errorMeta && errorMeta.status ? parseInt(errorMeta.status, 10) : 0;
                if (attemptNumber < maxInitialLoadAttempts) {
                    // Retry transient failures (including rate-limit responses) before
                    // giving up and leaving placeholders in place.
                    var delayMs = (status === 429) ? 1200 * attemptNumber : 700 * attemptNumber;
                    window.setTimeout(function() {
                        triggerInitialLoadAttempt(attemptNumber + 1);
                    }, delayMs);
                    return;
                }
                $config.attr('data-pagination-initial-load', '0');
                // Last-resort fallback: unblock page placeholders so the UI is usable.
                // Replace the "Loading…" cell text with a concrete error state so
                // the page no longer appears stuck — stripping the attribute alone
                // leaves the original placeholder rows visible to the user.
                var fallbackDetails = abj404FormatAjaxFailureDetails(errorMeta || {});
                var errorMessage = 'Could not load table data. ' + fallbackDetails.join('\n');
                jQuery('.abj404-table[data-table-awaiting-load] tbody').html(
                    '<tr><td class="abj404-empty-message abj404-error">' +
                    jQuery('<div/>').text(errorMessage).html() +
                    '</td></tr>'
                );
                jQuery('[data-table-awaiting-load]').removeAttr('data-table-awaiting-load');
                jQuery('[data-tab-counts-placeholder]').removeAttr('data-tab-counts-placeholder');
                jQuery('[data-health-bar-placeholder]').removeAttr('data-health-bar-placeholder');
            }
        });
    };

    triggerInitialLoadAttempt(1);
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
                    }, 700);
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

    jQuery.ajax({
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
            requestId: requestId
        },
        success: function(result) {
            stopStageProgressPolling();
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
            stopStageProgressPolling();
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

function getStatsRefreshConfigHost() {
    return jQuery('.abj404-stats-refresh-config').first();
}

function getStatsAutoRefreshCacheKey($config) {
    var page = getURLParameter('page') || '';
    var subpage = ($config && $config.attr) ? ($config.attr('data-stats-refresh-subpage') || getURLParameter('subpage') || 'abj404_stats') : (getURLParameter('subpage') || 'abj404_stats');
    return 'abj404:stats_auto_refresh:' + [page, subpage].join(':');
}

function shouldRunStatsAutoRefreshNow($config) {
    try {
        if (!window.localStorage) {
            return true;
        }
        var key = getStatsAutoRefreshCacheKey($config);
        var lastTs = parseInt(localStorage.getItem(key) || '0', 10);
        var cooldownMs = 30000; // at most once every 30s for this tab
        return !(lastTs > 0 && (Date.now() - lastTs) < cooldownMs);
    } catch (e) {
        return true;
    }
}

function markStatsAutoRefreshCompleted($config) {
    try {
        if (!window.localStorage) {
            return;
        }
        var key = getStatsAutoRefreshCacheKey($config);
        localStorage.setItem(key, String(Date.now()));
    } catch (e) {
        // ignore storage failures
    }
}

function triggerStatsBackgroundRefreshIfEnabled() {
    var $config = getStatsRefreshConfigHost();
    if ($config.length === 0) {
        return;
    }
    if ($config.attr('data-stats-refresh-enabled') !== '1') {
        return;
    }
    if (!shouldRunStatsAutoRefreshNow($config)) {
        return;
    }
    if (window.abj404StatsRefreshTriggered) {
        return;
    }
    window.abj404StatsRefreshTriggered = true;

    var nonce = $config.attr('data-stats-refresh-nonce') || '';
    if (nonce === '') {
        return;
    }

    window.abj404StatsBackgroundRefreshState = {
        enabled: true,
        startedAt: Date.now(),
        finishedAt: null,
        difference: null,
        durationMs: null,
        lastStatusCode: null,
        hasUpdateAvailable: false,
        lastError: null
    };

    var action = $config.attr('data-stats-refresh-action') || 'ajaxRefreshStatsDashboard';
    var refreshAvailableText = $config.attr('data-stats-refresh-available-text') || 'Refresh available';
    var currentHash = $config.attr('data-stats-refresh-current-hash') || '';

    var runRefresh = function() {
        var startMs = Date.now();
        abj404UpdateAjaxDebugLog('Starting Stats AJAX: ' + action);
        jQuery.ajax({
            url: window.ajaxurl || 'admin-ajax.php',
            type: 'POST',
            dataType: 'json',
            data: {
                action: action,
                page: getURLParameter('page') || '',
                subpage: getURLParameter('subpage') || 'abj404_stats',
                nonce: nonce,
                currentHash: currentHash
            },
            success: function(result) {
                var payload = result;
                if (payload && payload.data && typeof payload.data === 'object' && payload.success === false) {
                    payload = payload.data;
                }
                var hasUpdate = !!(payload && payload.hasUpdate);
                abj404UpdateAjaxDebugLog('Stats AJAX Success: ' + action, {
                    hasUpdate: hasUpdate,
                    durationMs: Date.now() - startMs
                });
                if (hasUpdate) {
                    showRefreshAvailablePill(refreshAvailableText, 5000);
                }
                if (payload && payload.hash) {
                    $config.attr('data-stats-refresh-current-hash', payload.hash);
                }
                if (window.abj404StatsBackgroundRefreshState) {
                    var duration = Date.now() - startMs;
                    window.abj404StatsBackgroundRefreshState.finishedAt = Date.now();
                    window.abj404StatsBackgroundRefreshState.durationMs = duration;
                    window.abj404StatsBackgroundRefreshState.difference = duration;
                    window.abj404StatsBackgroundRefreshState.lastStatusCode = 200;
                    window.abj404StatsBackgroundRefreshState.hasUpdateAvailable = hasUpdate;
                }
                markStatsAutoRefreshCompleted($config);
            },
            error: function(xhr, textStatus, errorThrown) {
                abj404UpdateAjaxDebugLog('Stats AJAX Error: ' + action, {
                    status: xhr ? xhr.status : '',
                    textStatus: textStatus,
                    errorThrown: errorThrown,
                    durationMs: Date.now() - startMs
                });
                if (window.abj404StatsBackgroundRefreshState) {
                    var duration = Date.now() - startMs;
                    window.abj404StatsBackgroundRefreshState.finishedAt = Date.now();
                    window.abj404StatsBackgroundRefreshState.durationMs = duration;
                    window.abj404StatsBackgroundRefreshState.difference = duration;
                    window.abj404StatsBackgroundRefreshState.lastStatusCode = xhr ? xhr.status : null;
                    window.abj404StatsBackgroundRefreshState.lastError = textStatus || errorThrown || 'ajax-error';
                    window.abj404StatsBackgroundRefreshState.hasUpdateAvailable = false;
                }
                markStatsAutoRefreshCompleted($config);
            }
        });
    };

    if (typeof window.requestIdleCallback === 'function') {
        window.requestIdleCallback(runRefresh, {timeout: 2000});
    } else {
        setTimeout(runRefresh, 900);
    }
}

function setRefreshStatus($config, message) {
    if (!$config || $config.length === 0) {
        return;
    }
    var $status = $config.find('.abj404-refresh-status').first();
    if ($status.length === 0) {
        $status = jQuery('<span class="abj404-refresh-status" aria-live="polite"></span>');
        $config.append($status);
    }
    if ($status.length > 0) {
        $status.text(message || '');
        if (message) {
            abj404UpdateAjaxDebugLog('Status: ' + message);
        }
    }
}

function clearRefreshStatus($config) {
    setRefreshStatus($config, '');
}

function normalizeHtmlForBackgroundComparison(html) {
    if (!html) {
        return '';
    }
    return String(html)
            .replace(/&#0*38;|&amp;/gi, '&')
            .replace(/<!--[\s\S]*?-->/g, '')
            // Browser DOM serialization of SVG can differ from server-rendered HTML
            // (e.g., <path .../> vs <path ...></path>) without any data change.
            .replace(/<svg\b[\s\S]*?<\/svg>/gi, '')
            .replace(/<span class="abj404-refresh-status"[^>]*>[\s\S]*?<\/span>/g, '')
            .replace(/(<span class="abj404-time-ago"[^>]*>)[\s\S]*?(<\/span>)/g, '$1$2')
            // "time-ago" markers are freshness metadata; timestamp drift alone should not signal table-data change.
            .replace(/\sdata-timestamp="[^"]*"/gi, '')
            .replace(/\sdata-previous-value="[^"]*"/g, '')
            // Search input is initially rendered disabled and then enabled client-side;
            // ignore this client-only attribute drift for no-change detection.
            .replace(/\sdisabled(?:=(?:"disabled"|""))?/gi, '')
            .replace(/data-pagination-ajax-nonce="[^"]*"/g, 'data-pagination-ajax-nonce="nonce"')
            // URLs in attributes may encode "&" as "&amp;" or "&#038;".
            // Normalize all nonce query params so nonce churn is not treated as data change.
            .replace(/((?:\?|&|&amp;|&#038;)(?:_wpnonce|nonce)=)[^&"'\s>]+/gi, '$1nonce')
            .replace(/\s+/g, ' ')
            .trim();
}

function hasBackgroundRefreshUpdate(result) {
    return hasBackgroundRefreshUpdateWithBaseline(result, null);
}

function getComparableTableHtml(html) {
    if (!html) {
        return '';
    }
    var raw = String(html);
    // Compare rendered row data (tbody) only; header/pagination/link-encoding differences
    // are presentation concerns and can trigger false positives.
    var match = raw.match(/<tbody[^>]*>[\s\S]*<\/tbody>/i);
    if (match && match.length > 0) {
        return match[0];
    }
    return raw;
}

function buildComparableTableSignature(html) {
    var comparableHtml = getComparableTableHtml(html);
    var normalizedHtml = normalizeHtmlForBackgroundComparison(comparableHtml);
    if (!normalizedHtml) {
        return '';
    }

    // Compare semantic row/cell text instead of raw markup so entity/quote/style
    // serialization differences do not produce false positives.
    if (typeof DOMParser !== 'function') {
        return normalizedHtml;
    }

    try {
        var parser = new DOMParser();
        var doc = parser.parseFromString('<table>' + normalizedHtml + '</table>', 'text/html');
        var rows = doc.querySelectorAll('tbody tr');
        if (!rows || rows.length === 0) {
            return normalizedHtml;
        }
        var rowParts = [];
        for (var i = 0; i < rows.length; i++) {
            var cells = rows[i].querySelectorAll('td');
            var cellParts = [];
            for (var j = 0; j < cells.length; j++) {
                var text = (cells[j].textContent || '').replace(/\s+/g, ' ').trim();
                cellParts.push(text);
            }
            rowParts.push(cellParts.join('||'));
        }
        // Ignore non-deterministic row ordering for equal sort values.
        rowParts.sort();
        return rowParts.join('\n');
    } catch (e) {
        return normalizedHtml;
    }
}

function hasBackgroundRefreshUpdateWithBaseline(result, baseline) {
    var currentTableHtml = '';
    var incomingTableHtml = '';

    var currentTable = jQuery('.abj404-table, .wp-list-table').first();
    if (currentTable.length > 0) {
        currentTableHtml = currentTable.prop('outerHTML') || '';
    }
    if (result && typeof result.table === 'string') {
        incomingTableHtml = result.table;
    }

    var normalizedCurrentTable = buildComparableTableSignature(currentTableHtml);
    var normalizedIncomingTable = buildComparableTableSignature(incomingTableHtml);

    if (baseline && typeof baseline === 'object') {
        if (typeof baseline.table === 'string') {
            normalizedCurrentTable = baseline.table;
        }
    }

    return normalizedCurrentTable !== normalizedIncomingTable;
}

function getAutoRefreshCacheKey($config) {
    var page = getURLParameter('page') || '';
    var subpage = ($config && $config.attr) ? ($config.attr('data-pagination-ajax-subpage') || '') : '';
    var filter = ($config && $config.attr) ? ($config.attr('data-pagination-current-filter') || '') : '';
    if (filter === '') {
        filter = getURLParameter('filter') || '0';
    }
    var orderby = ($config && $config.attr) ? ($config.attr('data-pagination-current-orderby') || '') : '';
    if (orderby === '') {
        orderby = getURLParameter('orderby') || '';
    }
    var order = ($config && $config.attr) ? ($config.attr('data-pagination-current-order') || '') : '';
    if (order === '') {
        order = getURLParameter('order') || '';
    }
    return 'abj404:auto_refresh:' + [page, subpage, filter, orderby, order].join(':');
}

function shouldRunAutoRefreshNow($config) {
    try {
        if (!window.localStorage) {
            return true;
        }
        var key = getAutoRefreshCacheKey($config);
        var lastTs = parseInt(localStorage.getItem(key) || '0', 10);
        var cooldownMs = 30000; // 30s throttle per tab/sort/filter key
        return !(lastTs > 0 && (Date.now() - lastTs) < cooldownMs);
    } catch (e) {
        return true;
    }
}

function markAutoRefreshCompleted($config) {
    try {
        if (!window.localStorage) {
            return;
        }
        var key = getAutoRefreshCacheKey($config);
        localStorage.setItem(key, String(Date.now()));
    } catch (e) {
        // ignore storage failures
    }
}

function ensureRefreshToastStyles() {
    if (document.getElementById('abj404-refresh-toast-styles')) {
        return;
    }
    var style = document.createElement('style');
    style.id = 'abj404-refresh-toast-styles';
    style.textContent =
        '#abj404-background-refresh-toast{' +
        'position:fixed;right:16px;bottom:16px;z-index:99999;padding:8px 10px;' +
        'background:var(--abj404-surface,#1f2937);color:var(--abj404-text,#fff);' +
        'border:1px solid var(--abj404-border,rgba(255,255,255,.15));border-radius:18px;font-size:12px;' +
        'box-shadow:0 4px 14px rgba(0,0,0,.22);max-width:360px;display:flex;align-items:center;gap:8px;' +
        'cursor:default;transition:width .18s ease,max-width .18s ease,padding .18s ease,gap .18s ease,border-radius .18s ease,opacity .18s ease,background-color .18s ease;box-sizing:border-box;}' +
        '#abj404-background-refresh-toast .abj404-refresh-spinner{' +
        'width:12px;height:12px;border:2px solid var(--abj404-text-muted,rgba(255,255,255,.45));border-top-color:var(--abj404-accent,#fff);' +
        'border-radius:50%;flex:0 0 auto;animation:abj404-refresh-spin .8s linear infinite;}' +
        '#abj404-background-refresh-toast .abj404-refresh-label{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}' +
        '#abj404-background-refresh-toast.abj404-refresh-collapsed{padding:0;width:28px;min-width:28px;max-width:28px;overflow:hidden;}' +
        '#abj404-background-refresh-toast.abj404-refresh-collapsed{height:28px;min-height:28px;gap:0;line-height:0;justify-content:center;border-radius:14px;}' +
        '#abj404-background-refresh-toast.abj404-refresh-collapsed .abj404-refresh-label{display:none;}' +
        '#abj404-background-refresh-toast.abj404-refresh-collapsed:hover{max-width:340px;width:auto;min-width:0;padding:8px 10px;height:28px;min-height:28px;line-height:normal;gap:8px;border-radius:14px;}' +
        '#abj404-background-refresh-toast.abj404-refresh-collapsed:hover .abj404-refresh-label{display:inline;}' +
        '#abj404-background-refresh-toast.abj404-refresh-complete{background:var(--abj404-success,#1f9d55);color:#fff;border-color:transparent;}' +
        '#abj404-background-refresh-toast.abj404-refresh-complete .abj404-refresh-spinner{animation:none;border-color:rgba(255,255,255,.5);border-top-color:rgba(255,255,255,.5);}' +
        '@keyframes abj404-refresh-spin{from{transform:rotate(0)}to{transform:rotate(360deg)}}';
    document.head.appendChild(style);
}

function ensureRefreshToast() {
    ensureRefreshToastStyles();
    var id = 'abj404-background-refresh-toast';
    var toast = document.getElementById(id);
    if (!toast) {
        toast = document.createElement('div');
        toast.id = id;
        toast.setAttribute('role', 'status');
        toast.setAttribute('aria-live', 'polite');
        toast.innerHTML = '<span class="abj404-refresh-spinner" aria-hidden="true"></span><span class="abj404-refresh-label"></span>';
        document.body.appendChild(toast);
    }
    return toast;
}

function setRefreshToastMessage(message) {
    var toast = ensureRefreshToast();
    var label = toast.querySelector('.abj404-refresh-label');
    if (label) {
        label.textContent = message || '';
    }
    return toast;
}

function showRefreshToastStart(message) {
    var toast = setRefreshToastMessage(message);
    toast.classList.remove('abj404-refresh-complete');
    toast.classList.remove('abj404-refresh-collapsed');
    toast.style.display = 'flex';
    window.setTimeout(function() {
        if (toast.style.display !== 'none' && !toast.classList.contains('abj404-refresh-complete')) {
            toast.classList.add('abj404-refresh-collapsed');
        }
    }, 2000);
}

function showRefreshToastComplete(message) {
    var toast = setRefreshToastMessage(message);
    toast.classList.remove('abj404-refresh-collapsed');
    toast.classList.add('abj404-refresh-complete');
    toast.style.display = 'flex';
}

function hideRefreshToast() {
    var toast = document.getElementById('abj404-background-refresh-toast');
    if (toast) {
        toast.classList.remove('abj404-refresh-collapsed');
        toast.classList.remove('abj404-refresh-complete');
        toast.style.display = 'none';
    }
}

function ensureRefreshAvailablePillStyles() {
    if (document.getElementById('abj404-refresh-available-pill-styles')) {
        return;
    }
    var style = document.createElement('style');
    style.id = 'abj404-refresh-available-pill-styles';
    style.textContent =
        '#abj404-refresh-available-pill{' +
        'position:fixed;right:16px;bottom:16px;z-index:99999;padding:8px 12px;' +
        'background:var(--abj404-accent,#2271b1);color:#fff;border:1px solid rgba(0,0,0,.08);' +
        'border-radius:999px;font-size:12px;font-weight:600;line-height:1.2;cursor:pointer;' +
        'box-shadow:0 4px 14px rgba(0,0,0,.22);transition:opacity .15s ease,transform .15s ease;}' +
        '#abj404-refresh-available-pill:hover{transform:translateY(-1px);background:var(--abj404-accent-hover,#135e96);}';
    document.head.appendChild(style);
}

function hideRefreshAvailablePill() {
    var pill = document.getElementById('abj404-refresh-available-pill');
    if (pill && pill.parentNode) {
        pill.parentNode.removeChild(pill);
    }
    if (window.abj404RefreshAvailableHideTimer) {
        window.clearTimeout(window.abj404RefreshAvailableHideTimer);
        window.abj404RefreshAvailableHideTimer = null;
    }
    window.abj404RefreshAvailableHiddenAt = Date.now();
}

function showRefreshAvailablePill(message, timeoutMs) {
    ensureRefreshAvailablePillStyles();
    hideRefreshAvailablePill();
    var pill = document.createElement('button');
    pill.type = 'button';
    pill.id = 'abj404-refresh-available-pill';
    pill.textContent = message || 'Refresh available';
    pill.setAttribute('aria-live', 'polite');
    pill.setAttribute('title', message || 'Refresh available');
    pill.addEventListener('click', function() {
        window.location.reload();
    });
    document.body.appendChild(pill);
    window.abj404RefreshAvailableShownAt = Date.now();
    window.abj404RefreshAvailableLastMessage = message || 'Refresh available';
    var delay = Math.max(1000, parseInt(timeoutMs, 10) || 5000);
    window.abj404RefreshAvailableHideTimer = window.setTimeout(function() {
        hideRefreshAvailablePill();
    }, delay);
}

function bindSearchFieldListeners() {
    var filters = jQuery('input[name=searchFilter]');
    if (filters === undefined || filters === null || filters.length === 0) {
        return;
    }

    filters.prop('disabled', false);

    var field = jQuery(filters[0]);
    var fieldLength = field.val().length;
    // only set the focus if the input box is visible. otherwise screen scrolls for no reason.
    if (isElementFullyVisible(filters[0])) {
        field.focus();
    }
    // put the cursor at the end of the field
    filters[0].setSelectionRange(fieldLength, fieldLength);

    // Initialize previous-value so the search event handler won't fire
    // spuriously when the field is programmatically cleared from an
    // already-empty state (e.g. Playwright's fill() clears before typing).
    filters.each(function() {
        var $f = jQuery(this);
        if (typeof $f.attr('data-previous-value') === 'undefined') {
            $f.attr('data-previous-value', $f.val() || '');
        }
    });

    // Remove any previously bound handlers to prevent accumulation
    // across repeated AJAX table refreshes.
    filters.off('search.abj404 keypress.abj404 click.abj404');

    filters.on("search.abj404", function(event) {
        var field = jQuery(event.target || event.srcElement);
        var previousValue = field.attr("data-previous-value");
        var fieldLength = field.val() == null ? 0 : field.val().length;
        if (fieldLength === 0 && field.val() !== previousValue) {
            paginationLinksChange(event.target || event.srcElement || filters[0]);
            event.preventDefault();
        }
        field.attr("data-previous-value", field.val());
    });
    
    // update the page when the user presses enter.
    // store the typed value to restore once the page is reloaded.
    filters.on("keypress.abj404", function(event) {
        var field = jQuery(event.target || event.srcElement);
        var keycode = (event.which ? event.which : event.keyCode);
        if (keycode === 13) {
            event.preventDefault();
            var srcElement = event.target || event.srcElement;
            // prefer using the "perpage" element as the source element because when
            // the input box itself is used as a source element there's some kind of bug
            // and I don't care to figure out why at the moment, therefore this hack...
            var perpageElements = document.querySelectorAll('.perpage');
            if (perpageElements != null && perpageElements.length > 0) {
            	srcElement = perpageElements[0];
            }
            paginationLinksChange(srcElement);
        }
        field.attr("data-previous-value", field.val());
    });
    
    // select all text when clicked
    filters.on("click.abj404", function() {
        jQuery(this).select();
    });
}

/** Returns true if an element is within the viewport.
 * From https://stackoverflow.com/a/22480938/222564
 * @param {type} el
 * @returns {Boolean}
 */
function isElementFullyVisible(el) {
    var rect = el.getBoundingClientRect();
    var elemTop = rect.top;
    var elemBottom = rect.bottom;

    // Only completely visible elements return true:
    var isVisible = (elemTop >= 0) && (elemBottom <= window.innerHeight);
    return isVisible;
}

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
    // Inflight-stage nonce is optional — older page renders won't have it,
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

    jQuery.ajax({
        url: baseUrl,
        type: 'POST',
        dataType: "json",
        // Without a client-side timeout, a slow server (e.g. while
        // attemptMissingTableRepairAndRetry runs createDatabaseTables) can leave
        // the table stuck on its loading placeholder forever — onError never
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
            stopStageProgressPolling();
            jQuery('.abj404-refresh-status').text('');
            
            if (result && result.cachePending) {
                jQuery('.abj404-loading-overlay').remove();
                var pendingMsg = result.message || 'Preparing table data in the background.';
                jQuery('.abj404-refresh-status').text(pendingMsg);
                abj404UpdateAjaxDebugLog('AJAX Success (Cache Pending): ' + pendingMsg);
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
            stopStageProgressPolling();
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
            var whatHappeningFromServer = '';
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
                        whatHappeningFromServer = String(details.context.what_happening);
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
                    whatHappening: whatHappeningFromServer || inferredDiagnostics.whatHappening,
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
                    detailLines.push('Inflight stage: (looking up…)');
                }
                var $detailsEl = jQuery('<pre></pre>')
                    .css({whiteSpace: 'pre-wrap', margin: '0 0 8px 0'})
                    .text(detailLines.join('\n'));
                $notice.append($titleEl).append($detailsEl);
                if (shouldFetchInflightStage) {
                    jQuery.ajax({
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
                        var inflightWhatHappening = '';
                        if (stageResult && typeof stageResult.stage === 'string' && stageResult.stage !== '') {
                            inflightStage = stageResult.stage;
                        }
                        if (stageResult && typeof stageResult.queryLabel === 'string' && stageResult.queryLabel !== '') {
                            inflightQueryLabel = stageResult.queryLabel;
                        }
                        if (stageResult && typeof stageResult.whatHappening === 'string' && stageResult.whatHappening !== '') {
                            inflightWhatHappening = stageResult.whatHappening;
                        }
                        var lookupLine = inflightStage
                            ? 'Inflight stage: ' + inflightStage
                            : 'Inflight stage: (unknown)';
                        var lookupDiagnostics = abj404AjaxStageDiagnostics(inflightStage, subpage);
                        var updated = detailLines.slice();
                        for (var i = 0; i < updated.length; i++) {
                            if (updated[i].indexOf('What was happening:') === 0) {
                                updated[i] = 'What was happening: ' + (inflightWhatHappening || lookupDiagnostics.whatHappening);
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
                    whatHappening: whatHappeningFromServer || abj404AjaxStageDiagnostics(stageFromServer, subpage).whatHappening,
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

function extractPagedFromTrigger(triggerItem) {
    if (!triggerItem) {
        return '';
    }
    var href = '';
    var $trigger = jQuery(triggerItem);
    if ($trigger.is('a')) {
        href = $trigger.attr('href') || '';
    } else {
        var $link = $trigger.closest('a');
        href = ($link.length > 0) ? ($link.attr('href') || '') : '';
    }
    if (!href) {
        return '';
    }

    // Support both relative admin URLs and absolute URLs.
    var query = '';
    var queryStart = href.indexOf('?');
    if (queryStart >= 0) {
        query = href.substring(queryStart + 1);
    } else {
        query = href;
    }
    if (!query) {
        return '';
    }

    var pairs = query.split('&');
    for (var i = 0; i < pairs.length; i++) {
        var kv = pairs[i].split('=');
        if (kv.length < 2) {
            continue;
        }
        var key = decodeURIComponent(kv[0] || '');
        if (key !== 'paged') {
            continue;
        }
        var value = decodeURIComponent(kv[1] || '');
        if (/^[0-9]+$/.test(value)) {
            return value;
        }
        return '';
    }
    return '';
}
