/**
 * Is the JavaScript executing in this browser the JavaScript this install
 * shipped -- for every diagnostic module, not one probe function?
 *
 * (Bruno timeout cause matrix, gap GF / Codex gap #8, cause A8 "wrong,
 * duplicated, or stale client JS".)
 *
 * The previous build probe hashed the source text of ONE tiny function inside
 * ONE telemetry file. That proved the browser had a current copy of that
 * function and said nothing about the request driver, the transport, the
 * storage adapter, the delivery routes, the canary ladder, or the support
 * client -- the modules a stalled table request actually runs. An edge cache
 * or an in-flight optimizer serving a stale copy of any of THOSE was
 * invisible.
 *
 * Each diagnostic module now hands this registry the function its whole body
 * lives in, so `Function.prototype.toString()` yields that module's complete
 * executing source; flat modules (top-level function declarations rather than
 * an IIFE) hand over their functions in file order instead. The server
 * independently extracts the same text out of the shipped .js file
 * (ABJ_404_Solution_ClientBuildFingerprint) and hashes it the same way, so
 * nothing is kept in sync by hand: editing a module changes both sides at
 * once because both derive their value from the same bytes.
 *
 * Per-module hashes travel with the combined one. A single combined value
 * would say only THAT something drifted; the per-module map says WHICH file,
 * which is the difference between "the client is stale" and "the canary
 * ladder is stale while everything else is current".
 *
 * Loading order: this module is a dependency of every module that registers,
 * so it is always present by the time one runs. Registration is guarded
 * anyway -- a module whose registry failed to load must still work, and a
 * smaller manifest is a smaller proof, never a broken page.
 *
 * Globals defined: abj404ClientBuildRegistry.
 */
