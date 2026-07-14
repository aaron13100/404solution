/**
 * AJAX transport for the support-request modal.
 *
 * Pure network layer: this module never reads or writes the DOM. It
 * exposes two Promise-returning functions consumed by the modal
 * controller (support-request-button.js) and by the modal view's
 * payload preview expander.
 *
 *   loadPreview(triggeredFrom, userMessage)
 *     POSTs to the abj404_support_request_preview AJAX endpoint and
 *     resolves with the preview payload ({payload: {...}, ...}).
 *     Rejects with Error('missing nonce') when the nonce was not
 *     primed (degraded admin path), Error('preview failed') when the
 *     server returns success=false, and the native fetch TypeError
 *     when the network call fails. A stalled response past
 *     PREVIEW_TIMEOUT_MS is aborted, rejecting with the AbortError
 *     DOMException; the view's preview expander catch is already
 *     generic (renders t('previewError') for any rejection reason),
 *     so no new branch is needed to handle it.
 *
 *   sendReport({triggered_from, user_message, reply_email})
 *     Delegates to window.abj404SupportRequest.send() so the cooldown
 *     / network-failure / generic-error branches in doSend continue
 *     to see the same error shapes (retry_after_seconds, TypeError,
 *     {message}). Rejects with Error('transport unavailable') when
 *     the client bundle has not loaded yet (defensive: degraded path
 *     enqueues these in dependency order, but mountAll can race the
 *     bundle on very slow connections).
 *
 * Browser support: matches .browserslistrc. Uses fetch + Promise + no
 * jQuery so the component runs on a fatal-fallback page where the
 * plugin's normal JS stack may not be loaded.
 */

(function (window) {
    'use strict';

    function getAjaxUrl() {
        if (typeof window.ajaxurl === 'string' && window.ajaxurl) {
            return window.ajaxurl;
        }
        if (window.ABJ404 && window.ABJ404.ajaxurl) {
            return String(window.ABJ404.ajaxurl);
        }
        return '/wp-admin/admin-ajax.php';
    }

    function getPreviewNonce() {
        if (window.ABJ404 && window.ABJ404.nonces && window.ABJ404.nonces.support_request_preview) {
            return String(window.ABJ404.nonces.support_request_preview);
        }
        return '';
    }

    // Preview is a one-shot admin action; a generous bound avoids a
    // false-positive abort on a slow-but-working shared host while still
    // guaranteeing the preview expander cannot stay stuck on "Loading
    // report contents..." forever if admin-ajax never responds (M501).
    var PREVIEW_TIMEOUT_MS = 30000;

    /**
     * @param {string} triggeredFrom
     * @param {string} userMessage
     * @returns {Promise<Object>}
     */
    function loadPreview(triggeredFrom, userMessage) {
        var nonce = getPreviewNonce();
        if (!nonce) {
            return Promise.reject(new Error('missing nonce')); // allow-raw-error: internal sentinel consumed by view's preview expander; never surfaced as user text
        }
        var formData = new FormData();
        formData.append('action', 'abj404_support_request_preview');
        formData.append('nonce', nonce);
        formData.append('triggered_from', triggeredFrom);
        formData.append('user_message', userMessage || '');
        var controller = new AbortController();
        var timeoutId = setTimeout(function () { controller.abort(); }, PREVIEW_TIMEOUT_MS);
        // ajax-direct-approved: no jQuery dependency (component runs on fatal-fallback pages where jQuery may not load)
        return fetch(getAjaxUrl(), { // allow-direct-network: this module IS the support-request API client; no project-wide adapter exists for this AJAX surface
            method: 'POST',
            credentials: 'same-origin',
            body: formData,
            signal: controller.signal
        }).then(function (response) {
            clearTimeout(timeoutId);
            // Clone before reading: a Response body can only be consumed
            // once, and a JSON-parse failure needs the raw text (WAF block
            // page, gateway timeout HTML, PHP fatal output) so the failure
            // is diagnosable instead of surfacing only a raw SyntaxError.
            return response.clone().text().then(function (rawText) {
                var json;
                try {
                    json = JSON.parse(rawText);
                } catch (parseError) {
                    if (window.console && window.console.error) {
                        window.console.error('404 Solution: support request preview response was not valid JSON', {
                            status: response.status,
                            bodySnippet: rawText.slice(0, 500),
                            parseError: parseError.message
                        });
                    }
                    throw new Error('preview failed'); // allow-raw-error: internal sentinel; view's preview expander renders t('previewError') from its own catch
                }
                if (json && json.success === true && json.data) {
                    return json.data;
                }
                throw new Error('preview failed'); // allow-raw-error: internal sentinel; view's preview expander renders t('previewError') from its own catch
            });
        }).catch(function (err) {
            // Clear on the failure path too (parse failure above, a real
            // network TypeError, or the AbortError from a timed-out
            // request). The preview expander's own catch is already
            // generic -- it renders t('previewError') for any rejection
            // reason -- so the timeout needs no new branch here; just
            // preserve the existing rejection behavior.
            clearTimeout(timeoutId);
            throw err;
        });
    }

    /**
     * @param {Object} payload  {triggered_from, user_message, reply_email}
     * @returns {Promise<Object>}  resolves with the server response object
     *   (typically {reference_id: '...'}). Rejects with the underlying
     *   error so the controller can branch on retry_after_seconds /
     *   TypeError / generic message.
     */
    function sendReport(payload) {
        var sender = window.abj404SupportRequest;
        if (!sender || typeof sender.send !== 'function') {
            return Promise.reject(new Error('transport unavailable')); // allow-raw-error: internal sentinel; controller's doSend catch falls into the generic-error branch with t('genericError')
        }
        return sender.send(payload);
    }

    window.ABJ404 = window.ABJ404 || {};
    window.ABJ404.SupportRequestTransport = {
        loadPreview: loadPreview,
        sendReport: sendReport
    };

})(window);
