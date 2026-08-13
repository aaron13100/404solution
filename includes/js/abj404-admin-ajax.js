/**
 * Enforced single call-through point for admin AJAX. scripts/lint/lint-raw-ajax.sh
 * requires call sites to use this instead of raw jQuery.ajax / $.ajax / $.post /
 * $.get, so any future cross-cutting concern (retry, telemetry, error handling)
 * has one seam to attach to instead of N call sites; add
 * "// ajax-direct-approved: <reason>" to opt a call site out.
 *
 * @param {object} options  jQuery.ajax settings (url, data, type, etc.)
 * @returns {jqXHR}         The jQuery AJAX promise, for chaining .done/.fail.
 */
function abj404AdminAjax(options) {
    if (!options || typeof options !== 'object') {
        throw new Error('abj404AdminAjax: options object is required'); // allow-raw-error: internal caller-contract assertion, never reaches an end user
    }

    return jQuery.ajax(options); // ajax-direct-approved: wrapper implementation
}

/** Characters of the raw response body kept for the console entry. */
var ABJ404_ADMIN_AJAX_EXCERPT_LIMIT = 200;

/**
 * What the server said about a failed request, or '' when it said nothing
 * this ladder can read.
 *
 * WordPress error responses arrive in more than one shape: wp_send_json_error()
 * with a string, wp_send_json_error() with an array carrying a `message`, and
 * handlers that put `message` at the top level. Callers used to each carry
 * their own copy of this ladder, which is how four copies drifted into four
 * behaviours; it lives here now so there is one shape list to extend.
 *
 * May throw: a property read only raises if the response object is hostile
 * (a throwing getter), which is exactly the case the caller has to record
 * rather than swallow.
 *
 * @param {object} jqXHR
 * @returns {string}
 */
function abj404AdminAjaxServerMessage(jqXHR) {
    if (!jqXHR || !jqXHR.responseJSON) {
        return '';
    }
    if (typeof jqXHR.responseJSON.message === 'string' && jqXHR.responseJSON.message !== '') {
        return jqXHR.responseJSON.message;
    }
    if (!jqXHR.responseJSON.data) {
        return '';
    }
    if (typeof jqXHR.responseJSON.data === 'string') {
        return jqXHR.responseJSON.data;
    }
    if (typeof jqXHR.responseJSON.data.message === 'string' && jqXHR.responseJSON.data.message !== '') {
        return jqXHR.responseJSON.data.message;
    }
    return '';
}

/**
 * The shortest phrase that identifies an otherwise undecodable failure, or ''.
 *
 * jQuery reports a generic 'error' statusText for most transport failures, so
 * that word is dropped rather than shown: "(502 Bad Gateway)" is actionable,
 * "(502 error)" is noise wearing the same shape.
 *
 * @param {{status: (number|null), statusText: string, errorThrown: string}} context
 * @returns {string}
 */
function abj404AdminAjaxFailureDetail(context) {
    if (context.status !== null && context.status > 0) {
        if (context.statusText !== '' && context.statusText.toLowerCase() !== 'error') {
            return context.status + ' ' + context.statusText;
        }
        return String(context.status);
    }
    if (context.errorThrown !== '' && context.errorThrown.toLowerCase() !== 'error') {
        return context.errorThrown;
    }
    return '';
}

/**
 * Describe a failed admin AJAX request: return the best message to show, and
 * record the full failure context to the console on the way past.
 *
 * Both halves are deliberately one call. Every admin error handler needs the
 * message AND owes the underlying detail to whoever has to diagnose the
 * failure later, and leaving the second half to each call site is exactly how
 * two of them ended up catching the decode failure and commenting the empty
 * body "ignore and use generic msg": the response shape that broke was the
 * only evidence of why, and it was thrown away at the moment it was caught.
 *
 * When the server explains itself, that explanation is shown verbatim. When it
 * cannot -- an upstream gateway, WAF or proxy killing the request returns an
 * HTML error page, so there is no JSON to read at all -- the caller's fallback
 * carries the underlying code in parentheses, because an admin reporting
 * "it says error" cannot be helped and an admin reporting "it says 502 Bad
 * Gateway" can.
 *
 * @param {object} jqXHR                 The failed jQuery XHR.
 * @param {object} options
 * @param {string} options.fallback      Sentence to show when the server explained nothing.
 * @param {string} [options.source]      Call-site label, so a console entry names the workflow.
 * @param {string} [options.errorThrown] jQuery's errorThrown argument, when the handler has it.
 * @returns {string}                     The message to show the admin.
 */
function abj404AdminAjaxErrorMessage(jqXHR, options) {
    var opts = options || {};
    var context = {
        source: (typeof opts.source === 'string' && opts.source !== '') ? opts.source : 'admin-ajax',
        status: null,
        statusText: '',
        errorThrown: (typeof opts.errorThrown === 'string') ? opts.errorThrown : '',
        responseExcerpt: '',
        shapeError: null,
        shown: ''
    };
    var fallback = (typeof opts.fallback === 'string' && opts.fallback !== '')
        ? opts.fallback : 'The request failed.';
    var serverMessage = '';

    // Every read of the response happens inside the guard: the object came
    // from the network by way of whatever else is on the page, so no property
    // on it is guaranteed safe to touch.
    try {
        if (jqXHR) {
            if (typeof jqXHR.status === 'number') {
                context.status = jqXHR.status;
            }
            if (typeof jqXHR.statusText === 'string') {
                context.statusText = jqXHR.statusText;
            }
            if (typeof jqXHR.responseText === 'string') {
                context.responseExcerpt =
                    jqXHR.responseText.substring(0, ABJ404_ADMIN_AJAX_EXCERPT_LIMIT);
            }
        }
        serverMessage = abj404AdminAjaxServerMessage(jqXHR);
    } catch (shapeError) {
        context.shapeError = (shapeError && shapeError.message)
            ? String(shapeError.message) : String(shapeError);
    }

    if (serverMessage !== '') {
        context.shown = serverMessage;
    } else {
        var detail = abj404AdminAjaxFailureDetail(context);
        context.shown = (detail !== '') ? (fallback + ' (' + detail + ')') : fallback;
    }

    if (window.console && typeof window.console.error === 'function') {
        window.console.error('404 Solution: admin AJAX request failed', context);
    }

    return context.shown;
}
