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

    /** @param {string} text @param {number} from @param {number} direction @returns {number} */
    function whitespaceBoundary(text, from, direction) {
        var index = from;
        while (index >= 0 && index < text.length && /\s/.test(text.charAt(index))) {
            index += direction;
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

    /** @param {string} text @param {string} opener @param {string} closer @returns {object|null} */
    function wrappedJsonCandidate(text, opener, closer) {
        var start = text.indexOf(opener);
        var end = text.lastIndexOf(closer);
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
            wrapped = wrappedJsonCandidate(text, '{', '}');
        } else if (firstChar === '[') {
            wrapped = wrappedJsonCandidate(text, '[', ']');
        } else {
            wrapped = wrappedJsonCandidate(text, '{', '}') ||
                wrappedJsonCandidate(text, '[', ']');
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
        var first = whitespaceBoundary(text, 0, 1);
        var last = whitespaceBoundary(text, text.length - 1, -1);
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
