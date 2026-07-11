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
