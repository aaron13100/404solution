/**
 * Bounded transport for one ajaxUpdatePaginationLinks response part.
 *
 * Sends a table, counts, or pagination request with the descriptor's client
 * deadline. Only transport-level failures are retryable: timeout, status 0
 * network failure, or an unstructured 5xx response. Permission, validation,
 * rate-limit, and structured server errors are terminal. The fixed retry
 * schedule contains two entries, so every part has at most three attempts.
 *
 * Globals defined: abj404RequestPaginationPart.
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
    var ajaxRunner = (typeof abj404AjaxWithNonceRetry === 'function')
        ? abj404AjaxWithNonceRetry : jQuery.ajax; // ajax-direct-approved: fallback when the nonce-refresh module has not loaded

    var sendAttempt = function(attemptIndex) {
        if (typeof callbacks.shouldAbort === 'function' && callbacks.shouldAbort()) {
            if (typeof callbacks.onAbort === 'function') {
                callbacks.onAbort(part);
            }
            return;
        }
        var settled = false;
        ajaxRunner({
            url: req.baseUrl,
            type: 'POST',
            dataType: 'json',
            timeout: req.ajaxTimeoutMs,
            data: jQuery.extend({}, req.payload, { part: part, retryCount: attemptIndex }),
            success: function(result) {
                settled = true;
                if (typeof callbacks.onSuccess === 'function') {
                    callbacks.onSuccess(result, part, attemptIndex);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                settled = true;
                var canRetry = abj404PaginationFailureIsTransient(jqXHR, textStatus) &&
                    attemptIndex < retryDelays.length;
                if (canRetry) {
                    window.setTimeout(function() {
                        sendAttempt(attemptIndex + 1);
                    }, retryDelays[attemptIndex]);
                    return;
                }
                if (typeof callbacks.onTerminalError === 'function') {
                    callbacks.onTerminalError(jqXHR, textStatus, errorThrown, part, attemptIndex);
                }
            },
            complete: function(jqXHR, textStatus) {
                if (!settled && typeof callbacks.onTerminalError === 'function') {
                    callbacks.onTerminalError(jqXHR || {}, textStatus || 'abort', 'request incomplete', part, attemptIndex);
                }
            }
        });
    };

    sendAttempt(0);
}
