/**
 * Pagination AJAX error notice renderer.
 *
 * Owns everything the orchestrator does after ajaxUpdatePaginationLinks
 * fails: parse the failure response, write a structured entry to the
 * debug log, and render a non-blocking admin notice with diagnostic
 * details (foreground only).
 *
 * Returns the parsed error fields to the caller so the orchestrator's
 * `onError` callback and background-refresh telemetry can forward
 * normalized data without re-parsing responseJson.
 *
 * Background refreshes silently parse + log + telemeter; only
 * foreground calls render the notice.
 *
 * Globals defined: abj404HandlePaginationAjaxError.
 *
 * Depends on view_updater.js (abj404UpdateAjaxDebugLog),
 * view_updater_stage_diagnostics.js (abj404AjaxStageDiagnostics),
 * view_updater_table_init.js (abj404FormatAjaxFailureDetails,
 * abj404RenderAjaxErrorNotice).
 */
function abj404HandlePaginationAjaxError(ctx, jqXHR, textStatus, errorThrown) {
    var status = jqXHR && jqXHR.status ? jqXHR.status : '';
    var responseText = jqXHR && jqXHR.responseText ? String(jqXHR.responseText) : '';
    var responseJson = jqXHR && jqXHR.responseJSON ? jqXHR.responseJSON : null;
    var responsePreview = responseText;
    if (responsePreview.length > 2000) {
        // allow-em-dash: visible truncation marker preserved verbatim from the original debug-log preview
        responsePreview = responsePreview.slice(0, 2000) + "\n…(truncated)…";
    }

    abj404UpdateAjaxDebugLog('AJAX Error: ' + ctx.action, {
        status: status,
        textStatus: textStatus,
        errorThrown: errorThrown,
        durationMs: Date.now() - ctx.requestStartedAt // allow-direct-time: AJAX wall-clock duration for the failure debug log entry; preserved verbatim from view_updater_pagination.js pre-i352 split
    });

    // Always log full details to the console for easier debugging.
    if (window && window.console && window.console.error) {
        window.console.error('404 Solution AJAX error', {
            context: 'Updating table',
            status: status,
            textStatus: textStatus,
            errorThrown: errorThrown,
            url: ctx.baseUrl,
            action: ctx.action,
            subpage: ctx.subpage,
            responseJson: responseJson,
            responseText: responseText
        });
    }

    var parsed = {
        status: status,
        messageFromServer: '',
        stageFromServer: '',
        queryLabelFromServer: '',
        whatsHappeningFromServer: '',
        lastQueryRedacted: '',
        responseText: responseText
    };
    if (responseJson && responseJson.data) {
        if (responseJson.data.message) {
            parsed.messageFromServer = String(responseJson.data.message);
        }
        // ViewUpdater::ajaxUpdatePaginationLinks attaches a debug payload under
        // data.details when the caller is a plugin admin. context.stage names the
        // phase that was running (e.g. 'table_captured', 'captured_status_counts');
        // wpdb.last_query_redacted is the most recent SQL with literal values masked.
        // Surfacing both makes admin-side timeout/500 reports actionable without a
        // server-side debug log dump.
        if (responseJson.data.details && typeof responseJson.data.details === 'object') {
            var details = responseJson.data.details;
            if (details.context && details.context.stage) {
                parsed.stageFromServer = String(details.context.stage);
            }
            if (details.context && details.context.query_label) {
                parsed.queryLabelFromServer = String(details.context.query_label);
            }
            if (details.context && details.context.what_happening) {
                parsed.whatsHappeningFromServer = String(details.context.what_happening);
            }
            if (details.wpdb && details.wpdb.last_query_redacted) {
                parsed.lastQueryRedacted = String(details.wpdb.last_query_redacted);
            }
        }
    }

    if (!ctx.isBackgroundRefresh) {
        abj404RenderPaginationErrorNoticeForeground(ctx, parsed, textStatus, errorThrown);
    }

    return parsed;
}

/**
 * Build and emit the foreground admin notice describing the AJAX failure,
 * and (when the failure was a pure client-side timeout) kick off the
 * inflight-stage follow-up AJAX so the notice can pick up the stage label
 * the server stamped just before the client gave up.
 *
 * Internal to view_updater_pagination_error_notice.js; called by
 * abj404HandlePaginationAjaxError only.
 */
