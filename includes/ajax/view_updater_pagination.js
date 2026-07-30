/**
 * Progressive ajaxUpdatePaginationLinks orchestrator.
 *
 * Foreground actions issue independent table, counts, and pagination requests
 * in priority order, with at most one database-bound request in flight. Each
 * response updates its own DOM section. The transport module owns finite
 * transient retry; this module owns visible state, last-write-wins protection,
 * loading overlay lifetime, callbacks, and background detect-only telemetry.
 *
 * Globals defined: paginationLinksChange, abj404CollapseEmptyPaginationStrips.
 */

function paginationLinksChange(triggerItem, options) {
    options = options || {};
    var req = abj404BuildPaginationRequest(triggerItem, options);
    if (req === null) {
        return;
    }

    if (req.isDetectOnlyBackground && isDetectOnlyRefreshInFlight()) {
        if (typeof options.onComplete === 'function') {
            options.onComplete({ hasUpdate: false, skipped: true });
        }
        return;
    }
    if (req.isDetectOnlyBackground) {
        setDetectOnlyRefreshInFlight(true);
    }
    if (!req.isBackgroundRefresh) {
        window.abj404LatestForegroundRequestId = req.requestId;
    }
    abj404RecordPaginationRequestStart(req);

    if (req.isDetectOnlyBackground) {
        abj404RunDetectOnlyPaginationRequest(req, options);
        return;
    }
    if (req.isBackgroundRefresh) {
        if (typeof options.onComplete === 'function') {
            options.onComplete({ skippedReplace: true });
        }
        return;
    }

    hideRefreshAvailablePill();
    var removeLoadingOverlay = abj404ShowPaginationLoadingOverlay(req);
    abj404RunProgressivePaginationRequest(req, options, removeLoadingOverlay);
}

/** @param {object} req @returns {void} */
function abj404RecordPaginationRequestStart(req) {
    if (!window.abj404BackgroundRefreshState || !req.isBackgroundRefresh) {
        return;
    }
    var state = window.abj404BackgroundRefreshState;
    state.requestCount = (state.requestCount || 0) + 1;
    state.lastSubpage = req.subpage;
    state.lastAction = req.action;
    state.lastRowsPerPage = parseInt(req.rowsPerPage, 10) || 0;
    state.lastFilterTextLength = (req.filterText || '').length;
    state.lastError = null;
    state.lastStatusCode = null;
    state.lastResponseBytes = null;
    state.hasUpdateAvailable = false;
}

/**
 * Show one request-owned table overlay and return its safe remover.
 *
 * @param {object} req
 * @returns {function(): void}
 */
function abj404ShowPaginationLoadingOverlay(req) {
    var $table = jQuery(req.tableSelector);
    if (!$table.parent().hasClass('abj404-table-wrapper')) {
        $table.wrap('<div class="abj404-table-wrapper"></div>');
    }
    var $wrapper = $table.parent();
    $wrapper.find('.abj404-loading-overlay').remove();
    $wrapper.append(
        '<div class="abj404-loading-overlay" data-abj404-request-id="' + req.requestId + '">' +
        '<div class="abj404-spinner-container"><div class="abj404-spinner"></div></div></div>'
    );
    return function() {
        $wrapper.find('.abj404-loading-overlay').each(function() {
            var $overlay = jQuery(this);
            if ($overlay.attr('data-abj404-request-id') === req.requestId) {
                $overlay.fadeOut(200, function() { jQuery(this).remove(); });
            }
        });
    };
}

/**
 * @param {object} req
 * @param {object} options
 * @param {function(): void} removeLoadingOverlay
 * @returns {void}
 */
function abj404RunProgressivePaginationRequest(req, options, removeLoadingOverlay) {
    var parts = Array.isArray(req.parts) ? req.parts : ['table', 'counts', 'pagination'];
    var runPart = function(partIndex) {
        if (partIndex >= parts.length || abj404PaginationRequestIsSuperseded(req)) {
            return;
        }
        var part = parts[partIndex];
        abj404RequestPaginationPart(req, part, {
            shouldAbort: function() { return abj404PaginationRequestIsSuperseded(req); },
            onAbort: function(abortedPart) {
                if (abortedPart === 'table') {
                    removeLoadingOverlay();
                    if (typeof options.onComplete === 'function') {
                        options.onComplete({ skippedReplace: true, superseded: true });
                    }
                }
            },
            onSuccess: function(result, successfulPart) {
                var responseApplied = false;
                try {
                    responseApplied = abj404HandlePaginationPartSuccess(
                        req, options, successfulPart, result
                    );
                } finally {
                    if (successfulPart === 'table') {
                        // Loading state is infrastructure owned by this
                        // orchestrator. Guarantee cleanup around every
                        // fallible response-application callback.
                        removeLoadingOverlay();
                    }
                }
                if (responseApplied) {
                    runPart(partIndex + 1);
                }
            },
            onTerminalError: function(jqXHR, textStatus, errorThrown, failedPart, retryCount) {
                try {
                    abj404HandlePaginationPartFailure(
                        req, options, failedPart, jqXHR, textStatus, errorThrown, retryCount
                    );
                } finally {
                    if (failedPart === 'table') {
                        // Error presentation, diagnostics, and extension
                        // callbacks are all fallible boundaries.
                        removeLoadingOverlay();
                    }
                }
                runPart(partIndex + 1);
            }
        });
    };
    runPart(0);
}

