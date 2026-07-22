/**
 * Bounded transport for one ajaxUpdatePaginationLinks response part.
 *
 * Sends a table, counts, or pagination request with the descriptor's client
 * deadline. Only transport-level failures are retryable: timeout, status 0
 * network failure, or an unstructured 5xx response. Permission, validation,
 * rate-limit, and structured server errors are terminal. The fixed retry
 * schedule contains two entries, so every part has at most three attempts.
 *
 * Every attempt carries its own ledger identity (a unique attempt id in the
 * POST body, the query string, and the X-ABJ404-Request-ID header, plus the
 * previous attempt's id as retryParentId) and its own browser-side transport
 * record. The previous attempt's record rides the next attempt's params, so
 * the server holds the client's view of a failure even when the admin never
 * sends a support request.
 *
 * Globals defined: abj404RequestPaginationPart.
 *
 * Depends on view_updater_transport_telemetry.js (attempt records).
 */

/**
 * @param {object} jqXHR
 * @returns {boolean}
 */
function abj404PaginationResponseHasStructuredError(jqXHR) {
    var responseBody = jqXHR && jqXHR.responseJSON;
    if (!responseBody || typeof responseBody !== 'object') {
        return false;
    }
    return responseBody.success === false || typeof responseBody.error !== 'undefined' ||
        typeof responseBody.message !== 'undefined' || typeof responseBody.data !== 'undefined';
}

/**
 * @param {object} jqXHR
 * @param {string} textStatus
 * @returns {boolean}
 */
function abj404PaginationFailureIsTransient(jqXHR, textStatus) {
    if (abj404PaginationResponseHasStructuredError(jqXHR)) {
        return false;
    }
    var status = jqXHR && typeof jqXHR.status === 'number' ? jqXHR.status : 0;
    if (textStatus === 'timeout') {
        return true;
    }
    return status === 0 || status >= 500;
}

/**
 * The attempt recorder, or an inert stand-in when it did not load. A missing
 * diagnostic asset must never be able to stop the table from loading.
 *
 * @returns {object}
 */
function abj404PaginationTelemetry() {
    if (window.abj404TransportTelemetry) {
        return window.abj404TransportTelemetry;
    }
    return {
        beginAttempt: function(ctx) {
            return { id: '', rid: (ctx && ctx.requestId) || '', outcome: 'pending' };
        },
        // Returning undefined is deliberate: jQuery.extend skips undefined
        // option values, so jQuery falls back to its own transport factory
        // exactly as if no xhr option had been supplied.
        xhrFactory: function() { return undefined; },
        finishAttempt: function(record) { return record; }
    };
}

/**
 * The telemetry delivery channels, or an inert stand-in. Same rule: an admin
 * whose diagnostics did not load still gets their table.
 *
 * @returns {object}
 */
function abj404PaginationTelemetryDelivery() {
    if (window.abj404TransportTelemetryDelivery) {
        return window.abj404TransportTelemetryDelivery;
    }
    return {
        priorReportParam: function() { return ''; },
        sendBeacon: function() { return false; },
        timelineLines: function() { return []; }
    };
}

/**
 * Request URL for one attempt. The attempt id also travels in the query
 * string so a proxy, CDN, or host access log records which browser attempt a
 * given origin request was, and so PerformanceResourceTiming entries can be
 * matched to their attempt by name.
 *
 * @param {object} req
 * @param {string} part
 * @param {number} attemptIndex
 * @param {string} attemptRequestId
 * @returns {string}
 */
function abj404PaginationAttemptUrl(req, part, attemptIndex, attemptRequestId) {
    if (attemptRequestId === '') {
        return req.baseUrl;
    }
    return req.baseUrl + '?requestId=' + encodeURIComponent(attemptRequestId) +
        '&part=' + encodeURIComponent(part) + '&retryCount=' + encodeURIComponent(String(attemptIndex));
}

/**
 * POST body for one attempt: the shared payload plus this attempt's ledger
 * fields and the prior attempt's telemetry.
 *
 * @param {object} req
 * @param {string} part
 * @param {number} attemptIndex
 * @param {object} record
 * @param {string} parentAttemptId
 * @returns {object}
 */
