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
    var I18N_FALLBACK = {
        button: 'Send debug log to developer',
        modalTitle: 'Send debug log to developer',
        explainer: 'This sends a one-time diagnostic report (URLs, PHP/WP/DB versions, debug log excerpt, active plugins, site URL) to the plugin developer.',
        showPayloadOpen: "Show what's in this report",
        showPayloadClose: 'Hide report contents',
        loadingPreview: 'Loading report contents...',
        previewError: 'Could not load preview. The full report is still safe to send.',
        userMessageLabel: 'What went wrong? (optional, helps us diagnose)',
        replyEmailLabel: 'Where should we reply? (optional)',
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
            var titleId = 'abj404-srb-title-' + Math.random().toString(36).slice(2, 8);
            var title = el('h2', { id: titleId, className: 'abj404-srb-title', text: t('modalTitle') });
            var explainer = el('p', { className: 'abj404-srb-explainer', text: t('explainer') });

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
            userMessageLabel.textContent = t('userMessageLabel');
            var userMessageInput = el('textarea', {
                className: 'abj404-srb-user-message',
                rows: '4',
                maxlength: '2000'
            });
            userMessageLabel.appendChild(userMessageInput);

            var replyEmailLabel = el('label', { className: 'abj404-srb-field-label' });
            replyEmailLabel.textContent = t('replyEmailLabel');
            var replyEmailInput = el('input', {
                type: 'email',
                className: 'abj404-srb-reply-email'
            });
            replyEmailLabel.appendChild(replyEmailInput);

            var formBlock = el('div', { className: 'abj404-srb-form' }, [
                userMessageLabel,
                replyEmailLabel
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
            // Inline minimal styles so the modal works on a
            // fatal-fallback page where the plugin's CSS may not be
            // loaded. Keep this small; rich styling lives in admin CSS.
            overlay.style.position = 'fixed';
            overlay.style.top = '0';
            overlay.style.left = '0';
            overlay.style.right = '0';
            overlay.style.bottom = '0';
            overlay.style.background = 'rgba(0,0,0,0.5)';
            overlay.style.zIndex = '160000';
            overlay.style.alignItems = 'center';
            overlay.style.justifyContent = 'center';
            dialog.style.background = '#fff';
            dialog.style.padding = '20px';
            dialog.style.maxWidth = '600px';
            dialog.style.maxHeight = '80vh';
            dialog.style.overflow = 'auto';
            dialog.style.borderRadius = '4px';

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
