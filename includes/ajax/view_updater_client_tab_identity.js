/**
 * Stable identity for one browser tab.
 *
 * Every durable client-side diagnostic surface is keyed by this: the attempt
 * ring buffer is one buffer per tab, the cross-tab presence registry is one
 * entry per tab, and every attempt record carries the id so a support payload
 * can tell "one tab retried three times" from "three tabs each tried once".
 * It lives in sessionStorage rather than localStorage because that is exactly
 * the scope wanted -- a reload keeps reporting one session while a second tab
 * reports its own.
 *
 * Globals defined: abj404ClientTabIdentity.
 */
(function (global, abj404Module) {
    if (global.abj404ClientBuildRegistry) {
        global.abj404ClientBuildRegistry.register('tab_identity', abj404Module);
    }
    abj404Module(global);
}(typeof window !== 'undefined' ? window : this, /* abj404-client-module:start */ function (global) {
    'use strict';

    var SESSION_ID_KEY = 'abj404:client_session_id';
    var SESSION_ID_PATTERN = /^[a-z0-9]{8,64}$/;

    var tabId = '';
    var fallbackIdCounter = 0;

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
    function tabStorage() {
        try {
            return global.sessionStorage || null; // allow-direct-storage: this IS the per-tab identity adapter
        } catch (accessError) {
            warn('sessionStorage is unavailable for the per-tab telemetry identity', accessError);
            return null;
        }
    }

    /**
     * Read a per-tab value, minting and persisting it via mintValue() on first
     * use. Falls back to the freshly minted value (without persistence) when
     * sessionStorage is unavailable, so the field is never empty.
     *
     * @param {string} key
     * @param {function(): string} mintValue
     * @param {RegExp} validPattern rejects a corrupt or foreign stored value.
     * @returns {string}
     */
    function scopedValue(key, mintValue, validPattern) {
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

    /** @returns {string} stable identifier for this browser tab. */
    function id() {
        if (tabId !== '') {
            return tabId;
        }
        tabId = scopedValue(SESSION_ID_KEY, mint, SESSION_ID_PATTERN);
        return tabId;
    }

    /**
     * Prefers the page's shared request-id generator so every id on the page
     * comes from one source; the local fallback exists only for a page that
     * loaded this file without it.
     *
     * @returns {string}
     */
    function mint() {
        if (typeof global.abj404GenerateRequestId === 'function') {
            return global.abj404GenerateRequestId();
        }
        fallbackIdCounter++;
        var monotonic = global.performance && typeof global.performance.now === 'function'
            ? global.performance.now() : Date.now(); // allow-direct-time: fallback session uniqueness when performance.now is absent
        return ('f' + Date.now().toString(36) + fallbackIdCounter.toString(36) + // allow-direct-time: session uniqueness source when the shared generator is absent
            Math.round(monotonic).toString(36)).slice(0, 32);
    }

    global.abj404ClientTabIdentity = {
        id: id,
        scopedValue: scopedValue,
        SESSION_ID_KEY: SESSION_ID_KEY
    };
} /* abj404-client-module:end */));