function abj404RenderPaginationErrorNoticeForeground(ctx, parsed, textStatus, errorThrown) {
    // Render a non-blocking admin notice instead of a native alert().
    // Native alert() blocks the page, breaks browser automation tests,
    // and forces the admin to dismiss before they can refresh.
    var noticeTitle = '404 Solution: AJAX error while updating the table.';
    // Wall-clock elapsed since AJAX dispatch.  Distinguishes
    // "instant network drop" (small elapsed) from "real slow query"
    // (close to the timeout budget) on pure client-timeout errors
    // where no responseJson is available.
    var elapsedMs = Date.now() - ctx.requestStartedAt; // allow-direct-time: visible elapsed-ms field in the diagnostic notice; preserved verbatim from view_updater_pagination.js pre-i352 split
    var inferredDiagnostics = abj404AjaxStageDiagnostics(parsed.stageFromServer, ctx.subpage);
    var detailMeta = {
        whatsHappening: parsed.whatsHappeningFromServer || inferredDiagnostics.whatsHappening,
        queryLabel: parsed.queryLabelFromServer || inferredDiagnostics.queryLabel,
        status: parsed.status,
        textStatus: textStatus,
        errorThrown: errorThrown,
        action: ctx.action,
        subpage: ctx.subpage,
        elapsedMs: elapsedMs,
        timeoutMs: ctx.ajaxTimeoutMs,
        stage: parsed.stageFromServer,
        message: parsed.messageFromServer,
        lastQueryRedacted: parsed.lastQueryRedacted
    };
    var detailLines = abj404FormatAjaxFailureDetails(detailMeta);
    var shouldFetchInflightStage = (
        textStatus === 'timeout' && !parsed.stageFromServer && !!ctx.inflightNonce
    );
    if (shouldFetchInflightStage) {
        detailLines.push('Inflight stage: (looking up...)');
    }
    var $detailsEl = jQuery('<pre></pre>')
        .css({whiteSpace: 'pre-wrap', margin: '0 0 8px 0'})
        .text(detailLines.join('\n'));
    // Trigger slug + context summary for the support button so
    // server-side log attribution distinguishes the two tabs and
    // the modal preview shows the admin which failure they are
    // reporting. Both slugs live in Ajax_SupportRequest::
    // ALLOWED_TRIGGER_SOURCES so the AJAX handler will accept them.
    var triggeredFromSlug = (ctx.subpage === 'abj404_captured')
        ? 'captured_404s_page' : 'redirects_page';
    var contextSummary = noticeTitle;
    if (parsed.messageFromServer) {
        contextSummary += ' ' + String(parsed.messageFromServer).slice(0, 200);
    } else if (textStatus) {
        contextSummary += ' (' + String(textStatus) + ')';
    }
    // Append the stage label to the support-request context so
    // the outgoing payload retains the same diagnostic trace
    // (e.g. "stage 1, getAdminRedirectsPageTable() ...") that
    // is no longer shown verbatim in the user-visible notice.
    var stageDiagnosticForPayload = inferredDiagnostics.stageNumber
        ? 'stage ' + inferredDiagnostics.stageNumber + ', '
        : '';
    stageDiagnosticForPayload += (parsed.queryLabelFromServer || inferredDiagnostics.queryLabel || '');
    if (stageDiagnosticForPayload) {
        contextSummary += ' [' + stageDiagnosticForPayload + ']';
    }
    abj404RenderAjaxErrorNotice({
        noticeTitle: noticeTitle,
        $detailsEl: $detailsEl,
        triggeredFromSlug: triggeredFromSlug,
        contextSummary: contextSummary
    });
    if (shouldFetchInflightStage) {
        abj404FollowUpInflightStage(ctx, detailLines, $detailsEl);
    }
}

/**
 * Read the server-stamped inflight-stage transient and patch the pending
 * notice diagnostic lines once the lookup returns.
 *
 * @param {Object} ctx Pagination error context.
 * @param {Array<string>} detailLines Rendered diagnostic lines.
 * @param {Object} $detailsEl jQuery-wrapped diagnostic <pre>.
 * @returns {void}
 */
function abj404FollowUpInflightStage(ctx, detailLines, $detailsEl) {
    var inflightAjaxRunner = (typeof abj404AjaxWithNonceRetry === 'function')
        ? abj404AjaxWithNonceRetry : jQuery.ajax; // ajax-direct-approved: documented fallback when view_updater_nonce_refresh.js is not yet loaded; canonical pattern in every view_updater_*.js dispatch site, preserved verbatim from view_updater_pagination.js pre-i352 split
    inflightAjaxRunner({
        url: ctx.baseUrl,
        type: 'POST',
        dataType: 'json',
        timeout: 5000,
        data: {
            action: 'ajaxFetchInflightStage',
            nonce: ctx.inflightNonce,
            requestId: ctx.requestId
        }
    }).done(function(stageResult) {
        var inflightStage = '';
        var inflightQueryLabel = '';
        var inflightWhatsHappening = '';
        if (stageResult && typeof stageResult.stage === 'string' && stageResult.stage !== '') {
            inflightStage = stageResult.stage;
        }
        if (stageResult && typeof stageResult.queryLabel === 'string' && stageResult.queryLabel !== '') {
            inflightQueryLabel = stageResult.queryLabel;
        }
        if (stageResult && typeof stageResult.whatsHappening === 'string' && stageResult.whatsHappening !== '') {
            inflightWhatsHappening = stageResult.whatsHappening;
        }
        var lookupLine = inflightStage
            ? 'Inflight stage: ' + inflightStage
            : 'Inflight stage: (unknown)';
        var lookupDiagnostics = abj404AjaxStageDiagnostics(inflightStage, ctx.subpage);
        var updated = detailLines.slice();
        for (var i = 0; i < updated.length; i++) {
            if (updated[i].indexOf('What was happening:') === 0) {
                updated[i] = 'What was happening: ' + (inflightWhatsHappening || lookupDiagnostics.whatsHappening);
            }
            if (updated[i].indexOf('Query:') === 0) {
                updated[i] = 'Query: ' + (inflightQueryLabel || lookupDiagnostics.queryLabel);
            }
            if (updated[i].indexOf('Inflight stage:') === 0) {
                updated[i] = lookupLine;
            }
        }
        $detailsEl.text(updated.join('\n'));
    }).fail(function() {
        var updated = detailLines.slice();
        for (var i = 0; i < updated.length; i++) {
            if (updated[i].indexOf('Inflight stage:') === 0) {
                updated[i] = 'Inflight stage: (lookup failed)';
                break;
            }
        }
        $detailsEl.text(updated.join('\n'));
    });
}
