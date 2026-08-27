/**
 * Redirects-page health-bar hydration and response-delivery telemetry.
 *
 * The health endpoint is recorded through the same native XHR, Resource
 * Timing, durable storage, and beacon path as the table endpoint. A 200
 * `parsererror` therefore retains enough privacy-safe evidence to distinguish
 * truncation, wrapper bytes, and a jQuery-only parse rejection.
 *
 * Globals defined: abj404BuildHealthBarFragment, refreshHealthBarIfNeeded.
 */

/**
 * Build the health-bar's status-dot + message (+ optional "View" link) as
 * real DOM nodes. Assigning `link.href` and `.textContent` are DOM property
 * writes, not HTML parsing, so `message` and `viewLink` cannot break out of
 * an attribute or inject markup regardless of their content.
 *
 * @param {string} dotClass
 * @param {string} message
 * @param {string|null} viewLink
 * @return {DocumentFragment}
 */
function abj404BuildHealthBarFragment(dotClass, message, viewLink) {
    var fragment = document.createDocumentFragment();
    var dot = document.createElement('span');
    dot.className = dotClass;
    fragment.appendChild(dot);
    fragment.appendChild(document.createTextNode(' ' + message));
    if (viewLink) {
        fragment.appendChild(document.createTextNode(' '));
        var link = document.createElement('a');
        link.href = viewLink;
        link.textContent = 'View';
        fragment.appendChild(link);
    }
    return fragment;
}

/** @returns {object|null} */
function abj404HealthBarTelemetry() {
    var telemetry = window.abj404TransportTelemetry;
    return telemetry && typeof telemetry.beginAttempt === 'function' &&
        typeof telemetry.xhrFactory === 'function' &&
        typeof telemetry.finishAttempt === 'function' ? telemetry : null;
}

/** @param {string} url @param {string} attemptId @returns {string} */
function abj404HealthBarAttemptUrl(url, attemptId) {
    var separator = String(url).indexOf('?') >= 0 ? '&' : '?';
    return String(url) + separator + 'requestId=' + encodeURIComponent(attemptId);
}

/** @param {string} textStatus @returns {string} */
function abj404HealthBarOutcome(textStatus) {
    return textStatus === 'timeout' || textStatus === 'abort' || textStatus === 'parsererror'
        ? textStatus : 'error';
}

/** @returns {string} */
function abj404HealthBarReportNonce() {
    return jQuery('[data-pagination-ajax-nonce]').first().attr('data-pagination-ajax-nonce') || '';
}

/** @returns {object|null} */
function abj404HealthBarRequestContext() {
    var $bar = jQuery('.abj404-health-bar[data-health-bar-placeholder]');
    if ($bar.length === 0 || $bar.attr('data-health-bar-loading') === '1') {
        return null;
    }

    var url = $bar.attr('data-health-bar-ajax-url') || window.ajaxurl;
    var action = $bar.attr('data-health-bar-ajax-action') || 'ajaxRefreshHealthBar';
    var nonce = $bar.attr('data-health-bar-nonce') || '';
    if (!url || !nonce) {
        $bar.removeAttr('data-health-bar-placeholder');
        $bar.empty();
        return null;
    }

    $bar.attr('data-health-bar-loading', '1');
    var subpage = getURLParameter('subpage') || '';
    var telemetry = abj404HealthBarTelemetry();
    var record = telemetry ? telemetry.beginAttempt({
        requestId: abj404GenerateRequestId(),
        part: 'health',
        attemptIndex: 0,
        subpage: subpage,
        timeoutMs: 0
    }) : null;
    return {
        $bar: $bar,
        action: action,
        nonce: nonce,
        reportNonce: abj404HealthBarReportNonce(),
        record: record,
        requestId: record ? record.id : abj404GenerateRequestId(),
        subpage: subpage,
        telemetry: telemetry,
        url: url
    };
}

