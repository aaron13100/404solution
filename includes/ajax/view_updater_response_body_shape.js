/**
 * Privacy-bounded classification of a response body that jQuery could not parse.
 *
 * The classifier records structure and numeric boundary code units only,
 * never response strings or JSON field values.
 * That is enough to distinguish the delivery hypotheses that matter here:
 * native-valid JSON rejected by a jQuery converter, valid JSON wrapped in
 * foreign prefix/suffix bytes, an abruptly truncated document, and a body
 * that is malformed for another reason.
 *
 * Globals defined: abj404ResponseBodyShape.
 */
(function (global, abj404Module) {
    if (global.abj404ClientBuildRegistry) {
        global.abj404ClientBuildRegistry.register('response_body_shape', abj404Module);
    }
    abj404Module(global);
}(typeof window !== 'undefined' ? window : this, /* abj404-client-module:start */ function (global) {
    'use strict';

    /**
     * The delimiter pairs, each written once.
     *
     * Passing an opener and a closer as two separate string arguments left an
     * order to get wrong, and getting it wrong is invisible: `indexOf` then
     * searches for the closing brace and the wrapper is simply never found, so
     * a body with foreign prefix bytes is reported as ordinary `invalid-json`.
     */
    var OBJECT_DELIMITERS = { opener: '{', closer: '}' };
    var ARRAY_DELIMITERS = { opener: '[', closer: ']' };

    /**
     * Index of the first non-whitespace code unit, or text.length when there
     * is none.
     *
     * Deliberately two functions rather than one scanner taking a start and a
     * step: those are both numbers, so the call site could transpose them, and
     * a step of 0 never advances the index -- an infinite loop in the admin's
     * browser, inside a classifier whose entire job is to describe a response
     * that has already failed.
     *
     * @param {string} text @returns {number}
     */
    function firstSignificantIndex(text) {
        var index = 0;
        while (index < text.length && /\s/.test(text.charAt(index))) {
            index += 1;
        }
        return index;
    }

    /** @param {string} text @returns {number} Index of the last non-whitespace code unit, or -1. */
    function lastSignificantIndex(text) {
        var index = text.length - 1;
        while (index >= 0 && /\s/.test(text.charAt(index))) {
            index -= 1;
        }
        return index;
    }

    /** @param {string} text @returns {number|null} */
    function parseErrorOffset(text) {
        try {
            JSON.parse(text);
            return null;
        } catch (error) {
            var message = String(error && error.message ? error.message : '');
            var match = message.match(/(?:position|column)\s+(\d+)/i);
            return match ? parseInt(match[1], 10) : null;
        }
    }

    /** @param {string} text @param {{opener: string, closer: string}} delimiters @returns {object|null} */
    function wrappedJsonCandidate(text, delimiters) {
        var start = text.indexOf(delimiters.opener);
        var end = text.lastIndexOf(delimiters.closer);
        if (start < 0 || end < start) {
            return null;
        }
        try {
            JSON.parse(text.slice(start, end + 1));
            return {
                classification: 'valid-json-with-wrapper',
                prefixChars: start,
                suffixChars: text.length - end - 1
            };
        } catch (error) {
            return null;
        }
    }

    /** @param {string} text @param {number} first @param {object} shape @returns {boolean} */
    function applyWrappedShape(text, first, shape) {
        var firstChar = text.charAt(first);
        var wrapped;
        if (firstChar === '{') {
            wrapped = wrappedJsonCandidate(text, OBJECT_DELIMITERS);
        } else if (firstChar === '[') {
            wrapped = wrappedJsonCandidate(text, ARRAY_DELIMITERS);
        } else {
            wrapped = wrappedJsonCandidate(text, OBJECT_DELIMITERS) ||
                wrappedJsonCandidate(text, ARRAY_DELIMITERS);
        }
        if (!wrapped) {
            return false;
        }
        shape.classification = wrapped.classification;
        shape.prefixChars = wrapped.prefixChars;
        shape.suffixChars = wrapped.suffixChars;
        return true;
    }

    /**
     * @param {*} body
     * @returns {{classification: string, length: number, leadingWhitespace: number,
     *   trailingWhitespace: number, firstSignificantCodeUnit: number|null,
     *   lastSignificantCodeUnit: number|null, prefixChars: number,
     *   suffixChars: number, parseErrorOffset: number|null}}
     */
    function inspect(body) {
        var text = typeof body === 'string' ? body : '';
        var first = firstSignificantIndex(text);
        var last = lastSignificantIndex(text);
        var shape = {
            classification: text.length === 0 ? 'empty' : 'invalid-json',
            length: text.length,
            leadingWhitespace: Math.min(first, text.length),
            trailingWhitespace: Math.max(0, text.length - last - 1),
            firstSignificantCodeUnit: first < text.length ? text.charCodeAt(first) : null,
            lastSignificantCodeUnit: last >= 0 ? text.charCodeAt(last) : null,
            prefixChars: 0,
            suffixChars: 0,
            parseErrorOffset: null
        };
        if (text.length === 0) {
            return shape;
        }
        if (first >= text.length) {
            shape.classification = 'whitespace-only';
            return shape;
        }
        try {
            JSON.parse(text);
            shape.classification = 'native-json-valid';
            return shape;
        } catch (error) {
            shape.parseErrorOffset = parseErrorOffset(text);
        }

        if (applyWrappedShape(text, first, shape)) {
            return shape;
        }

        var firstChar = text.charAt(first);
        var lastChar = text.charAt(last);
        if ((firstChar === '{' && lastChar !== '}') ||
                (firstChar === '[' && lastChar !== ']')) {
            shape.classification = 'abrupt-tail';
        }
        return shape;
    }

    global.abj404ResponseBodyShape = { inspect: inspect };
} /* abj404-client-module:end */));