/** @param {object} req @returns {boolean} */
function abj404PaginationRequestIsSuperseded(req) {
    return typeof window.abj404LatestForegroundRequestId === 'string' &&
        window.abj404LatestForegroundRequestId !== req.requestId;
}

/**
 * @param {object} req
 * @param {object} options
 * @param {string} part
 * @param {object} result
 * @returns {boolean} Whether the response was applied and lower-priority parts may continue.
 */
function abj404HandlePaginationPartSuccess(req, options, part, result) {
    jQuery('.abj404-refresh-status').text('');
    if (abj404PaginationRequestIsSuperseded(req)) {
        if (part === 'table') {
            if (typeof options.onComplete === 'function') {
                options.onComplete({ skippedReplace: true, superseded: true });
            }
        }
        return false;
    }
    try {
        abj404ApplyPaginationPartResponse(part, result);
    } catch (applicationError) {
        var cause = applicationError && applicationError.message
            ? applicationError.message : String(applicationError);
        if (window.console && typeof window.console.error === 'function') {
            window.console.error('404 Solution: client response application failed', {
                part: part,
                requestId: req.requestId,
                error: applicationError
            });
        }
        abj404HandlePaginationPartFailure(
            req,
            options,
            part,
            {
                status: 200,
                responseJSON: {
                    success: false,
                    data: { message: 'Client response application failed: ' + cause }
                }
            },
            'clienterror',
            cause,
            0
        );
        return false;
    }
    if (part !== 'table') {
        return true;
    }

    if (typeof options.onComplete === 'function') {
        options.onComplete();
    }
    if (typeof triggerBackgroundTableRefreshIfEnabled === 'function') {
        window.abj404InitialTableRefreshTriggered = false;
        window.setTimeout(function() { triggerBackgroundTableRefreshIfEnabled(); }, 0);
    }
    return true;
}

/**
 * @param {object} req
 * @param {object} options
 * @param {string} part
 * @param {object} jqXHR
 * @param {string} textStatus
 * @param {string} errorThrown
 * @returns {void}
 */
function abj404HandlePaginationPartFailure(
    req, options, part, jqXHR, textStatus, errorThrown, retryCount
) {
    jQuery('.abj404-refresh-status').text('');
    if (part === 'pagination') {
        abj404CollapseEmptyPaginationStrips();
    }
    var errorCtx = {
        baseUrl: req.baseUrl,
        action: req.action,
        subpage: req.subpage,
        part: part,
        requestId: req.requestId,
        retryCount: retryCount,
        isBackgroundRefresh: false,
        requestStartedAt: req.requestStartedAt,
        ajaxTimeoutMs: req.ajaxTimeoutMs,
        attemptTimeline: abj404PaginationAttemptTimeline(req)
    };
    var parsed = abj404HandlePaginationAjaxError(errorCtx, jqXHR, textStatus, errorThrown);
    if (part !== 'table') {
        return;
    }
    abj404MaybeRunCanaryLadderAfterTableFailure(req);
    if (typeof options.onError === 'function') {
        options.onError(abj404PaginationErrorMeta(req, parsed, textStatus, errorThrown));
    }
}

/**
 * Fire the adaptive canary ladder (Bruno matrix req. 7) after the FIRST
 * foreground table failure in a session; the ladder's own cooldown (at most
 * once per hour) makes every later failure in the same hour a no-op call.
 * Fire-and-forget: the ladder runs on its own timeline and must never delay
 * or affect the error notice the admin sees for the failure that triggered
 * it.
 *
 * @param {object} req
 * @returns {void}
 */
function abj404MaybeRunCanaryLadderAfterTableFailure(req) {
    if (!window.abj404CanaryLadder || typeof window.abj404CanaryLadder.maybeTrigger !== 'function') {
        return;
    }
    window.abj404CanaryLadder.maybeTrigger({
        baseUrl: req.baseUrl,
        nonce: req.nonce,
        subpage: req.subpage,
        requestId: req.requestId,
        concurrentControlEvidence: req.concurrentControlEvidence || null
    });
}

