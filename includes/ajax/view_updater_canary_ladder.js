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
 * Each step is its own POST, so the server already has independent trace
 * evidence that every step's PHP execution happened. The one thing only the
 * browser can supply is whether each step's RESPONSE actually arrived, and
 * that confirmation rides the NEXT step's request (see createReceiptRelay --
 * the same "ride the next request" route ClientTransportReport uses for
 * table requests). It used to be bundled solely into the final `interpret`
 * POST, which meant one lost request -- a hang on the very host under
 * diagnosis, a closed tab, an interrupted script -- erased the receipt side
 * of the evidence for the entire ladder at once.
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

    /**
     * Ceiling on how many not-yet-delivered step receipts one request may
     * carry. A ladder run has nine steps, so this can only ever be reached by
     * a run whose requests keep failing -- exactly the case worth bounding,
     * and the bound is on RECORDS rather than on the serialized string so a
     * trimmed payload always arrives as valid JSON (the byte-slice shape gap
     * G1/GG removed from the other client channels).
     */
    var MAX_PENDING_RECEIPTS = 10;

    /** Longest transport status string kept on a receipt ('parsererror' etc). */
    var MAX_TEXT_STATUS_CHARS = 32;

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
     * The browser's receipt confirmation for one finished step, shaped for
     * the wire. Deliberately tiny and fixed-shape: it exists to answer "did
     * this step's response reach the browser, how long did it take, and how
     * many bytes arrived", which is precisely what no server-side trace can
     * say. The step's own payload is never included.
     *
     * @param {string} step
     * @param {object} observation the value postStep/runStaticAssetCanary resolved with.
     * @returns {object}
     */
    function stepReceipt(step, observation) {
        observation = observation || {};
        return {
            step: step,
            // The step's OWN server request id, so the receipt joins that
            // step's server-side trace group rather than the group of
            // whichever request happened to carry it.
            requestId: typeof observation.requestId === 'string' ? observation.requestId : '',
            ok: !!observation.ok,
            ms: typeof observation.ms === 'number' ? Math.round(observation.ms) : -1,
            bytes: typeof observation.bytes === 'number' ? observation.bytes : -1,
            textStatus: String(observation.textStatus || '').slice(0, MAX_TEXT_STATUS_CHARS)
        };
    }

    /**
     * Carries finished steps' receipts forward onto later requests until the
     * server has demonstrably received them.
     *
     * A receipt is only cleared once the request that carried it came BACK,
     * because a request that never returned is exactly the case where the
     * server may never have seen it. So an undelivered receipt simply rides
     * the next request instead of being dropped, and the ladder can lose any
     * single request without losing the evidence that request was carrying.
     *
     * @returns {object}
     */
    function createReceiptRelay() {
        var pending = [];
        var carried = 0;
        return {
            /**
             * Record one finished step's own outcome for later delivery.
             * @param {string} step @param {object} observation @returns {void}
             */
            hold: function (step, observation) {
                pending.push(stepReceipt(step, observation));
                while (pending.length > MAX_PENDING_RECEIPTS) {
                    pending.shift();
                }
            },
            /**
             * Attach everything still undelivered to an outgoing request.
             * @param {object} data @returns {void}
             */
            attach: function (data) {
                carried = pending.length;
                if (carried > 0) {
                    data.canaryStepReceipts = JSON.stringify(pending);
                }
            },
            /**
             * Resolve what the carrying request's outcome means for the
             * receipts it took with it.
             * @param {boolean} delivered whether that request came back.
             * @returns {void}
             */
            settled: function (delivered) {
                if (delivered) {
                    pending = pending.slice(carried);
                }
                carried = 0;
            }
        };
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
     * @param {object} [relay] the run's receipt relay, when this POST belongs
     *   to a ladder run. Omitted for the standalone concurrent control, which
     *   runs beside the real table attempt and has no preceding step.
     * @returns {Promise<object>}
     */
    function postStep(ctx, step, extra, parentId, relay) {
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
            if (relay) {
                relay.attach(data);
            }
            var settle = function (ok, result, textStatus) {
                var observation = {
                    ok: ok,
                    ms: nowMs() - started,
                    bytes: result ? JSON.stringify(result).length : 0,
                    requestId: requestId,
                    textStatus: textStatus || '',
                    result: result || null
                };
                if (relay) {
                    relay.settled(ok);
                    relay.hold(step, observation);
                }
                resolve(observation);
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
     * A step poster bound to one ladder run's receipt relay.
     *
     * Binding rather than passing the relay at each call site is what makes
     * the incremental reporting structural: every ladder request necessarily
     * goes through this one function, so a step added later cannot forget to
     * carry the previous step's receipt and silently reintroduce the
     * batched-until-interpret loss.
     *
     * @param {object} ctx {baseUrl, nonce, subpage, requestId}
     * @returns {function(string, object, string): Promise<object>} with a
     *   .hold(step, observation) for the one step that never reaches PHP.
     */
    function ladderPoster(ctx) {
        var relay = createReceiptRelay();
        var post = function (step, extra, parentId) {
            return postStep(ctx, step, extra, parentId, relay);
        };
        post.hold = relay.hold;
        return post;
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
        var post = ladderPoster(ctx);

        return runStaticAssetCanary(ladderRunId)
            .then(function (staticResult) {
                observations.static_asset = staticResult;
                // The one step with no request of its own to be reported on,
                // so its receipt is handed to the relay directly.
                post.hold('static_asset', staticResult);
                return post('auth_only', {}, ctx.requestId || '');
            })
            .then(function (authResult) {
                observations.auth_only = authResult;
                return post('post_limiter', {}, authResult.requestId);
            })
            .then(function (limiterResult) {
                observations.post_limiter = limiterResult;
                return post('summary', {}, limiterResult.requestId);
            })
            .then(function (summaryResult) {
                observations.summary = summaryResult;
                var bytes = targetPayloadBytes(ctx);
                return post('inert', { payloadBytes: bytes }, summaryResult.requestId)
                    .then(function (inertResult) {
                        observations.inert = inertResult;
                        return bytes;
                    });
            })
            .then(function (bytes) {
                return post('compress_on', { payloadBytes: bytes }, observations.inert.requestId)
                    .then(function (onResult) {
                        observations.compress_on = onResult;
                        return post('compress_off', { payloadBytes: bytes }, onResult.requestId);
                    });
            })
            .then(function (offResult) {
                observations.compress_off = offResult;
                return post('stream', {}, offResult.requestId);
            })
            .then(function (streamResult) {
                observations.stream = streamResult;
                // The full observation set still rides interpret: the
                // interpretation matrix needs every step at once. The
                // per-step receipts already delivered above are the
                // durable floor under it, not a replacement for it.
                return post('interpret', {
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