/** @param {object} context @param {object} result @returns {void} */
function abj404RenderHealthBarResult(context, result) {
    var $bar = context.$bar;
    $bar.removeAttr('data-health-bar-loading');
    if (!result || typeof result.highImpactCapturedCount === 'undefined' || !result.statusCounts) {
        $bar.removeAttr('data-health-bar-placeholder');
        $bar.empty();
        return;
    }
    var active = (result.statusCounts.all || 0) - (result.statusCounts.trash || 0);
    var available = result.rollupAvailable !== false && result.highImpactCapturedCount !== null;
    var high = result.highImpactCapturedCount || 0;
    var fragment;
    if (!available) {
        fragment = abj404BuildHealthBarFragment('abj404-health-dot abj404-health-gray',
            active + ' redirects active, URL attention status unavailable while logs rebuild', null);
    } else if (high === 0) {
        fragment = abj404BuildHealthBarFragment('abj404-health-dot abj404-health-green',
            active + ' redirects active, no URLs need attention', null);
    } else {
        var viewLink = '?page=' + encodeURIComponent(getURLParameter('page') || 'abj404_solution') +
            '&subpage=abj404_captured&filter=' + encodeURIComponent(result.statusCounts._capturedFilter || '');
        fragment = abj404BuildHealthBarFragment('abj404-health-dot abj404-health-yellow',
            // allow-em-dash: visible separator preserved from the health-bar copy
            active + ' redirects active — ' + high + ' captured URLs have repeat visitors', viewLink);
    }
    $bar.empty().append(fragment);
    $bar.removeAttr('data-health-bar-placeholder');
}

/** @param {object} context @returns {object} */
function abj404HealthBarAjaxOptions(context) {
    var healthBarAjaxRunner = typeof abj404AjaxWithNonceRetry === 'function'
        ? abj404AjaxWithNonceRetry : jQuery.ajax; // ajax-direct-approved: nonce helper fallback
    var options = {
        url: abj404HealthBarAttemptUrl(context.url, context.requestId),
        type: 'POST',
        dataType: 'json',
        data: {
            action: context.action,
            nonce: context.nonce,
            page: getURLParameter('page') || '',
            subpage: context.subpage,
            requestId: context.requestId
        },
        beforeSend: function(jqXHR) {
            if (context.requestId && jqXHR && typeof jqXHR.setRequestHeader === 'function') {
                jqXHR.setRequestHeader('X-ABJ404-Request-ID', context.requestId);
            }
        },
        success: function(result, textStatus, jqXHR) {
            if (context.telemetry) {
                context.telemetry.finishAttempt(context.record, 'success', jqXHR, textStatus);
            }
            abj404RenderHealthBarResult(context, result);
        },
        error: function(jqXHR, textStatus) {
            if (context.telemetry) {
                context.telemetry.finishAttempt(
                    context.record, abj404HealthBarOutcome(textStatus), jqXHR, textStatus);
                var delivery = window.abj404TransportTelemetryDelivery;
                if (context.reportNonce && delivery && typeof delivery.sendBeacon === 'function') {
                    delivery.sendBeacon(context.url, context.record, context.reportNonce);
                }
            }
            context.$bar.removeAttr('data-health-bar-loading');
            context.$bar.removeAttr('data-health-bar-placeholder');
            context.$bar.empty();
        }
    };
    if (context.telemetry) {
        options.xhr = context.telemetry.xhrFactory(context.record);
    }
    return { options: options, runner: healthBarAjaxRunner };
}

/**
 * Hydrate the redirects-page health bar through its public AJAX entry point.
 *
 * Endpoint config comes from the placeholder's data attributes. The loading
 * flag makes calls from both jQuery.ready and pagination success idempotent.
 * Missing config or any request failure removes the decorative placeholder so
 * health telemetry can never block or leave the primary table looking busy.
 */
function refreshHealthBarIfNeeded() {
    var context = abj404HealthBarRequestContext();
    if (!context) {
        return;
    }
    var request = abj404HealthBarAjaxOptions(context);
    request.runner(request.options);
}

if (typeof window !== 'undefined' && window.abj404ClientBuildRegistry) {
    window.abj404ClientBuildRegistry.registerFunctions('health_bar', [
        abj404BuildHealthBarFragment,
        abj404HealthBarTelemetry,
        abj404HealthBarAttemptUrl,
        abj404HealthBarOutcome,
        abj404HealthBarReportNonce,
        abj404HealthBarRequestContext,
        abj404RenderHealthBarResult,
        abj404HealthBarAjaxOptions,
        refreshHealthBarIfNeeded
    ]);
}
