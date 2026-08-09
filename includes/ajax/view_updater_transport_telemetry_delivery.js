/**
 * Getting a recorded transport attempt out of the browser.
 *
 * A record that only exists in the tab that produced it proves nothing: the
 * beta.1 investigation ended with three timed-out requests and no client-side
 * evidence at all. This module owns every route a finished record can take to
 * someone who can read it, and nothing else:
 *
 *   1. The next request's params. The newest not-yet-delivered record rides
 *      the following table request, so the server journals the client's view
 *      of a failure even when the admin never sends a support request. This is
 *      the primary channel precisely because it reuses the request path that
 *      is already working often enough for the admin to still be on the page.
 *   2. navigator.sendBeacon, after the final attempt of a request fails.
 *      Supplemental only: a beacon may be queued behind the very connection
 *      that is stalling, so it can add evidence but must never be relied on.
 *      It is its own HTTP request and travels under its own carrier id (see
 *      beaconCarrierId), naming the attempt it reports on separately.
 *   3. The admin failure notice, as one compact line per attempt inside the
 *      diagnostic block the notice already renders.
 *
 * Recording lives in view_updater_transport_telemetry.js; durable storage
 * lives in view_updater_client_telemetry_store.js. This module reads both and
 * writes neither, apart from marking a record delivered as it hands it out.
 *
 * Globals defined: abj404TransportTelemetryDelivery.
 */
