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
 * How long the health-bar request may run before it is abandoned.
 *
 * Shorter than the 30s admin default because this bar is a secondary
 * enrichment of a page that has already rendered: if the summary cannot be
 * had promptly the placeholder is removed and the redirects table is left
 * alone, which is a better outcome than a status dot that spins past the
 * point the admin has moved on.
 */
var ABJ404_HEALTH_BAR_TIMEOUT_MS = 15000;

/**
 * Build the health-bar's status-dot + message (+ optional "View" link) as
 * real DOM nodes. Assigning `link.href` and `.textContent` are DOM property
 * writes, not HTML parsing, so `message` and `viewLink` cannot break out of
 * an attribute or inject markup regardless of their content.
 *
 * Takes one options object rather than three loose strings: `dotClass`,
 * `message` and `viewLink` are all strings, so a positional signature lets any
 * two of them be swapped with nothing to catch it -- the bar would render the
 * message as a class name and the class name as its text, and every type check
 * would still pass.
 *
 * @param {{dotClass: string, message: string, viewLink: (string|null)}} parts
 * @return {DocumentFragment}
 */
function abj404BuildHealthBarFragment(parts) {
    var fragment = document.createDocumentFragment();
    var dot = document.createElement('span');
    dot.className = parts.dotClass;
    fragment.appendChild(dot);
    fragment.appendChild(document.createTextNode(' ' + parts.message));
    if (parts.viewLink) {
        fragment.appendChild(document.createTextNode(' '));
        var link = document.createElement('a');
        link.href = parts.viewLink;
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

/**
 * The endpoint URL with the attempt's request id attached, so a server-side
 * journal entry can be joined to the client attempt that produced it.
 *
 * One options object: `url` and `attemptId` are both strings, and swapping
 * them yields a syntactically fine URL built from the request id with the
 * endpoint as a query value. It would fail at the server, far from the call
 * site that transposed them.
 *
 * @param {{url: string, attemptId: string}} attempt
 * @returns {string}
 */
function abj404HealthBarAttemptUrl(attempt) {
    var separator = String(attempt.url).indexOf('?') >= 0 ? '&' : '?';
    return String(attempt.url) + separator + 'requestId=' + encodeURIComponent(attempt.attemptId);
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
        timeoutMs: ABJ404_HEALTH_BAR_TIMEOUT_MS
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

/**
 * Render the health summary into the bar, or remove the bar when the server
 * did not return one.
 *
 * The payload is named rather than passed as a bare `result` because four
 * separate decisions read it: whether the response is usable at all, the
 * active-redirect subtraction, whether the rollup has finished rebuilding,
 * and which of the three dot states to show.
 *
 * @param {object} context Request context from abj404HealthBarRequestContext().
 * @param {{highImpactCapturedCount: (number|null), statusCounts: object,
 *          rollupAvailable: (boolean|undefined)}} health The health endpoint's payload.
 * @returns {void}
 */
function abj404RenderHealthBarResult(context, health) {
    var $bar = context.$bar;
    $bar.removeAttr('data-health-bar-loading');
    if (!health || typeof health.highImpactCapturedCount === 'undefined' || !health.statusCounts) {
        $bar.removeAttr('data-health-bar-placeholder');
        $bar.empty();
        return;
    }
    var active = (health.statusCounts.all || 0) - (health.statusCounts.trash || 0);
    var available = health.rollupAvailable !== false && health.highImpactCapturedCount !== null;
    var high = health.highImpactCapturedCount || 0;
    var fragment;
    if (!available) {
        fragment = abj404BuildHealthBarFragment({
            dotClass: 'abj404-health-dot abj404-health-gray',
            message: active + ' redirects active, URL attention status unavailable while logs rebuild',
            viewLink: null
        });
    } else if (high === 0) {
        fragment = abj404BuildHealthBarFragment({
            dotClass: 'abj404-health-dot abj404-health-green',
            message: active + ' redirects active, no URLs need attention',
            viewLink: null
        });
    } else {
        fragment = abj404BuildHealthBarFragment({
            dotClass: 'abj404-health-dot abj404-health-yellow',
            // allow-em-dash: visible separator preserved from the health-bar copy
            message: active + ' redirects active — ' + high + ' captured URLs have repeat visitors',
            viewLink: '?page=' + encodeURIComponent(getURLParameter('page') || 'abj404_solution') +
                '&subpage=abj404_captured&filter=' +
                encodeURIComponent(health.statusCounts._capturedFilter || '')
        });
    }
    $bar.empty().append(fragment);
    $bar.removeAttr('data-health-bar-placeholder');
}

/** @param {object} context @returns {object} */
function abj404HealthBarAjaxOptions(context) {
    var healthBarAjaxRunner = typeof abj404AjaxWithNonceRetry === 'function'
        ? abj404AjaxWithNonceRetry : jQuery.ajax; // ajax-direct-approved: nonce helper fallback
    var options = {
        url: abj404HealthBarAttemptUrl({ url: context.url, attemptId: context.requestId }),
        type: 'POST',
        dataType: 'json',
        // Without this the bar keeps data-health-bar-loading set for the life
        // of the page when a request never returns: neither handler below runs,
        // so the placeholder is never cleared and a later pagination success
        // cannot retry it either.
        timeout: ABJ404_HEALTH_BAR_TIMEOUT_MS,
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
