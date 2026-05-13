/**
 * Stage progress polling and bounded view-build advance polling.
 *
 * Two cooperating loops drive cold-start view rebuilds from the browser:
 *
 *   - abj404StartStageProgressPolling: polls ajaxFetchInflightStage every 2.5s
 *     and updates the visible "Currently refreshing data (stage N, label)"
 *     status line. Read-only: never advances a build.
 *
 *   - abj404PollViewBuildAdvance: polls the bounded ajaxAdvanceViewBuild
 *     endpoint, which performs at most one resumable build tick per call
 *     (10s/stage budget, yields mid-stage on S2/S4/S5). The fetch endpoint
 *     never builds inline, so this poller is the only path that advances a
 *     cold-start build from the browser side.
 *
 * Multi-tab coordination lives here too: shared build state is keyed in
 * localStorage so a second admin tab follows the active build instead of
 * racing it (45s stale-detection window). pagehide listener releases
 * ownership when the owning tab navigates away.
 *
 * Globals defined: abj404StartStageProgressPolling, abj404PollViewBuildAdvance,
 * abj404CanUseSharedBuildCoordination, abj404ReadSharedBuildState,
 * abj404WriteSharedBuildState, abj404TryClaimSharedBuildOwner,
 * abj404RegisterReleaseSharedBuildOnUnload, abj404UpdateSharedBuildOwner,
 * abj404FollowSharedBuildThenRetry.
 *
 * Depends on view_updater_stage_diagnostics.js (abj404AjaxStageDiagnostics,
 * abj404FormatRefreshingStageMessage) and view_updater.js
 * (abj404UpdateAjaxDebugLog, paginationLinksChange).
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
        ? abj404AjaxWithNonceRetry : jQuery.ajax;
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
    var lastProgressTickAtMs = Date.now();

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
        var sinceLastProgressMs = Date.now() - lastProgressTickAtMs;
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
            ? abj404AjaxWithNonceRetry : jQuery.ajax;
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
                    lastProgressTickAtMs = Date.now();
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
                    window.setTimeout(fireOnce, (parseInt(config.lockedIntervalMs, 10) || 3500) + Math.floor(Math.random() * 750));
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

function abj404CanUseSharedBuildCoordination() {
    try {
        var key = 'abj404_coord_test';
        window.localStorage.setItem(key, '1');
        window.localStorage.removeItem(key);
        return true;
    } catch (e) {
        return false;
    }
}

function abj404ReadSharedBuildState() {
    if (!abj404CanUseSharedBuildCoordination()) {
        return null;
    }
    try {
        var raw = window.localStorage.getItem('abj404ViewBuildAdvanceState');
        return raw ? JSON.parse(raw) : null;
    } catch (e) {
        return null;
    }
}

function abj404WriteSharedBuildState(state) {
    if (!abj404CanUseSharedBuildCoordination()) {
        return;
    }
    try {
        window.localStorage.setItem('abj404ViewBuildAdvanceState', JSON.stringify(state || {}));
    // allow-silent-catch: storage best-effort coordination key; quota or disabled storage must not break refresh flow
    } catch (e) {}
}

function abj404TryClaimSharedBuildOwner(ownerId) {
    if (!abj404CanUseSharedBuildCoordination()) {
        return true;
    }
    var now = Date.now();
    var current = abj404ReadSharedBuildState();
    if (current && current.status === 'running' && current.ownerId && current.ownerId !== ownerId
            && (now - (parseInt(current.updatedAt, 10) || 0)) < 45000) {
        return false;
    }
    abj404WriteSharedBuildState({
        ownerId: ownerId,
        status: 'running',
        updatedAt: now,
        progressText: 'starting'
    });
    current = abj404ReadSharedBuildState();
    var claimed = !current || current.ownerId === ownerId;
    if (claimed) {
        abj404RegisterReleaseSharedBuildOnUnload(ownerId);
    }
    return claimed;
}

/**
 * Release the shared build owner state if THIS tab is still the owner when
 * the page is unloaded. Without this, a tab that claims ownership and then
 * navigates away (form submit, link click, browser back) leaves the
 * localStorage state stuck at status:running. The next tab, including the
 * same tab loading its next page, falls into abj404FollowSharedBuildThenRetry
 * and waits the full 45 s stale-detection window before claiming itself,
 * blocking the redirects-table placeholder for that whole period.
 *
 * pagehide is preferred over beforeunload: it fires for both bfcache and
 * full unloads, and unlike beforeunload it does not block the navigation
 * UX. localStorage writes inside pagehide handlers are honoured by all
 * browsers we support.
 *
 * Idempotent and per-claim: once:true guarantees the listener detaches
 * after firing, so repeated claims within one page lifetime do not stack
 * handlers. The owner-id check ensures we never clobber state that another
 * tab has since taken over.
 *
 * @param {string} ownerId
 * @returns {void}
 */
