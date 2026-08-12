/**
 * Client for the abj404_support_request AJAX endpoint.
 *
 * Exposes window.abj404SupportRequest.send({triggered_from, user_message,
 * reply_email}) for the reusable button UI added in task B. Returns a
 * Promise that resolves to {ok, reference_id, fallback_used} on success
 * or rejects with {status, message, retry_after_seconds?} on error. A
 * genuine network failure rejects with the native fetch TypeError (never
 * caught here); a stalled response past SEND_TIMEOUT_MS is aborted and
 * surfaces as that same TypeError shape so callers' existing
 * `err instanceof TypeError` network-failure branch (see
 * support-request-button.js's doSend) handles both identically.
 *
 * The nonce is read from a global ABJ404 namespace populated server-side:
 *     ABJ404.nonces.support_request = '<wp_create_nonce(...)>';
 * Callers do NOT have to pass the nonce; it is injected automatically.
 */

(function (window, abj404Module) {
    if (window.abj404ClientBuildRegistry) {
        window.abj404ClientBuildRegistry.register('support_request', abj404Module);
    }
    abj404Module(window);
}(window, /* abj404-client-module:start */ function (window) {
    'use strict';

    function resolveNonce() {
        if (window.ABJ404 && window.ABJ404.nonces && window.ABJ404.nonces.support_request) {
            return String(window.ABJ404.nonces.support_request);
        }
        return '';
    }

    function resolveAjaxUrl() {
        if (typeof window.ajaxurl === 'string' && window.ajaxurl) {
            return window.ajaxurl;
        }
        if (window.ABJ404 && window.ABJ404.ajaxurl) {
            return String(window.ABJ404.ajaxurl);
        }
        return '/wp-admin/admin-ajax.php';
    }

    // Submission is a one-shot admin action; a generous bound avoids a
    // false-positive abort on a slow-but-working shared host while still
    // guaranteeing the caller's Promise cannot stay pending forever if
    // admin-ajax never responds (M501).
    var SEND_TIMEOUT_MS = 30000;

    /**
     * Hard bound on the drained client transport telemetry. The server bounds
     * the same value again; this keeps a pathological buffer from inflating
     * the request before it is even sent.
     */
    var MAX_TELEMETRY_CHARS = 32000;

    /**
     * The only attempt outcome that means "did not fail". An allowlist, not a
     * deny-list of failure words: 'pending' is an attempt that never finished
     * (the hung request itself) and an unrecognised or absent outcome is an
     * unknown, which is worth more than a known success when something has to
     * be dropped.
     */
    var HEALTHY_OUTCOMES = ['success'];

    /**
     * The attempt records that fit in MAX_TELEMETRY_CHARS, in their original
     * order, failures first.
     *
     * Whole records are dropped, never bytes. The buffer store holds up to
     * 48 KB while this bound is 32 KB, so slicing the serialized array at a
     * character offset -- which is what this did -- produced invalid JSON
     * whenever the buffer was full. The server then reported the entire
     * client-side story as unparseable, which is total loss on the ONE channel
     * that can describe attempts the server never saw. Failed attempts are
     * kept oldest first (the first failure has no retry effects in it) and
     * successes newest first.
     *
     * @param {Array<object>} records
     * @returns {Array<object>}
     */
    function recordsWithinBound(records) {
        var failed = [];
        var healthy = [];
        records.forEach(function (record, position) {
            var outcome = record && typeof record.outcome === 'string' ? record.outcome : '';
            if (HEALTHY_OUTCOMES.indexOf(outcome) === -1) {
                failed.push(position);
            } else {
                healthy.push(position);
            }
        });

        // Two brackets, plus one comma per record after the first.
        var used = 2;
        var keep = [];
        failed.concat(healthy.reverse()).forEach(function (position) {
            var encoded = JSON.stringify(records[position]);
            if (typeof encoded !== 'string') {
                return;
            }
            var cost = encoded.length + (keep.length === 0 ? 0 : 1);
            if (used + cost > MAX_TELEMETRY_CHARS) {
                return;
            }
            used += cost;
            keep.push(position);
        });

        return keep.sort(function (left, right) { return left - right; })
            .map(function (position) { return records[position]; });
    }

    /**
     * Drain the browser's transport-attempt buffer into the support payload.
     *
     * This is the only channel that carries records the server never saw:
     * attempts whose retry never happened because the admin gave up, and
     * attempts from a page that was closed before the next request went out.
     * Returns '' when the telemetry module is absent or the buffer is empty.
     *
     * @return {string}
     */
    function drainClientTelemetry() {
        try {
            if (!window.abj404ClientTelemetryStore) {
                return '';
            }
            var records = window.abj404ClientTelemetryStore.readAll();
            if (!records || records.length === 0) {
                return '';
            }
            var serialized = JSON.stringify(records);
            if (serialized.length <= MAX_TELEMETRY_CHARS) {
                return serialized;
            }
            return JSON.stringify(recordsWithinBound(records));
        } catch (drainError) {
            if (window.console && window.console.warn) {
                window.console.warn('404 Solution: could not drain client transport telemetry', drainError);
            }
            return '';
        }
    }

    /**
     * The browser session (tab) this report is being sent from.
     *
     * Every table request already carries this id, and the server journals the
     * detach A/B mode it chose under it. Sending it here is what lets the
     * server decide the experiment for THIS tab at harvest time: the checkpoint
     * journal is site-wide, so without the id a second admin tab's attempts
     * would be indistinguishable from this one's. Returns '' when the telemetry
     * modules are not on the page (the plugins-list row action, a corrupt
     * install's degraded screen), which the server reports as "no session"
     * rather than as a measured result.
     *
     * @return {string}
     */
    function browserSessionId() {
        try {
            var env = window.abj404ClientTelemetryEnv;
            return env && typeof env.sessionId === 'function' ? String(env.sessionId()) : '';
        } catch (sessionError) {
            if (window.console && window.console.warn) {
                window.console.warn('404 Solution: could not read the client session id', sessionError);
            }
            return '';
        }
    }

    /**
     * Send a support request. Returns a Promise.
     *
     * @param {Object} args
     * @param {string} args.triggered_from  Required. Must match the server
     *   allowlist (redirects_page, captured_404s_page, plugins_row_action,
     *   settings_debug, system_corrupt_install).
     * @param {string} [args.user_message]  Optional, max 2000 chars.
     * @param {string} [args.reply_email]   Optional.
     * @return {Promise<Object>}
     */
    function send(args) {
        args = args || {};
        var formData = new FormData();
        formData.append('action', 'abj404_support_request');
        formData.append('nonce', resolveNonce());
        formData.append('triggered_from', String(args.triggered_from || ''));
        if (typeof args.user_message === 'string') {
            formData.append('user_message', args.user_message);
        }
        if (typeof args.reply_email === 'string') {
            formData.append('reply_email', args.reply_email);
        }
        var clientTelemetry = drainClientTelemetry();
        if (clientTelemetry !== '') {
            formData.append('client_telemetry', clientTelemetry);
        }
        var sessionId = browserSessionId();
        if (sessionId !== '') {
            formData.append('sessionId', sessionId);
        }

        var controller = new AbortController();
        var timeoutId = setTimeout(function () { controller.abort(); }, SEND_TIMEOUT_MS);

        // ajax-direct-approved: this is the dedicated fetch-based API client for the support-request endpoint
        return fetch(resolveAjaxUrl(), { // allow-direct-network: this IS the dedicated fetch-based API client for the support-request endpoint
            method: 'POST',
            credentials: 'same-origin',
            body: formData,
            signal: controller.signal
        }).then(function (response) {
            clearTimeout(timeoutId);
            // Clone before reading: a Response body can only be consumed
            // once, and a JSON-parse failure needs the raw text (WAF block
            // page, gateway timeout HTML, PHP fatal output) so the failure
            // is diagnosable instead of collapsing into a generic message.
            return response.clone().text().then(function (rawText) {
                var json;
                try {
                    json = JSON.parse(rawText);
                } catch (parseError) {
                    if (window.console && window.console.error) {
                        window.console.error('404 Solution: support request response was not valid JSON', {
                            status: response.status,
                            bodySnippet: rawText.slice(0, 500),
                            parseError: parseError.message
                        });
                    }
                    return { status: response.status, body: null };
                }
                return { status: response.status, body: json };
            });
        }).then(function (wrapped) {
            var body = wrapped.body || {};
            var data = body.data || {};
            if (body.success === true) {
                return data;
            }
            var err = {
                status: wrapped.status,
                message: data.message || 'Support request failed.'
            };
            if (typeof data.retry_after_seconds === 'number') {
                err.retry_after_seconds = data.retry_after_seconds;
            }
            if (typeof data.fallback_used === 'boolean') {
                err.fallback_used = data.fallback_used;
            }
            throw err;
        }).catch(function (err) {
            clearTimeout(timeoutId);
            if (err && err.name === 'AbortError') {
                // A stalled response never resolves the request; treat the
                // timeout as the same network-class failure the native
                // TypeError already represents, so it flows through the
                // caller's existing `err instanceof TypeError` branch
                // (support-request-button.js's doSend) instead of falling
                // into the generic markFailure() message path.
                throw new TypeError('Request timed out'); // allow-raw-error: reuses the native TypeError shape callers already branch on; not a new error contract
            }
            throw err;
        });
    }

    window.abj404SupportRequest = { send: send };

} /* abj404-client-module:end */));
