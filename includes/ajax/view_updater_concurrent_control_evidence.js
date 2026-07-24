/**
 * Durable browser evidence for the control launched beside a real table attempt.
 *
 * The server can prove that both requests reached WordPress, but only the
 * browser knows whether each response arrived and whether their live intervals
 * actually overlapped. This module owns that paired record. It persists a
 * pending record immediately, merges settlements in either order, and exposes
 * a completion Promise to the later canary interpretation.
 *
 * Globals defined: abj404ConcurrentControlEvidence.
 *
 * Depends on view_updater_client_telemetry_store.js for persistence and
 * view_updater_canary_measurements.js for a late resource-timing re-read.
 */
(function (global, abj404Module) {
    if (global.abj404ClientBuildRegistry) {
        global.abj404ClientBuildRegistry.register('concurrent_control_evidence', abj404Module);
    }
    abj404Module(global);
}(typeof window !== 'undefined' ? window : this, /* abj404-client-module:start */ function (global) {
    'use strict';

    var RECORD_VERSION = 1;
    var RESOURCE_TIMING_RETRY_MS = 250;

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

    /** @param {*} value @returns {number|null} */
    function finiteNumber(value) {
        return typeof value === 'number' && isFinite(value) ? value : null;
    }

    /** @param {object} observation @returns {string} */
    function controlOutcome(observation) {
        var status = String((observation && observation.textStatus) || '');
        if (status === 'diagnostic-unavailable') {
            return 'unavailable';
        }
        if (observation && observation.ok === true) {
            return 'success';
        }
        if (status === 'timeout' || status === 'abort' || status === 'parsererror') {
            return status;
        }
        return 'error';
    }

    /** @param {object} observation @returns {object} */
    function receiptFrom(observation) {
        observation = observation || {};
        return {
            ok: observation.ok === true,
            textStatus: String(observation.textStatus || ''),
            contentEncoding: String(observation.contentEncoding || ''),
            transferBytes: finiteNumber(observation.transferBytes),
            encodedBodyBytes: finiteNumber(observation.encodedBodyBytes),
            decodedBodyBytes: finiteNumber(observation.decodedBodyBytes),
            resourceTimingState: String(observation.resourceTimingState || 'unavailable')
        };
    }

    /** @param {object} evidence @returns {void} */
    function persist(evidence) {
        var telemetryStore = store();
        if (telemetryStore && typeof telemetryStore.put === 'function') {
            telemetryStore.put(evidence);
        }
    }

    /** @param {object} evidence @returns {object} */
    function calculateOverlap(evidence) {
        var tableStart = finiteNumber(evidence.tableStartedAt);
        var tableEnd = finiteNumber(evidence.tableEndedAt);
        var controlStart = finiteNumber(evidence.controlStartedAt);
        var controlEnd = finiteNumber(evidence.controlEndedAt);
        if (controlStart === null || controlEnd === null) {
            return {
                state: 'unavailable',
                reason: 'control_not_started',
                durationMs: null,
                startedAt: null,
                endedAt: null
            };
        }
        if (tableStart === null || tableEnd === null) {
            return {
                state: 'pending',
                reason: 'table_not_settled',
                durationMs: null,
                startedAt: null,
                endedAt: null
            };
        }
        var startedAt = Math.max(tableStart, controlStart);
        var endedAt = Math.min(tableEnd, controlEnd);
        var duration = Math.max(0, endedAt - startedAt);
        return {
            state: 'computed',
            durationMs: Math.round(duration),
            startedAt: Math.round(startedAt),
            endedAt: Math.round(endedAt)
        };
    }

    /**
     * One lifecycle relay for the control associated with a table attempt.
     *
     * @param {object} tableRecord open record from transport telemetry.
     * @returns {{controlSettled: function(object): void, controlRejected: function(*): void,
     *   tableSettled: function(object): void, completion: function(): Promise<object>}}
     */
    function create(tableRecord) {
        tableRecord = tableRecord || {};
        var completed = false;
        var resolveCompletion;
        var completionPromise = new Promise(function (resolve) {
            resolveCompletion = resolve;
        });
        var evidence = {
            v: RECORD_VERSION,
            id: 'cc:' + String(tableRecord.id || ''),
            kind: 'concurrent_control_browser_receipt',
            rid: String(tableRecord.rid || ''),
            sid: String(tableRecord.sid || ''),
            part: 'concurrent_control',
            subpage: String(tableRecord.subpage || ''),
            controlForRequestId: String(tableRecord.id || ''),
            controlRequestId: '',
            sentAt: finiteNumber(tableRecord.sentAt) || Date.now(), // allow-direct-time: paired browser intervals use one wall-clock domain
            tableStartedAt: finiteNumber(tableRecord.sentAt),
            tableEndedAt: null,
            controlStartedAt: null,
            controlEndedAt: null,
            tableOutcome: 'pending',
            outcome: 'pending',
            receipt: {
                ok: false,
                textStatus: 'pending',
                contentEncoding: '',
                transferBytes: null,
                encodedBodyBytes: null,
                decodedBodyBytes: null,
                resourceTimingState: 'pending'
            },
            overlap: {
                state: 'pending',
                reason: 'requests_not_settled',
                durationMs: null,
                startedAt: null,
                endedAt: null
            },
            deliverable: false,
            deliveryRevision: 0
        };

        var finishIfReady = function () {
            if (completed || evidence.tableOutcome === 'pending' || evidence.outcome === 'pending') {
                persist(evidence);
                return;
            }
            evidence.overlap = calculateOverlap(evidence);
            evidence.deliverable = true;
            evidence.deliveryRevision = 1;
            completed = true;
            persist(evidence);
            resolveCompletion(evidence);
            scheduleResourceTimingPatch(evidence);
        };

        var controlSettled = function (observation) {
            observation = observation || {};
            evidence.controlRequestId = String(observation.requestId || '');
            evidence.controlStartedAt = finiteNumber(observation.startedAt);
            evidence.controlEndedAt = finiteNumber(observation.endedAt);
            evidence.outcome = controlOutcome(observation);
            evidence.receipt = receiptFrom(observation);
            finishIfReady();
        };

        persist(evidence);
        return {
            controlSettled: controlSettled,
            controlRejected: function (error) {
                warn('concurrent control observation rejected unexpectedly', error);
                controlSettled({
                    ok: false,
                    textStatus: 'diagnostic-error',
                    resourceTimingState: 'error'
                });
            },
            tableSettled: function (settledRecord) {
                settledRecord = settledRecord || {};
                evidence.tableOutcome = String(settledRecord.outcome || 'unknown');
                evidence.tableEndedAt = Date.now(); // allow-direct-time: same wall-clock domain as control settle
                finishIfReady();
            },
            completion: function () {
                return completionPromise;
            }
        };
    }

    /** @param {object} evidence @returns {void} */
    function scheduleResourceTimingPatch(evidence) {
        if (evidence.receipt.resourceTimingState !== 'missing' || !evidence.controlRequestId) {
            return;
        }
        global.setTimeout(function () {
            try {
                var measurements = global.abj404CanaryMeasurements;
                if (!measurements || typeof measurements.responseWireEvidence !== 'function') {
                    return;
                }
                var patch = measurements.responseWireEvidence(evidence.controlRequestId, null);
                if (!patch || patch.resourceTimingState !== 'found') {
                    return;
                }
                var priorEncoding = evidence.receipt.contentEncoding;
                evidence.receipt = receiptFrom(Object.assign({}, evidence.receipt, patch));
                evidence.receipt.contentEncoding = priorEncoding;
                evidence.deliveryRevision = 2;
                evidence.delivered = false;
                persist(evidence);
            } catch (timingError) {
                warn('could not patch concurrent control resource timing', timingError);
            }
        }, RESOURCE_TIMING_RETRY_MS);
    }

    global.abj404ConcurrentControlEvidence = {
        create: create
    };
} /* abj404-client-module:end */));
