/**
 * Durable per-origin cooldown for the adaptive canary ladder.
 *
 * A page-lifetime marker is unsafe here: after storage fails, every reload
 * could launch another diagnostic ladder against an already struggling site.
 * Eligibility therefore fails closed unless localStorage can read the shared
 * timestamp, and marking a run reports whether the durable write succeeded.
 *
 * Globals defined: abj404CanaryCooldown.
 */
(function (global, abj404Module) {
    if (global.abj404ClientBuildRegistry) {
        global.abj404ClientBuildRegistry.register('canary_cooldown', abj404Module);
    }
    abj404Module(global);
}(typeof window !== 'undefined' ? window : this, /* abj404-client-module:start */ function (global) {
    'use strict';

    var CANARY_LADDER_KEY = 'abj404:canary_ladder_last_run';

    /** @param {string} message @param {*} error @returns {void} */
    function warn(message, error) {
        if (global.console && global.console.warn) {
            global.console.warn('404 Solution: ' + message, error);
        }
    }

    /** @returns {object|null} */
    function storage() {
        try {
            return global.localStorage || null; // allow-direct-storage: this IS the canary cooldown storage adapter
        } catch (accessError) {
            warn('localStorage is unavailable for the canary ladder cooldown', accessError);
            return null;
        }
    }

    /**
     * @param {number} nowMsValue
     * @param {number} cooldownMs
     * @returns {boolean}
     */
    function eligible(nowMsValue, cooldownMs) {
        var store = storage();
        if (store === null) {
            return false;
        }
        try {
            var raw = store.getItem(CANARY_LADDER_KEY);
            if (raw === null) {
                return true;
            }
            var lastRun = parseInt(raw, 10);
            if (!isFinite(lastRun) || isNaN(lastRun)) {
                return true;
            }
            return (nowMsValue - lastRun) >= cooldownMs;
        } catch (readError) {
            warn('could not read the canary ladder cooldown marker', readError);
            return false;
        }
    }

    /**
     * @param {number} nowMsValue
     * @returns {boolean}
     */
    function markRan(nowMsValue) {
        var store = storage();
        if (store === null) {
            return false;
        }
        try {
            store.setItem(CANARY_LADDER_KEY, String(nowMsValue));
            return true;
        } catch (writeError) {
            warn('could not persist the canary ladder cooldown marker', writeError);
            return false;
        }
    }

    global.abj404CanaryCooldown = {
        eligible: eligible,
        markRan: markRan,
        KEY: CANARY_LADDER_KEY
    };
} /* abj404-client-module:end */));
