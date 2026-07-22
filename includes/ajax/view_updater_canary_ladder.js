/**
 * Adaptive canary ladder (Bruno timeout cause matrix, coverage req. 7).
 *
 * The server flight recorder and the client transport telemetry can each
 * prove what happened on their own side of a failed table request, but
 * neither can prove which EXTERNAL system between them is responsible:
 * browser/network, Cloudflare, LiteSpeed/LVE admission, WordPress boot, the
 * rate limiter, the real query path, response size, compression, or output
 * buffering. After the first foreground table failure in a session, this
 * module launches one lightweight control beside the first real table
 * attempt, then runs seven ordered probes after a failure. The paired control
 * distinguishes request content from a transient that cleared before the
 * sequential ladder began.
 *
 * Rate-limited to at most one ladder run per hour per browser (the cooldown
 * lives in view_updater_client_telemetry_store.js, the one file allowed to
 * touch storage). Zero new visible UI: the ladder runs silently and its
 * results land in the server flight-recorder journal via
 * ajaxRunCanaryStep, joined back to the failure that triggered it through
 * the ordinary retryParentId chain every attempt already carries.
 *
 * Step order (step 1 never reaches PHP; steps 2-8 are ajaxRunCanaryStep):
 *   1. static_asset   - same-host 1KB static file, no PHP at all.
 *   2. auth_only      - boot + auth + delivery, bypasses the rate limiter.
 *   3. post_limiter   - identical, placed after a rate-limit check.
 *   4. summary        - the real table path's own DB work, tiny response.
 *   5. inert          - filler response of the real payload's byte size.
 *   6. compress_on/off - the same filler, with/without a no-transform hint.
 *   7. stream         - a flushed leading-whitespace block before the JSON.
 *   8. interpret      - journals the client-computed interpretation matrix.
 *
 * Globals defined: abj404CanaryLadder.
 *
 * Depends on view_updater_client_telemetry_store.js (cooldown gate),
 * view_updater_client_telemetry_env.js (session id), and
 * view_updater_transport_telemetry.js (the real request's observed byte
 * size, when available). Degrades to doing nothing if any of those, or
 * jQuery itself, did not load -- a missing diagnostic module must never
 * affect the table the admin is trying to use.
 */
