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
 *   3. The admin failure notice, as one compact line per attempt inside the
 *      diagnostic block the notice already renders.
 *
 * Recording lives in view_updater_transport_telemetry.js; durable storage
 * lives in view_updater_client_telemetry_store.js. This module reads both and
 * writes neither, apart from marking a record delivered as it hands it out.
 *
 * Globals defined: abj404TransportTelemetryDelivery.
 */
(function (global) {
    'use strict';

    /** Prior-attempt reports ride a request param, so they carry a hard bound. */
    var MAX_REPORT_CHARS = 4000;

    /** Events kept when a report has to be trimmed to fit the bound. */
    var TRIMMED_EVENT_COUNT = 12;

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
     * Serialize one record within the request-param bound. When it does not
     * fit, the timeline is trimmed rather than the identity: which attempt
     * this was, and how it ended, matter more than its middle events.
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
            return JSON.stringify(trimmed).slice(0, MAX_REPORT_CHARS);
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
            var form = new global.FormData();
            form.append('action', 'ajaxUpdatePaginationLinks');
            form.append('clientReportOnly', '1');
            form.append('requestId', String(record.id || ''));
            form.append('sessionId', String(record.sid || ''));
            form.append('nonce', String(nonce || ''));
            form.append('subpage', String(record.subpage || ''));
            form.append('clientReport', serializeBounded(record));
            return global.navigator.sendBeacon(url, form) === true;
        } catch (beaconError) {
            warn('could not queue the transport telemetry beacon', beaconError);
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
        timelineLines: timelineLines,
        describeAttempt: describeAttempt,
        MAX_REPORT_CHARS: MAX_REPORT_CHARS
    };
})(typeof window !== 'undefined' ? window : this);
