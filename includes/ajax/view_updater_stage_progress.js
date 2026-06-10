/**
 * Stage progress polling for cold-start view rebuilds.
 *
 * abj404StartStageProgressPolling polls ajaxFetchInflightStage every 2.5s and
 * updates the visible "Currently refreshing data (stage N, label)" status
 * line. Read-only: never advances a build (that is the job of
 * abj404PollViewBuildAdvance in view_updater_build_advance.js).
 *
 * Both the build-advance flow (view_updater_table_warmup.js) and the
 * pagination flow (view_updater_pagination.js) use this poller to surface
 * what the in-flight build is doing.
 *
 * Globals defined: abj404StartStageProgressPolling.
 *
 * Depends on view_updater_stage_diagnostics.js (abj404AjaxStageDiagnostics,
 * abj404FormatRefreshingStageMessage), view_updater.js
 * (abj404UpdateAjaxDebugLog), and view_updater_nonce_refresh.js
 * (abj404AjaxWithNonceRetry).
 */

function abj404StartStageProgressPolling(config) {
    config = config || {};
    if (!config.baseUrl || !config.nonce || !config.requestId) {
        return function() {};
    }
    var stopped = false;
    var seenStageEvents = {};
    var baseMessage = config.message || 'Currently refreshing data';
    var processStageResult = function(stageResult, allowUiUpdate) {
        if (!stageResult) {
            return;
        }
        var stageEvents = (stageResult && jQuery.isArray(stageResult.events)) ? stageResult.events : [];
        for (var i = 0; i < stageEvents.length; i++) {
            var event = stageEvents[i] || {};
            var eventStage = typeof event.stage === 'string' ? event.stage : '';
            if (!eventStage) {
                continue;
            }
            var eventTime = parseInt(event.timeMs || 0, 10) || 0;
            var eventKey = eventTime + ':' + eventStage + ':' + i;
            if (seenStageEvents[eventKey]) {
                continue;
            }
            seenStageEvents[eventKey] = true;
            var eventDiagnostics = abj404AjaxStageDiagnostics(eventStage, config.subpage || '');
            abj404UpdateAjaxDebugLog('Stage progress: ' + eventDiagnostics.whatsHappening, {
                stage: eventStage,
                queryLabel: event.queryLabel || eventDiagnostics.queryLabel || '',
                whatsHappening: event.whatsHappening || eventDiagnostics.whatsHappening || ''
            });
        }

        var stage = typeof stageResult.stage === 'string' ? stageResult.stage : '';
        var queryLabel = typeof stageResult.queryLabel === 'string' ? stageResult.queryLabel : '';
        // When the build finishes between two polls, the inflight transient's
        // top-level stage clears but the events list still records every
        // completed stage. Fall back to the last event so the visible label
        // still shows progress on fast builds (small sites, after-cache hits)
        // instead of being stuck on the "(...)" placeholder.
        if (!stage && !queryLabel && stageEvents.length > 0) {
            var lastEvent = stageEvents[stageEvents.length - 1];
            if (lastEvent && typeof lastEvent.stage === 'string') {
                stage = lastEvent.stage;
                queryLabel = (typeof lastEvent.queryLabel === 'string') ? lastEvent.queryLabel : '';
            }
        }
        if (allowUiUpdate && (stage || queryLabel)) {
            var message = abj404FormatRefreshingStageMessage(baseMessage, stage, queryLabel, config.subpage || '');
            jQuery('.abj404-refresh-status').text(message);

            var toast = document.getElementById('abj404-background-refresh-toast');
            if (toast) {
                var label = toast.querySelector('.abj404-refresh-label');
                if (label) {
                    label.textContent = message;
                }
            }
        }
    };
    var stageAjaxRunner = (typeof abj404AjaxWithNonceRetry === 'function')
        ? abj404AjaxWithNonceRetry : jQuery.ajax; // ajax-direct-approved: documented fallback when view_updater_nonce_refresh.js is not yet loaded; canonical pattern in every view_updater_*.js dispatch site, preserved verbatim from view_updater_build_advance.js pre-i353 split
    var updateStage = function(forceFinalFetch) {
        if (stopped && forceFinalFetch !== true) {
            return;
        }
        // Use the callback-style success/error keys (not .done()/.fail() on
        // the returned jqXHR) so the B20 expired-nonce retry wrapper can
        // intercept the 403 before the user's handler sees the failure.
        stageAjaxRunner({
            url: config.baseUrl,
            type: 'POST',
            dataType: 'json',
            timeout: 5000,
            data: {
                action: 'ajaxFetchInflightStage',
                nonce: config.nonce,
                requestId: config.requestId
            },
            success: function(stageResult) {
                processStageResult(stageResult, !stopped || forceFinalFetch === true);
            }
        });
    };
    jQuery('.abj404-refresh-status').text(baseMessage + ' (...)');
    updateStage(false);
    var intervalId = window.setInterval(updateStage, 2500);
    return function(flushFinalEvents) {
        stopped = true;
        window.clearInterval(intervalId);
        if (flushFinalEvents === true) {
            updateStage(true);
        }
    };
}
