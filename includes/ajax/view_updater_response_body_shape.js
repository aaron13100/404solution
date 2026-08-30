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

    /**
     * Look for a delimiter-bounded JSON region inside a body that would not
     * parse whole.
     *
     * Answers THREE outcomes, not two. This used to return null both when no
     * such region existed and when one existed but failed to parse, which threw
     * away the more informative of the two: a body carrying a brace-delimited
     * region that does not parse is positive evidence that something wrapped or
     * rewrote our JSON in transit, and that is the single hypothesis this whole
     * classifier exists to separate. A body with no braces at all is evidence
     * against it. Collapsing them reported both as plain invalid-json with no
     * record that a candidate had even been found.
     *
     * @param {string} text
     * @param {{opener: string, closer: string}} delimiters
     * @returns {{found: boolean, valid: boolean, prefixChars: number, suffixChars: number}}
     */
    function wrappedJsonCandidate(text, delimiters) {
        var start = text.indexOf(delimiters.opener);
        var end = text.lastIndexOf(delimiters.closer);
        if (start < 0 || end < start) {
            return { found: false, valid: false, prefixChars: 0, suffixChars: 0 };
        }
        try {
            JSON.parse(text.slice(start, end + 1));
            return {
                found: true,
                valid: true,
                prefixChars: start,
                suffixChars: text.length - end - 1
            };
        } catch (error) {
            // Deliberately not propagated: this runs in an admin's browser to
            // DESCRIBE an already-failed response, and the parse error's own
            // message is the engine's wording for text we are not allowed to
            // retain. That the region exists and did not parse is the whole of
            // the evidence, and it is now returned rather than dropped.
            return { found: true, valid: false, prefixChars: 0, suffixChars: 0 };
        }
    }

    /**
     * Record what the wrapper probe found, and report whether it settled the
     * classification.
     *
     * `wrapperCandidateFound` is written on every path, including the ones that
     * return false, because "we looked and found no JSON region" is a finding
     * the reader needs in order to interpret a bare invalid-json.
     *
     * @param {string} text @param {number} first @param {object} shape @returns {boolean}
     */
    function applyWrappedShape(text, first, shape) {
        var firstChar = text.charAt(first);
        var wrapped;
        if (firstChar === '{') {
            wrapped = wrappedJsonCandidate(text, OBJECT_DELIMITERS);
        } else if (firstChar === '[') {
            wrapped = wrappedJsonCandidate(text, ARRAY_DELIMITERS);
        } else {
            wrapped = wrappedJsonCandidate(text, OBJECT_DELIMITERS);
            if (!wrapped.valid) {
                var asArray = wrappedJsonCandidate(text, ARRAY_DELIMITERS);
                // A found-but-broken object region still counts as found even
                // when the array probe finds nothing, so the two are OR-ed
                // rather than the second simply replacing the first.
                wrapped = {
                    found: wrapped.found || asArray.found,
                    valid: asArray.valid,
                    prefixChars: asArray.prefixChars,
                    suffixChars: asArray.suffixChars
                };
            }
        }
        shape.wrapperCandidateFound = wrapped.found;
        if (!wrapped.valid) {
            return false;
        }
        shape.classification = 'valid-json-with-wrapper';
        shape.prefixChars = wrapped.prefixChars;
        shape.suffixChars = wrapped.suffixChars;
        return true;
    }

    /**
     * @param {*} body
     * @returns {{classification: string, length: number, leadingWhitespace: number,
     *   trailingWhitespace: number, firstSignificantCodeUnit: number|null,
     *   lastSignificantCodeUnit: number|null, prefixChars: number,
     *   suffixChars: number, parseErrorOffset: number|null,
     *   wrapperCandidateFound: boolean}}
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
            parseErrorOffset: null,
            // Whether a delimiter-bounded JSON region was found at all. False
            // on bodies that never reach the wrapper probe (empty, whitespace,
            // natively valid), which is accurate: no region was found.
            wrapperCandidateFound: false
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
