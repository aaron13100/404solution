/**
 * AJAX wrapper that auto-logs every admin AJAX request and its outcome to
 * the plugin's client-side debug log (abj404UpdateAjaxDebugLog).
 *
 * New admin JS should call abj404AdminAjax() instead of raw jQuery.ajax /
 * $.ajax / $.post / $.get.  The lint script scripts/lint/lint-raw-ajax.sh
 * enforces this; add "// ajax-direct-approved: <reason>" to opt out.
 *
 * @param {object} options  jQuery.ajax settings (url, data, type, etc.)
 * @returns {jqXHR}         The jQuery AJAX promise, for chaining .done/.fail.
 */
function abj404AdminAjax(options) {
    if (!options || typeof options !== 'object') {
        throw new Error('abj404AdminAjax: options object is required');
    }

    var actionName = '(unknown)';
    if (options.data && typeof options.data === 'object' && options.data.action) {
        actionName = options.data.action;
    } else if (typeof options.data === 'string') {
        var match = options.data.match(/(?:^|&)action=([^&]*)/);
        if (match) { actionName = decodeURIComponent(match[1]); }
    } else if (options.url) {
        var urlMatch = String(options.url).match(/[?&]action=([^&]*)/);
        if (urlMatch) { actionName = decodeURIComponent(urlMatch[1]); }
    }

    var method = (options.type || options.method || 'GET').toUpperCase();

    if (typeof abj404UpdateAjaxDebugLog === 'function') {
        abj404UpdateAjaxDebugLog(method + ' ' + actionName + ' => started');
    }

    var userSuccess = options.success;
    var userError   = options.error;

    options.success = function(data, textStatus, jqXHR) {
        if (typeof abj404UpdateAjaxDebugLog === 'function') {
            var outcome = (data && data.success === false) ? 'server error' : 'success';
            abj404UpdateAjaxDebugLog(method + ' ' + actionName + ' => ' + outcome,
                { status: jqXHR.status });
        }
        if (typeof userSuccess === 'function') {
            userSuccess.call(this, data, textStatus, jqXHR);
        }
    };

    options.error = function(jqXHR, textStatus, errorThrown) {
        if (typeof abj404UpdateAjaxDebugLog === 'function') {
            abj404UpdateAjaxDebugLog(method + ' ' + actionName + ' => error',
                { status: jqXHR.status, textStatus: textStatus, error: errorThrown });
        }
        if (typeof userError === 'function') {
            userError.call(this, jqXHR, textStatus, errorThrown);
        }
    };

    return jQuery.ajax(options); // ajax-direct-approved: wrapper implementation
}
