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
 * The tab's identity (view_updater_client_tab_identity.js) and the cross-tab
 * presence registry (view_updater_client_tab_presence.js) are separate
 * modules it reads: this one keys and bounds buffers, they answer who and how
 * many.
 *
 * ONE BUFFER PER TAB, never one shared buffer. localStorage is shared by every
 * same-origin tab, and a shared key written with the obvious
 * get-parse-mutate-stringify-set sequence loses records: two tabs that both
 * read before either writes leave only the second tab's version behind, and
 * the record that disappears is as likely as not the failing attempt the admin
 * is about to report. Keying the buffer by tab makes that impossible by
 * construction rather than merely unlikely -- no tab ever writes a key another
 * tab writes. Reads (readAll) union every tab's buffer, so a support request
 * still carries the whole origin's evidence; writes and delivery marking stay
 * strictly local to the writing tab. Chrome's "duplicate tab" copies
 * sessionStorage, which can give two live tabs the same identity, so a write
 * additionally merges against whatever is in its own key at write time.
 *
 * Two consumers read it, and only one of them consumes:
 *   1. The next outgoing table request carries the newest not-yet-delivered
 *      record THIS TAB produced in its params (takeUndelivered), so the server
 *      pairs the client and server views of a failure even when no support
 *      request is sent. Delivery is never marked on another tab's record: a
 *      record flagged delivered by a request that did not carry it is evidence
 *      recorded as sent that the server never received.
 *   2. The support request reads everything, from every tab (readAll), and
 *      leaves it all in place.
 *
 * Retention rule (matrix requirement 4, "never outcome-delete telemetry"):
 * nothing is dropped because it succeeded or because it was already reported.
 * Capacity is the only bound, at two levels. Inside one tab's buffer the
 * OLDEST SUCCESS is evicted first and a failure only when nothing but failures
 * remain. Across tabs, buffers left behind by tabs that are gone are reaped
 * only once the origin holds more than MAX_TAB_BUFFERS of them, and the ones
 * holding no failure go first.
 *
 * Globals defined: abj404ClientTelemetryStore.
 *
 * Depends on view_updater_client_tab_identity.js (which tab's buffer this is)
 * and view_updater_client_tab_presence.js (which tabs are still open).
 */
(function (global, abj404Module) {
    if (global.abj404ClientBuildRegistry) {
        global.abj404ClientBuildRegistry.register('telemetry_store', abj404Module);
    }
    abj404Module(global);
}(typeof window !== 'undefined' ? window : this, /* abj404-client-module:start */ function (global) {
    'use strict';

    /**
     * The pre-per-tab single shared key. Read-only from here on: it may still
     * hold records written by a previous build, and those are reported like
     * any other, but nothing writes it again.
     */
    var LEGACY_KEY = 'abj404:client_transport_telemetry';

    /** One attempt buffer per tab, suffixed with that tab's session id. */
    var TAB_KEY_PREFIX = 'abj404:client_transport_telemetry:tab:';

    /**
     * Attempt buffers kept for the whole origin. Every tab that is opened and
     * closed leaves one behind (a closed tab cannot clean up after itself), so
     * without a ceiling an admin who works in tabs would eventually spend the
     * origin quota on evidence from sessions nobody will ever ask about.
     */
    var MAX_TAB_BUFFERS = 8;

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

    /**
     * One buffer's parsed state, or null when the key is absent, unreadable or
     * corrupt, from the module that owns what is inside a buffer.
     *
     * @param {string} key
     * @returns {{v: number, t: number, records: Array<object>}|null}
     */
    function readStateAt(key) {
        var buffer = global.abj404ClientAttemptBuffer;
        return buffer && typeof buffer.read === 'function' ? buffer.read(key) : null;
    }

    /**
     * @param {string} key
     * @param {{records: Array<object>}} state
     * @returns {{stored: boolean, persisted: boolean, health: object}}
     */
    function writeStateAt(key, state) {
        var buffer = global.abj404ClientAttemptBuffer;
        if (!buffer || typeof buffer.write !== 'function') {
            return {
                stored: false,
                persisted: false,
                health: unavailableStorageHealth()
            };
        }
        var result = buffer.write(key, state);
        if (!result.persisted && result.health && result.health.quota === 'exceeded') {
            // The write failed with the buffer already at its own bound, so
            // the space it is competing with is the buffers left behind by
            // tabs that are gone. Reclaim the least valuable one and retry.
            if (reapAbandonedBuffers(1) > 0) {
                result = buffer.write(key, state);
            }
        }
        return result;
    }

    /** @returns {object} */
    function unavailableStorageHealth() {
        return {
            status: 'unavailable',
            accessible: false,
            writable: false,
            quota: 'unknown',
            last_write_ok: false,
            fallback: 'memory'
        };
    }

    /** @returns {object} */
    function storageHealth() {
        var buffer = global.abj404ClientAttemptBuffer;
        return buffer && typeof buffer.health === 'function'
            ? buffer.health(ownBufferKey()) : unavailableStorageHealth();
    }

    /** @returns {{v: number, t: number, records: Array<object>}} an empty buffer state. */
    function emptyState() {
        var buffer = global.abj404ClientAttemptBuffer;
        return buffer && typeof buffer.empty === 'function'
            ? buffer.empty() : { v: 2, t: 0, records: [] };
    }

    /** @returns {string} this tab's identity, from the module that owns it. */
    function tabId() {
        var identity = global.abj404ClientTabIdentity;
        return identity && typeof identity.id === 'function' ? identity.id() : 'identitymissing';
    }

    /** @returns {object} tab ids with a live presence entry, mapped to their age. */
    function liveTabIds() {
        var presence = global.abj404ClientTabPresence;
        return presence && typeof presence.liveTabIds === 'function' ? presence.liveTabIds() : {};
    }

    /**
     * Announce this tab as active. Called on every write because a tab that is
     * producing telemetry is by definition open, and the buffer reaper below
     * must never mistake it for one that is gone.
     *
     * @returns {void}
     */
    function announceThisTab() {
        var presence = global.abj404ClientTabPresence;
        if (presence && typeof presence.announce === 'function') {
            presence.announce();
        }
    }

    /**
     * Every localStorage key starting with the given prefix, collected before
     * anything is removed (removing during an index walk skips entries).
     *
     * @param {string} prefix
     * @returns {Array<string>}
     */
    function keysWithPrefix(prefix) {
        var names = [];
        var store = storage();
        if (store === null) {
            return names;
        }
        try {
            var total = typeof store.length === 'number' ? store.length : 0;
            for (var i = 0; i < total; i++) {
                var name = store.key(i);
                if (typeof name === 'string' && name.indexOf(prefix) === 0) {
                    names.push(name);
                }
            }
        } catch (enumerateError) {
            warn('could not enumerate the transport telemetry buffers', enumerateError);
        }
        return names;
    }

    /** @returns {string} the localStorage key holding THIS tab's attempt buffer. */
    function ownBufferKey() {
        return TAB_KEY_PREFIX + tabId();
    }

    /** @returns {{v: number, t: number, records: Array<object>}} this tab's buffer. */
    function readOwnState() {
        var state = readStateAt(ownBufferKey());
        return state === null ? emptyState() : state;
    }

    /**
     * Attempt buffers belonging to tabs that are demonstrably gone (no live
     * presence entry), never this tab's own, ordered least-valuable first:
     * buffers holding no failure before buffers holding one, then oldest
     * last-write before newest.
     *
     * @returns {Array<string>}
     */
    function abandonedBufferKeys() {
        var live = liveTabIds();
        var own = ownBufferKey();
        var candidates = [];
        var keys = keysWithPrefix(TAB_KEY_PREFIX);
        for (var i = 0; i < keys.length; i++) {
            var tabId = keys[i].slice(TAB_KEY_PREFIX.length);
            if (keys[i] === own || Object.prototype.hasOwnProperty.call(live, tabId)) {
                continue;
            }
            var state = readStateAt(keys[i]);
            candidates.push({
                key: keys[i],
                failures: state === null ? 0 : countFailures(state.records),
                t: state === null ? 0 : state.t
            });
        }
        candidates.sort(function (left, right) {
            if (left.failures !== right.failures) {
                return left.failures - right.failures;
            }
            return left.t - right.t;
        });
        var ordered = [];
        for (var j = 0; j < candidates.length; j++) {
            ordered.push(candidates[j].key);
        }
        return ordered;
    }

    /** @param {Array<object>} records @returns {number} */
    function countFailures(records) {
        var failures = 0;
        for (var i = 0; i < records.length; i++) {
            if (records[i] && records[i].outcome !== 'success') {
                failures++;
            }
        }
        return failures;
    }

    /**
     * Remove up to `count` abandoned buffers. Removing another tab's key is
     * safe only because the tab is gone; a live tab's buffer is never a
     * candidate, and removeItem is idempotent when two tabs reap the same
     * dead key at once.
     *
     * @param {number} count
     * @returns {number} how many were removed.
     */
    function reapAbandonedBuffers(count) {
        var store = storage();
        if (store === null || count <= 0) {
            return 0;
        }
        var keys = abandonedBufferKeys();
        var removed = 0;
        for (var i = 0; i < keys.length && removed < count; i++) {
            try {
                store.removeItem(keys[i]);
                removed++;
            } catch (removeError) {
                warn('could not reclaim an abandoned transport telemetry buffer', removeError);
            }
        }
        return removed;
    }

    /** @returns {void} */
    function enforceBufferBudget() {
        var overflow = keysWithPrefix(TAB_KEY_PREFIX).length - MAX_TAB_BUFFERS;
        if (overflow > 0) {
            reapAbandonedBuffers(overflow);
        }
    }

    /**
     * Persist one finalized attempt record into THIS tab's buffer, replacing
     * any earlier revision of the same attempt. Upsert rather than append
     * because a record is written when the attempt settles and rewritten if
     * late-arriving resource timing patches it; two revisions of one attempt
     * in the buffer would look like two attempts to whoever reads the report.
     *
     * @param {object} record
     * @returns {boolean}
     */
    function put(record) {
        if (!record || typeof record !== 'object') {
            return false;
        }
        record.storage_health = storageHealth();
        announceThisTab();
        var key = ownBufferKey();
        var state = readOwnState();
        var replaced = false;
        for (var i = 0; i < state.records.length; i++) {
            if (state.records[i] && record.id && state.records[i].id === record.id) {
                // Most late patches (for example a table's resource timing)
                // describe the same delivered revision and must not make it
                // ride twice. A control receipt is different: its pending
                // revision is durable but deliberately not deliverable, and
                // the final outcome/overlap revision must get one real relay.
                var priorRevision = typeof state.records[i].deliveryRevision === 'number'
                    ? state.records[i].deliveryRevision : 0;
                var nextRevision = typeof record.deliveryRevision === 'number'
                    ? record.deliveryRevision : 0;
                record.delivered = nextRevision > priorRevision
                    ? false : (record.delivered || state.records[i].delivered);
                state.records[i] = record;
                replaced = true;
                break;
            }
        }
        if (!replaced) {
            state.records.push(record);
        }
        var result = writeStateAt(key, state);
        record.storage_health = result.health;
        enforceBufferBudget();
        return result.stored;
    }

    /**
     * Newest record THIS TAB produced that has not yet ridden a request to the
     * server, marked delivered as it is handed out. Returns null when
     * everything this tab stored has already been reported.
     *
     * Deliberately scoped to this tab's own buffer: marking another tab's
     * record delivered would retire evidence that this request is not
     * carrying, and that tab will carry it on its own next request.
     *
     * @returns {object|null}
     */
    function takeUndelivered() {
        var key = ownBufferKey();
        var state = readOwnState();
        // The same-phase control is a required diagnostic receipt, not one
        // ordinary attempt among many. A counts request can settle after the
        // control and otherwise become the newest record forever one carrier
        // ahead of it. Prefer the finalized control receipt so the next real
        // request journals it deterministically.
        for (var priority = state.records.length - 1; priority >= 0; priority--) {
            var required = state.records[priority];
            if (required && required.kind === 'concurrent_control_browser_receipt'
                    && required.deliverable !== false && required.delivered !== true) {
                required.delivered = true;
                writeStateAt(key, state);
                return required;
            }
        }
        // A finalized control can consume the carrier that historically sent
        // the table attempt itself. Keep that table record ahead of the newer
        // counts/pagination records so the two remaining progressive requests
        // deterministically deliver both halves instead of starving the table
        // account behind its own follow-up traffic.
        for (var tablePriority = state.records.length - 1; tablePriority >= 0; tablePriority--) {
            var tableRecord = state.records[tablePriority];
            if (tableRecord && tableRecord.part === 'table'
                    && tableRecord.deliverable !== false && tableRecord.delivered !== true) {
                tableRecord.delivered = true;
                writeStateAt(key, state);
                return tableRecord;
            }
        }
        for (var i = state.records.length - 1; i >= 0; i--) {
            var record = state.records[i];
            if (record && record.deliverable !== false && record.delivered !== true) {
                record.delivered = true;
                writeStateAt(key, state);
                return record;
            }
        }
        return null;
    }

    /**
     * Every stored record from every tab of this origin, oldest first by send
     * time. A pure read: nothing is removed or marked, so a failed send can be
     * retried and a second support request still carries the same history.
     * takeUndelivered() is the consuming half of this pair; this is the half
     * that may be called as many times as a caller likes with the same answer.
     *
     * @returns {Array<object>}
     */
    function readAll() {
        var sources = [LEGACY_KEY].concat(keysWithPrefix(TAB_KEY_PREFIX));
        var own = ownBufferKey();
        if (sources.indexOf(own) < 0) {
            sources.push(own);
        }
        var records = [];
        for (var i = 0; i < sources.length; i++) {
            var state = readStateAt(sources[i]);
            if (state === null) {
                continue;
            }
            for (var j = 0; j < state.records.length; j++) {
                records.push(state.records[j]);
            }
        }
        // Stable by construction: records the browser never stamped (an
        // older build, or a hand-written buffer) sort as 0 and keep the order
        // they were read in rather than being shuffled to the front.
        return records.sort(function (left, right) {
            return sentAtOf(left) - sentAtOf(right);
        });
    }

    /** @param {object} record @returns {number} */
    function sentAtOf(record) {
        return record && typeof record.sentAt === 'number' && isFinite(record.sentAt)
            ? record.sentAt : 0;
    }

    /**
     * Drop every attempt buffer this origin holds, this tab's included.
     * Presence entries are left alone: they are not telemetry payload, and
     * clearing them would make every other open tab look closed.
     *
     * @returns {void}
     */
    function clear() {
        var store = storage();
        var keys = [LEGACY_KEY].concat(keysWithPrefix(TAB_KEY_PREFIX));
        var own = ownBufferKey();
        if (keys.indexOf(own) < 0) {
            keys.push(own);
        }
        for (var i = 0; i < keys.length; i++) {
            if (store !== null) {
                try {
                    store.removeItem(keys[i]);
                } catch (removeError) {
                    warn('could not clear the transport telemetry buffer ' + keys[i], removeError);
                }
            }
            var buffer = global.abj404ClientAttemptBuffer;
            if (buffer && typeof buffer.clearMemory === 'function') {
                buffer.clearMemory(keys[i]);
            }
        }
    }

    /** @returns {number} the per-buffer record ceiling the buffer module enforces. */
    function maxRecords() {
        var buffer = global.abj404ClientAttemptBuffer;
        return buffer && typeof buffer.MAX_RECORDS === 'number' ? buffer.MAX_RECORDS : 0;
    }

    global.abj404ClientTelemetryStore = {
        put: put,
        takeUndelivered: takeUndelivered,
        readAll: readAll,
        clear: clear,
        storageHealth: storageHealth,
        LEGACY_KEY: LEGACY_KEY,
        TAB_KEY_PREFIX: TAB_KEY_PREFIX,
        MAX_RECORDS: maxRecords(),
        MAX_TAB_BUFFERS: MAX_TAB_BUFFERS
    };
} /* abj404-client-module:end */));
