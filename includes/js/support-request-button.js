/**
 * Reusable "Send debug log to developer" button + confirmation modal.
 *
 * Public API:
 *   ABJ404.SupportRequestButton.mount(rootEl, opts)
 *     - rootEl: HTMLElement to render the button into.
 *     - opts.triggered_from: required allowlisted slug
 *       (redirects_page, captured_404s_page, plugins_row_action,
 *        settings_debug, system_corrupt_install).
 *     - opts.context_summary: optional one-line description shown in
 *       the modal so the admin remembers which screen the report
 *       anchors to.
 *
 * mountAll() auto-bootstraps every .abj404-support-request-mount on the
 * page using their data-* attributes. Callers that need finer control
 * (e.g. lazy-mounting after AJAX content loads) call mount(rootEl,
 * {...}) directly.
 *
 * State machine for the modal:
 *   idle      -> button visible, modal closed.
 *   confirming-> modal open, primary button enabled.
 *   sending   -> modal open, primary button disabled + spinner.
 *   success   -> modal open, success message + close button.
 *   failure   -> modal open, error message + retry button.
 *   cooldown  -> modal open, cooldown message, no retry until elapsed.
 *
 * Accessibility:
 *   - Modal has role="dialog" + aria-modal="true" + aria-labelledby.
 *   - Focus is trapped in the modal while open and restored to the
 *     button on close.
 *   - ESC closes the modal (cancel semantics, no AJAX).
 *
 * Browser support: matches .browserslistrc (last 2 versions of each
 * major browser). Uses fetch (via abj404SupportRequest.send), Promise,
 * and standard DOM APIs. No jQuery dependency for the component itself
 * so it can mount on a fatal-error fallback page where jQuery may not
 * be loaded.
 */

