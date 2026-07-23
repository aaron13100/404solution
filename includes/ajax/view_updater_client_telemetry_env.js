/**
 * Page-scoped observation environment for transport telemetry.
 *
 * A transport attempt record answers "what happened to this request". This
 * module answers the questions that are about the PAGE rather than any one
 * request, and that an attempt record samples as it finishes: how many other
 * plugin requests were in flight, whether the main thread was blocked, whether
 * timers were drifting, whether the tab was hidden or frozen, whether a
 * service worker was in the way, and whether the JavaScript actually executing
 * is the JavaScript this install shipped.
 *
 * Those observations outlive any single request (some of them start before the
 * request and end after it), which is why they live here and not in the record
 * module. Observers are installed lazily on the first in-flight registration so
 * a page that never issues a table request pays nothing, and the timer-drift
 * sampler runs only while at least one request is in flight.
 *
 * Same-site contention (Bruno matrix bucket C, "another request took the
 * worker slot"): the in-flight registry below only ever knew about requests
 * THIS plugin issued, so the Heartbeat poll every admin screen runs, another
 * plugin's polling AJAX, and a second tab's own table request were all
 * invisible. Two collaborators close that and are folded into the snapshot
 * here: view_updater_page_ajax_activity.js (what other AJAX this page is
 * doing) and view_updater_client_tab_presence.js (how many admin tabs are
 * open). Each names its own scope in the record, because neither can see
 * everything -- cron loopbacks and other tabs' server-side traffic are the
 * server census's job, not this one's.
 *
 * Client build identity (matrix requirement 6, cause A8 "stale/duplicated
 * client JS", gap GF): this module used to carry a one-function source probe
 * and hash only that. It now reports what view_updater_client_build_registry.js
 * collected from EVERY diagnostic module the page loaded -- request driver,
 * transport, storage, delivery, canary, support -- because a stale edge copy
 * of any one of those was invisible to a probe that covered one function in
 * one file. This module registers its own body with that registry like all the
 * others (see the wrapper below) and reads the combined and per-module hashes
 * back out for the attempt record.
 *
 * Globals defined: abj404ClientTelemetryEnv.
 *
 * Depends on view_updater_client_build_registry.js (build identity),
 * view_updater_client_tab_identity.js (this tab's id),
 * view_updater_client_tab_presence.js (open-tab count) and
 * view_updater_page_ajax_activity.js (other code's AJAX on this page).
 */
