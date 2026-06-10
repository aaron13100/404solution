/**
 * Bounded view-build advance polling.
 *
 * abj404PollViewBuildAdvance polls the bounded ajaxAdvanceViewBuild endpoint,
 * which performs at most one resumable build tick per call (10s/stage budget,
 * yields mid-stage on S2/S4/S5). The fetch endpoint never builds inline, so
 * this poller is the only path that advances a cold-start build from the
 * browser side.
 *
 * Globals defined: abj404PollViewBuildAdvance.
 *
 * Depends on view_updater.js (abj404UpdateAjaxDebugLog) and
 * view_updater_nonce_refresh.js (abj404AjaxWithNonceRetry).
 */

/**
 * Poll the bounded ajaxAdvanceViewBuild endpoint until the staged view_done
 * table is ready (status === 'ready') or the budget is exhausted. Each call
 * runs at most one resumable build tick (10s/stage budget; yields mid-stage
 * on S2/S4/S5).  The fetch endpoint never builds inline, so this poller is
 * the only path that advances a cold-start build from the browser side.
 *
 * config (all optional except baseUrl + nonce):
 *   - baseUrl:   admin-ajax.php URL.
 *   - nonce:     `abj404_fetchInflightStage` nonce (reused for advance).
 *   - requestId: in-flight stage tracking id for the build-advance request.
 *   - subpage:   used by the status-line message lookup.
 *   - intervalMs: poll cadence; default 1000ms.
 *   - noProgressDeadlineMs: how long to keep polling without observing
 *       any forward progress before giving up; default 240000 (4 min).
 *       Replaces the prior fixed maxAttempts cap (~4 min): on large
 *       installs (Bruno's 484K rows, May 2026) the build legitimately
 *       takes longer than that, so the cap was firing while the build
 *       was still progressing. The new contract: as long as the server
 *       returns a different `progress.fingerprint` within the window,
 *       keep polling; only give up when no progress has been observed
 *       for the whole window. 240s and not 60s because a single S2/S4/S5
 *       batch can take 60 to 120s on slow shared hosts and the high-water
 *       counter only advances at end-of-batch; doubling that gives a
 *       comfortable margin before declaring the build genuinely stuck.
 *   - maxAttempts: optional absolute safety-net cap; default 0 (unbounded
 *       so long as progress is observed within noProgressDeadlineMs).
 *   - onProgress(progress): called after every tick.
 *   - onReady(progress):    called once when status === 'ready'.
 *   - onError(meta):        called if the endpoint returns 5xx or hits
 *                           the no-progress deadline.
 *
 * Returns a `stop()` function the caller can invoke to cancel polling
 * (e.g. when the admin navigates away).
 */
