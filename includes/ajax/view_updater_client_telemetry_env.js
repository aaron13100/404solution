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
 * Client build identity (matrix requirement 6, cause A8 "stale/duplicated
 * client JS"): abj404ClientBuildProbe() below is a source-identity probe. The
 * browser hashes the probe's own executing source via Function.prototype
 * .toString(); the server independently hashes the same function's text out of
 * the shipped .js file (ABJ_404_Solution_ClientBuildFingerprint). Equal hashes
 * prove the browser is running the bytes this install shipped. Unequal hashes
 * prove it is not, whether that is a stale browser/edge cache, a second copy of
 * the plugin's JS, or an optimizer rewriting the bundle in flight. Nothing has
 * to be kept in sync by hand for that comparison to hold.
 *
 * Globals defined: abj404ClientTelemetryEnv, abj404ClientBuildProbe.
 *
 * Depends on view_updater_client_telemetry_store.js (tabScopedValue) and
 * view_updater.js (abj404GenerateRequestId).
 */
(function (global) {
    'use strict';

    /* abj404-client-build-probe:start */
    function abj404ClientBuildProbe() {
        return 'abj404-client-transport-telemetry-v1';
    }
    /* abj404-client-build-probe:end */

    /** Bounded histories: enough to cover a 25-second attempt, never unbounded. */
    var MAX_LONG_TASKS = 64;
    var MAX_LIFECYCLE_EVENTS = 40;
    var DRIFT_SAMPLE_INTERVAL_MS = 1000;
    var SESSION_ID_KEY = 'abj404:client_session_id';
    var SESSION_ID_PATTERN = /^[a-z0-9]{8,64}$/;

    var inFlight = {};
    var longTasks = [];
    var lifecycleEvents = [];
    var driftMaxMs = 0;
    var driftSamples = 0;
    var driftTimer = null;
    var observersInstalled = false;
    var longTaskObserverState = 'not-started';
    var sessionId = '';
    var fallbackIdCounter = 0;

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
     * FNV-1a, 32-bit, lowercase hex. Chosen because the server side has to
     * reproduce it byte for byte over the same source text in PHP, so the
     * algorithm has to be small enough to be obviously identical in both
     * languages (see ABJ_404_Solution_ClientBuildFingerprint::hashOf).
     *
     * @param {string} text
     * @returns {string}
     */
    function fnv1a32(text) {
        var hash = 0x811c9dc5;
        for (var i = 0; i < text.length; i++) {
            hash ^= text.charCodeAt(i) & 0xff;
            // 16777619 expressed as shift-and-add: a plain multiply overflows
            // the exactly-representable range and stops matching PHP's result.
            hash = (hash + ((hash << 1) + (hash << 4) + (hash << 7) + (hash << 8) + (hash << 24))) >>> 0;
        }
        return ('0000000' + hash.toString(16)).slice(-8);
    }

    /**
     * Hash of the browser's actually-executing probe source, with carriage
     * returns stripped so a checkout with CRLF endings still matches the
     * server's hash of the same file.
     *
     * @returns {string}
     */
    function clientBuildHash() {
        try {
            return fnv1a32(String(abj404ClientBuildProbe).replace(/\r/g, ''));
        } catch (probeError) {
            warn('could not hash the client build probe', probeError);
            return '';
        }
    }

    /**
     * One id generator for the whole client, borrowed from view_updater.js
     * rather than duplicated here. The deterministic fallback only runs if
     * that module failed to load, in which case a per-tab-unique value still
     * beats an empty session field.
     *
     * @returns {string}
     */
    function mintId() {
        if (typeof global.abj404GenerateRequestId === 'function') {
            return global.abj404GenerateRequestId();
        }
        fallbackIdCounter++;
        return ('f' + Date.now().toString(36) + fallbackIdCounter.toString(36) + // allow-direct-time: id uniqueness source when the shared generator is absent
            Math.round(nowMs()).toString(36)).slice(0, 32);
    }

    /**
     * Per-tab session identifier. Persisted through the telemetry storage
     * adapter so the whole tab (including a reload) reports one session, which
     * is what joins the "same tab retried three times, then reloaded and failed
     * again" story server-side.
     *
     * @returns {string}
     */
    function getSessionId() {
        if (sessionId !== '') {
            return sessionId;
        }
        var store = global.abj404ClientTelemetryStore;
        sessionId = (store && typeof store.tabScopedValue === 'function')
            ? store.tabScopedValue(SESSION_ID_KEY, mintId, SESSION_ID_PATTERN)
            : mintId();
        return sessionId;
    }

    /** @param {string} name @returns {void} */
    function recordLifecycle(name) {
        lifecycleEvents.push({ e: name, t: Math.round(nowMs()) });
        if (lifecycleEvents.length > MAX_LIFECYCLE_EVENTS) {
            lifecycleEvents.shift();
        }
    }

    /** @returns {void} */
    function installObservers() {
        if (observersInstalled) {
            return;
        }
        observersInstalled = true;
        installLongTaskObserver();
        installLifecycleListeners();
    }

    /**
     * Long tasks are the decisive measurement for "the browser received the
     * response but could not run the completion callback" (matrix cause A6/A7).
     *
     * @returns {void}
     */
    function installLongTaskObserver() {
        if (typeof global.PerformanceObserver !== 'function') {
            longTaskObserverState = 'unsupported';
            return;
        }
        try {
            var observer = new global.PerformanceObserver(function (list) {
                var entries = list.getEntries();
                for (var i = 0; i < entries.length; i++) {
                    longTasks.push({
                        start: Math.round(entries[i].startTime),
                        dur: Math.round(entries[i].duration)
                    });
                }
                while (longTasks.length > MAX_LONG_TASKS) {
                    longTasks.shift();
                }
            });
            observer.observe({ type: 'longtask', buffered: true });
            longTaskObserverState = 'observing';
        } catch (observerError) {
            // Firefox and Safari do not implement the longtask entry type and
            // throw here. That is a browser fact worth recording, not an error
            // to hide: the record then says why the field is empty, and the
            // timer-drift sampler covers the same question in those browsers.
            longTaskObserverState = 'unavailable';
            warn('long-task observer unavailable', observerError);
        }
    }

    /** @returns {void} */
    function installLifecycleListeners() {
        var events = ['visibilitychange', 'pagehide', 'pageshow', 'freeze', 'resume', 'online', 'offline'];
        for (var i = 0; i < events.length; i++) {
            bindLifecycle(events[i]);
        }
    }

    /** @param {string} name @returns {void} */
    function bindLifecycle(name) {
        try {
            var target = (name === 'visibilitychange' || name === 'freeze' || name === 'resume')
                ? global.document : global;
            if (!target || typeof target.addEventListener !== 'function') {
                return;
            }
            target.addEventListener(name, function () {
                recordLifecycle(name === 'visibilitychange'
                    ? 'visibilitychange:' + visibilityState() : name);
            });
        } catch (bindError) {
            warn('could not observe the ' + name + ' page-lifecycle event', bindError);
        }
    }

    /** @returns {string} */
    function visibilityState() {
        return (global.document && typeof global.document.visibilityState === 'string')
            ? global.document.visibilityState : 'unknown';
    }

    /**
     * Sample timer drift only while a request is in flight. Drift measures the
     * same starvation a long-task observer sees, but works in the browsers
     * that do not implement the longtask entry type.
     *
     * @returns {void}
     */
    function startDriftSampler() {
        if (driftTimer !== null) {
            return;
        }
        var expected = nowMs() + DRIFT_SAMPLE_INTERVAL_MS;
        driftTimer = global.setInterval(function () {
            var actual = nowMs();
            var drift = Math.max(0, Math.round(actual - expected));
            expected = actual + DRIFT_SAMPLE_INTERVAL_MS;
            driftSamples++;
            if (drift > driftMaxMs) {
                driftMaxMs = drift;
            }
        }, DRIFT_SAMPLE_INTERVAL_MS);
    }

    /** @returns {void} */
    function stopDriftSampler() {
        if (driftTimer === null) {
            return;
        }
        global.clearInterval(driftTimer);
        driftTimer = null;
    }

    /**
     * @param {string} attemptId
     * @param {object} meta
     * @returns {void}
     */
    function registerInFlight(attemptId, meta) {
        installObservers();
        inFlight[attemptId] = {
            part: (meta && meta.part) || '',
            requestId: (meta && meta.requestId) || '',
            startedAt: Math.round(nowMs())
        };
        startDriftSampler();
    }

    /** @param {string} attemptId @returns {void} */
    function releaseInFlight(attemptId) {
        delete inFlight[attemptId];
        if (inFlightIds().length === 0) {
            stopDriftSampler();
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
        var windowStart = typeof startedAtMs === 'number' ? startedAtMs : 0;
        var tasks = 0;
        var totalMs = 0;
        var maxMs = 0;
        for (var i = 0; i < longTasks.length; i++) {
            if (longTasks[i].start + longTasks[i].dur >= windowStart) {
                tasks++;
                totalMs += longTasks[i].dur;
                maxMs = Math.max(maxMs, longTasks[i].dur);
            }
        }
        var lifecycle = [];
        for (var j = 0; j < lifecycleEvents.length; j++) {
            if (lifecycleEvents[j].t >= windowStart) {
                lifecycle.push(lifecycleEvents[j]);
            }
        }
        return {
            inflight: { count: inFlightIds().length, ids: inFlightIds() },
            longtasks: {
                state: longTaskObserverState,
                count: tasks,
                totalMs: totalMs,
                maxMs: maxMs
            },
            drift: { maxMs: driftMaxMs, samples: driftSamples },
            lifecycle: lifecycle,
            sw: serviceWorkerId(),
            vis: visibilityState()
        };
    }

    global.abj404ClientBuildProbe = abj404ClientBuildProbe;
    global.abj404ClientTelemetryEnv = {
        sessionId: getSessionId,
        clientBuildHash: clientBuildHash,
        registerInFlight: registerInFlight,
        releaseInFlight: releaseInFlight,
        inFlightIds: inFlightIds,
        scriptVersions: scriptVersions,
        snapshot: snapshot,
        fnv1a32: fnv1a32
    };
})(typeof window !== 'undefined' ? window : this);
