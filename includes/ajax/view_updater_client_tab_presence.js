/**
 * How many admin tabs of this page are open right now.
 *
 * Same-site concurrency is one of the candidate causes of the admin table
 * timeout -- a second tab's own table request, plus the Heartbeat poll every
 * open admin screen runs, competing for the site's PHP worker allotment -- and
 * nothing in the surviving evidence used to record it, so a cross-tab cause
 * could not even be suspected after the fact. This module is the browser's
 * half of that measurement; the server's half is
 * ABJ_404_Solution_SameSiteRequestCensus.
 *
 * ONE ENTRY PER TAB, written only by the tab it names. Any shared-key scheme
 * that a tab reads-modifies-writes would lose entries exactly when several
 * tabs are active, which is the only condition under which the count matters
 * at all. A closed tab cannot clean up after itself, so an entry is live only
 * while it is inside the TTL, and an idle-but-open tab stays counted because
 * it re-announces whenever any other tab does (a `storage` event), with no
 * polling timer on the page.
 *
 * Globals defined: abj404ClientTabPresence.
 *
 * Depends on view_updater_client_tab_identity.js.
 */
(function (global, abj404Module) {
    if (global.abj404ClientBuildRegistry) {
        global.abj404ClientBuildRegistry.register('tab_presence', abj404Module);
    }
    abj404Module(global);
}(typeof window !== 'undefined' ? window : this, /* abj404-client-module:start */ function (global) {
    'use strict';

    /** One entry per tab: the wall-clock time that tab was last active. */
    var PRESENCE_PREFIX = 'abj404:client_tab_presence:';

    /**
     * How long an entry counts as an open tab. Generous on purpose: an admin
     * tab sitting idle on the table page IS open and IS still being polled by
     * the WordPress Heartbeat API, so treating it as closed after a minute or
     * two would undercount exactly the contention being measured.
     */
    var PRESENCE_TTL_MS = 900000;

    /** Minimum gap between announcements, so activity costs one write a minute at most. */
    var PRESENCE_REFRESH_MS = 30000;

    /** Ages carried in a snapshot. The count is the finding; the ages are context. */
    var MAX_AGES_REPORTED = 12;

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
        return Date.now(); // allow-direct-time: cross-tab presence must use one wall clock, not a per-page monotonic one
    }

    /** @returns {object|null} */
    function storage() {
        try {
            return global.localStorage || null; // allow-direct-storage: this IS the cross-tab presence adapter
        } catch (accessError) {
            warn('localStorage is unavailable for the cross-tab presence registry', accessError);
            return null;
        }
    }

    /** @returns {string} */
    function tabId() {
        var identity = global.abj404ClientTabIdentity;
        return identity && typeof identity.id === 'function' ? identity.id() : 'identitymissing';
    }

    /**
     * Every localStorage key naming a tab, collected before anything is read
     * so an index walk cannot be disturbed by a concurrent write.
     *
     * @returns {Array<string>}
     */
    function presenceKeys() {
        var names = [];
        var store = storage();
        if (store === null) {
            return names;
        }
        try {
            var total = typeof store.length === 'number' ? store.length : 0;
            for (var i = 0; i < total; i++) {
                var name = store.key(i);
                if (typeof name === 'string' && name.indexOf(PRESENCE_PREFIX) === 0) {
                    names.push(name);
                }
            }
        } catch (enumerateError) {
            warn('could not enumerate the cross-tab presence registry', enumerateError);
        }
        return names;
    }

    /**
     * Announce that this tab is alive, at most once per PRESENCE_REFRESH_MS.
     * The rate limit is also what stops two tabs answering each other's
     * storage events forever: by the time a tab is notified of a foreign
     * announcement its own entry is fresh, so its answer is a no-op.
     *
     * @returns {boolean} true when an entry was written.
     */
    function announce() {
        var store = storage();
        if (store === null) {
            return false;
        }
        try {
            var key = PRESENCE_PREFIX + tabId();
            var last = parseInt(store.getItem(key), 10);
            var now = nowMs();
            if (isFinite(last) && !isNaN(last) && (now - last) >= 0 && (now - last) < PRESENCE_REFRESH_MS) {
                return false;
            }
            store.setItem(key, String(now));
            return true;
        } catch (presenceError) {
            warn('could not record this tab in the cross-tab presence registry', presenceError);
            return false;
        }
    }

    /**
     * Tab ids with an entry inside the TTL, mapped to how old that entry is.
     *
     * @returns {object}
     */
    function liveTabIds() {
        var live = {};
        var store = storage();
        if (store === null) {
            return live;
        }
        var keys = presenceKeys();
        var now = nowMs();
        for (var i = 0; i < keys.length; i++) {
            var stamp;
            try {
                stamp = parseInt(store.getItem(keys[i]), 10);
            } catch (readError) {
                warn('could not read a cross-tab presence entry', readError);
                continue;
            }
            if (!isFinite(stamp) || isNaN(stamp)) {
                continue;
            }
            // A negative age is a tab whose clock runs ahead of this one, not
            // a dead tab: skew must never be read as absence.
            if ((now - stamp) <= PRESENCE_TTL_MS) {
                live[keys[i].slice(PRESENCE_PREFIX.length)] = now - stamp;
            }
        }
        return live;
    }

    /**
     * The count, refreshing this tab's own entry first so the counting tab is
     * always part of the count.
     *
     * @returns {{status: string, count: number, ages: Array<number>, ttlMs: number}}
     */
    function openTabs() {
        if (storage() === null) {
            return { status: 'unavailable', reason: 'storage_unavailable', count: -1, ages: [],
                ttlMs: PRESENCE_TTL_MS };
        }
        announce();
        var live = liveTabIds();
        var ages = [];
        for (var id in live) {
            if (Object.prototype.hasOwnProperty.call(live, id)) {
                ages.push(live[id]);
            }
        }
        ages.sort(function (left, right) { return left - right; });
        return {
            status: 'available',
            count: ages.length,
            ages: ages.slice(0, MAX_AGES_REPORTED),
            ttlMs: PRESENCE_TTL_MS
        };
    }

    /**
     * Answer a foreign tab's announcement so an idle tab still counts as open
     * the moment any other tab becomes active, without a polling timer.
     *
     * @returns {void}
     */
    function installListener() {
        try {
            if (!global || typeof global.addEventListener !== 'function') {
                return;
            }
            global.addEventListener('storage', function (event) {
                var key = event && typeof event.key === 'string' ? event.key : '';
                if (key.indexOf(PRESENCE_PREFIX) === 0 && key !== PRESENCE_PREFIX + tabId()) {
                    announce();
                }
            });
        } catch (listenError) {
            warn('could not observe cross-tab presence announcements', listenError);
        }
    }

    /**
     * Announce once the document has finished parsing, so a tab that is merely
     * OPEN (still polled by the Heartbeat API, still holding a worker slot
     * when it does poll) is counted even before it issues a table request.
     *
     * Deferred rather than announced at module-evaluation time because the
     * announcement resolves this tab's identity, and the shared request-id
     * generator that identity prefers is defined by another script:
     * announcing during evaluation would lock in the local fallback id for
     * whichever load order happened to apply. A script evaluated after the
     * document is already complete announces at first use instead, which is
     * the same guarantee one step later.
     *
     * @returns {void}
     */
    function announceWhenParsed() {
        try {
            var doc = global.document;
            if (!doc || typeof doc.addEventListener !== 'function' || doc.readyState !== 'loading') {
                return;
            }
            doc.addEventListener('DOMContentLoaded', function () {
                announce();
            });
        } catch (readyError) {
            warn('could not schedule this tab\'s presence announcement', readyError);
        }
    }

    installListener();
    announceWhenParsed();

    global.abj404ClientTabPresence = {
        announce: announce,
        liveTabIds: liveTabIds,
        openTabs: openTabs,
        PRESENCE_PREFIX: PRESENCE_PREFIX,
        PRESENCE_TTL_MS: PRESENCE_TTL_MS
    };
} /* abj404-client-module:end */));