function abj404RegisterReleaseSharedBuildOnUnload(ownerId) {
    if (!abj404CanUseSharedBuildCoordination()) {
        return;
    }
    if (typeof window === 'undefined' || typeof window.addEventListener !== 'function') {
        return;
    }
    var release = function() {
        try {
            var current = abj404ReadSharedBuildState();
            if (current && current.ownerId === ownerId) {
                window.localStorage.removeItem('abj404ViewBuildAdvanceState');
            }
        } catch (e) {
            // allow-silent-catch: pagehide handlers cannot recover from
            // storage failures, and any throw here would be discarded by the
            // browser anyway. Best-effort release is the contract.
        }
    };
    window.addEventListener('pagehide', release, { once: true });
}

function abj404UpdateSharedBuildOwner(ownerId, status, progress) {
    if (!abj404CanUseSharedBuildCoordination()) {
        return;
    }
    abj404WriteSharedBuildState({
        ownerId: ownerId,
        status: status || 'running',
        updatedAt: Date.now(),
        stage: progress && progress.stage,
        of: progress && progress.of,
        progressText: progress && progress.progress_text
    });
}

function abj404FollowSharedBuildThenRetry(triggerItem, $config) {
    var startedAt = Date.now();
    var maxWaitMs = 300000;
    var poll = function() {
        var state = abj404ReadSharedBuildState();
        var updatedAt = state ? (parseInt(state.updatedAt, 10) || 0) : 0;
        var stale = !state || !updatedAt || (Date.now() - updatedAt) > 45000;
        if (state && state.status === 'ready') {
            window.abj404ViewBuildAdvanceRunning = false;
            paginationLinksChange(triggerItem, {
                backgroundRefresh: false,
                detectOnly: false,
                cacheMode: 'cache_or_pending',
                onComplete: function(meta) {
                    if (meta && meta.cachePending) {
                        startPlaceholderTableHydration(triggerItem);
                        return;
                    }
                    if ($config && $config.length > 0) {
                        $config.attr('data-pagination-initial-load', '0');
                    }
                },
                onError: function(errorMeta) {
                    if ($config && $config.length > 0) {
                        $config.attr('data-pagination-initial-load', '0');
                    }
                    showTableWarmupFailure(errorMeta || {});
                }
            });
            return;
        }
        if (stale) {
            window.abj404ViewBuildAdvanceRunning = false;
            startViewBuildPollingThenRetry(triggerItem, $config, 1);
            return;
        }
        if ((Date.now() - startedAt) > maxWaitMs) {
            window.abj404ViewBuildAdvanceRunning = false;
            showTableWarmupFailure({lastError: 'Timed out waiting for another tab to finish preparing redirects view.'});
            return;
        }
        if (state && state.progressText) {
            jQuery('.abj404-refresh-status').text('Preparing redirects view (' + state.progressText + ')');
        }
        window.setTimeout(poll, 1500 + Math.floor(Math.random() * 500));
    };
    poll();
}
