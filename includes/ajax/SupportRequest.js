/**
 * Client for the abj404_support_request AJAX endpoint.
 *
 * Exposes window.abj404SupportRequest.send({triggered_from, user_message,
 * reply_email}) for the reusable button UI added in task B. Returns a
 * Promise that resolves to {ok, reference_id, fallback_used} on success
 * or rejects with {status, message, retry_after_seconds?} on error.
 *
 * The nonce is read from a global ABJ404 namespace populated server-side:
 *     ABJ404.nonces.support_request = '<wp_create_nonce(...)>';
 * Callers do NOT have to pass the nonce; it is injected automatically.
 */

(function (window) {
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

        return fetch(resolveAjaxUrl(), { // allow-direct-network: this IS the dedicated fetch-based API client for the support-request endpoint
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        }).then(function (response) {
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
        });
    }

    window.abj404SupportRequest = { send: send };

})(window);