function abj404PollViewBuildAdvance(config) {
    config = config || {};
    if (!config.baseUrl || !config.nonce) {
        if (typeof config.onError === 'function') {
            config.onError({lastError: 'Missing baseUrl or nonce'});
        }
        return function() {};
    }
    var stopped = false;
    var attemptCount = 0;
    // maxAttempts default 0 = unbounded. Callers can still pass a safety-net
    // cap if they want one, but the no-progress-deadline below is the real
    // contract.
    var maxAttempts = parseInt(config.maxAttempts, 10) || 0;
    var noProgressDeadlineMs = parseInt(config.noProgressDeadlineMs, 10) || 240000;
    var intervalMs = parseInt(config.intervalMs, 10) || 1000;
    var onProgress = (typeof config.onProgress === 'function') ? config.onProgress : function() {};
    var onReady = (typeof config.onReady === 'function') ? config.onReady : function() {};
    var onError = (typeof config.onError === 'function') ? config.onError : function() {};
    var stop = function() { stopped = true; };
    // forceViewRebuild is sent only on the first advance call so the server
    // invalidates view_done exactly once. Re-sending it on subsequent calls
    // would reset build progress mid-rebuild and cause stages to repeat.
    var sendForceViewRebuild = (config.forceViewRebuild === true);

    // Progress-fingerprint tracking: the server returns a `fingerprint`
    // object in `progress` that mutates whenever the build advances
    // (started_at / current_stage / s{2,4,5}_high_water). We give up only
    // when this string-serialized fingerprint stays unchanged for the
    // full noProgressDeadlineMs window.
    var lastFingerprintKey = '';
    var lastProgressTickAtMs = Date.now(); // allow-direct-time: no-progress deadline anchor; preserved verbatim from view_updater_build_advance.js pre-i353 split

    var serializeFingerprint = function(progress) {
        if (!progress || typeof progress !== 'object') { return ''; }
        var fp = progress.fingerprint;
        if (!fp || typeof fp !== 'object') {
            // Fall back to coarser fields if the server didn't send fingerprint
            // (older server, mocked test, etc). stage alone advances rarely on
            // S2/S4/S5 so this fallback is best-effort only.
            return [progress.stage || 0, progress.build_started || 0].join('|');
        }
        return [
            fp.started_at || 0,
            fp.current_stage || 0,
            fp.s2_high_water || 0,
            fp.s4_high_water || 0,
            fp.s5_high_water || 0
        ].join('|');
    };

    var fireOnce = function() {
        if (stopped) {
            return;
        }
        attemptCount++;
        // Optional absolute safety-net cap. Off by default (0); kept as an
        // escape hatch for callers that want one (tests, etc).
        if (maxAttempts > 0 && attemptCount > maxAttempts) {
            stopped = true;
            onError({
                lastError: 'View build advance exceeded ' + maxAttempts + ' attempts',
                attemptCount: attemptCount
            });
            return;
        }
        // No-progress deadline. The build is presumed stuck (worker died,
        // database deadlock, GET_LOCK held by a dead session, etc.) when
        // the fingerprint hasn't changed in noProgressDeadlineMs.
        var sinceLastProgressMs = Date.now() - lastProgressTickAtMs; // allow-direct-time: no-progress deadline check; preserved verbatim from view_updater_build_advance.js pre-i353 split
        if (sinceLastProgressMs > noProgressDeadlineMs) {
            stopped = true;
            onError({
                lastError: 'View build advance made no progress for '
                    + Math.round(sinceLastProgressMs / 1000) + 's '
                    + '(deadline ' + Math.round(noProgressDeadlineMs / 1000) + 's)',
                attemptCount: attemptCount,
                noProgressDeadlineMs: noProgressDeadlineMs,
                sinceLastProgressMs: sinceLastProgressMs
            });
            return;
        }

        var requestData = {
            action: 'ajaxAdvanceViewBuild',
            nonce: config.nonce,
            page: config.page || '',
            subpage: config.subpage || '',
            requestId: config.requestId || ''
        };
        if (sendForceViewRebuild) {
            requestData.forceViewRebuild = '1';
            sendForceViewRebuild = false;
        }
        var advanceAjaxRunner = (typeof abj404AjaxWithNonceRetry === 'function')
            ? abj404AjaxWithNonceRetry : jQuery.ajax; // ajax-direct-approved: documented fallback when view_updater_nonce_refresh.js is not yet loaded; canonical pattern in every view_updater_*.js dispatch site, preserved verbatim from view_updater_build_advance.js pre-i353 split
        // Use the callback-style success/error keys (not .done()/.fail() on
        // the returned jqXHR) so the B20 expired-nonce retry wrapper can
        // intercept the 403 before the user's handler sees the failure.
        advanceAjaxRunner({
            url: config.baseUrl,
            type: 'POST',
            dataType: 'json',
            timeout: 30000,
            data: requestData,
            success: function(result) {
                if (stopped) {
                    return;
                }
                var progress = (result && result.progress) ? result.progress : {};
                var status = (result && typeof result.status === 'string') ? result.status : '';
                // Update no-progress deadline tracking BEFORE checking ready/locked.
                var fingerprintKey = serializeFingerprint(progress);
                if (fingerprintKey !== '' && fingerprintKey !== lastFingerprintKey) {
                    lastFingerprintKey = fingerprintKey;
                    lastProgressTickAtMs = Date.now(); // allow-direct-time: refresh no-progress anchor on observed forward progress; preserved verbatim from view_updater_build_advance.js pre-i353 split
                }
                // Visible status text is owned by abj404StartStageProgressPolling,
                // which reads the inflight transient and shows the live mid-stage
                // detail (batch X/Y, yielded in N ms). Writing here from
                // progress.stage (a snapshot of the lagging current_stage option)
                // raced with that poller and made the displayed stage flicker
                // backwards when a stage was yielding mid-batch.
                abj404UpdateAjaxDebugLog('View build advance: ' + (progress.progress_text || ''), {
                    status: status,
                    stage: progress.stage,
                    of: progress.of,
                    build_started: progress.build_started,
                    fingerprint: progress.fingerprint || null,
                    attemptCount: attemptCount
                });
                onProgress(progress);
                if (status === 'ready') {
                    stopped = true;
                    onReady(progress);
                    return;
                }
                if (progress.locked === true) {
                    window.setTimeout(fireOnce, (parseInt(config.lockedIntervalMs, 10) || 3500) + Math.floor(Math.random() * 750)); // allow-direct-random: jittered locked-retry backoff; preserved verbatim from view_updater_build_advance.js pre-i353 split
                    return;
                }
                window.setTimeout(fireOnce, intervalMs);
            },
            error: function(jqXHR, textStatus, errorThrown) {
                if (stopped) {
                    return;
                }
                // 4xx is terminal: nonce expired (already handled by the
                // B20 retry wrapper), auth lost, route gone. Retrying these
                // will never succeed and surfacing the error promptly is the
                // correct UX. 5xx and network errors (status 0) are treated
                // as a no-progress tick: the build itself may be fine on
                // the next request, and the existing noProgressDeadlineMs
                // (240s) already catches a genuinely-stuck server. This
                // keeps a single transient blip from killing the poll loop
                // after the user has been waiting through a long build.
                var status = jqXHR && jqXHR.status ? jqXHR.status : 0;
                var isTransient = (status === 0) || (status >= 500 && status < 600);
                if (!isTransient) {
                    stopped = true;
                    onError({
                        status: status,
                        textStatus: textStatus,
                        errorThrown: errorThrown,
                        lastError: textStatus || errorThrown || 'ajax-error',
                        attemptCount: attemptCount
                    });
                    return;
                }
                abj404UpdateAjaxDebugLog('View build advance transient AJAX failure (continuing)', {
                    status: status,
                    textStatus: textStatus,
                    attemptCount: attemptCount
                });
                window.setTimeout(fireOnce, intervalMs);
            }
        });
    };

    fireOnce();
    return stop;
}
