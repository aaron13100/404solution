/**
 * Adaptive canary ladder (Bruno timeout cause matrix, coverage req. 7).
 *
 * The server flight recorder and the client transport telemetry can each
 * prove what happened on their own side of a failed table request, but
 * neither can prove which EXTERNAL system between them is responsible:
 * browser/network, Cloudflare, LiteSpeed/LVE admission, WordPress boot, the
 * rate limiter, the real query path, response size, compression, or output
 * buffering. After a foreground table failure, this runs ordered probes plus
 * a concurrent control. Armed pre-releases also interleave a fixed baseline
 * so host time trends are not attributed to step order.
 *
 * Rate-limited to at most one ladder run per hour per browser by
 * view_updater_canary_cooldown.js. Zero new visible UI: the ladder runs silently and its
 * results land in the server flight-recorder journal via
 * ajaxRunCanaryStep, joined back to the failure that triggered it through
 * the ordinary retryParentId chain every attempt already carries.
 *
 * Each step is its own POST, so the server already has independent trace
 * evidence that every step's PHP execution happened. The one thing only the
 * browser can supply is whether each step's RESPONSE actually arrived, and
 * that confirmation rides the NEXT step's request through the measurement
 * module's relay (the same route ClientTransportReport uses for table
 * requests). It used to be bundled solely into the final `interpret`
 * POST, which meant one lost request -- a hang on the very host under
 * diagnosis, a closed tab, an interrupted script -- erased the receipt side
 * of the evidence for the entire ladder at once.
 *
 * Order: static asset; auth; limiter; summary; server size lookup; geometric
 * matched compressible/incompressible probes; inert size; compression
 * on/off; stream; then interpret. Pre-releases place a 1 KB baseline after
 * each measured control step. The static asset is the only step that
 * bypasses PHP.
 *
 * Globals defined: abj404CanaryLadder.
 *
 * Depends on view_updater_canary_cooldown.js (cooldown gate),
 * view_updater_client_telemetry_env.js (session id), and
 * view_updater_transport_telemetry.js (the real request's observed byte
 * size fallback), plus view_updater_canary_measurements.js (wire/decoded
 * evidence, payload rungs, and receipt relay). Degrades to doing nothing if
 * any required control module, or jQuery itself, did not load -- a missing
 * diagnostic module must never affect the table the admin is trying to use.
 */
