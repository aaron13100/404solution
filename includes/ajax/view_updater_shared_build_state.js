/**
 * Shared build owner coordination via the browser storage API.
 *
 * Multi-tab coordination for cold-start view rebuilds: shared build state is
 * keyed in browser-local storage so a second admin tab follows the active
 * build instead of racing it (45s stale-detection window). pagehide listener
 * releases ownership when the owning tab navigates away.
 *
 * abj404FollowSharedBuildThenRetry is the "another tab is already building"
 * branch: it polls the shared state and either resumes pagination once the
 * other tab signals ready, falls back to claiming on staleness, or surfaces
 * a timeout failure after 5 minutes.
 *
 * Globals defined: abj404CanUseSharedBuildCoordination,
 * abj404ReadSharedBuildState, abj404WriteSharedBuildState,
 * abj404TryClaimSharedBuildOwner, abj404RegisterReleaseSharedBuildOnUnload,
 * abj404UpdateSharedBuildOwner, abj404FollowSharedBuildThenRetry.
 *
 * Depends at call time on view_updater_pagination.js (paginationLinksChange)
 * and view_updater_table_warmup.js (startViewBuildPollingThenRetry,
 * startPlaceholderTableHydration, showTableWarmupFailure). Those globals are
 * looked up at call time rather than load time, so reverse-direction
 * references between warmup and shared-state are fine.
 */

function abj404CanUseSharedBuildCoordination() {
    try {
        var key = 'abj404_coord_test';
        window.localStorage.setItem(key, '1'); // allow-direct-storage: feature-detect browser storage; preserved verbatim from view_updater_build_advance.js pre-i353 split
        window.localStorage.removeItem(key); // allow-direct-storage: feature-detect browser storage; preserved verbatim from view_updater_build_advance.js pre-i353 split
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
        var raw = window.localStorage.getItem('abj404ViewBuildAdvanceState'); // allow-direct-storage: shared-build coordination key; preserved verbatim from view_updater_build_advance.js pre-i353 split
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
        window.localStorage.setItem('abj404ViewBuildAdvanceState', JSON.stringify(state || {})); // allow-direct-storage: shared-build coordination key; preserved verbatim from view_updater_build_advance.js pre-i353 split
    // allow-silent-catch: storage best-effort coordination key; quota or disabled storage must not break refresh flow
    } catch (e) {}
}

function abj404TryClaimSharedBuildOwner(ownerId) {
    if (!abj404CanUseSharedBuildCoordination()) {
        return true;
    }
    var now = Date.now(); // allow-direct-time: stale-claim window check; preserved verbatim from view_updater_build_advance.js pre-i353 split
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
 * stored state stuck at status:running. The next tab, including the
 * same tab loading its next page, falls into abj404FollowSharedBuildThenRetry
 * and waits the full 45 s stale-detection window before claiming itself,
 * blocking the redirects-table placeholder for that whole period.
 *
 * pagehide is preferred over beforeunload: it fires for both bfcache and
 * full unloads, and unlike beforeunload it does not block the navigation
 * UX. Storage writes inside pagehide handlers are honoured by all
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
                window.localStorage.removeItem('abj404ViewBuildAdvanceState'); // allow-direct-storage: shared-build coordination key release; preserved verbatim from view_updater_build_advance.js pre-i353 split
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
        updatedAt: Date.now(), // allow-direct-time: owner-state heartbeat; preserved verbatim from view_updater_build_advance.js pre-i353 split
        stage: progress && progress.stage,
        of: progress && progress.of,
        progressText: progress && progress.progress_text
    });
}

function abj404FollowSharedBuildThenRetry(triggerItem, $config) {
    var startedAt = Date.now(); // allow-direct-time: follow-then-retry timeout anchor; preserved verbatim from view_updater_build_advance.js pre-i353 split
    var maxWaitMs = 300000;
    var poll = function() {
        var state = abj404ReadSharedBuildState();
        var updatedAt = state ? (parseInt(state.updatedAt, 10) || 0) : 0;
        var stale = !state || !updatedAt || (Date.now() - updatedAt) > 45000; // allow-direct-time: shared-state staleness probe; preserved verbatim from view_updater_build_advance.js pre-i353 split
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
        if ((Date.now() - startedAt) > maxWaitMs) { // allow-direct-time: follow-then-retry max-wait check; preserved verbatim from view_updater_build_advance.js pre-i353 split
            window.abj404ViewBuildAdvanceRunning = false;
            showTableWarmupFailure({lastError: 'Timed out waiting for another tab to finish preparing redirects view.'});
            return;
        }
        if (state && state.progressText) {
            jQuery('.abj404-refresh-status').text('Preparing redirects view (' + state.progressText + ')');
        }
        window.setTimeout(poll, 1500 + Math.floor(Math.random() * 500)); // allow-direct-random: jittered follow-poll cadence; preserved verbatim from view_updater_build_advance.js pre-i353 split
    };
    poll();
}
