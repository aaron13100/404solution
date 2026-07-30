/**
 * Measurement primitives for the adaptive canary ladder.
 *
 * Owns the browser-only evidence contract: response encoding/body sizes,
 * geometric target planning, and the incremental receipt relay. Request
 * sequencing stays in view_updater_canary_ladder.js.
 *
 * Globals defined: abj404CanaryMeasurements.
 */
(function (global, abj404Module) {
    if (global.abj404ClientBuildRegistry) {
        global.abj404ClientBuildRegistry.register('canary_measurements', abj404Module);
    }
    abj404Module(global);
}(typeof window !== 'undefined' ? window : this, /* abj404-client-module:start */ function (global) {
    'use strict';

    var DEFAULT_TARGET_BYTES = 50000;
    var MAX_PENDING_RECEIPTS = 24;
    var MAX_TEXT_STATUS_CHARS = 32;

    /** @param {string} message @param {*} error @returns {void} */
    function warn(message, error) {
        if (global.console && global.console.warn) {
            global.console.warn('404 Solution: ' + message, error);
        }
    }

    /** @param {string} requestId @param {object|null} jqXHR @returns {object} */
    function responseWireEvidence(requestId, jqXHR) {
        var timingResult = { state: 'unsupported', timing: null };
        try {
            var resourceTiming = global.abj404ClientResourceTiming;
            if (resourceTiming && typeof resourceTiming.forAttempt === 'function') {
                timingResult = resourceTiming.forAttempt(requestId);
            }
        } catch (timingError) {
            warn('could not read canary response resource timing', timingError);
            timingResult = { state: 'error', timing: null };
        }
        var timing = timingResult && timingResult.timing ? timingResult.timing : {};
        var contentEncoding = '';
        try {
            contentEncoding = jqXHR && typeof jqXHR.getResponseHeader === 'function'
                ? String(jqXHR.getResponseHeader('Content-Encoding') || '') : '';
        } catch (headerError) {
            warn('could not read canary response Content-Encoding', headerError);
        }
        return {
            contentEncoding: contentEncoding.slice(0, MAX_TEXT_STATUS_CHARS),
            transferBytes: typeof timing.transferSize === 'number' ? timing.transferSize : -1,
            encodedBodyBytes: typeof timing.encodedBodySize === 'number' ? timing.encodedBodySize : -1,
            decodedBodyBytes: typeof timing.decodedBodySize === 'number' ? timing.decodedBodySize : -1,
            resourceTimingState: String(timingResult.state || 'unsupported')
        };
    }

    /** @param {string} step @param {object} observation @returns {object} */
    function stepReceipt(step, observation) {
        observation = observation || {};
        return {
            step: step,
            requestId: typeof observation.requestId === 'string' ? observation.requestId : '',
            ok: !!observation.ok,
            ms: typeof observation.ms === 'number' ? Math.round(observation.ms) : -1,
            bytes: typeof observation.bytes === 'number' ? observation.bytes : -1,
            textStatus: String(observation.textStatus || '').slice(0, MAX_TEXT_STATUS_CHARS),
            contentEncoding: String(observation.contentEncoding || '').slice(0, MAX_TEXT_STATUS_CHARS),
            transferBytes: typeof observation.transferBytes === 'number' ? observation.transferBytes : -1,
            encodedBodyBytes: typeof observation.encodedBodyBytes === 'number' ? observation.encodedBodyBytes : -1,
            decodedBodyBytes: typeof observation.decodedBodyBytes === 'number' ? observation.decodedBodyBytes : -1,
            resourceTimingState: String(observation.resourceTimingState || 'unsupported'),
            payloadVariant: String(observation.payloadVariant || ''),
            payloadRungPercent: typeof observation.payloadRungPercent === 'number'
                ? observation.payloadRungPercent : -1,
            targetBytes: typeof observation.targetBytes === 'number' ? observation.targetBytes : -1,
            targetBytesSource: String(observation.targetBytesSource || '')
        };
    }

    /**
     * Keep receipts until a later request demonstrably returns after carrying
     * them. A lost carrier leaves its receipts pending for the next request.
     *
     * @returns {object}
     */
    function createReceiptRelay() {
        var pending = [];
        var carried = 0;
        return {
            hold: function (step, observation) {
                pending.push(stepReceipt(step, observation));
                while (pending.length > MAX_PENDING_RECEIPTS) {
                    pending.shift();
                }
            },
            attach: function (data) {
                carried = pending.length;
                if (carried > 0) {
                    data.canaryStepReceipts = JSON.stringify(pending);
                }
            },
            settled: function (delivered) {
                if (delivered) {
                    pending = pending.slice(carried);
                }
                carried = 0;
            }
        };
    }

    /**
     * Project raw probe responses onto the server interpretation contract.
     * Full responses can contain megabytes of filler; the server parser is
     * deliberately bounded because each step is already journaled separately.
     *
     * @param {object} observations
     * @returns {object}
     */
    function interpretationInput(observations) {
        var compact = {};
        [
            'static_asset', 'auth_only', 'post_limiter', 'summary', 'inert',
            'compress_on', 'compress_off', 'stream'
        ].forEach(function(step) {
            var raw = observations[step] || {};
            compact[step] = {
                ok: raw.ok === true,
                ms: typeof raw.ms === 'number' ? raw.ms : -1
            };
            if (step === 'stream' && typeof raw.gapMs === 'number') {
                compact[step].gapMs = raw.gapMs;
            }
        });
        compact.baseline_control = (observations.baseline_control || []).map(function(raw) {
            return {
                ok: raw && raw.ok === true,
                ms: raw && typeof raw.ms === 'number' ? raw.ms : -1
            };
        });
        var concurrent = observations.concurrent_control || {};
        compact.concurrent_control = {
            tableOutcome: String(concurrent.tableOutcome || ''),
            receipt: {ok: !!(concurrent.receipt && concurrent.receipt.ok === true)},
            overlap: {
                state: String((concurrent.overlap && concurrent.overlap.state) || ''),
                durationMs: concurrent.overlap && typeof concurrent.overlap.durationMs === 'number'
                    ? concurrent.overlap.durationMs : null
            }
        };
        return compact;
    }

    /** @param {string} resolvedUrl @param {string} requestId @returns {string} */
    function requestUrl(resolvedUrl, requestId) {
        var separator = resolvedUrl.indexOf('?') >= 0 ? '&' : '?';
        return resolvedUrl + separator + 'requestId=' + encodeURIComponent(requestId);
    }

    /**
     * @param {object} ctx
     * @param {object} observations
     * @returns {{bytes: number, source: string}}
     */
    function targetPayload(ctx, observations) {
        var targetResult = observations && observations.size_target && observations.size_target.result;
        if (targetResult && typeof targetResult.realResponseBytes === 'number'
                && targetResult.realResponseBytes > 0) {
            return {
                bytes: targetResult.realResponseBytes,
                source: String(targetResult.realResponseBytesSource || 'session_json_encode')
            };
        }
        try {
            var telemetry = global.abj404TransportTelemetry;
            if (!telemetry || typeof telemetry.attemptsFor !== 'function') {
                return { bytes: DEFAULT_TARGET_BYTES, source: 'default_unavailable' };
            }
            var attempts = telemetry.attemptsFor(ctx.requestId);
            if (!attempts.length) {
                return { bytes: DEFAULT_TARGET_BYTES, source: 'default_unavailable' };
            }
            var bytes = attempts[attempts.length - 1].bytes;
            return (typeof bytes === 'number' && bytes > 0)
                ? { bytes: bytes, source: 'browser_response' }
                : { bytes: DEFAULT_TARGET_BYTES, source: 'default_unavailable' };
        } catch (readError) {
            warn('could not read the real response size for the canary ladder', readError);
            return { bytes: DEFAULT_TARGET_BYTES, source: 'default_unavailable' };
        }
    }

    /** @param {{bytes: number, source: string}} target @returns {Array<object>} */
    function sizeProbePlan(target) {
        var plan = [];
        var seen = {};
        [25, 50, 100].forEach(function (percent, index) {
            var bytes = Math.min(2000000, Math.max(64, Math.round(target.bytes * percent / 100)));
            var effectivePercent = Math.min(100, Math.max(1, Math.round(bytes * 100 / target.bytes)));
            var variants = index % 2 === 0
                ? ['compressible', 'incompressible']
                : ['incompressible', 'compressible'];
            variants.forEach(function (variant) {
                var key = bytes + '|' + variant;
                if (seen[key]) {
                    return;
                }
                seen[key] = true;
                plan.push({
                    payloadBytes: bytes,
                    payloadVariant: variant,
                    payloadRungPercent: effectivePercent,
                    targetBytesSource: target.source
                });
            });
        });
        return plan;
    }

    global.abj404CanaryMeasurements = {
        createReceiptRelay: createReceiptRelay,
        interpretationInput: interpretationInput,
        requestUrl: requestUrl,
        responseWireEvidence: responseWireEvidence,
        sizeProbePlan: sizeProbePlan,
        targetPayload: targetPayload
    };
} /* abj404-client-module:end */));