(function (window, document) {
    'use strict';

    var SELECTOR = '.abj404-support-request-mount';
    var LINK_SELECTOR = '.abj404-support-request-link';
    var STYLE_TAG_ID = 'abj404-srb-styles';

    /**
     * Polished modal styles. Injected once per page on first build so
     * the modal works on a fatal-fallback page where the plugin's
     * stylesheet may not be loaded. Kept self-contained (no external
     * font, no external image).
     */
    var STYLE_BLOCK = [
        '.abj404-srb-overlay {',
        '  box-sizing: border-box;',
        '  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", Arial, sans-serif;',
        '  padding: 24px;',
        '}',
        '.abj404-srb-overlay * { box-sizing: border-box; }',
        '.abj404-srb-dialog {',
        '  width: 100%;',
        '  max-width: 560px;',
        '  max-height: calc(100vh - 48px);',
        '  overflow-y: auto;',
        '  border-radius: 8px;',
        '  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.28), 0 0 0 1px rgba(0, 0, 0, 0.04);',
        '  padding: 26px 30px 22px;',
        '  color: #1f2937;',
        '}',
        '.abj404-srb-title {',
        '  margin: 0 0 8px;',
        '  font-size: 18px;',
        '  font-weight: 600;',
        '  line-height: 1.3;',
        '  color: #111827;',
        '}',
        '.abj404-srb-explainer {',
        '  margin: 0 0 16px;',
        '  color: #4b5563;',
        '  line-height: 1.55;',
        '  font-size: 14px;',
        '}',
        '.abj404-srb-context-summary {',
        '  margin: 0 0 16px;',
        '  padding: 10px 12px;',
        '  background: #f3f4f6;',
        '  border-left: 3px solid #2271b1;',
        '  border-radius: 3px;',
        '  font-size: 13px;',
        '  color: #374151;',
        '}',
        '.abj404-srb-categories-heading {',
        '  margin: 0 0 6px;',
        '  font-size: 13px;',
        '  font-weight: 600;',
        '  color: #1f2937;',
        '}',
        '.abj404-srb-categories {',
        '  margin: 0 0 18px;',
        '  padding-left: 22px;',
        '  font-size: 13px;',
        '  color: #4b5563;',
        '  line-height: 1.55;',
        '}',
        '.abj404-srb-categories li {',
        '  margin: 0 0 2px;',
        '  list-style: disc;',
        '}',
        '.abj404-srb-payload-details {',
        '  margin: 0 0 18px;',
        '  border: 1px solid #e5e7eb;',
        '  border-radius: 6px;',
        '  overflow: hidden;',
        '  background: #fff;',
        '}',
        '.abj404-srb-payload-details > summary {',
        '  cursor: pointer;',
        '  padding: 10px 14px;',
        '  background: #f9fafb;',
        '  font-weight: 500;',
        '  color: #2271b1;',
        '  user-select: none;',
        '  outline-offset: -2px;',
        '}',
        '.abj404-srb-payload-details[open] > summary {',
        '  border-bottom: 1px solid #e5e7eb;',
        '}',
        '.abj404-srb-payload-preview {',
        '  margin: 0;',
        '  padding: 12px 14px;',
        '  background: #fff;',
        '  color: #111827;',
        '  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;',
        '  font-size: 12px;',
        '  line-height: 1.5;',
        '  max-height: 220px;',
        '  overflow: auto;',
        '  white-space: pre-wrap;',
        '  word-break: break-word;',
        '}',
        '.abj404-srb-form { display: block; }',
        '.abj404-srb-field-label {',
        '  display: block;',
        '  margin: 0 0 14px;',
        '  font-weight: 500;',
        '  color: #1f2937;',
        '  font-size: 13px;',
        '}',
        '.abj404-srb-field-label-text {',
        '  display: block;',
        '  margin-bottom: 5px;',
        '}',
        '.abj404-srb-user-message,',
        '.abj404-srb-reply-email {',
        '  display: block;',
        '  width: 100%;',
        '  border: 1px solid #d1d5db;',
        '  border-radius: 4px;',
        '  padding: 8px 10px;',
        '  font-size: 14px;',
        '  line-height: 1.45;',
        '  font-family: inherit;',
        '  color: #111827;',
        '  background: #fff;',
        '  transition: border-color 0.15s ease, box-shadow 0.15s ease;',
        '}',
        '.abj404-srb-user-message:focus,',
        '.abj404-srb-reply-email:focus {',
        '  border-color: #2271b1;',
        '  outline: none;',
        '  box-shadow: 0 0 0 2px rgba(34, 113, 177, 0.2);',
        '}',
        '.abj404-srb-user-message {',
        '  resize: vertical;',
        '  min-height: 96px;',
        '  font-family: inherit;',
        '}',
        '.abj404-srb-consent {',
        '  margin: 4px 0 16px;',
        '  font-size: 12px;',
        '  color: #4b5563;',
        '  line-height: 1.5;',
        '}',
        '.abj404-srb-success,',
        '.abj404-srb-error {',
        '  margin: 12px 0 4px;',
        '  padding: 10px 14px;',
        '  border-radius: 4px;',
        '  font-size: 13px;',
        '  line-height: 1.45;',
        '}',
        '.abj404-srb-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }',
        '.abj404-srb-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }',
        '.abj404-srb-buttons {',
        '  display: flex;',
        '  gap: 10px;',
        '  justify-content: flex-end;',
        '  align-items: center;',
        '  margin-top: 22px;',
        '  flex-wrap: wrap;',
        '}',
        '.abj404-srb-buttons .abj404-srb-send {',
        '  min-width: 110px;',
        '}',
        '@media (max-width: 540px) {',
        '  .abj404-srb-overlay { padding: 12px; }',
        '  .abj404-srb-dialog { padding: 20px 18px 16px; max-height: calc(100vh - 24px); }',
        '  .abj404-srb-buttons { flex-direction: column-reverse; align-items: stretch; }',
        '  .abj404-srb-buttons .abj404-srb-send,',
        '  .abj404-srb-buttons .abj404-srb-cancel { width: 100%; min-width: 0; }',
        '}'
    ].join('\n');

    function ensureStyles() {
        if (!document.head || document.getElementById(STYLE_TAG_ID)) {
            return;
        }
        var style = document.createElement('style');
        style.id = STYLE_TAG_ID;
        style.appendChild(document.createTextNode(STYLE_BLOCK));
        document.head.appendChild(style);
    }
    var I18N_FALLBACK = {
        button: 'Send debug log to developer',
        modalTitle: 'Send debug log to developer',
        explainer: 'This sends a one-time diagnostic report to the plugin developer so they can investigate the issue you are seeing.',
        categoriesHeading: 'This report includes:',
        categorySiteUrl: 'Site URL',
        categoryVersions: 'Plugin, PHP, WordPress, and database versions',
        categoryActivePlugins: 'List of active plugins',
        categoryDebugLog: 'Recent debug log excerpt',
        categoryUserMessage: 'Optional message and reply email you provide below',
        showPayloadOpen: "Show what's in this report",
        showPayloadClose: 'Hide report contents',
        loadingPreview: 'Loading report contents...',
        previewError: 'Could not load preview. The full report is still safe to send.',
        userMessageLabel: 'What went wrong? (optional, helps us diagnose)',
        replyEmailLabel: 'Where should we reply? (optional)',
        consent: 'By clicking Send report, you consent to transmitting the information above to the plugin developer for support purposes. The data is used only to diagnose your issue and is not shared with third parties.',
        send: 'Send report',
        cancel: 'Cancel',
        sending: 'Sending...',
        retry: 'Retry',
        close: 'Close',
        successPrefix: 'Sent. Reference: ',
        successSuffix: '. Thank you.',
        cooldownTemplate: 'You already sent a report recently. Try again in {minutes} minute(s).',
        genericError: 'Could not send report. Please try again later.'
    };

    // window.wp.i18n is the canonical translation surface in WP admin
    // contexts. When unavailable (test harness, fatal-fallback page),
    // fall back to the English strings above.
    function t(key) {
        if (window.wp && window.wp.i18n && typeof window.wp.i18n.__ === 'function') {
            return window.wp.i18n.__(I18N_FALLBACK[key], '404-solution');
        }
        return I18N_FALLBACK[key];
    }

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function (k) {
                if (k === 'className') {
                    node.className = attrs[k];
                } else if (k === 'text') {
                    node.textContent = attrs[k];
                } else {
                    node.setAttribute(k, attrs[k]);
                }
            });
        }
        if (children) {
            children.forEach(function (c) {
                if (c) {
                    node.appendChild(c);
                }
            });
        }
        return node;
    }

    /**
     * Mount the support-request button + modal into `rootEl`.
     *
     * @param {HTMLElement} rootEl
     * @param {Object} opts
     * @param {string} opts.triggered_from
     * @param {string} [opts.context_summary]
     * @returns {Object} controller with .destroy(), .openModal(), .getState()
     */
    function mount(rootEl, opts) {
        opts = opts || {};
        var triggeredFrom = String(opts.triggered_from || '');
        var contextSummary = opts.context_summary ? String(opts.context_summary) : '';

        if (!rootEl || !triggeredFrom) {
            return { destroy: function () {}, openModal: function () {}, getState: function () { return 'idle'; } };
        }

        var state = 'idle';
        var lastFocus = null;

        // -- Render the trigger button --------------------------------
        var button = el('button', {
            type: 'button',
            className: 'button abj404-support-request-button',
            'aria-label': t('button')
        });
        button.textContent = t('button');
        rootEl.innerHTML = '';
        rootEl.appendChild(button);

        // Modal scaffolding (created on first open; reused thereafter
        // so re-opening preserves a typed message until the user
        // explicitly cancels). One modal per mount() call.
        var modal = null;
        var modalEls = null;

        // The button replaces rootEl's contents and serves as the
        // explicit click target. attachLink() (below) reuses this same
        // controller but binds the click handler to a caller-provided
        // anchor element instead, leaving its inline DOM intact.
        button.addEventListener('click', function () {
            openModal();
        });

        function openModal() {
            lastFocus = document.activeElement;
            if (!modal) {
                modal = buildModalDOM();
                document.body.appendChild(modal.overlay);
            }
            modal.overlay.style.display = 'flex';
            setState('confirming');
            // Defer focus so screen readers announce the dialog.
            window.setTimeout(function () {
                if (modal && modal.firstFocusable) {
                    modal.firstFocusable.focus();
                }
            }, 0);
        }

        function closeModal() {
            if (modal) {
                modal.overlay.style.display = 'none';
            }
            setState('idle');
            if (lastFocus && typeof lastFocus.focus === 'function') {
                lastFocus.focus();
            }
        }

        function setState(next) {
            state = next;
            if (!modal) {
                return;
            }
            // Toggle UI groups based on state. Hidden via display:none
            // because aria-hidden alone leaves them in the focus order
            // for non-AT users.
            modal.successBlock.style.display = (state === 'success') ? 'block' : 'none';
            modal.errorBlock.style.display = (state === 'failure' || state === 'cooldown') ? 'block' : 'none';
            modal.formBlock.style.display = (state === 'confirming' || state === 'sending') ? 'block' : 'none';
            modal.sendButton.disabled = (state === 'sending' || state === 'cooldown' || state === 'success');
            if (state === 'sending') {
                modal.sendButton.textContent = t('sending');
            } else if (state === 'failure') {
                modal.sendButton.textContent = t('retry');
            } else {
                modal.sendButton.textContent = t('send');
            }
            if (state === 'success' || state === 'cooldown') {
                modal.cancelButton.textContent = t('close');
            } else {
                modal.cancelButton.textContent = t('cancel');
            }
        }

        function buildModalDOM() {
            ensureStyles();
            var titleId = 'abj404-srb-title-' + Math.random().toString(36).slice(2, 8);
            var title = el('h2', { id: titleId, className: 'abj404-srb-title', text: t('modalTitle') });
            var explainer = el('p', { className: 'abj404-srb-explainer', text: t('explainer') });

            // Data-category list (GDPR: admin sees every category of
            // data that will be transmitted before the consent step).
            var categoriesHeading = el('p', { className: 'abj404-srb-categories-heading', text: t('categoriesHeading') });
            var categoriesList = el('ul', { className: 'abj404-srb-categories' });
            ['categorySiteUrl', 'categoryVersions', 'categoryActivePlugins', 'categoryDebugLog', 'categoryUserMessage'].forEach(function (key) {
                categoriesList.appendChild(el('li', { text: t(key) }));
            });

            var contextNode = null;
            if (contextSummary) {
                contextNode = el('p', { className: 'abj404-srb-context-summary' });
                contextNode.textContent = contextSummary;
            }

            // Collapsible "Show what's in this report" expander. Uses
            // <details>/<summary> for native a11y semantics; the
            // payload preview is fetched lazily on first open so the
            // modal is responsive even if the preview AJAX is slow.
            var details = el('details', { className: 'abj404-srb-payload-details' });
            var summary = el('summary');
            summary.textContent = t('showPayloadOpen');
            var payloadPre = el('pre', {
                className: 'abj404-srb-payload-preview',
                'aria-live': 'polite'
            });
            payloadPre.textContent = '';
            details.appendChild(summary);
            details.appendChild(payloadPre);
            var previewLoaded = false;
            details.addEventListener('toggle', function () {
                if (details.open) {
                    summary.textContent = t('showPayloadClose');
                    if (!previewLoaded) {
                        payloadPre.textContent = t('loadingPreview');
                        previewLoaded = true;
                        loadPreview(triggeredFrom, userMessageInput.value).then(function (preview) {
                            payloadPre.textContent = JSON.stringify(preview.payload, null, 2);
                        }).catch(function () {
                            payloadPre.textContent = t('previewError');
                        });
                    }
                } else {
                    summary.textContent = t('showPayloadOpen');
                    // Reset the cache flag on close so the user can
                    // retry a failed preview by closing + re-opening
                    // the expander. Resetting it inside the catch
                    // would race with any auto-toggle the host fires
                    // shortly after, re-triggering loadPreview and
                    // overwriting the error message with "Loading...".
                    previewLoaded = false;
                }
            });

            var userMessageLabel = el('label', { className: 'abj404-srb-field-label' });
            var userMessageLabelText = el('span', { className: 'abj404-srb-field-label-text', text: t('userMessageLabel') });
            var userMessageInput = el('textarea', {
                className: 'abj404-srb-user-message',
                rows: '4',
                maxlength: '2000'
            });
            userMessageLabel.appendChild(userMessageLabelText);
            userMessageLabel.appendChild(userMessageInput);

            var replyEmailLabel = el('label', { className: 'abj404-srb-field-label' });
            var replyEmailLabelText = el('span', { className: 'abj404-srb-field-label-text', text: t('replyEmailLabel') });
            var replyEmailInput = el('input', {
                type: 'email',
                className: 'abj404-srb-reply-email'
            });
            replyEmailLabel.appendChild(replyEmailLabelText);
            replyEmailLabel.appendChild(replyEmailInput);

            var consent = el('p', { className: 'abj404-srb-consent', text: t('consent') });

            var formBlock = el('div', { className: 'abj404-srb-form' }, [
                userMessageLabel,
                replyEmailLabel,
                consent
            ]);

            var successBlock = el('div', {
                className: 'abj404-srb-success notice notice-success',
                role: 'status'
            });
            var errorBlock = el('div', {
                className: 'abj404-srb-error notice notice-error',
                role: 'alert'
            });

            var sendButton = el('button', {
                type: 'button',
                className: 'button button-primary abj404-srb-send'
            });
            sendButton.textContent = t('send');
            var cancelButton = el('button', {
                type: 'button',
                className: 'button abj404-srb-cancel'
            });
            cancelButton.textContent = t('cancel');

            var buttons = el('div', { className: 'abj404-srb-buttons' }, [cancelButton, sendButton]);

            var dialog = el('div', {
                className: 'abj404-srb-dialog',
                role: 'dialog',
                'aria-modal': 'true',
                'aria-labelledby': titleId
            }, [
                title,
                explainer,
                contextNode,
                categoriesHeading,
                categoriesList,
                details,
                formBlock,
                errorBlock,
                successBlock,
                buttons
            ]);

            var overlay = el('div', {
                className: 'abj404-srb-overlay'
            }, [dialog]);
            overlay.style.display = 'none';
            // Inline only the layout-mode / positioning props so the
            // overlay floats correctly even before our injected
            // <style> tag has parsed. Visual polish (padding, radius,
            // shadow, typography) lives in STYLE_BLOCK, injected
            // once by ensureStyles() in buildModalDOM().
            overlay.style.position = 'fixed';
            overlay.style.top = '0';
            overlay.style.left = '0';
            overlay.style.right = '0';
            overlay.style.bottom = '0';
            overlay.style.background = 'rgba(15, 23, 42, 0.55)';
            overlay.style.zIndex = '160000';
            overlay.style.alignItems = 'center';
            overlay.style.justifyContent = 'center';
            dialog.style.background = '#fff';

            // Wire button + ESC + overlay-click handlers.
            sendButton.addEventListener('click', function () {
                doSend(userMessageInput.value, replyEmailInput.value);
            });
            cancelButton.addEventListener('click', function () {
                closeModal();
            });
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) {
                    closeModal();
                }
            });
            dialog.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    closeModal();
                    return;
                }
                if (e.key === 'Tab') {
                    trapFocus(e, dialog);
                }
            });

            return {
                overlay: overlay,
                dialog: dialog,
                firstFocusable: userMessageInput,
                userMessageInput: userMessageInput,
                replyEmailInput: replyEmailInput,
                sendButton: sendButton,
                cancelButton: cancelButton,
                successBlock: successBlock,
                errorBlock: errorBlock,
                formBlock: formBlock,
                payloadPre: payloadPre,
                detailsEl: details,
                resetPreviewLoaded: function () { previewLoaded = false; }
            };
        }

        function doSend(userMessage, replyEmail) {
            if (!modal) { return; }
            modal.errorBlock.textContent = '';
            modal.successBlock.textContent = '';
            setState('sending');

            var sender = window.abj404SupportRequest;
            if (!sender || typeof sender.send !== 'function') {
                modal.errorBlock.textContent = t('genericError');
                setState('failure');
                return;
            }

            sender.send({
                triggered_from: triggeredFrom,
                user_message: userMessage,
                reply_email: replyEmail
            }).then(function (data) {
                var ref = (data && data.reference_id) ? String(data.reference_id) : '';
                modal.successBlock.textContent = t('successPrefix') + ref + t('successSuffix');
                setState('success');
            }).catch(function (err) {
                err = err || {};
                if (typeof err.retry_after_seconds === 'number') {
                    var minutes = Math.max(1, Math.ceil(err.retry_after_seconds / 60));
                    modal.errorBlock.textContent = t('cooldownTemplate').replace('{minutes}', String(minutes));
                    setState('cooldown');
                    return;
                }
                modal.errorBlock.textContent = (err.message ? String(err.message) : t('genericError'));
                setState('failure');
            });
        }

        modalEls = modal; // initialize tracker (filled on first openModal)

        return {
            openModal: openModal,
            closeModal: closeModal,
            getState: function () { return state; },
            destroy: function () {
                if (modal && modal.overlay && modal.overlay.parentNode) {
                    modal.overlay.parentNode.removeChild(modal.overlay);
                }
                rootEl.innerHTML = '';
            },
            // Test hook so the JS suite can assert internal state without
            // having to scrape the DOM. Not part of the public API.
            __internalForTests: function () {
                return { modal: modal, lastFocus: lastFocus };
            }
        };
    }

    /**
     * Bind a click handler on an existing anchor / clickable element
     * so that activating it opens the support-request modal in-place
     * (preventDefault) instead of navigating elsewhere. Used for the
     * `plugin_row_meta` link on wp-admin/plugins.php so the admin can
     * send a debug log without leaving the Plugins listing and without
     * depending on the plugin's Settings page rendering correctly.
     *
     * The link's own `href` is left untouched so it still acts as a
     * fallback when JavaScript fails to load on the host page.
     *
     * @param {HTMLElement} linkEl
     * @param {Object} opts
     * @param {string} opts.triggered_from
     * @param {string} [opts.context_summary]
     * @returns {Object} controller with .openModal(), .closeModal(), .destroy()
     */
    function attachLink(linkEl, opts) {
        opts = opts || {};
        if (!linkEl || !opts.triggered_from) {
            return { destroy: function () {}, openModal: function () {}, getState: function () { return 'idle'; } };
        }
        // mount() owns the modal lifecycle. Give it a detached host so
        // the button it renders is never visible. The visible trigger
        // is the linkEl supplied by the caller.
        var hiddenHost = document.createElement('span');
        hiddenHost.style.display = 'none';
        var controller = mount(hiddenHost, opts);
        var onClick = function (e) {
            e.preventDefault();
            controller.openModal();
        };
        linkEl.addEventListener('click', onClick);
        var baseDestroy = controller.destroy;
        controller.destroy = function () {
            linkEl.removeEventListener('click', onClick);
            baseDestroy();
        };
        return controller;
    }

    /**
     * Lazy-load preview from the abj404_support_request_preview AJAX
     * endpoint. Returns a Promise that resolves to {payload, ...} or
     * rejects on transport / nonce / 4xx errors.
     *
     * The nonce is read from window.ABJ404.nonces.support_request_preview
     * (populated by WordPress_Connector). When missing, the preview
     * call is skipped and the .catch path is used.
     *
     * @param {string} triggeredFrom
     * @param {string} userMessage
     * @returns {Promise<Object>}
     */
    function loadPreview(triggeredFrom, userMessage) {
        var ajaxurl = (typeof window.ajaxurl === 'string' && window.ajaxurl)
            ? window.ajaxurl
            : (window.ABJ404 && window.ABJ404.ajaxurl) ? String(window.ABJ404.ajaxurl) : '/wp-admin/admin-ajax.php';
        var nonce = (window.ABJ404 && window.ABJ404.nonces && window.ABJ404.nonces.support_request_preview)
            ? String(window.ABJ404.nonces.support_request_preview) : '';
        if (!nonce) {
            return Promise.reject(new Error('missing nonce'));
        }
        var formData = new FormData();
        formData.append('action', 'abj404_support_request_preview');
        formData.append('nonce', nonce);
        formData.append('triggered_from', triggeredFrom);
        formData.append('user_message', userMessage || '');
        return fetch(ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        }).then(function (response) {
            return response.json().then(function (json) {
                if (json && json.success === true && json.data) {
                    return json.data;
                }
                throw new Error('preview failed');
            });
        });
    }

    /**
     * Focus-trap helper. Keeps Tab / Shift-Tab inside the dialog.
     * @param {KeyboardEvent} e
     * @param {HTMLElement} dialog
     */
    function trapFocus(e, dialog) {
        var focusables = dialog.querySelectorAll(
            'button:not([disabled]), [href], input:not([disabled]), textarea:not([disabled]), select:not([disabled]), summary, [tabindex]:not([tabindex="-1"])'
        );
        if (!focusables.length) { return; }
        var first = focusables[0];
        var last = focusables[focusables.length - 1];
        var active = document.activeElement;
        if (e.shiftKey && active === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && active === last) {
            e.preventDefault();
            first.focus();
        }
    }

    /**
     * Auto-mount every .abj404-support-request-mount on the page using
     * its data-* attributes. Idempotent: a div that already has a
     * mounted button is skipped.
     *
     * After mounting, applies the URL-driven auto-open behavior: when
     * the request arrives at the plugin's Settings or degraded-admin
     * screen with `?abj404_support_open=1` (and optional
     * `abj404_support_trigger=<slug>`), the matching mount's modal is
     * opened immediately. This is how the Plugins-page row action and
     * other deep links land the user directly on the support modal.
     */
    function mountAll() {
        var mounts = document.querySelectorAll(SELECTOR);
        var firstMountedController = null;
        var triggerMatchController = null;
        var requestedTrigger = readAutoOpenTrigger();
        var shouldAutoOpen = autoOpenRequested();
        for (var i = 0; i < mounts.length; i++) {
            var node = mounts[i];
            if (node.getAttribute('data-abj404-srb-mounted') === '1') {
                continue;
            }
            var triggeredFrom = node.getAttribute('data-triggered-from') || '';
            var contextSummary = node.getAttribute('data-context-summary') || '';
            var controller = mount(node, { triggered_from: triggeredFrom, context_summary: contextSummary });
            node.setAttribute('data-abj404-srb-mounted', '1');
            if (!firstMountedController) {
                firstMountedController = controller;
            }
            if (requestedTrigger && triggeredFrom === requestedTrigger && !triggerMatchController) {
                triggerMatchController = controller;
            }
        }
        // Link-style triggers (e.g. the wp-admin/plugins.php row-meta
        // entry) open the modal in-place without leaving the host
        // page. Same idempotency contract as the mount divs above.
        var linkTriggers = document.querySelectorAll(LINK_SELECTOR);
        for (var j = 0; j < linkTriggers.length; j++) {
            var linkNode = linkTriggers[j];
            if (linkNode.getAttribute('data-abj404-srb-mounted') === '1') {
                continue;
            }
            var linkTriggeredFrom = linkNode.getAttribute('data-triggered-from') || '';
            var linkContextSummary = linkNode.getAttribute('data-context-summary') || '';
            var linkController = attachLink(linkNode, {
                triggered_from: linkTriggeredFrom,
                context_summary: linkContextSummary
            });
            linkNode.setAttribute('data-abj404-srb-mounted', '1');
            if (!firstMountedController) {
                firstMountedController = linkController;
            }
            if (requestedTrigger && linkTriggeredFrom === requestedTrigger && !triggerMatchController) {
                triggerMatchController = linkController;
            }
        }
        if (shouldAutoOpen) {
            var target = triggerMatchController || firstMountedController;
            if (target && typeof target.openModal === 'function') {
                target.openModal();
            }
        }
    }

    /**
     * Returns true when the current URL signals that a support modal
     * should auto-open on page load. Two signals:
     *   - query arg `abj404_support_open=1` (durable across refresh)
     *   - fragment `#abj404-support-request` (anchor target on the
     *     Settings page, so the section is in view AND the modal opens)
     */
    function autoOpenRequested() {
        try {
            var loc = window.location || {};
            var search = String(loc.search || '');
            if (search.indexOf('abj404_support_open=1') !== -1) {
                return true;
            }
            var hash = String(loc.hash || '');
            if (hash === '#abj404-support-request') {
                return true;
            }
        // allow-silent-catch: defensive guard for non-browser test harnesses where window.location is mocked or absent; auto-open is a UX nicety and must never throw on the boot path
        } catch (e) {
            return false;
        }
        return false;
    }

    /**
     * Optional trigger slug hint from the deep link. When present we
     * prefer the matching mount (`data-triggered-from`) over the first
     * one on the page, so a row-action click that says "I came from the
     * plugins page" opens the mount marked as plugins_row_action.
     */
    function readAutoOpenTrigger() {
        try {
            var loc = window.location || {};
            var search = String(loc.search || '');
            var match = search.match(/[?&]abj404_support_trigger=([^&#]+)/);
            if (match) {
                return decodeURIComponent(match[1]);
            }
        // allow-silent-catch: defensive guard for non-browser test harnesses where window.location is mocked or absent; trigger hint is optional and must never throw on the boot path
        } catch (e) {
            return '';
        }
        return '';
    }

    window.ABJ404 = window.ABJ404 || {};
    window.ABJ404.SupportRequestButton = {
        mount: mount,
        attachLink: attachLink,
        mountAll: mountAll,
        // Exposed for the JS unit test; not part of the public API.
        __loadPreview: loadPreview
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mountAll);
    } else {
        mountAll();
    }

})(window, document);