(function (global, abj404Module) {
    if (global.abj404ClientBuildRegistry) {
        global.abj404ClientBuildRegistry.register('telemetry_env', abj404Module);
    }
    abj404Module(global);
}(typeof window !== 'undefined' ? window : this, /* abj404-client-module:start */ function (global) {
    'use strict';

    var MODULE_INSTANCE_KEY = '__abj404ClientTelemetryEnvInstanceCount';

    var inFlight = {};

    var priorModuleInstances = parseInt(global[MODULE_INSTANCE_KEY], 10);
    global[MODULE_INSTANCE_KEY] = isFinite(priorModuleInstances) && priorModuleInstances > 0
        ? priorModuleInstances + 1 : 1;

    /** @returns {number} monotonic milliseconds since page load where available. */
    function nowMs() {
        if (global.performance && typeof global.performance.now === 'function') {
            return global.performance.now();
        }
        return Date.now(); // allow-direct-time: fallback monotonic clock for browsers without performance.now
    }

    /**
     * @param {string} message
     * @param {*} error
     * @returns {void}
     */
    function warn(message, error) {
        if (global.console && global.console.warn) {
            global.console.warn('404 Solution: ' + message, error);
        }
    }

    /**
     * How many admin tabs of this page are open, from the module that owns the
     * cross-tab presence registry.
     *
     * @returns {object}
     */
    function openTabs() {
        var presence = global.abj404ClientTabPresence;
        return presence && typeof presence.openTabs === 'function'
            ? presence.openTabs()
            : { status: 'unavailable', reason: 'presence_module_missing', count: -1, ages: [] };
    }

    /**
     * The same count as a plain integer for the attempt record, or -1 when it
     * could not be observed. -1 rather than 0 so a blind spot can never be
     * read as a page with one tab open.
     *
     * @returns {number}
     */
    function openTabCount() {
        var tabs = openTabs();
        return tabs && typeof tabs.count === 'number' ? tabs.count : -1;
    }

    /**
     * What OTHER code's AJAX is doing on this page, from the module that
     * observes it.
     *
     * @param {number} windowStart
     * @returns {object}
     */
    function foreignAjax(windowStart) {
        var activity = global.abj404PageAjaxActivity;
        return activity && typeof activity.snapshot === 'function'
            ? activity.snapshot(windowStart)
            : { state: 'unavailable', scope: 'jquery-ajax-this-tab', inflight: 0,
                heartbeatInflight: false, requests: [] };
    }

    /**
     * Which jQuery instances the page has and who registered AJAX hooks on
     * them, from the same module.
     *
     * @returns {object}
     */
    function jqueryFingerprint() {
        var activity = global.abj404PageAjaxActivity;
        return activity && typeof activity.jqueryFingerprint === 'function'
            ? activity.jqueryFingerprint()
            : { versions: [], instances: 0, ajaxPrefiltersObserved: -1,
                ajaxTransportsObserved: -1, registrationScope: 'unavailable' };
    }


    /**
     * Main-thread and page-lifecycle observation, from the module that owns
     * those observers. An inert stand-in when it failed to load, so a table
     * request never breaks over a missing diagnostic collaborator; the empty
     * window then says the channel was unavailable rather than that the page
     * was quiet.
     *
     * @returns {object}
     */
    function observations() {
        return global.abj404ClientMainThreadObservations || {
            install: function () {},
            startDrift: function () {},
            stopDrift: function () {},
            visibilityState: function () { return 'unknown'; },
            since: function () {
                return {
                    longtasks: { state: 'module_missing', count: 0, totalMs: 0, maxMs: 0 },
                    drift: { maxMs: 0, samples: 0 },
                    lifecycle: [], pageErrors: [], vis: 'unknown'
                };
            }
        };
    }

    /**
     * The build registry, or null when it failed to load. Every read below
     * degrades to an empty answer rather than throwing: an unhashable build
     * is a smaller proof, never a broken table request.
     *
     * @returns {object|null}
     */
    function buildRegistry() {
        return global.abj404ClientBuildRegistry || null;
    }

    /**
     * FNV-1a, 32-bit, lowercase hex, from the one place that owns it (see
     * view_updater_client_build_registry.js and its PHP twin
     * ABJ_404_Solution_ClientBuildFingerprint::hashOf). Kept on this module's
     * public surface because callers already reach it here; '' when the
     * registry is absent, so a missing hash reads as unknown rather than as
     * a value that could be compared.
     *
     * @param {string} text
     * @returns {string}
     */
    function fnv1a32(text) {
        var registry = buildRegistry();
        return registry && typeof registry.fnv1a32 === 'function' ? registry.fnv1a32(text) : '';
    }

    /**
     * One hash over the executing source of every diagnostic module this page
     * loaded. Compared server-side against the same modules' shipped bytes.
     *
     * @returns {string}
     */
    function clientBuildHash() {
        try {
            var registry = buildRegistry();
            return registry ? registry.digest().combined : '';
        } catch (probeError) {
            warn('could not hash the client build', probeError);
            return '';
        }
    }

    /**
     * The per-module hashes as the compact `name:hash,name:hash` wire string.
     * The combined hash above says THAT the client drifted; this says WHICH
     * module did, which is what makes a mismatch actionable instead of
     * merely alarming.
     *
     * @returns {string}
     */
    function clientBuildModules() {
        try {
            var registry = buildRegistry();
            return registry ? registry.wireString() : '';
        } catch (probeError) {
            warn('could not list the client build modules', probeError);
            return '';
        }
    }

    /**
     * Per-tab identity belongs to the module that persists it.
     * @returns {string}
     */
    function getSessionId() {
        var identity = global.abj404ClientTabIdentity;
        return identity && typeof identity.id === 'function' ? identity.id() : 'identitymissing';
    }

    /**
     * @param {string} attemptId
     * @param {object} meta
     * @returns {void}
     */
    function registerInFlight(attemptId, meta) {
        observations().install();
        inFlight[attemptId] = {
            part: (meta && meta.part) || '',
            requestId: (meta && meta.requestId) || '',
            startedAt: Math.round(nowMs())
        };
        observations().startDrift();
    }

    /** @param {string} attemptId @returns {void} */
    function releaseInFlight(attemptId) {
        delete inFlight[attemptId];
        if (inFlightIds().length === 0) {
            observations().stopDrift();
        }
    }

    /** @returns {Array<string>} */
    function inFlightIds() {
        var ids = [];
        for (var id in inFlight) {
            if (Object.prototype.hasOwnProperty.call(inFlight, id)) {
                ids.push(id);
            }
        }
        return ids;
    }

    /** @returns {string} same-origin path of the controlling service worker, or a reason. */
    function serviceWorkerId() {
        try {
            if (!global.navigator || !global.navigator.serviceWorker) {
                return 'unsupported';
            }
            var controller = global.navigator.serviceWorker.controller;
            if (!controller || typeof controller.scriptURL !== 'string') {
                return 'none';
            }
            // Path only: the origin is already known server-side and the path
            // is what identifies which worker is intercepting.
            return controller.scriptURL.replace(/^https?:\/\/[^/]+/i, '');
        } catch (workerError) {
            warn('could not read the service worker controller', workerError);
            return 'error';
        }
    }

    /**
     * The ?ver= values the browser actually used for this plugin's own script
     * tags. The server emits those from file mtimes, so a browser running a
     * page whose asset URLs predate the deployed files is directly visible.
     *
     * @returns {object}
     */
    function scriptVersions() {
        var versions = {};
        try {
            if (!global.document || typeof global.document.querySelectorAll !== 'function') {
                return versions;
            }
            var tags = global.document.querySelectorAll('script[src*="404-solution"]');
            for (var i = 0; i < tags.length; i++) {
                var src = String(tags[i].getAttribute('src') || '');
                var name = src.split('?')[0].split('/').pop();
                var verMatch = src.match(/[?&]ver=([^&]*)/);
                if (name !== '') {
                    versions[name] = verMatch ? decodeURIComponent(verMatch[1]) : '';
                }
            }
        } catch (scanError) {
            warn('could not read plugin script versions', scanError);
        }
        return versions;
    }

    /**
     * Everything observable about the page at the moment an attempt settles,
     * windowed to that attempt where the data is windowed.
     *
     * @param {number} startedAtMs performance.now() value at attempt start.
     * @returns {object}
     */
    function snapshot(startedAtMs) {
        // Stored observation timestamps are integer milliseconds. Floor the
        // attempt boundary to the same precision so events emitted during
        // the attempt's first fractional millisecond are not filtered out.
        var windowStart = typeof startedAtMs === 'number' ? Math.floor(startedAtMs) : 0;
        var observed = observations().since(windowStart);
        return {
            inflight: { count: inFlightIds().length, ids: inFlightIds() },
            foreignAjax: foreignAjax(windowStart),
            tabs: openTabs(),
            longtasks: observed.longtasks,
            drift: observed.drift,
            lifecycle: observed.lifecycle,
            pageErrors: observed.pageErrors,
            jquery: jqueryFingerprint(),
            moduleInstances: global[MODULE_INSTANCE_KEY],
            sw: serviceWorkerId(),
            vis: observed.vis
        };
    }

    global.abj404ClientTelemetryEnv = {
        sessionId: getSessionId,
        clientBuildHash: clientBuildHash,
        clientBuildModules: clientBuildModules,
        registerInFlight: registerInFlight,
        releaseInFlight: releaseInFlight,
        inFlightIds: inFlightIds,
        openTabs: openTabs,
        foreignAjax: foreignAjax,
        openTabCount: openTabCount,
        scriptVersions: scriptVersions,
        snapshot: snapshot,
        fnv1a32: fnv1a32
    };
} /* abj404-client-module:end */));
