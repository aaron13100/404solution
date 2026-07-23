/**
 * What the browser's own performance timeline says happened to one request.
 *
 * The server can prove what PHP did. Only the browser can say whether the
 * request ever left the connection queue, how long DNS/TCP/TLS took, whether
 * response headers arrived and the body then stalled, how many bytes actually
 * crossed the wire, and (workerStart) whether a service worker INTERCEPTED
 * this request rather than merely existing on the page. That is a distinct
 * question from "what did this attempt do", which is why it lives here rather
 * than inside the attempt-record module that consumes it.
 *
 * Matched on the attempt id placed in the request's query string, so the match
 * stays exact with several table requests in flight against one endpoint.
 *
 * Globals defined: abj404ClientResourceTiming.
 */
(function (global, abj404Module) {
    if (global.abj404ClientBuildRegistry) {
        global.abj404ClientBuildRegistry.register('resource_timing', abj404Module);
    }
    abj404Module(global);
}(typeof window !== 'undefined' ? window : this, /* abj404-client-module:start */ function (global) {
    'use strict';

    /** @param {string} message @param {*} error @returns {void} */
    function warn(message, error) {
        if (global.console && global.console.warn) {
            global.console.warn('404 Solution: ' + message, error);
        }
    }

    /**
     * Two decimal places, or null when the browser did not supply a usable
     * number. Null rather than 0, because a zero phase boundary is meaningful
     * (no redirect, no service worker) and must not be confused with "the
     * browser declined to tell us".
     *
     * @param {*} value
     * @returns {number|null}
     */
    function round(value) {
        return typeof value === 'number' && isFinite(value) ? Math.round(value * 100) / 100 : null;
    }

    /** @param {*} value @param {number} fallback @returns {number} */
    function numberOr(value, fallback) {
        return typeof value === 'number' && isFinite(value) ? value : fallback;
    }

    /**
     * The connection-phase and body-size timeline for the attempt whose id
     * appears in the resource entry's URL.
     *
     * @param {string} attemptId
     * @returns {{state: string, timing: object|null}} state is
     *     unsupported | found | missing | error, so an absent timeline is a
     *     stated fact rather than an empty object.
     */
    function forAttempt(attemptId) {
        try {
            if (!global.performance || typeof global.performance.getEntriesByType !== 'function') {
                return { state: 'unsupported', timing: null };
            }
            var entries = global.performance.getEntriesByType('resource');
            for (var i = entries.length - 1; i >= 0; i--) {
                if (String(entries[i].name || '').indexOf(attemptId) < 0) {
                    continue;
                }
                return { state: 'found', timing: {
                    workerStart: round(entries[i].workerStart),
                    redirectStart: round(entries[i].redirectStart),
                    redirectEnd: round(entries[i].redirectEnd),
                    fetchStart: round(entries[i].fetchStart),
                    domainLookupStart: round(entries[i].domainLookupStart),
                    domainLookupEnd: round(entries[i].domainLookupEnd),
                    connectStart: round(entries[i].connectStart),
                    secureConnectionStart: round(entries[i].secureConnectionStart),
                    connectEnd: round(entries[i].connectEnd),
                    requestStart: round(entries[i].requestStart),
                    responseStart: round(entries[i].responseStart),
                    responseEnd: round(entries[i].responseEnd),
                    duration: round(entries[i].duration),
                    responseStatus: numberOr(entries[i].responseStatus, -1),
                    transferSize: numberOr(entries[i].transferSize, -1),
                    encodedBodySize: numberOr(entries[i].encodedBodySize, -1),
                    decodedBodySize: numberOr(entries[i].decodedBodySize, -1),
                    nextHopProtocol: String(entries[i].nextHopProtocol || '')
                } };
            }
            return { state: 'missing', timing: null };
        } catch (timingError) {
            warn('could not read resource timing for the table request', timingError);
            return { state: 'error', timing: null };
        }
    }

    global.abj404ClientResourceTiming = {
        forAttempt: forAttempt,
        round: round,
        numberOr: numberOr
    };
} /* abj404-client-module:end */));