/**
 * Browser-side timeline of every attempt made for this request, one compact
 * line each. Empty when the telemetry module did not load.
 *
 * @param {object} req
 * @returns {Array<string>}
 */
function abj404PaginationAttemptTimeline(req) {
    if (!window.abj404TransportTelemetryDelivery) {
        return [];
    }
    return window.abj404TransportTelemetryDelivery.timelineLines(req.requestId);
}

/** @returns {object} */
function abj404PaginationErrorMeta(req, parsed, textStatus, errorThrown) {
    var inferred = abj404AjaxStageDiagnostics(parsed.stageFromServer, req.subpage);
    return {
        attemptTimeline: abj404PaginationAttemptTimeline(req),
        status: parsed.status,
        textStatus: textStatus,
        errorThrown: errorThrown,
        message: parsed.messageFromServer,
        action: req.action,
        subpage: req.subpage,
        elapsedMs: Date.now() - req.requestStartedAt, // allow-direct-time: elapsed time reported to the admin error callback
        timeoutMs: req.ajaxTimeoutMs,
        stage: parsed.stageFromServer,
        queryLabel: parsed.queryLabelFromServer || inferred.queryLabel,
        whatsHappening: parsed.whatsHappeningFromServer || inferred.whatsHappening,
        lastQueryRedacted: parsed.lastQueryRedacted,
        requestId: req.requestId,
        retryCount: parsed.retryCount
    };
}

/** @param {object} req @param {object} options @returns {void} */
function abj404RunDetectOnlyPaginationRequest(req, options) {
    abj404RequestPaginationPart(req, 'table', {
        onSuccess: function(result) {
            setDetectOnlyRefreshInFlight(false);
            var hasUpdate = result && typeof result.hasUpdate === 'boolean'
                ? !!result.hasUpdate
                : hasBackgroundRefreshUpdateWithBaseline(result, req.baselineComparison);
            if (typeof options.onComplete === 'function') {
                options.onComplete({ hasUpdate: hasUpdate });
            }
            abj404FinishPaginationBackgroundTelemetry(req, 200, result, '', hasUpdate);
        },
        onTerminalError: function(jqXHR, textStatus, errorThrown, part, retryCount) {
            setDetectOnlyRefreshInFlight(false);
            var errorCtx = {
                baseUrl: req.baseUrl, action: req.action, subpage: req.subpage,
                part: 'table', isBackgroundRefresh: true,
                requestId: req.requestId, retryCount: retryCount,
                requestStartedAt: req.requestStartedAt, ajaxTimeoutMs: req.ajaxTimeoutMs,
                attemptTimeline: abj404PaginationAttemptTimeline(req)
            };
            var parsed = abj404HandlePaginationAjaxError(errorCtx, jqXHR, textStatus, errorThrown);
            if (typeof options.onError === 'function') {
                options.onError(abj404PaginationErrorMeta(req, parsed, textStatus, errorThrown));
            }
            abj404FinishPaginationBackgroundTelemetry(
                req, parsed.status || 0, null, textStatus || errorThrown || 'ajax-error', false
            );
        }
    });
}

/** @returns {void} */
function abj404FinishPaginationBackgroundTelemetry(req, status, result, error, hasUpdate) {
    if (!window.abj404BackgroundRefreshState) {
        return;
    }
    var resultSize = 0;
    if (result) {
        try {
            resultSize = JSON.stringify(result).length;
        } catch (serializationError) {
            console.warn('404 Solution: could not measure background response size', serializationError);
        }
    }
    var state = window.abj404BackgroundRefreshState;
    state.finishedAt = Date.now(); // allow-direct-time: browser telemetry completion timestamp
    state.durationMs = Date.now() - req.requestStartedAt; // allow-direct-time: browser telemetry duration
    state.difference = state.durationMs;
    state.lastStatusCode = status || null;
    state.lastResponseBytes = resultSize;
    state.lastError = error || null;
    state.hasUpdateAvailable = !!hasUpdate;
}

/**
 * Hide placeholder-only pagination strips after their part exhausts retries.
 * Existing real controls remain visible.
 *
 * @returns {void}
 */
function abj404CollapseEmptyPaginationStrips() {
    jQuery('.abj404-pagination').each(function() {
        var $strip = jQuery(this);
        if ($strip.find('.pagination-links, .abj404-page-btn').length === 0) {
            $strip.hide();
        }
    });
}