(function (global) {
    'use strict';

    /** Registered module source text, keyed by the short name the server uses. */
    var sources = {};

    /**
     * The UTF-8 bytes of a string.
     *
     * The hash below has to run over the SAME byte sequence PHP sees, and PHP
     * strings are bytes while JavaScript strings are UTF-16 code units. The
     * earlier one-function probe hashed `charCodeAt(i) & 0xff`, which happens
     * to be right for pure ASCII and silently wrong for anything else: a
     * single accented character, curly quote, or em dash anywhere in a module
     * made the two sides disagree, and this probe reports disagreement as
     * "the browser is running a stale build". Encoding explicitly removes
     * that whole failure class instead of relying on module sources staying
     * ASCII forever (view_updater_stage_diagnostics.js already is not).
     *
     * Written by hand rather than with TextEncoder so the browser floor stays
     * where the rest of this plugin's admin JavaScript is.
     *
     * @param {string} text
     * @returns {Array<number>}
     */
    function utf8Bytes(text) {
        var bytes = [];
        for (var i = 0; i < text.length; i++) {
            var code = text.charCodeAt(i);
            if (code < 0x80) {
                bytes.push(code);
            } else if (code < 0x800) {
                bytes.push(0xc0 | (code >> 6), 0x80 | (code & 0x3f));
            } else if (code >= 0xd800 && code <= 0xdbff && i + 1 < text.length) {
                // Surrogate pair: one code point above the BMP, four bytes.
                var point = 0x10000 + ((code - 0xd800) << 10) + (text.charCodeAt(i + 1) - 0xdc00);
                i++;
                bytes.push(0xf0 | (point >> 18), 0x80 | ((point >> 12) & 0x3f),
                    0x80 | ((point >> 6) & 0x3f), 0x80 | (point & 0x3f));
            } else {
                bytes.push(0xe0 | (code >> 12), 0x80 | ((code >> 6) & 0x3f), 0x80 | (code & 0x3f));
            }
        }
        return bytes;
    }

    /**
     * FNV-1a, 32-bit, lowercase hex, over the string's UTF-8 bytes. Byte for
     * byte identical to ABJ_404_Solution_ClientBuildFingerprint::hashOf().
     * Written as shift-and-add because the multiply form overflows
     * JavaScript's exactly-representable integer range and stops matching PHP.
     *
     * @param {string} text
     * @returns {string}
     */
    function fnv1a32(text) {
        var bytes = utf8Bytes(text);
        var hash = 0x811c9dc5;
        for (var i = 0; i < bytes.length; i++) {
            hash ^= bytes[i];
            hash = (hash + ((hash << 1) + (hash << 4) + (hash << 7) + (hash << 8) + (hash << 24))) >>> 0;
        }
        return ('0000000' + hash.toString(16)).slice(-8);
    }

    /**
     * Normalize a function's source the same way the server normalizes the
     * text it reads out of the file: carriage returns stripped so a checkout
     * with CRLF line endings still matches, and the surrounding whitespace
     * between the marker comments trimmed off.
     *
     * @param {Function} fn
     * @returns {string}
     */
    function sourceOf(fn) {
        return String(fn).replace(/\r/g, '').replace(/^\s+|\s+$/g, '');
    }

    /**
     * Register one module whose entire body is a single function (the IIFE
     * shape every telemetry/canary/support module uses).
     *
     * @param {string} name Short module name; must match the server's table.
     * @param {Function} moduleFn The function the module body lives in.
     * @returns {void}
     */
    function register(name, moduleFn) {
        try {
            if (typeof moduleFn !== 'function') {
                return;
            }
            sources[String(name)] = sourceOf(moduleFn);
        } catch (registerError) {
            warn('could not register the build source of ' + name, registerError);
        }
    }

    /**
     * Register a module made of top-level function declarations, in file
     * order. The joined text is what the server reconstructs by extracting
     * each named function from the same file.
     *
     * @param {string} name
     * @param {Array<Function>} fns
     * @returns {void}
     */
    function registerFunctions(name, fns) {
        try {
            if (!fns || typeof fns.length !== 'number') {
                return;
            }
            var parts = [];
            for (var i = 0; i < fns.length; i++) {
                if (typeof fns[i] === 'function') {
                    parts.push(sourceOf(fns[i]));
                }
            }
            sources[String(name)] = parts.join('\n');
        } catch (registerError) {
            warn('could not register the build sources of ' + name, registerError);
        }
    }

    /** @param {string} message @param {*} error @returns {void} */
    function warn(message, error) {
        if (global.console && global.console.warn) {
            global.console.warn('404 Solution: ' + message, error);
        }
    }

    /**
     * Per-module hashes plus one combined hash over the modules THIS page
     * actually loaded.
     *
     * The combined value is computed over the sorted `name:hash` pairs, which
     * the server reproduces for whichever subset it is told about -- an admin
     * screen that loads the support client but not the canary ladder is a
     * smaller module set, not a mismatch.
     *
     * @returns {{combined: string, modules: object, count: number}}
     */
    function digest() {
        var names = [];
        var name;
        for (name in sources) {
            if (Object.prototype.hasOwnProperty.call(sources, name)) {
                names.push(name);
            }
        }
        names.sort();
        var modules = {};
        var parts = [];
        for (var i = 0; i < names.length; i++) {
            var hash = fnv1a32(sources[names[i]]);
            modules[names[i]] = hash;
            parts.push(names[i] + ':' + hash);
        }
        return {
            combined: names.length === 0 ? '' : fnv1a32(parts.join('|')),
            modules: modules,
            count: names.length
        };
    }

    /**
     * The per-module map as one compact wire string, `name:hash,name:hash`.
     * Sent as a request parameter on every instrumented attempt, so it is
     * kept to roughly fifteen bytes per module rather than a nested JSON
     * object: the evidence budget is the scarce resource (gap G1).
     *
     * @returns {string}
     */
    function wireString() {
        var current = digest();
        var pairs = [];
        var name;
        for (name in current.modules) {
            if (Object.prototype.hasOwnProperty.call(current.modules, name)) {
                pairs.push(name + ':' + current.modules[name]);
            }
        }
        return pairs.join(',');
    }

    global.abj404ClientBuildRegistry = {
        register: register,
        registerFunctions: registerFunctions,
        digest: digest,
        wireString: wireString,
        fnv1a32: fnv1a32
    };
})(typeof window !== 'undefined' ? window : this);
