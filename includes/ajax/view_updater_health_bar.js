/**
 * Redirects-page health-bar hydration and response-delivery telemetry.
 *
 * The health endpoint is recorded through the same native XHR, Resource
 * Timing, durable storage, and beacon path as the table endpoint. A 200
 * `parsererror` therefore retains enough privacy-safe evidence to distinguish
 * truncation, wrapper bytes, and a jQuery-only parse rejection.
 *
 * Layering, deliberately: abj404HealthBarState() decides what the server's
 * numbers MEAN, abj404HealthBarFragmentParts() decides how that meaning reads,
 * and only abj404RenderHealthBarResult() touches the DOM. They were one
 * function until a review caught the mix, and the split is what makes the
 * subtraction rule and the three-state dot assertable without a document.
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
 * The two attributes that carry the bar's lifecycle.
 *
 * Named once because every path reads or clears them: acquisition, success,
 * failure, and the two give-up routes. Spelled as literals in six places, a
 * partial rename would leave the bar either permanently "loading" (no retry
 * can ever acquire it again) or permanently a placeholder that never fills.
 */
var ABJ404_HEALTH_BAR_PLACEHOLDER_ATTR = 'data-health-bar-placeholder';
var ABJ404_HEALTH_BAR_LOADING_ATTR = 'data-health-bar-loading';

/** The rollup is still rebuilding, so URL attention status cannot be stated. */
var ABJ404_HEALTH_STATUS_REBUILDING = 'rebuilding';

/** The rollup is current and no captured URL needs attention. */
var ABJ404_HEALTH_STATUS_CLEAR = 'clear';

/** The rollup is current and some captured URLs have repeat visitors. */
var ABJ404_HEALTH_STATUS_ATTENTION = 'attention';

/**
 * What the health endpoint's payload MEANS, or null when it did not return a
 * usable summary at all.
 *
 * Pure: no DOM, no jQuery, no formatting. The three decisions that used to sit
 * inline in the renderer live here because each is a rule rather than a
 * presentation choice -- active redirects EXCLUDE trashed ones, an absent or
 * still-rebuilding rollup is not the same as a zero count, and "needs
 * attention" is a positive high-impact count rather than the absence of a
 * clean signal.
 *
 * @param {{highImpactCapturedCount: (number|null), statusCounts: object,
 *          rollupAvailable: (boolean|undefined)}} health Health endpoint payload.
 * @returns {{status: string, activeCount: number, highImpactCount: number,
 *            capturedFilter: string}|null}
 */
function abj404HealthBarState(health) {
    if (!health || typeof health.highImpactCapturedCount === 'undefined' || !health.statusCounts) {
        return null;
    }
    var activeCount = (health.statusCounts.all || 0) - (health.statusCounts.trash || 0);
    var highImpactCount = health.highImpactCapturedCount || 0;
    var status;
    if (health.rollupAvailable === false || health.highImpactCapturedCount === null) {
        status = ABJ404_HEALTH_STATUS_REBUILDING;
    } else if (highImpactCount === 0) {
        status = ABJ404_HEALTH_STATUS_CLEAR;
    } else {
        status = ABJ404_HEALTH_STATUS_ATTENTION;
    }
    return {
        status: status,
        activeCount: activeCount,
        highImpactCount: highImpactCount,
        capturedFilter: health.statusCounts._capturedFilter || ''
    };
}

/**
 * How one health state reads: dot colour, sentence, and the optional link to
 * the captured URLs it is talking about.
 *
 * Pure, and separate from abj404HealthBarState() so that changing the copy
 * cannot change the subtraction, and changing the rule cannot silently leave
 * the copy describing the old one.
 *
 * @param {{status: string, activeCount: number, highImpactCount: number,
 *          capturedFilter: string}} state
 * @returns {{dotClass: string, message: string, viewLink: (string|null)}}
 */
function abj404HealthBarFragmentParts(state) {
    if (state.status === ABJ404_HEALTH_STATUS_REBUILDING) {
        return {
            dotClass: 'abj404-health-dot abj404-health-gray',
            message: state.activeCount +
                ' redirects active, URL attention status unavailable while logs rebuild',
            viewLink: null
        };
    }
    if (state.status === ABJ404_HEALTH_STATUS_CLEAR) {
        return {
            dotClass: 'abj404-health-dot abj404-health-green',
            message: state.activeCount + ' redirects active, no URLs need attention',
            viewLink: null
        };
    }
    return {
        dotClass: 'abj404-health-dot abj404-health-yellow',
        // allow-em-dash: visible separator preserved from the health-bar copy
        message: state.activeCount + ' redirects active — ' + state.highImpactCount +
            ' captured URLs have repeat visitors',
        viewLink: '?page=' + encodeURIComponent(getURLParameter('page') || 'abj404_solution') +
            '&subpage=abj404_captured&filter=' + encodeURIComponent(state.capturedFilter)
    };
}

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

/**
 * Remove the decorative bar for good: not loading, not a placeholder, empty.
 *
 * The one way this page gives up on the health summary, so a failure, an
 * unusable payload, and a missing endpoint all leave the DOM in the same
 * state and none of them can leave a half-cleared attribute behind.
 *
 * @param {object} $bar jQuery-wrapped health bar element.
 * @returns {void}
 */
