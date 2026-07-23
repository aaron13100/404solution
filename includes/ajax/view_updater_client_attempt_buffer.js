/**
 * One tab's attempt buffer: reading it, reconciling a write against it, and
 * keeping it inside its capacity bound.
 *
 * Every durable attempt record lives in a buffer keyed by the tab that wrote
 * it. This module owns what is INSIDE one of those keys -- the stored shape,
 * the parse, the merge, the eviction rule, and the quota failure -- and knows
 * nothing about which key belongs to which tab or how many buffers the origin
 * holds; that is view_updater_client_telemetry_store.js.
 *
 * The eviction rule is the reason this is its own module rather than inline
 * bookkeeping: it is the retention promise the whole diagnostic rests on
 * (matrix requirement 4, "never outcome-delete telemetry"). Nothing is dropped
 * for having succeeded or for having already been reported; when the bound is
 * reached the OLDEST SUCCESS goes first, and a failure is evicted only when
 * the buffer holds nothing but failures. A quiet hour of successful polls can
 * therefore never push out the one failure the admin is about to report.
 *
 * Globals defined: abj404ClientAttemptBuffer.
 */
(function (global) {
    'use strict';

    /** 1: single shared buffer. 2: per-tab buffer, with a last-write stamp. */
    var STATE_VERSION = 2;

    /** Records kept at once, per tab. Three attempts per part, three parts, plus headroom. */
    var MAX_RECORDS = 16;

    /**
     * Serialized byte ceiling for ONE tab's buffer. localStorage is a shared
     * 5 MB-ish origin quota, so the diagnostic buffers stay a rounding error
     * against it and can never be the reason another admin script fails to
     * write.
     */
    var MAX_BYTES = 48000;

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

    /** @returns {number} wall-clock milliseconds, the only clock shared across tabs. */
    function nowMs() {
        return Date.now(); // allow-direct-time: a buffer's last-write stamp is compared across tabs, so it must use one wall clock
    }

    /** @returns {object|null} */
    function storage() {
        try {
            return global.localStorage || null; // allow-direct-storage: this IS the attempt-buffer adapter
        } catch (accessError) {
            // Reading window.localStorage itself throws when storage is
            // blocked by policy (third-party-cookie style restrictions).
            warn('localStorage is unavailable for transport telemetry', accessError);
            return null;
        }
    }

    /** @returns {{v: number, t: number, records: Array<object>}} */
    function emptyState() {
        return { v: STATE_VERSION, t: nowMs(), records: [] };
    }

    /**
     * One buffer's parsed state, or null when the key is absent, unreadable or
     * corrupt. Null rather than an empty state so a caller can tell "no such
     * buffer" from "a buffer that holds nothing".
     *
     * @param {string} key
     * @returns {{v: number, t: number, records: Array<object>}|null}
     */
    function readStateAt(key) {
        var store = storage();
        if (store === null) {
            return null;
        }
        try {
            var raw = store.getItem(key);
            if (!raw) {
                return null;
            }
            var parsed = JSON.parse(raw);
            if (!parsed || typeof parsed !== 'object' || !Array.isArray(parsed.records)) {
                return null;
            }
            var stamp = typeof parsed.t === 'number' && isFinite(parsed.t) ? parsed.t : 0;
            return { v: STATE_VERSION, t: stamp, records: parsed.records };
        } catch (readError) {
            warn('could not read the transport telemetry buffer ' + key, readError);
            return null;
        }
    }


    /**
     * Index of the oldest record whose outcome was a success, or -1 when the
     * buffer holds only failures.
     *
     * @param {Array<object>} records
     * @returns {number}
     */
    function indexOfOldestSuccess(records) {
        for (var i = 0; i < records.length; i++) {
            if (records[i] && records[i].outcome === 'success') {
                return i;
            }
        }
        return -1;
    }

    /**
     * Enforce the per-buffer capacity bound, evicting successes before failures.
     *
     * @param {{records: Array<object>}} state
     * @returns {string} the serialized state that fits the bound.
     */
    function trimToCapacity(state) {
        var serialized = JSON.stringify(state);
        while (state.records.length > 1 &&
                (state.records.length > MAX_RECORDS || serialized.length > MAX_BYTES)) {
            var evictIndex = indexOfOldestSuccess(state.records);
            state.records.splice(evictIndex < 0 ? 0 : evictIndex, 1);
            serialized = JSON.stringify(state);
        }
        return serialized;
    }

    /**
     * The state to persist, reconciled against whatever is in the key right
     * now. Per-tab keys already make a cross-tab clobber impossible; this
     * covers the one case that still shares a key, a duplicated tab that
     * inherited another tab's sessionStorage identity, by keeping any record
     * id present in storage that the in-memory state does not know about.
     *
     * @param {string} key
     * @param {{records: Array<object>}} state
     * @returns {{v: number, t: number, records: Array<object>}}
     */
    function reconcileWithStored(key, state) {
        var merged = [];
        var known = {};
        var i;
        for (i = 0; i < state.records.length; i++) {
            var record = state.records[i];
            if (record && typeof record.id === 'string' && record.id !== '') {
                known[record.id] = true;
            }
            merged.push(record);
        }
        var stored = readStateAt(key);
        if (stored !== null) {
            for (i = 0; i < stored.records.length; i++) {
                var existing = stored.records[i];
                if (existing && typeof existing.id === 'string' && existing.id !== '' &&
                        !Object.prototype.hasOwnProperty.call(known, existing.id)) {
                    merged.push(existing);
                }
            }
        }
        return { v: STATE_VERSION, t: nowMs(), records: merged };
    }

    /**
     * Persist one buffer, reconciled and trimmed.
     *
     * Reports failure rather than trying to make room: quota exhaustion is a
     * fact about the ORIGIN (how many buffers other tabs left behind), and
     * which of those is least valuable to reclaim is a decision this module
     * cannot make from inside one key. The caller retries after reclaiming.
     *
     * @param {string} key
     * @param {{records: Array<object>}} state
     * @returns {boolean} true when the state reached storage.
     */
    function writeStateAt(key, state) {
        var store = storage();
        if (store === null) {
            return false;
        }
        try {
            store.setItem(key, trimToCapacity(reconcileWithStored(key, state)));
            return true;
        } catch (writeError) {
            // Quota exhaustion and private-mode write blocks both land here.
            warn('could not persist a transport telemetry record', writeError);
            return false;
        }
    }


    global.abj404ClientAttemptBuffer = {
        read: readStateAt,
        write: writeStateAt,
        empty: emptyState,
        MAX_RECORDS: MAX_RECORDS
    };
})(typeof window !== 'undefined' ? window : this);
