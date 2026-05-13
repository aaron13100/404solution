/**
 * Table initial-load and background-refresh orchestration.
 *
 * Owns the entry-point flows that fire on page ready (or after a force
 * rebuild completes):
 *
 *   - triggerInitialTableLoadIfNeeded: hydrates the placeholder rows on
 *     first paint via paginationLinksChange. Falls through to the staged
 *     view-build poller when the server reports `viewBuildPending`.
 *   - triggerBackgroundTableRefreshIfEnabled: detect-only refresh that
 *     never overwrites the visible table, only flips the "Refresh
 *     available" pill when content has changed.
 *   - refreshHealthBarIfNeeded: hydrates the redirects-page health bar
 *     via its own AJAX call so the slow getHighImpactCapturedCount()
 *     query never blocks the table render.
 *
 * Also exposes the shared refresh-status host lookup and the AJAX
 * failure-details formatter consumed by the pagination error handler.
 *
 * Globals defined: abj404FormatAjaxFailureDetails, refreshHealthBarIfNeeded,
 * getRefreshStatusHost, isDetectOnlyRefreshInFlight, setDetectOnlyRefreshInFlight,
 * triggerBackgroundTableRefreshIfEnabled, triggerInitialTableLoadIfNeeded.
 *
 * Depends on view_updater.js (abj404UpdateAjaxDebugLog, getURLParameter,
 * paginationLinksChange, isElementFullyVisible), view_updater_table_warmup.js
 * (startViewBuildPollingThenRetry, startPlaceholderTableHydration),
 * view_updater_toast.js (showRefreshAvailablePill, showRefreshToastStart,
 * showRefreshToastComplete, hideRefreshToast), view_updater_stats.js
 * (markAutoRefreshCompleted, shouldRunAutoRefreshNow).
 */

function abj404FormatAjaxFailureDetails(meta) {
    meta = meta || {};
    var elapsed = parseInt(meta.elapsedMs, 10);
    var timeout = parseInt(meta.timeoutMs, 10);
    var lines = [
        'What was happening: ' + (meta.whatsHappening || 'Updating table data'),
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
        // Endpoint config missing: drop the placeholder so the page is usable.
        $bar.removeAttr('data-health-bar-placeholder');
        $bar.empty();
        return;
    }

    $bar.attr('data-health-bar-loading', '1');

    abj404UpdateAjaxDebugLog('Starting Health Bar AJAX: ' + action);

    var healthBarAjaxRunner = (typeof abj404AjaxWithNonceRetry === 'function')
        ? abj404AjaxWithNonceRetry : jQuery.ajax;
    healthBarAjaxRunner({
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
                    // allow-em-dash: visible em-dash separator preserved verbatim from the original health-bar copy
                    jQuery('<span>').text(active + ' redirects active — ' + high + ' captured URLs have repeat visitors').html() +
                    ' <a href="?page=' + (getURLParameter('page') || 'abj404_solution') + '&subpage=abj404_captured&filter=' +
                    (result.statusCounts._capturedFilter || '') + '">View</a>';
            }
            $bar.html(html);
            $bar.removeAttr('data-health-bar-placeholder');
        },
        error: function(jqXHR, textStatus, errorThrown) {
            // On error, drop the placeholder so the UI doesn't get stuck on
            // "Loading status..." forever.  The failure has already been logged
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

    // allow-em-dash: visible horizontal-ellipsis preserved verbatim from the original toast text
    var startedText = $config.attr('data-pagination-refresh-started-text') || 'Refreshing data in background…';
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
                if (meta && meta.viewBuildPending) {
                    // Cold start: the staged view_done table is missing or
                    // invalidated. Poll the bounded build-advance endpoint
                    // (one resumable tick per call) and retry the fetch when
                    // the build reports ready.  No HTTP 500 path can fire
                    // here: the fetch endpoint never builds inline.
                    startViewBuildPollingThenRetry(perpageElements[0], $config, attemptNumber);
                    return;
                }
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
                // Replace the "Loading..." cell text with a concrete error state so
                // the page no longer appears stuck. Stripping the attribute alone
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