(function (global, $, abj404Module) {
    if (global.abj404ClientBuildRegistry) {
        global.abj404ClientBuildRegistry.register('canary_ladder', abj404Module);
    }
    abj404Module(global, $);
}(typeof window !== 'undefined' ? window : this, typeof jQuery !== 'undefined' ? jQuery : null,
    /* abj404-client-module:start */ function (global, $) {
    'use strict';

    if (!$) {
        return;
    }

    var ACTION = 'ajaxRunCanaryStep';
    var COOLDOWN_MS = 60 * 60 * 1000;
    var STATIC_ASSET_TIMEOUT_MS = 10000;
    var STEP_TIMEOUT_MS = 15000;
    var BASELINE_BYTES = 1024;

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
    function canaryCooldown() {
        return global.abj404CanaryCooldown || null;
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

    /** @returns {boolean} */
    function baselineControlEnabled() {
        return !!(global.ABJ404 && global.ABJ404.detachAbDiagnosticEnabled === true);
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

    /** @returns {object|null} */
    function measurements() {
        var module = global.abj404CanaryMeasurements;
        return module && typeof module.createReceiptRelay === 'function'
            && typeof module.interpretationInput === 'function' ? module : null;
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
            var finish = function (ok, bytes, xhr) {
                if (settled) {
                    return;
                }
                settled = true;
                resolve($.extend({
                    ok: ok,
                    ms: nowMs() - started,
                    bytes: bytes || 0
                }, measurements().responseWireEvidence(cacheBuster, xhr || null)));
            };
            try {
                var xhr = new global.XMLHttpRequest();
                var sep = url.indexOf('?') >= 0 ? '&' : '?';
                xhr.open('GET', url + sep + 'cb=' + encodeURIComponent(cacheBuster), true);
                xhr.timeout = STATIC_ASSET_TIMEOUT_MS;
                xhr.addEventListener('load', function () {
                    finish(xhr.status >= 200 && xhr.status < 300, (xhr.responseText || '').length, xhr);
                });
                xhr.addEventListener('error', function () { finish(false, 0, xhr); });
                xhr.addEventListener('timeout', function () { finish(false, 0, xhr); });
                xhr.send();
            } catch (fetchError) {
                warn('static asset canary could not start', fetchError);
                finish(false, 0, null);
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
            var settle = function (ok, result, textStatus, jqXHR) {
                var observation = $.extend({
                    ok: ok,
                    ms: nowMs() - started,
                    startedAt: started,
                    endedAt: nowMs(),
                    bytes: result ? JSON.stringify(result).length : 0,
                    requestId: requestId,
                    textStatus: textStatus || '',
                    payloadVariant: String((result && result.payloadVariant)
                        || (extra && extra.payloadVariant) || ''),
                    payloadRungPercent: result && typeof result.payloadRungPercent === 'number'
                        ? result.payloadRungPercent
                        : (extra && typeof extra.payloadRungPercent === 'number'
                            ? extra.payloadRungPercent : -1),
                    targetBytes: result && typeof result.targetBytes === 'number'
                        ? result.targetBytes
                        : (extra && typeof extra.payloadBytes === 'number' ? extra.payloadBytes : -1),
                    targetBytesSource: String((result && result.targetBytesSource)
                        || (extra && extra.targetBytesSource) || ''),
                    result: result || null
                }, measurements().responseWireEvidence(requestId, jqXHR || null));
                if (relay) {
                    relay.settled(ok);
                    relay.hold(step, observation);
                }
                resolve(observation);
            };
            ajaxRunner()({
                url: measurements().requestUrl(resolveAjaxUrl(ctx.baseUrl), requestId),
                type: 'POST',
                dataType: 'json',
                timeout: STEP_TIMEOUT_MS,
                data: data,
                success: function (result, textStatus, jqXHR) {
                    settle(!!(result && result.canaryStep === step && result.success !== false),
                        result, textStatus || 'success', jqXHR);
                },
                error: function (jqXHR, textStatus) {
                    settle(false, null, textStatus, jqXHR);
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
        if (!measurements()) {
            return Promise.resolve({ ok: false, requestId: '', textStatus: 'diagnostic-unavailable' });
        }
        return postStep(ctx, 'concurrent_control', {
            controlForRequestId: ctx.requestId || ''
        }, ctx.requestId || '').catch(function (controlError) {
            warn('concurrent canary control failed', controlError);
            return { ok: false, requestId: '', textStatus: 'diagnostic-error' };
        });
    }

    /**
     * Run the matched geometric probes serially through the same incremental
     * receipt relay as every other ladder step.
     *
     * @param {function(string, object, string): Promise<object>} post
     * @param {object} observations
     * @param {{bytes: number, source: string}} target
     * @param {string} parentId
     * @returns {Promise<{requestId: string, target: object}>}
     */
    function postSizeProbes(post, observations, target, parentId) {
        observations.size_probe = [];
        return measurements().sizeProbePlan(target).reduce(function (chain, probe) {
            return chain.then(function (previousId) {
                return post('size_probe', probe, previousId).then(function (result) {
                    observations.size_probe.push(result);
                    return result.requestId;
                });
            });
        }, Promise.resolve(parentId)).then(function (lastRequestId) {
            return { requestId: lastRequestId, target: target };
        });
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
        var relay = measurements().createReceiptRelay();
        var post = function (step, extra, parentId) {
            return postStep(ctx, step, extra, parentId, relay);
        };
        post.hold = relay.hold;
        return post;
    }

    /** Run a measured step plus its gated fixed baseline, preserving the request chain. */
    function postMeasuredStep(post, observations, step, extra, parentId, baselineOrdinal) {
        return post(step, extra, parentId).then(function (result) {
            observations[step] = result;
            if (!baselineControlEnabled()) {
                return { result: result, requestId: result.requestId };
            }
            return post('baseline_control', { payloadBytes: BASELINE_BYTES,
                baselineOrdinal: baselineOrdinal }, result.requestId).then(function (baselineResult) {
                observations.baseline_control = observations.baseline_control || [];
                observations.baseline_control.push(baselineResult);
                return { result: result, requestId: baselineResult.requestId };
            });
        });
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
        if (!measurements()) {
            warn('canary measurement module is unavailable', null);
            return Promise.resolve({});
        }
        var ladderRunId = mintId();
        var observations = {};
        var post = ladderPoster(ctx);

        var concurrentResult = concurrentControlObservation(ctx);
        var firstStep;
        if (concurrentResult && typeof concurrentResult.then === 'function') {
            firstStep = concurrentResult.then(function (resolvedControl) {
                observations.concurrent_control = resolvedControl;
                return runStaticAssetCanary(ladderRunId);
            });
        } else {
            observations.concurrent_control = concurrentResult;
            firstStep = runStaticAssetCanary(ladderRunId);
        }
        return firstStep
            .then(function (staticResult) {
                observations.static_asset = staticResult;
                // The one step with no request of its own to be reported on,
                // so its receipt is handed to the relay directly.
                post.hold('static_asset', staticResult);
                return postMeasuredStep(post, observations, 'auth_only', {}, ctx.requestId || '', 0);
            })
            .then(function (authStage) {
                return postMeasuredStep(post, observations, 'post_limiter', {}, authStage.requestId, 1);
            })
            .then(function (limiterStage) {
                return postMeasuredStep(post, observations, 'summary', {}, limiterStage.requestId, 2);
            })
            .then(function (summaryStage) {
                return postMeasuredStep(post, observations, 'size_target', {},
                    summaryStage.requestId, 3);
            })
            .then(function (targetStage) {
                var target = measurements().targetPayload(ctx, observations);
                return postSizeProbes(post, observations, target, targetStage.requestId);
            })
            .then(function (sizeStage) {
                return postMeasuredStep(post, observations, 'inert', {
                    payloadBytes: sizeStage.target.bytes,
                    targetBytesSource: sizeStage.target.source
                }, sizeStage.requestId, 4).then(function (inertStage) {
                    return { target: sizeStage.target, requestId: inertStage.requestId };
                });
            })
            .then(function (inertStage) {
                return postMeasuredStep(post, observations, 'compress_on',
                    { payloadBytes: inertStage.target.bytes,
                        targetBytesSource: inertStage.target.source },
                    inertStage.requestId, 5).then(function (onStage) {
                    return { target: inertStage.target, requestId: onStage.requestId };
                });
            })
            .then(function (onStage) {
                return postMeasuredStep(post, observations, 'compress_off',
                    { payloadBytes: onStage.target.bytes,
                        targetBytesSource: onStage.target.source },
                    onStage.requestId, 6);
            })
            .then(function (offStage) {
                return postMeasuredStep(post, observations, 'stream', {}, offStage.requestId, 7);
            })
            .then(function (streamStage) {
                // The full observation set still rides interpret: the
                // interpretation matrix needs every step at once. The
                // per-step receipts already delivered above are the
                // durable floor under it, not a replacement for it.
                return post('interpret', {
                    observations: JSON.stringify(measurements().interpretationInput(observations)),
                    realRequestFailed: '1'
                }, streamStage.requestId);
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
     * The completed same-phase control evidence supplied by pagination
     * transport. Its promise resolves after both the first table attempt and
     * its control settle, so interpretation cannot race either callback.
     *
     * @param {object} ctx
     * @returns {Promise<object>|object}
     */
    function concurrentControlObservation(ctx) {
        var evidence = ctx && ctx.concurrentControlEvidence;
        if (evidence && typeof evidence.then === 'function') {
            return evidence.catch(function (evidenceError) {
                warn('concurrent control evidence could not be read', evidenceError);
                return {
                    kind: 'concurrent_control_browser_receipt',
                    outcome: 'error',
                    tableOutcome: 'unknown',
                    receipt: { ok: false, textStatus: 'diagnostic-error' },
                    overlap: { state: 'unavailable', reason: 'evidence_error', durationMs: null }
                };
            });
        }
        return {
            kind: 'concurrent_control_browser_receipt',
            outcome: 'unavailable',
            tableOutcome: 'unknown',
            receipt: { ok: false, textStatus: 'diagnostic-unavailable' },
            overlap: { state: 'unavailable', reason: 'evidence_module_unavailable', durationMs: null }
        };
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
            var cooldown = canaryCooldown();
            if (!cooldown || typeof cooldown.eligible !== 'function' || typeof cooldown.markRan !== 'function') {
                return false;
            }
            var now = nowMs();
            if (!cooldown.eligible(now, COOLDOWN_MS)) {
                return false;
            }
            cooldown.markRan(now);
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
} /* abj404-client-module:end */));
