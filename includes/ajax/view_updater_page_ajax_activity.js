/**
 * What AJAX code OTHER than this plugin is doing on this admin page.
 *
 * Two questions, one subject. Which jQuery instances exist and who registered
 * AJAX hooks on them answers "is something else in the request path" (matrix
 * cause A8, stale or duplicated client JS, and any prefilter that rewrites the
 * plugin's own requests). Which requests other code actually has in flight
 * answers "did this site's own Heartbeat poll or another plugin's polling take
 * the PHP worker slot" -- the same-site contention that a host-wide load
 * average cannot distinguish from a busy machine.
 *
 * Observed through jQuery.ajaxPrefilter, the documented public registration
 * point, NOT by patching XMLHttpRequest.prototype: the telemetry environment
 * module reports whether the page's transport functions are native or wrapped,
 * and a recorder that wrapped them would be reporting itself.
 *
 * The observation is kept on the window rather than in this closure, so a
 * second copy of this file reuses the one view of the page instead of
 * registering a second prefilter and forking the history.
 *
 * Globals defined: abj404PageAjaxActivity.
 */
(function (global, abj404Module) {
    if (global.abj404ClientBuildRegistry) {
        global.abj404ClientBuildRegistry.register('page_ajax_activity', abj404Module);
    }
    abj404Module(global);
}(typeof window !== 'undefined' ? window : this, /* abj404-client-module:start */ function (global) {
    'use strict';

    var OBSERVATION_KEY = '__abj404ForeignAjaxObservation';
    var JQUERY_PROBE_KEY = '__abj404AjaxRegistrationProbe';

    /** Bounded history. A busy admin page polls; it does not flood. */
    var MAX_FOREIGN_REQUESTS = 40;

    /** WordPress action names are alphanumeric with dashes/underscores; anything else is not recorded. */
    var ACTION_PATTERN = /^[A-Za-z0-9_-]{1,64}$/;

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

    /** @returns {number} monotonic milliseconds since page load where available. */
    function nowMs() {
        if (global.performance && typeof global.performance.now === 'function') {
            return global.performance.now();
        }
        return Date.now(); // allow-direct-time: fallback monotonic clock for browsers without performance.now
    }

    /**
     * @returns {{state: string, inflight: object, history: Array<object>, seq: number}}
     */
    function observation() {
        var shared = global[OBSERVATION_KEY];
        if (!shared || typeof shared !== 'object') {
            shared = { state: 'not-started', inflight: {}, history: [], seq: 0 };
            global[OBSERVATION_KEY] = shared;
        }
        return shared;
    }

    /**
     * jQuery keeps its AJAX registries in closure-private objects. Wrapping
     * the public registration functions counts only post-probe registrations,
     * without misrepresenting the already-private entries as enumerable.
     * @returns {void}
     */
    function installRegistrationProbe() {
        var jq = global.jQuery;
        if (typeof jq !== 'function') {
            return;
        }
        var probe = jq[JQUERY_PROBE_KEY];
        if (!probe || typeof probe !== 'object') {
            probe = { prefilters: 0, transports: 0 };
            jq[JQUERY_PROBE_KEY] = probe;
        }
        var methods = ['ajaxPrefilter', 'ajaxTransport'];
        var counters = ['prefilters', 'transports'];
        for (var i = 0; i < methods.length; i++) {
            if (typeof jq[methods[i]] === 'function' &&
                    jq[methods[i]].__abj404RegistrationProbe !== true) {
                wrapRegistration(jq, probe, methods[i], counters[i]);
            }
        }
    }

    /** @param {Function} jq @param {object} probe @param {string} method @param {string} counter @returns {void} */
    function wrapRegistration(jq, probe, method, counter) {
        var original = jq[method];
        var wrapped = function () {
            var result = original.apply(this, arguments);
            var callback = typeof arguments[0] === 'function' ? arguments[0] : arguments[1];
            probe[counter] += typeof callback === 'function' ? 1 : 0;
            return result;
        };
        wrapped.__abj404RegistrationProbe = true;
        jq[method] = wrapped;
    }

    /** @returns {object} */
    function jqueryFingerprint() {
        var instances = [];
        var versions = [];
        var candidates = [];
        var names = Object.getOwnPropertyNames(global);
        for (var nameIndex = 0; nameIndex < names.length; nameIndex++) {
            try {
                candidates.push(global[names[nameIndex]]);
            } catch (propertyError) {
                warn('could not inspect page global ' + names[nameIndex], propertyError);
            }
        }
        for (var i = 0; i < candidates.length; i++) {
            var candidate = candidates[i];
            if (typeof candidate !== 'function' || !candidate.fn ||
                    typeof candidate.fn.jquery !== 'string' || instances.indexOf(candidate) >= 0) {
                continue;
            }
            instances.push(candidate);
            versions.push(candidate.fn.jquery);
        }
        versions.sort();
        var jq = global.jQuery;
        var probe = typeof jq === 'function' ? jq[JQUERY_PROBE_KEY] : null;
        return {
            versions: versions,
            instances: instances.length,
            ajaxPrefiltersObserved: probe ? probe.prefilters : -1,
            ajaxTransportsObserved: probe ? probe.transports : -1,
            registrationScope: probe ? 'after-probe' : 'unavailable'
        };
    }

    /**
     * Observe every jQuery AJAX request the page issues, ours excluded.
     * @returns {void}
     */
    function installRequestObserver() {
        var shared = observation();
        if (shared.state === 'observing') {
            return;
        }
        var jq = global.jQuery;
        if (typeof jq !== 'function' || typeof jq.ajaxPrefilter !== 'function') {
            shared.state = 'unavailable';
            return;
        }
        try {
            jq.ajaxPrefilter(function (options, originalOptions, jqXHR) {
                observeRequest(options, jqXHR);
            });
            shared.state = 'observing';
        } catch (installError) {
            // A page whose jQuery refuses registrations is a finding about the
            // page, not a reason to take the recorder down.
            shared.state = 'error';
            warn('could not observe page-global AJAX activity', installError);
        }
    }

    /** @param {object} options @param {object} jqXHR @returns {void} */
    function observeRequest(options, jqXHR) {
        try {
            if (isOwnRequest(options)) {
                return;
            }
            var shared = observation();
            shared.seq++;
            var key = 'f' + shared.seq;
            var entry = { a: actionOf(options), t: Math.round(nowMs()), d: null, ok: 'pending' };
            shared.inflight[key] = entry;
            shared.history.push(entry);
            while (shared.history.length > MAX_FOREIGN_REQUESTS) {
                shared.history.shift();
            }
            if (jqXHR && typeof jqXHR.always === 'function') {
                jqXHR.always(function (first, textStatus) {
                    delete shared.inflight[key];
                    entry.d = Math.round(nowMs()) - entry.t;
                    entry.ok = String(textStatus || 'unknown').slice(0, 24);
                });
            }
        } catch (observeError) {
            warn('could not record a page AJAX request', observeError);
        }
    }

    /**
     * Whether this request is one of ours, decided against the telemetry
     * environment's live in-flight registry so a request this plugin just
     * started is never double-counted as competing traffic.
     *
     * @param {object} options
     * @returns {boolean}
     */
    function isOwnRequest(options) {
        var env = global.abj404ClientTelemetryEnv;
        if (!env || typeof env.inFlightIds !== 'function') {
            return false;
        }
        var id = paramOf(options, 'requestId');
        return id !== '' && env.inFlightIds().indexOf(id) >= 0;
    }

    /**
     * One request parameter, from the settings body (object or serialized
     * string) or the URL query. Only named parameters are ever read out of a
     * URL: an admin page's query string can carry user data, and none of it
     * belongs in a diagnostic record.
     *
     * @param {object} options
     * @param {string} name
     * @returns {string}
     */
    function paramOf(options, name) {
        var data = options ? options.data : null;
        if (data && typeof data === 'object' && typeof data[name] === 'string') {
            return data[name];
        }
        var pattern = new RegExp('(?:^|[?&])' + name + '=([^&]*)');
        var haystacks = [typeof data === 'string' ? data : '', String((options && options.url) || '')];
        for (var i = 0; i < haystacks.length; i++) {
            var match = haystacks[i].match(pattern);
            if (match) {
                try {
                    return decodeURIComponent(match[1]);
                } catch (decodeError) {
                    warn('could not decode an observed request parameter', decodeError);
                    return '';
                }
            }
        }
        return '';
    }

    /**
     * The WordPress action a request names, or '' when it names none. This is
     * what separates "the Heartbeat API polled" from "some other plugin
     * polled" in the record.
     *
     * @param {object} options
     * @returns {string}
     */
    function actionOf(options) {
        var action = paramOf(options, 'action');
        return ACTION_PATTERN.test(action) ? action : '';
    }

    /**
     * Foreign AJAX overlapping one attempt's window, plus what is still in
     * flight right now.
     *
     * @param {number} windowStart
     * @returns {object}
     */
    function snapshot(windowStart) {
        var shared = observation();
        var requests = [];
        for (var i = 0; i < shared.history.length; i++) {
            var entry = shared.history[i];
            var endedAt = entry.d === null ? Infinity : entry.t + entry.d;
            if (endedAt >= windowStart) {
                requests.push({ a: entry.a, t: entry.t, d: entry.d, ok: entry.ok });
            }
        }
        var inflightCount = 0;
        var heartbeatInflight = false;
        for (var key in shared.inflight) {
            if (Object.prototype.hasOwnProperty.call(shared.inflight, key)) {
                inflightCount++;
                heartbeatInflight = heartbeatInflight || shared.inflight[key].a === 'heartbeat';
            }
        }
        return {
            state: shared.state,
            // Named, not implied: a reader must not mistake an empty list for
            // "the site was quiet" when the channel cannot see cron loopbacks,
            // other tabs, or callers that bypass jQuery entirely.
            scope: 'jquery-ajax-this-tab',
            inflight: inflightCount,
            heartbeatInflight: heartbeatInflight,
            requests: requests
        };
    }

    // Observer BEFORE probe, on purpose: the probe exists to count
    // registrations made by OTHER code, and counting our own would inflate the
    // very number it reports.
    installRequestObserver();
    installRegistrationProbe();

    global.abj404PageAjaxActivity = {
        jqueryFingerprint: jqueryFingerprint,
        snapshot: snapshot
    };
} /* abj404-client-module:end */));