function abj404PaginationAttemptData(req, part, attemptIndex, record, parentAttemptId) {
    var data = jQuery.extend({}, req.payload, {
        part: part,
        retryCount: attemptIndex,
        clientSentAt: String(record.sentAt || Date.now()) // allow-direct-time: ledger field paired server-side with REQUEST_TIME_FLOAT
    });
    if (record.id) {
        data.requestId = record.id;
    }
    if (record.sid) {
        data.sessionId = record.sid;
    }
    if (parentAttemptId) {
        data.retryParentId = parentAttemptId;
    }
    if (record.build) {
        data.clientBuild = record.build;
    }
    if (typeof record.inflightAtSend === 'number') {
        data.clientInflight = String(record.inflightAtSend);
    }
    if (Array.isArray(record.inflightIdsAtSend) && record.inflightIdsAtSend.length > 0) {
        data.clientInflightIds = record.inflightIdsAtSend.join(',').slice(0, 250);
    }
    var priorReport = abj404PaginationTelemetryDelivery().priorReportParam();
    if (priorReport !== '') {
        data.clientReport = priorReport;
    }
    return data;
}

/**
 * Execute one progressive response part with finite transient retry.
 *
 * @param {object} req Descriptor from abj404BuildPaginationRequest.
 * @param {string} part One of table, counts, pagination.
 * @param {object} callbacks Lifecycle callbacks.
 * @returns {void}
 */
function abj404RequestPaginationPart(req, part, callbacks) {
    callbacks = callbacks || {};
    var retryDelays = Array.isArray(req.retryDelaysMs) ? req.retryDelaysMs : [];
    var telemetry = abj404PaginationTelemetry();
    var ajaxRunner = (typeof abj404AjaxWithNonceRetry === 'function')
        ? abj404AjaxWithNonceRetry : jQuery.ajax; // ajax-direct-approved: fallback when the nonce-refresh module has not loaded

    var sendAttempt = function(attemptIndex, parentAttemptId) {
        if (typeof callbacks.shouldAbort === 'function' && callbacks.shouldAbort()) {
            if (typeof callbacks.onAbort === 'function') {
                callbacks.onAbort(part);
            }
            return;
        }
        var settled = false;
        var record = telemetry.beginAttempt({
            requestId: req.requestId,
            part: part,
            attemptIndex: attemptIndex,
            parentAttemptId: parentAttemptId || '',
            subpage: req.subpage,
            timeoutMs: req.ajaxTimeoutMs
        });
        ajaxRunner({
            url: abj404PaginationAttemptUrl(req, part, attemptIndex, record.id),
            type: 'POST',
            dataType: 'json',
            timeout: req.ajaxTimeoutMs,
            data: abj404PaginationAttemptData(req, part, attemptIndex, record, parentAttemptId),
            xhr: telemetry.xhrFactory(record),
            beforeSend: function(jqXHR) {
                if (record.id && jqXHR && typeof jqXHR.setRequestHeader === 'function') {
                    jqXHR.setRequestHeader('X-ABJ404-Request-ID', record.id);
                }
            },
            success: function(result, textStatus, jqXHR) {
                settled = true;
                telemetry.finishAttempt(record, 'success', jqXHR, textStatus);
                if (typeof callbacks.onSuccess === 'function') {
                    callbacks.onSuccess(result, part, attemptIndex);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                settled = true;
                telemetry.finishAttempt(record, abj404PaginationOutcome(textStatus), jqXHR, textStatus);
                var canRetry = abj404PaginationFailureIsTransient(jqXHR, textStatus) &&
                    attemptIndex < retryDelays.length;
                if (canRetry) {
                    window.setTimeout(function() {
                        sendAttempt(attemptIndex + 1, record.id);
                    }, retryDelays[attemptIndex]);
                    return;
                }
                // Final attempt for this part: try the supplemental beacon so
                // the record has a second chance to reach the server even if
                // the admin closes the tab. It may share the failing network
                // path, which is why it is never the only channel.
                abj404PaginationTelemetryDelivery().sendBeacon(req.baseUrl, record, req.nonce);
                if (typeof callbacks.onTerminalError === 'function') {
                    callbacks.onTerminalError(jqXHR, textStatus, errorThrown, part, attemptIndex);
                }
            },
            complete: function(jqXHR, textStatus) {
                if (!settled) {
                    telemetry.finishAttempt(record, 'abort', jqXHR, textStatus || 'abort');
                    if (typeof callbacks.onTerminalError === 'function') {
                        callbacks.onTerminalError(jqXHR || {}, textStatus || 'abort', 'request incomplete', part, attemptIndex);
                    }
                }
            }
        });
    };

    sendAttempt(0, '');
}

/**
 * Map jQuery's textStatus onto the record's outcome vocabulary.
 *
 * @param {string} textStatus
 * @returns {string}
 */
function abj404PaginationOutcome(textStatus) {
    if (textStatus === 'timeout' || textStatus === 'abort' || textStatus === 'parsererror') {
        return textStatus;
    }
    return 'error';
}