function abj404RetireHealthBar($bar) {
    $bar.removeAttr(ABJ404_HEALTH_BAR_LOADING_ATTR);
    $bar.removeAttr(ABJ404_HEALTH_BAR_PLACEHOLDER_ATTR);
    $bar.empty();
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

/**
 * What this page load should do about the health bar, decided without
 * changing anything.
 *
 * A query, so it is safe to call twice and safe to call from a test: it
 * reads the placeholder's own data attributes and returns one of three
 * intents rather than half-performing them. Marking the bar as loading and
 * opening a telemetry attempt are commands, and they live in
 * abj404BeginHealthBarRequest() where a reader expects a side effect.
 *
 * @returns {{intent: string, $bar: (object|null), config: (object|null)}}
 */
function abj404HealthBarRequestPlan() {
    var $bar = jQuery('.abj404-health-bar[' + ABJ404_HEALTH_BAR_PLACEHOLDER_ATTR + ']');
    if ($bar.length === 0 || $bar.attr(ABJ404_HEALTH_BAR_LOADING_ATTR) === '1') {
        return { intent: 'none', $bar: null, config: null };
    }

    var url = $bar.attr('data-health-bar-ajax-url') || window.ajaxurl;
    var nonce = $bar.attr('data-health-bar-nonce') || '';
    if (!url || !nonce) {
        return { intent: 'retire', $bar: $bar, config: null };
    }
    return {
        intent: 'request',
        $bar: $bar,
        config: {
            url: url,
            nonce: nonce,
            action: $bar.attr('data-health-bar-ajax-action') || 'ajaxRefreshHealthBar'
        }
    };
}

/**
 * Claim the bar for one request and open its telemetry attempt.
 *
 * The command half of the old request-context function: everything here
 * changes state, and it runs only after abj404HealthBarRequestPlan() has said
 * a request is the right thing to do.
 *
 * @param {{$bar: object, config: object}} plan A plan with intent 'request'.
 * @returns {object} Request context for the AJAX options builder.
 */
function abj404BeginHealthBarRequest(plan) {
    var $bar = plan.$bar;
    $bar.attr(ABJ404_HEALTH_BAR_LOADING_ATTR, '1');
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
        action: plan.config.action,
        nonce: plan.config.nonce,
        reportNonce: abj404HealthBarReportNonce(),
        record: record,
        requestId: record ? record.id : abj404GenerateRequestId(),
        subpage: subpage,
        telemetry: telemetry,
        url: plan.config.url
    };
}

/**
 * Render the health summary into the bar, or remove the bar when the server
 * did not return one.
 *
 * DOM only: what the numbers mean is abj404HealthBarState()'s job and how they
 * read is abj404HealthBarFragmentParts()'.
 *
 * @param {object} context Request context from abj404BeginHealthBarRequest().
 * @param {object} health The health endpoint's payload.
 * @returns {void}
 */
function abj404RenderHealthBarResult(context, health) {
    var state = abj404HealthBarState(health);
    if (state === null) {
        abj404RetireHealthBar(context.$bar);
        return;
    }
    context.$bar.removeAttr(ABJ404_HEALTH_BAR_LOADING_ATTR);
    context.$bar.empty().append(abj404BuildHealthBarFragment(abj404HealthBarFragmentParts(state)));
    context.$bar.removeAttr(ABJ404_HEALTH_BAR_PLACEHOLDER_ATTR);
}

/** @param {object} context @returns {object} */
function abj404HealthBarAjaxOptions(context) {
    var healthBarAjaxRunner = typeof abj404AjaxWithNonceRetry === 'function'
        ? abj404AjaxWithNonceRetry : jQuery.ajax; // ajax-direct-approved: nonce helper fallback
    var options = {
        url: abj404HealthBarAttemptUrl({ url: context.url, attemptId: context.requestId }),
        type: 'POST',
        dataType: 'json',
        // Without this the bar keeps its loading attribute set for the life
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
            abj404RetireHealthBar(context.$bar);
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
    var plan = abj404HealthBarRequestPlan();
    if (plan.intent === 'none') {
        return;
    }
    if (plan.intent === 'retire') {
        abj404RetireHealthBar(plan.$bar);
        return;
    }
    var request = abj404HealthBarAjaxOptions(abj404BeginHealthBarRequest(plan));
    request.runner(request.options);
}

if (typeof window !== 'undefined' && window.abj404ClientBuildRegistry) {
    window.abj404ClientBuildRegistry.registerFunctions('health_bar', [
        abj404HealthBarState,
        abj404HealthBarFragmentParts,
        abj404BuildHealthBarFragment,
        abj404RetireHealthBar,
        abj404HealthBarTelemetry,
        abj404HealthBarAttemptUrl,
        abj404HealthBarOutcome,
        abj404HealthBarReportNonce,
        abj404HealthBarRequestPlan,
        abj404BeginHealthBarRequest,
        abj404RenderHealthBarResult,
        abj404HealthBarAjaxOptions,
        refreshHealthBarIfNeeded
    ]);
}