(function (global, $) {
    'use strict';

    if (!$) {
        return;
    }

    var ACTION = 'ajaxRunCanaryStep';
    var COOLDOWN_MS = 60 * 60 * 1000;
    var STATIC_ASSET_TIMEOUT_MS = 10000;
    var STEP_TIMEOUT_MS = 15000;
    var DEFAULT_TARGET_BYTES = 50000;

    /** @param {string} message @param {*} error @returns {void} */
    function warn(message, error) {
        if (global.console && global.console.warn) {
            global.console.warn('404 Solution: ' + message, error);
        }
    }

    /** @returns {number} */
    function nowMs() {
        return Date.now(); // allow-direct-time: wall-clock cooldown/timing for a browser-only diagnostic, no server clock adapter applies
    }

    /** @returns {object|null} */
    function telemetryStore() {
        return global.abj404ClientTelemetryStore || null;
    }

    /** @returns {string} */
    function mintId() {
        if (typeof global.abj404GenerateRequestId === 'function') {
            return global.abj404GenerateRequestId();
        }
        return ('c' + nowMs().toString(36) + Math.floor(Math.random() * 1e9).toString(36)).slice(0, 32); // allow-direct-random: fallback id source only when the shared generator failed to load
    }

    /** @returns {string} */
    function sessionId() {
        return (global.abj404ClientTelemetryEnv && typeof global.abj404ClientTelemetryEnv.sessionId === 'function')
            ? global.abj404ClientTelemetryEnv.sessionId() : '';
    }

    /** @param {string} baseUrl @returns {string} */
    function resolveAjaxUrl(baseUrl) {
        if (typeof baseUrl === 'string' && baseUrl !== '') {
            return baseUrl;
        }
        if (typeof global.ajaxurl === 'string' && global.ajaxurl !== '') {
            return global.ajaxurl;
        }
        return '/wp-admin/admin-ajax.php';
    }

    /** @returns {function} */
    function ajaxRunner() {
        return (typeof global.abj404AjaxWithNonceRetry === 'function')
            ? global.abj404AjaxWithNonceRetry : $.ajax; // ajax-direct-approved: fallback matches the other view_updater transports when the nonce-retry module has not loaded
    }

    /**
     * Step 1: fetch the same-host static asset. No PHP, no admin-ajax
     * plumbing -- this is deliberately outside the request wrapper because
     * it is testing whether THAT wrapper's own path is even reachable.
     *
     * @param {string} cacheBuster
     * @returns {Promise<{ok: boolean, ms: number, bytes: number}>}
     */
    function runStaticAssetCanary(cacheBuster) {
        return new Promise(function (resolve) {
            var url = (global.ABJ404 && global.ABJ404.canaryStaticAssetUrl) || '';
            if (!url || typeof global.XMLHttpRequest !== 'function') {
                resolve({ ok: false, ms: 0, bytes: 0 });
                return;
            }
            var started = nowMs();
            var settled = false;
            var finish = function (ok, bytes) {
                if (settled) {
                    return;
                }
                settled = true;
                resolve({ ok: ok, ms: nowMs() - started, bytes: bytes || 0 });
            };
            try {
                var xhr = new global.XMLHttpRequest();
                var sep = url.indexOf('?') >= 0 ? '&' : '?';
                xhr.open('GET', url + sep + 'cb=' + encodeURIComponent(cacheBuster), true);
                xhr.timeout = STATIC_ASSET_TIMEOUT_MS;
                xhr.addEventListener('load', function () {
                    finish(xhr.status >= 200 && xhr.status < 300, (xhr.responseText || '').length);
                });
                xhr.addEventListener('error', function () { finish(false, 0); });
                xhr.addEventListener('timeout', function () { finish(false, 0); });
                xhr.send();
            } catch (fetchError) {
                warn('static asset canary could not start', fetchError);
                finish(false, 0);
            }
        });
    }

    /**
     * One ajaxRunCanaryStep POST. Always resolves (never rejects): a failed
     * canary is itself a result the interpretation matrix consumes, not an
     * error the ladder run should abort on.
     *
     * @param {object} ctx {baseUrl, nonce, subpage}
     * @param {string} step
     * @param {object} extra
     * @param {string} parentId
     * @returns {Promise<object>}
     */
    function postStep(ctx, step, extra, parentId) {
        return new Promise(function (resolve) {
            var requestId = mintId();
            var started = nowMs();
            var data = $.extend({
                action: ACTION,
                canaryStep: step,
                requestId: requestId,
                retryParentId: parentId || '',
                sessionId: sessionId(),
                nonce: ctx.nonce,
                subpage: ctx.subpage
            }, extra || {});
            var settle = function (ok, result, textStatus) {
                resolve({
                    ok: ok,
                    ms: nowMs() - started,
                    bytes: result ? JSON.stringify(result).length : 0,
                    requestId: requestId,
                    textStatus: textStatus || '',
                    result: result || null
                });
            };
            ajaxRunner()({
                url: resolveAjaxUrl(ctx.baseUrl),
                type: 'POST',
                dataType: 'json',
                timeout: STEP_TIMEOUT_MS,
                data: data,
                success: function (result) {
                    settle(!!(result && result.canaryStep === step && result.success !== false), result, 'success');
                },
                error: function (jqXHR, textStatus) {
                    settle(false, null, textStatus);
                }
            });
        });
    }

    /**
     * Launch one auth-only control beside the real table attempt. The real
     * attempt id is the retryParentId, so both server journals can be joined
     * even when either response never reaches the browser.
     *
     * @param {object} ctx {baseUrl, nonce, subpage, requestId}
     * @returns {Promise<object>}
     */
    function runConcurrentControl(ctx) {
        ctx = ctx || {};
        return postStep(ctx, 'concurrent_control', {
            controlForRequestId: ctx.requestId || ''
        }, ctx.requestId || '').catch(function (controlError) {
            warn('concurrent canary control failed', controlError);
            return { ok: false, requestId: '', textStatus: 'diagnostic-error' };
        });
    }

    /**
     * The most recently observed byte size of the REAL table response for
     * this request, when the transport telemetry module recorded one.
     * Falls back to a representative default so the size-comparison canaries
     * (inert, compress_on/off) still run meaningfully when the real request
     * never received any bytes at all -- exactly Bruno's symptom.
     *
     * @param {object} ctx
     * @returns {number}
     */
    function targetPayloadBytes(ctx) {
        try {
            var telemetry = global.abj404TransportTelemetry;
            if (!telemetry || typeof telemetry.attemptsFor !== 'function') {
                return DEFAULT_TARGET_BYTES;
            }
            var attempts = telemetry.attemptsFor(ctx.requestId);
            if (!attempts.length) {
                return DEFAULT_TARGET_BYTES;
            }
            var bytes = attempts[attempts.length - 1].bytes;
            return (typeof bytes === 'number' && bytes > 0) ? bytes : DEFAULT_TARGET_BYTES;
        } catch (readError) {
            warn('could not read the real response size for the canary ladder', readError);
            return DEFAULT_TARGET_BYTES;
        }
    }

    /**
     * Run the full ladder for one triggering context. Never throws; a
     * broken diagnostic module must never surface to the admin.
     *
     * @param {object} ctx {baseUrl, nonce, subpage, requestId}
     * @returns {Promise<object>} the assembled observations, for tests.
     */
    function runLadder(ctx) {
        ctx = ctx || {};
        var ladderRunId = mintId();
        var observations = {};

        return runStaticAssetCanary(ladderRunId)
            .then(function (staticResult) {
                observations.static_asset = staticResult;
                return postStep(ctx, 'auth_only', {}, ctx.requestId || '');
            })
            .then(function (authResult) {
                observations.auth_only = authResult;
                return postStep(ctx, 'post_limiter', {}, authResult.requestId);
            })
            .then(function (limiterResult) {
                observations.post_limiter = limiterResult;
                return postStep(ctx, 'summary', {}, limiterResult.requestId);
            })
            .then(function (summaryResult) {
                observations.summary = summaryResult;
                var bytes = targetPayloadBytes(ctx);
                return postStep(ctx, 'inert', { payloadBytes: bytes }, summaryResult.requestId)
                    .then(function (inertResult) {
                        observations.inert = inertResult;
                        return bytes;
                    });
            })
            .then(function (bytes) {
                return postStep(ctx, 'compress_on', { payloadBytes: bytes }, observations.inert.requestId)
                    .then(function (onResult) {
                        observations.compress_on = onResult;
                        return postStep(ctx, 'compress_off', { payloadBytes: bytes }, onResult.requestId);
                    });
            })
            .then(function (offResult) {
                observations.compress_off = offResult;
                return postStep(ctx, 'stream', {}, offResult.requestId);
            })
            .then(function (streamResult) {
                observations.stream = streamResult;
                return postStep(ctx, 'interpret', {
                    observations: JSON.stringify(observations),
                    realRequestFailed: '1'
                }, streamResult.requestId);
            })
            .then(function (interpretResult) {
                observations.interpret = interpretResult;
                return observations;
            })
            .catch(function (ladderError) {
                warn('canary ladder run failed', ladderError);
                return observations;
            });
    }

    /**
     * Start the ladder if the cooldown allows it. Marks the cooldown BEFORE
     * the first request goes out (not after the run finishes), so a burst
     * of near-simultaneous table/counts/pagination failures cannot each see
     * "eligible" and each start their own run.
     *
     * @param {object} ctx {baseUrl, nonce, subpage, requestId}
     * @returns {boolean} true when a ladder run was started.
     */
    function maybeTrigger(ctx) {
        try {
            var store = telemetryStore();
            if (!store || typeof store.canaryLadderEligible !== 'function' || typeof store.markCanaryLadderRan !== 'function') {
                return false;
            }
            var now = nowMs();
            if (!store.canaryLadderEligible(now, COOLDOWN_MS)) {
                return false;
            }
            store.markCanaryLadderRan(now);
            runLadder(ctx);
            return true;
        } catch (triggerError) {
            warn('could not start the canary ladder', triggerError);
            return false;
        }
    }

    global.abj404CanaryLadder = {
        maybeTrigger: maybeTrigger,
        runConcurrentControl: runConcurrentControl,
        runLadder: runLadder,
        COOLDOWN_MS: COOLDOWN_MS
    };
})(typeof window !== 'undefined' ? window : this, typeof jQuery !== 'undefined' ? jQuery : null);
