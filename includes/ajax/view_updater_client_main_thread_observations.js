/**
 * What the main thread and the page were doing while a request was in flight.
 *
 * "The response arrived and the browser could not run the completion callback"
 * and "the response never arrived" produce the same jQuery timeout and need
 * different fixes (Bruno matrix causes A6/A7). Telling them apart takes
 * observations that outlive any one request: long tasks blocking the main
 * thread, timer drift (the same starvation in the browsers that do not
 * implement the longtask entry type), page lifecycle transitions that freeze
 * or hide the tab, and uncaught page errors from any script on the page.
 *
 * Separate from the attempt record and from the in-flight registry because the
 * lifetime is different: these observers start before a request and keep
 * running after it, and each attempt just takes a WINDOW of what they saw.
 *
 * Every history is bounded. A stalled admin screen left open for an hour must
 * not accumulate an unbounded array, and the window an attempt asks for is at
 * most its own timeout.
 *
 * Globals defined: abj404ClientMainThreadObservations.
 */
(function (global, abj404Module) {
    if (global.abj404ClientBuildRegistry) {
        global.abj404ClientBuildRegistry.register('main_thread_observations', abj404Module);
    }
    abj404Module(global);
}(typeof window !== 'undefined' ? window : this, /* abj404-client-module:start */ function (global) {
    'use strict';

    /** Bounded histories: enough to cover a 25-second attempt, never unbounded. */
    var MAX_LONG_TASKS = 64;
    var MAX_LIFECYCLE_EVENTS = 40;
    var MAX_PAGE_ERRORS = 40;
    var MAX_DRIFT_SAMPLES = 64;
    var DRIFT_SAMPLE_INTERVAL_MS = 1000;

    var longTasks = [];
    var lifecycleEvents = [];
    var pageErrors = [];
    var driftHistory = [];
    var driftTimer = null;
    var observersInstalled = false;
    var longTaskObserverState = 'not-started';

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
        installPageErrorListeners();
    }

    /** @returns {void} */
    function installPageErrorListeners() {
        if (!global || typeof global.addEventListener !== 'function') {
            return;
        }
        global.addEventListener('error', function (event) {
            recordPageError('error', event || {});
        });
        global.addEventListener('unhandledrejection', function (event) {
            recordPageError('unhandledrejection', event || {});
        });
    }

    /** @param {string} type @param {object} event @returns {void} */
    function recordPageError(type, event) {
        var reason = event.reason;
        var rawMessage = type === 'unhandledrejection'
            ? (reason && typeof reason.message !== 'undefined' ? reason.message : reason)
            : event.message;
        pageErrors.push({
            type: type,
            message: String(rawMessage == null ? '' : rawMessage)
                .replace(/([?&][^=\s&#]{1,64})=([^&\s#]*)/g, '$1=[redacted]').slice(0, 240),
            source: String(event.filename || '').split(/[?#]/)[0].split(/[\\/]/).pop().slice(0, 160),
            line: typeof event.lineno === 'number' && isFinite(event.lineno) ? event.lineno : null,
            column: typeof event.colno === 'number' && isFinite(event.colno) ? event.colno : null,
            t: Math.round(nowMs())
        });
        while (pageErrors.length > MAX_PAGE_ERRORS) {
            pageErrors.shift();
        }
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
            driftHistory.push({ t: Math.round(actual), ms: drift });
            while (driftHistory.length > MAX_DRIFT_SAMPLES) {
                driftHistory.shift();
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
     * Everything observed since `windowStart`, which is the attempt's own
     * start time. Windowed rather than cumulative so a record describes ITS
     * attempt and not every attempt the page has ever made.
     *
     * @param {number} windowStart performance.now() value, already floored.
     * @returns {object}
     */
    function since(windowStart) {
        var windowEnd = Math.round(nowMs());
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
        var errors = [];
        for (var k = 0; k < pageErrors.length; k++) {
            if (pageErrors[k].t >= windowStart) {
                errors.push(pageErrors[k]);
            }
        }
        var driftMaxMs = 0;
        var driftSamples = 0;
        for (var l = 0; l < driftHistory.length; l++) {
            if (driftHistory[l].t >= windowStart && driftHistory[l].t <= windowEnd) {
                driftSamples++;
                driftMaxMs = Math.max(driftMaxMs, driftHistory[l].ms);
            }
        }
        return {
            longtasks: {
                state: longTaskObserverState,
                count: tasks,
                totalMs: totalMs,
                maxMs: maxMs
            },
            drift: { maxMs: driftMaxMs, samples: driftSamples },
            lifecycle: lifecycle,
            pageErrors: errors,
            vis: visibilityState()
        };
    }

    global.abj404ClientMainThreadObservations = {
        install: installObservers,
        startDrift: startDriftSampler,
        stopDrift: stopDriftSampler,
        visibilityState: visibilityState,
        since: since
    };
} /* abj404-client-module:end */));
