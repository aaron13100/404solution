/**
 * Per-attempt browser transport telemetry for the admin table AJAX.
 *
 * jQuery reports one word for a failed table request: 'timeout'. That word
 * covers browser connection queueing, a service worker holding the request, a
 * response whose headers arrived and whose body then stalled, a completed
 * response the main thread was too busy to hand back, and a request that never
 * left the machine. Those are different bugs in different systems, and the
 * plugin has been rewritten three times without ever measuring which one it is
 * (matrix cause class A/H). This module records the native timeline of every
 * attempt so the next failure names itself.
 *
 * One record per attempt, correlated to the server by an attempt-unique ledger
 * id that travels in the POST body, the query string (so proxy and host access
 * logs carry it), and the X-ABJ404-Request-ID request header.
 *
 * A record is never thrown away by outcome. It is persisted through the
 * telemetry storage adapter as soon as the attempt settles; getting it from
 * there to the server or to the admin belongs to
 * view_updater_transport_telemetry_delivery.js, so this module never has to
 * care who is reading.
 *
 * Globals defined: abj404TransportTelemetry.
 *
 * Depends on view_updater_client_telemetry_env.js (page observations, build
 * identity) and view_updater_client_telemetry_store.js (durable buffer).
 */
(function (global, abj404Module) {
    if (global.abj404ClientBuildRegistry) {
        global.abj404ClientBuildRegistry.register('transport_telemetry', abj404Module);
    }
    abj404Module(global);
}(typeof window !== 'undefined' ? window : this, /* abj404-client-module:start */ function (global) {
    'use strict';

    var RECORD_VERSION = 1;

    /** Bounded event timeline. A healthy attempt emits well under 20 entries. */
    var MAX_EVENTS = 60;

    /** Response headers worth keeping: transport identity, never user data. */
    var HEADERS_OF_INTEREST = [
        'cf-ray', 'x-abj404-request-id', 'content-encoding', 'content-length',
        'x-litespeed-cache', 'x-turbo-charged-by', 'server'
    ];

    /**
     * A timed-out or aborted XHR often gets its PerformanceResourceTiming
     * entry a tick after the error callback runs. One late re-read closes that
     * gap; the record is upserted, so the patch replaces the first revision.
     */
    var RESOURCE_TIMING_RETRY_MS = 250;

    /** In-memory index of this page's attempts, for the error notice timeline. */
    var attemptsByRequestId = {};
    var PAGE_TRANSPORT_FINGERPRINT = transportFingerprint();

    /** @param {string} message @param {*} error @returns {void} */
    function warn(message, error) {
        if (global.console && global.console.warn) {
            global.console.warn('404 Solution: ' + message, error);
        }
    }

    /** @returns {number} */
    function nowMs() {
        if (global.performance && typeof global.performance.now === 'function') {
            return global.performance.now();
        }
        return Date.now(); // allow-direct-time: fallback monotonic clock for browsers without performance.now
    }

    /** @returns {object|null} */
    function env() {
        return global.abj404ClientTelemetryEnv || null;
    }

    /** @returns {object|null} */
    function store() {
        return global.abj404ClientTelemetryStore || null;
    }

    /**
     * Ledger id for one attempt: the logical request id plus a part letter and
     * the attempt number. Unique per attempt (the ledger forbids reuse) while
     * still joining back to its logical request by prefix, and still matching
     * the server contract's ^[a-zA-Z0-9]{8,64}$ pattern.
     *
     * @param {string} requestId
     * @param {string} part
     * @param {number} attemptIndex
     * @returns {string}
     */
    function attemptId(requestId, part, attemptIndex) {
        var partCode = String(part || 'x').charAt(0);
        return String(requestId || '').replace(/[^a-zA-Z0-9]/g, '') + partCode + String(attemptIndex);
    }

    /**
     * Start recording one attempt.
     *
     * @param {object} ctx {requestId, part, attemptIndex, parentAttemptId, subpage, timeoutMs}
     * @returns {object} the open record.
     */
    function beginAttempt(ctx) {
        ctx = ctx || {};
        var pageEnv = env();
        var attemptStore = store();
        var record = {
            v: RECORD_VERSION,
            id: attemptId(ctx.requestId, ctx.part, ctx.attemptIndex),
            rid: String(ctx.requestId || ''),
            sid: pageEnv ? pageEnv.sessionId() : '',
            parent: String(ctx.parentAttemptId || ''),
            part: String(ctx.part || ''),
            subpage: String(ctx.subpage || ''),
            attempt: parseInt(ctx.attemptIndex, 10) || 0,
            build: pageEnv ? pageEnv.clientBuildHash() : '',
            // Per-module build hashes (gap GF). The combined hash above proves
            // only that SOMETHING in the client differs from what shipped;
            // this names the module, which is what turns "the client is
            // stale" into "the canary ladder is stale and nothing else is".
            buildModules: pageEnv && typeof pageEnv.clientBuildModules === 'function'
                ? pageEnv.clientBuildModules() : '',
            assets: pageEnv ? pageEnv.scriptVersions() : {},
            timeoutMs: parseInt(ctx.timeoutMs, 10) || 0,
            sentAt: Date.now(), // allow-direct-time: wall-clock send time, paired server-side with REQUEST_TIME_FLOAT
            t0: nowMs(),
            // Concurrency at SEND time, not just at settle time. The count
            // says whether this request was queued behind others; the ids let
            // the server check whether those others ever reached PHP at all,
            // which is what separates browser connection exhaustion from an
            // origin that stopped answering.
            inflightAtSend: pageEnv ? pageEnv.inFlightIds().length : 0,
            inflightIdsAtSend: pageEnv ? pageEnv.inFlightIds() : [],
            // Same-site contention at SEND time, from the two things the
            // browser can see: how many admin tabs of this page are open, and
            // how much non-plugin AJAX this tab already had outstanding. Both
            // ride the request itself (see abj404PaginationAttemptData), so the
            // server holds them even when no support request is ever sent.
            // -1 means "could not be observed", never "none": the difference
            // decides whether an empty reading is evidence or a blind spot.
            tabsAtSend: pageEnv && typeof pageEnv.openTabCount === 'function'
                ? pageEnv.openTabCount() : -1,
            foreignInflightAtSend: pageEnv && typeof pageEnv.foreignAjax === 'function'
                ? pageEnv.foreignAjax(nowMs()).inflight : -1,
            // Positive evidence for the recorder itself. Without this, a
            // browser whose localStorage rejected the attempt looked exactly
            // like a browser that observed nothing.
            storage_health: attemptStore && typeof attemptStore.storageHealth === 'function'
                ? attemptStore.storageHealth() : {
                    status: 'unavailable',
                    accessible: false,
                    writable: false,
                    quota: 'unknown',
                    last_write_ok: false,
                    fallback: 'memory'
                },
            transports: 0,
            events: [],
            eventsDropped: 0,
            rs: 0,
            status: 0,
            bytes: 0,
            firstHeadersMs: null,
            headers: {},
            outcome: 'pending',
            jq: '',
            durationMs: null,
            rt: null,
            rtState: 'not-looked-up',
            transport: PAGE_TRANSPORT_FINGERPRINT,
            env: null
        };
        if (pageEnv) {
            pageEnv.registerInFlight(record.id, { part: record.part, requestId: record.rid });
        }
        indexAttempt(record);
        return record;
    }

    /** @param {object} record @returns {void} */
    function indexAttempt(record) {
        if (!attemptsByRequestId[record.rid]) {
            attemptsByRequestId[record.rid] = [];
        }
        attemptsByRequestId[record.rid].push(record);
    }

    /**
     * @param {object} record
     * @param {string} name
     * @param {object} extra
     * @returns {void}
     */
    function pushEvent(record, name, extra) {
        if (record.events.length >= MAX_EVENTS) {
            record.eventsDropped++;
            return;
        }
        var entry = { e: name, t: Math.round(nowMs() - record.t0) };
        if (extra) {
            for (var key in extra) {
                if (Object.prototype.hasOwnProperty.call(extra, key)) {
                    entry[key] = extra[key];
                }
            }
        }
        record.events.push(entry);
    }

    /**
     * jQuery's `xhr` option: return the transport it would have built, with
     * native listeners attached. Going through jQuery.ajaxSettings.xhr keeps
     * whatever transport jQuery would have chosen instead of second-guessing
     * it.
     *
     * @param {object} record
     * @returns {function(): object}
     */
    function xhrFactory(record) {
        return function () {
            try {
                var xhr = createTransport();
                record.transports++;
                if (record.transports > 1) {
                    // The nonce-refresh wrapper re-issues the same options
                    // after a 403, which builds a second transport under one
                    // attempt. Say so rather than letting two timelines
                    // silently interleave.
                    pushEvent(record, 'transport-reissued', { n: record.transports });
                }
                instrument(record, xhr);
                record.xhr = xhr;
                return xhr;
            } catch (factoryError) {
                // jQuery calls this to obtain the transport it is about to
                // send on. Anything thrown here would fail the table request
                // itself, so a broken recorder degrades to an uninstrumented
                // request rather than taking the feature down with it.
                warn('could not build an instrumented transport', factoryError);
                return createTransport();
            }
        };
    }

    /** @returns {object} */
    function createTransport() {
        // ajax-direct-approved: reads jQuery's transport FACTORY, not a request. This builds the XMLHttpRequest the attempt will run on so native events can be observed; it issues nothing, so there is no call for abj404AdminAjax() to route, and constructing our own instead would discard whatever transport jQuery would have chosen.
        var settings = global.jQuery ? global.jQuery.ajaxSettings : null;
        var factory = settings ? settings.xhr : null;
        return typeof factory === 'function' ? factory() : new global.XMLHttpRequest();
    }

    /**
     * @param {object} record
     * @param {object} xhr
     * @returns {void}
     */
    function instrument(record, xhr) {
        if (!xhr || typeof xhr.addEventListener !== 'function') {
            pushEvent(record, 'instrument-unavailable');
            return;
        }
        try {
            xhr.addEventListener('readystatechange', function () {
                var readyState = numberOr(xhr.readyState, 0);
                record.rs = Math.max(record.rs, readyState);
                pushEvent(record, 'rs' + readyState, { s: numberOr(xhr.status, 0) });
                if (readyState >= 2 && record.firstHeadersMs === null) {
                    record.firstHeadersMs = Math.round(nowMs() - record.t0);
                    captureHeaders(record, xhr);
                }
            });
            xhr.addEventListener('progress', function (event) {
                record.bytes = Math.max(record.bytes, numberOr(event && event.loaded, 0));
                pushEvent(record, 'progress', {
                    l: numberOr(event && event.loaded, 0),
                    tot: numberOr(event && event.total, 0)
                });
            });
            bindSimpleEvents(record, xhr);
        } catch (instrumentError) {
            warn('could not instrument the table request transport', instrumentError);
            pushEvent(record, 'instrument-failed');
        }
    }

    /** @param {object} record @param {object} xhr @returns {void} */
    function bindSimpleEvents(record, xhr) {
        var names = ['loadstart', 'load', 'error', 'abort', 'timeout', 'loadend'];
        for (var i = 0; i < names.length; i++) {
            bindSimpleEvent(record, xhr, names[i]);
        }
    }

    /** @param {object} record @param {object} xhr @param {string} name @returns {void} */
    function bindSimpleEvent(record, xhr, name) {
        xhr.addEventListener(name, function () {
            pushEvent(record, name, {
                rs: numberOr(xhr.readyState, 0),
                s: numberOr(xhr.status, 0)
            });
        });
    }

    /** @param {*} value @param {number} fallback @returns {number} */
    function numberOr(value, fallback) {
        return typeof value === 'number' && isFinite(value) ? value : fallback;
    }

    /** @param {*} candidate @returns {string} native|wrapped|missing|unreadable */
    function functionState(candidate) {
        if (typeof candidate !== 'function') {
            return 'missing';
        }
        try {
            return /\[native code\]/.test(Function.prototype.toString.call(candidate))
                ? 'native' : 'wrapped';
        } catch (sourceError) {
            warn('could not fingerprint a page transport function', sourceError);
            return 'unreadable';
        }
    }

    /** @returns {object} */
    function transportFingerprint() {
        var prototype = global.XMLHttpRequest && global.XMLHttpRequest.prototype;
        return {
            xhrOpen: functionState(prototype && prototype.open),
            xhrSend: functionState(prototype && prototype.send),
            fetch: functionState(global.fetch)
        };
    }

    /**
     * @param {object} record
     * @param {object} xhr
     * @returns {void}
     */
    function captureHeaders(record, xhr) {
        try {
            if (typeof xhr.getAllResponseHeaders !== 'function') {
                return;
            }
            var raw = String(xhr.getAllResponseHeaders() || '');
            var lines = raw.split(/\r?\n/);
            for (var i = 0; i < lines.length; i++) {
                var separator = lines[i].indexOf(':');
                if (separator <= 0) {
                    continue;
                }
                var name = lines[i].slice(0, separator).trim().toLowerCase();
                if (HEADERS_OF_INTEREST.indexOf(name) >= 0) {
                    record.headers[name] = lines[i].slice(separator + 1).trim().slice(0, 120);
                }
            }
        } catch (headerError) {
            warn('could not read the table response headers', headerError);
        }
    }

    /**
     * Fold this attempt's connection-phase timeline into its record, from the
     * module that owns reading it (view_updater_client_resource_timing.js).
     *
     * @param {object} record
     * @returns {boolean} true when an entry was found.
     */
    function readResourceTiming(record) {
        var timing = global.abj404ClientResourceTiming;
        if (!timing || typeof timing.forAttempt !== 'function') {
            record.rtState = 'unavailable';
            return false;
        }
        var result = timing.forAttempt(record.id);
        record.rtState = result.state;
        if (result.timing !== null) {
            record.rt = result.timing;
        }
        return result.state === 'found';
    }

    /**
     * Close a record: sample the final transport state, the page environment,
     * and resource timing, then persist it.
     *
     * @param {object} record
     * @param {string} outcome success|timeout|error|abort
     * @param {object} jqXHR
     * @param {string} textStatus
     * @returns {object} the finalized record.
     */
    function finishAttempt(record, outcome, jqXHR, textStatus) {
        if (!record || record.outcome !== 'pending') {
            return record;
        }
        record.outcome = String(outcome || 'unknown');
        record.jq = String(textStatus || '');
        record.durationMs = Math.round(nowMs() - record.t0);
        // Both carriers are read, and the furthest observation wins. The
        // native transport and jQuery's jqXHR normally agree; when they do
        // not, the one that got further is the one that saw something, and
        // discarding it would understate how far the exchange actually got.
        var transport = record.xhr || jqXHR || {};
        record.rs = Math.max(record.rs, numberOr(transport.readyState, 0),
            numberOr(jqXHR && jqXHR.readyState, 0));
        record.status = numberOr(transport.status, 0) || numberOr(jqXHR && jqXHR.status, 0);
        record.bytes = Math.max(record.bytes, responseLength(transport), responseLength(jqXHR));
        if (record.firstHeadersMs === null && record.rs >= 2) {
            captureHeaders(record, transport);
        }
        var pageEnv = env();
        if (pageEnv) {
            // Released before the snapshot on purpose: the useful number is how
            // many OTHER plugin requests were still outstanding when this one
            // settled (the decisive measurement for browser connection and
            // stream exhaustion), not a constant 1 for the attempt itself.
            pageEnv.releaseInFlight(record.id);
            record.env = pageEnv.snapshot(record.t0);
        } else {
            record.env = null;
        }
        var found = readResourceTiming(record);
        delete record.xhr;
        persist(record);
        if (!found && record.rtState === 'missing') {
            scheduleResourceTimingPatch(record);
        }
        return record;
    }

    /** @param {object} carrier @returns {number} */
    function responseLength(carrier) {
        try {
            if (carrier && typeof carrier.responseText === 'string') {
                return carrier.responseText.length;
            }
        } catch (accessError) {
            // Reading responseText throws on a responseType-typed XHR. The
            // byte count still arrives through the progress events.
            warn('could not measure the table response length', accessError);
        }
        return 0;
    }

    /** @param {object} record @returns {void} */
    function scheduleResourceTimingPatch(record) {
        global.setTimeout(function () {
            if (readResourceTiming(record)) {
                persist(record);
            }
        }, RESOURCE_TIMING_RETRY_MS);
    }

    /** @param {object} record @returns {void} */
    function persist(record) {
        var buffer = store();
        if (buffer) {
            buffer.put(record);
        }
    }

    /**
     * Every attempt recorded on this page for one logical request, in the
     * order they were started.
     *
     * @param {string} requestId
     * @returns {Array<object>}
     */
    function attemptsFor(requestId) {
        return attemptsByRequestId[requestId] || [];
    }

    global.abj404TransportTelemetry = {
        beginAttempt: beginAttempt,
        xhrFactory: xhrFactory,
        finishAttempt: finishAttempt,
        attemptsFor: attemptsFor
    };
} /* abj404-client-module:end */));
