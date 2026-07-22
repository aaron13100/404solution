/**
 * Durable storage adapter for client transport telemetry.
 *
 * An attempt record is only useful if it survives longer than the page that
 * produced it: the browser may be reloaded, the tab may be discarded, and the
 * admin may not click "send debug log" until several failures later. This
 * module is the ONLY file in the telemetry stack that touches browser storage,
 * so every other telemetry module stays stateless with respect to persistence
 * and there is exactly one place where a quota or policy failure is handled.
 *
 * It owns two durable surfaces: the cross-page ring buffer of attempt records
 * (localStorage) and the per-tab session identity that joins them
 * (sessionStorage, so a reload keeps reporting one session while a second tab
 * reports its own).
 *
 * Two consumers drain it:
 *   1. The next outgoing table request carries the newest not-yet-delivered
 *      record in its params (takeUndelivered), so the server pairs the client
 *      and server views of a failure even when no support request is sent.
 *   2. The support request drains everything (drainAll) into the payload.
 *
 * Retention rule (matrix requirement 4, "never outcome-delete telemetry"):
 * nothing is dropped because it succeeded or because it was already reported.
 * Capacity is the only bound. When the cap is reached the OLDEST SUCCESS is
 * evicted first and a failure is evicted only when the buffer holds nothing
 * but failures, so a quiet hour of successful polls can never push the one
 * failure the user is about to report out of the buffer.
 *
 * Globals defined: abj404ClientTelemetryStore.
 */
(function (global) {
    'use strict';

    var STORAGE_KEY = 'abj404:client_transport_telemetry';
    var STATE_VERSION = 1;

    /** Records kept at once. Three attempts per part, three parts, plus headroom. */
    var MAX_RECORDS = 16;

    /**
     * Serialized byte ceiling. localStorage is a shared 5 MB-ish origin quota,
     * so the diagnostic buffer stays a rounding error against it and can never
     * be the reason another admin script fails to write.
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

    /** @returns {{v: number, records: Array<object>}} */
    function emptyState() {
        return { v: STATE_VERSION, records: [] };
    }

    /** @returns {object|null} */
    function storage() {
        try {
            return global.localStorage || null; // allow-direct-storage: this IS the telemetry storage adapter
        } catch (accessError) {
            // Reading window.localStorage itself throws when storage is
            // blocked by policy (third-party-cookie style restrictions).
            warn('localStorage is unavailable for transport telemetry', accessError);
            return null;
        }
    }

    /** @returns {object|null} */
    function tabStorage() {
        try {
            return global.sessionStorage || null; // allow-direct-storage: this IS the telemetry storage adapter
        } catch (accessError) {
            warn('sessionStorage is unavailable for transport telemetry', accessError);
            return null;
        }
    }

    /** @returns {{v: number, records: Array<object>}} */
    function readState() {
        var store = storage();
        if (store === null) {
            return emptyState();
        }
        try {
            var raw = store.getItem(STORAGE_KEY);
            if (!raw) {
                return emptyState();
            }
            var parsed = JSON.parse(raw);
            if (!parsed || typeof parsed !== 'object' || !Array.isArray(parsed.records)) {
                return emptyState();
            }
            return { v: STATE_VERSION, records: parsed.records };
        } catch (readError) {
            warn('could not read the transport telemetry buffer', readError);
            return emptyState();
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
     * Enforce the capacity bound, evicting successes before failures.
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
     * @param {{v: number, records: Array<object>}} state
     * @returns {boolean} true when the state reached storage.
     */
    function writeState(state) {
        var store = storage();
        if (store === null) {
            return false;
        }
        try {
            store.setItem(STORAGE_KEY, trimToCapacity(state));
            return true;
        } catch (writeError) {
            // Quota exhaustion and private-mode write blocks both land here.
            // The in-request telemetry path does not depend on this write, so
            // a failure is logged and the attempt record still rides the next
            // retry from memory.
            warn('could not persist a transport telemetry record', writeError);
            return false;
        }
    }

    /**
     * Persist one finalized attempt record, replacing any earlier revision of
     * the same attempt. Upsert rather than append because a record is written
     * when the attempt settles and rewritten if late-arriving resource timing
     * patches it; two revisions of one attempt in the buffer would look like
     * two attempts to whoever reads the report.
     *
     * @param {object} record
     * @returns {boolean}
     */
    function put(record) {
        if (!record || typeof record !== 'object') {
            return false;
        }
        var state = readState();
        for (var i = 0; i < state.records.length; i++) {
            if (state.records[i] && record.id && state.records[i].id === record.id) {
                // Keep the delivered flag: a patch must not make an already
                // reported record ride another request a second time.
                record.delivered = record.delivered || state.records[i].delivered;
                state.records[i] = record;
                return writeState(state);
            }
        }
        state.records.push(record);
        return writeState(state);
    }

    /**
     * Newest record that has not yet ridden a request to the server, marked
     * delivered as it is handed out. Returns null when everything stored has
     * already been reported.
     *
     * @returns {object|null}
     */
    function takeUndelivered() {
        var state = readState();
        for (var i = state.records.length - 1; i >= 0; i--) {
            var record = state.records[i];
            if (record && record.delivered !== true) {
                record.delivered = true;
                writeState(state);
                return record;
            }
        }
        return null;
    }

    /**
     * Every stored record, oldest first. Used by the support request; it does
     * NOT clear the buffer, so a failed send can be retried and a second
     * support request still carries the same history.
     *
     * @returns {Array<object>}
     */
    function drainAll() {
        return readState().records;
    }

    /** @returns {void} */
    function clear() {
        var store = storage();
        if (store === null) {
            return;
        }
        try {
            store.removeItem(STORAGE_KEY);
        } catch (removeError) {
            warn('could not clear the transport telemetry buffer', removeError);
        }
    }

    /**
     * Read a per-tab value, minting and persisting it via mintValue() on first
     * use. Used for the session identity that joins every attempt made from
     * one browser tab, including across reloads. Falls back to the freshly
     * minted value (without persistence) when sessionStorage is unavailable,
     * so the field is never empty.
     *
     * @param {string} key
     * @param {function(): string} mintValue
     * @param {RegExp} validPattern rejects a corrupt or foreign stored value.
     * @returns {string}
     */
    function tabScopedValue(key, mintValue, validPattern) {
        var minted = mintValue();
        var store = tabStorage();
        if (store === null) {
            return minted;
        }
        try {
            var stored = store.getItem(key);
            if (typeof stored === 'string' && validPattern.test(stored)) {
                return stored;
            }
            store.setItem(key, minted);
        } catch (tabError) {
            warn('could not persist the per-tab telemetry session id', tabError);
        }
        return minted;
    }

    global.abj404ClientTelemetryStore = {
        put: put,
        takeUndelivered: takeUndelivered,
        drainAll: drainAll,
        clear: clear,
        tabScopedValue: tabScopedValue,
        STORAGE_KEY: STORAGE_KEY,
        MAX_RECORDS: MAX_RECORDS
    };
})(typeof window !== 'undefined' ? window : this);