(function (global, abj404Module) {
    if (global.abj404ClientBuildRegistry) {
        global.abj404ClientBuildRegistry.register('telemetry_delivery', abj404Module);
    }
    abj404Module(global);
}(typeof window !== 'undefined' ? window : this, /* abj404-client-module:start */ function (global) {
    'use strict';

    /** Prior-attempt reports ride a request param, so they carry a hard bound. */
    var MAX_REPORT_CHARS = 4000;

    /** Events kept when a report has to be trimmed to fit the bound. */
    var TRIMMED_EVENT_COUNT = 12;

    /**
     * Fields dropped whole, in order, when trimming the events array is not
     * enough to fit the bound -- an env.pageErrors array alone (up to 40
     * entries) or the other environment/asset detail can outweigh the
     * timeline. Each entry is a path into the record; the field is deleted
     * entirely rather than partially cut, so the result stays valid JSON at
     * every step. Ordered least-diagnostic-value first: page-environment
     * detail and response headers before the event timeline itself, which is
     * dropped last of the optional fields. Identity/outcome fields (id, rid,
     * part, attempt, subpage, outcome, durationMs, rs, status, bytes) are
     * never in this list -- which attempt this was and how it ended matter
     * more than any detail field, per the docstring on serializeBounded().
     */
    var TRIM_FIELD_ORDER = [
        ['assets'],
        ['inflightIdsAtSend'],
        ['rt'],
        ['env', 'moduleInstances'],
        ['env', 'lifecycle'],
        ['env', 'pageErrors'],
        ['env', 'longtasks'],
        ['env', 'foreignAjax'],
        ['env', 'inflight'],
        ['env', 'tabs'],
        ['headers'],
        ['env'],
        ['events']
    ];

    /** @param {string} message @param {*} error @returns {void} */
    function warn(message, error) {
        if (global.console && global.console.warn) {
            global.console.warn('404 Solution: ' + message, error);
        }
    }

    /** @returns {object|null} */
    function store() {
        return global.abj404ClientTelemetryStore || null;
    }

    /**
     * Delete one field (or nested field) from a record in place.
     *
     * @param {object} record
     * @param {Array<string>} path
     * @returns {boolean} true when a field was actually removed.
     */
    function dropField(record, path) {
        var target = record;
        for (var i = 0; i < path.length - 1; i++) {
            if (!target[path[i]] || typeof target[path[i]] !== 'object') {
                return false;
            }
            target = target[path[i]];
        }
        var key = path[path.length - 1];
        if (!target || !Object.prototype.hasOwnProperty.call(target, key)) {
            return false;
        }
        delete target[key];
        return true;
    }

    /**
     * Last-resort record: only the scalar identity/outcome fields, which are
     * already known to be small and bounded. Reached only if dropping every
     * field in TRIM_FIELD_ORDER still left the record over budget.
     *
     * @param {object} record
     * @returns {string}
     */
    function minimalIdentityRecord(record) {
        return JSON.stringify({
            v: record.v,
            id: record.id,
            rid: record.rid,
            sid: record.sid,
            part: record.part,
            subpage: record.subpage,
            attempt: record.attempt,
            outcome: record.outcome,
            durationMs: record.durationMs,
            rs: record.rs,
            status: record.status,
            bytes: record.bytes,
            fieldsDropped: 'all-but-identity'
        });
    }

    /**
     * Serialize one record within the request-param bound. When it does not
     * fit, structural boundaries are cut -- never the serialized string
     * itself, which can land mid-token and hand the server invalid JSON. The
     * event timeline is trimmed first (which attempt this was, and how it
     * ended, matter more than its middle events); if that alone is not
     * enough, whole fields are dropped in a fixed priority order until the
     * JSON.stringify output fits.
     *
     * @param {object} record
     * @returns {string}
     */
    function serializeBounded(record) {
        try {
            var serialized = JSON.stringify(record);
            if (serialized.length <= MAX_REPORT_CHARS) {
                return serialized;
            }
            var trimmed = JSON.parse(serialized);
            var events = Array.isArray(trimmed.events) ? trimmed.events : [];
            trimmed.eventsDropped = (trimmed.eventsDropped || 0) +
                Math.max(0, events.length - TRIMMED_EVENT_COUNT);
            trimmed.events = events.slice(-TRIMMED_EVENT_COUNT);
            serialized = JSON.stringify(trimmed);
            if (serialized.length <= MAX_REPORT_CHARS) {
                return serialized;
            }
            trimmed.fieldsDropped = [];
            for (var i = 0; i < TRIM_FIELD_ORDER.length && serialized.length > MAX_REPORT_CHARS; i++) {
                if (dropField(trimmed, TRIM_FIELD_ORDER[i])) {
                    trimmed.fieldsDropped.push(TRIM_FIELD_ORDER[i].join('.'));
                    serialized = JSON.stringify(trimmed);
                }
            }
            return serialized.length <= MAX_REPORT_CHARS ? serialized : minimalIdentityRecord(trimmed);
        } catch (serializeError) {
            warn('could not serialize a transport telemetry record', serializeError);
            return '';
        }
    }

    /**
     * The newest record that has not yet reached the server, serialized for a
     * request parameter and marked delivered. Returns '' when there is nothing
     * undelivered, so a healthy session sends no payload at all.
     *
     * @returns {string}
     */
    function priorReportParam() {
        var buffer = store();
        if (!buffer) {
            return '';
        }
        var record = buffer.takeUndelivered();
        return record ? serializeBounded(record) : '';
    }

    /**
     * The ledger id shape the server accepts for a request id
     * (ABJ_404_Solution_AjaxRequestLedger::ID_PATTERN, declared identically in
     * contracts/schemas/ajax-update-pagination.schema.json). Anything outside
     * it is degraded server-side to the unknown-id sentinel, which is the one
     * outcome that would make a carrier's records unjoinable.
     */
    var MIN_LEDGER_ID_CHARS = 8;
    var MAX_LEDGER_ID_CHARS = 64;

    /** Beacons this page has sent, so each one gets a carrier id of its own. */
    var beaconsSent = 0;

    /**
     * The id a beacon's OWN HTTP request travels under.
     *
     * A beacon is a second request -- one that succeeds, does no table work,
     * and exists to talk about a first one that failed. Sending it under the
     * failed attempt's id put the beacon's own lifecycle records (its
     * report-only branch, its request end, its exit sentinel) inside that
     * attempt's journal group, with two costs: the attempt appeared to have
     * reached an orderly end when in fact only its messenger had, and the
     * messenger's records were charged against the attempt's reserved share of
     * a bounded support payload. So the carrier gets an id of its own and
     * names the attempt separately, in reportedAttemptId.
     *
     * Derived from the attempt id rather than freshly random, so the pair
     * still reads together at a glance ('...t2' is reported by '...t2b000')
     * and so a truncated payload that keeps only one of them still names the
     * other. The sequence suffix is fixed-width: two beacons that padded into
     * the same id would re-create the commingling one directional step over.
     *
     * @param {object} record
     * @returns {string}
     */
    function beaconCarrierId(record) {
        var sequence = String(beaconsSent++);
        while (sequence.length < 3) {
            sequence = '0' + sequence;
        }
        var suffix = 'b' + sequence;
        var base = String((record && record.id) || '').replace(/[^A-Za-z0-9]/g, '');
        while (base.length + suffix.length < MIN_LEDGER_ID_CHARS) {
            base += '0';
        }
        return base.slice(0, MAX_LEDGER_ID_CHARS - suffix.length) + suffix;
    }

    /** Build the authenticated carrier envelope shared by telemetry beacons. */
    function beaconForm(record, nonce) {
        var form = new global.FormData();
        form.append('action', 'ajaxUpdatePaginationLinks');
        form.append('clientReportOnly', '1');
        form.append('requestId', beaconCarrierId(record));
        var reportedAttemptId = String(record.id || '');
        if (reportedAttemptId !== '') {
            form.append('reportedAttemptId', reportedAttemptId);
        }
        form.append('sessionId', String(record.sid || ''));
        form.append('nonce', String(nonce || ''));
        form.append('subpage', String(record.subpage || ''));
        form.append('retryCount', String(Math.max(0, Math.min(2,
            parseInt(record.attempt, 10) || 0))));
        return form;
    }

    /**
     * Supplemental last-chance delivery after the final attempt of a request
     * fails. The same record is already in durable storage and will ride the
     * next request, so a beacon the browser drops costs nothing.
     *
     * @param {string} url
     * @param {object} record
     * @param {string} nonce Passed in rather than read off the record: the
     *   record is persisted to browser storage and a nonce does not belong in
     *   a durable diagnostic buffer.
     * @returns {boolean} true when the browser accepted the beacon.
     */
    function sendBeacon(url, record, nonce) {
        try {
            if (!global.navigator || typeof global.navigator.sendBeacon !== 'function' || !record) {
                return false;
            }
            var form = beaconForm(record, nonce);
            form.append('clientReport', serializeBounded(record));
            return global.navigator.sendBeacon(url, form) === true;
        } catch (beaconError) {
            warn('could not queue the transport telemetry beacon', beaconError);
            return false;
        }
    }

    /**
     * Snapshot the server operation while the original request is still
     * pending, before jQuery's 25-second foreground deadline aborts it.
     */
    function sendThresholdBeacon(url, record, nonce, thresholdMs) {
        try {
            if (!global.navigator || typeof global.navigator.sendBeacon !== 'function' ||
                    !record || Number(thresholdMs) !== 20000) {
                return false;
            }
            var form = beaconForm(record, nonce);
            form.append('clientThresholdMs', '20000');
            return global.navigator.sendBeacon(url, form) === true;
        } catch (beaconError) {
            warn('could not queue the server-operation threshold beacon', beaconError);
            return false;
        }
    }

    /**
     * One compact line per attempt for the existing failure notice. No new UI
     * surface: these lines join the diagnostic block the notice already shows.
     *
     * @param {string} requestId
     * @returns {Array<string>}
     */
    function timelineLines(requestId) {
        if (!global.abj404TransportTelemetry) {
            return [];
        }
        var records = global.abj404TransportTelemetry.attemptsFor(requestId);
        var lines = [];
        for (var i = 0; i < records.length; i++) {
            lines.push(describeAttempt(records[i]));
        }
        return lines;
    }

    /**
     * The one line an admin (or the developer reading their support request)
     * sees for an attempt. Ordered so the two questions that separate the
     * whole cause space come first: how far the exchange got (readyState,
     * bytes) and how long it took.
     *
     * @param {object} record
     * @returns {string}
     */
    function describeAttempt(record) {
        var parts = [
            'attempt ' + record.attempt + ' (' + record.part + ')',
            record.outcome,
            (record.durationMs === null ? '?' : record.durationMs) + 'ms',
            'readyState ' + record.rs,
            record.bytes + ' bytes'
        ];
        if (record.firstHeadersMs !== null && typeof record.firstHeadersMs !== 'undefined') {
            parts.push('headers at ' + record.firstHeadersMs + 'ms');
        }
        if (record.rt) {
            parts.push('requestStart ' + record.rt.requestStart + ', responseStart ' + record.rt.responseStart);
            if (record.rt.nextHopProtocol) {
                parts.push(record.rt.nextHopProtocol);
            }
        } else {
            parts.push('resource timing ' + record.rtState);
        }
        if (record.env && record.env.longtasks && record.env.longtasks.maxMs > 0) {
            parts.push('longest main-thread block ' + record.env.longtasks.maxMs + 'ms');
        }
        if (record.env && record.env.inflight && record.env.inflight.count > 0) {
            parts.push(record.env.inflight.count + ' still in flight');
        }
        if (record.headers && record.headers['cf-ray']) {
            parts.push('CF-Ray ' + record.headers['cf-ray']);
        }
        return parts.join(', ');
    }

    global.abj404TransportTelemetryDelivery = {
        priorReportParam: priorReportParam,
        sendBeacon: sendBeacon,
        sendThresholdBeacon: sendThresholdBeacon,
        timelineLines: timelineLines,
        describeAttempt: describeAttempt,
        MAX_REPORT_CHARS: MAX_REPORT_CHARS
    };
} /* abj404-client-module:end */));
