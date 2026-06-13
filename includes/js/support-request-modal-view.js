/**
 * Modal dialog view for the support-request component.
 *
 * Presentation layer: this module owns every DOM-shape and CSS
 * concern for the support-request modal:
 *
 *   - The polished STYLE_BLOCK, injected once per page via
 *     ensureStyles() so the modal is visually correct even on a
 *     fatal-fallback admin page where the plugin's stylesheet may
 *     not be loaded.
 *   - The English-fallback i18n table (I18N_FALLBACK + t()) so the
 *     modal renders readable strings before window.wp.i18n is
 *     available (test harness, degraded boot path).
 *   - The DOM construction (el(), build()). The trigger button is
 *     NOT built here; only the dialog itself.
 *   - The state to DOM mapping (markIdle / markConfirming /
 *     markSending / markSuccess / markCooldown / markNetworkFailure
 *     / markFailure). The controller calls these as it observes
 *     transport callbacks resolving or rejecting; the view picks
 *     the matching strings and visibility flips.
 *   - The Tab-focus trap inside the open dialog and the ESC binding
 *     that fires the caller-supplied onClose.
 *
 * Public API (window.ABJ404.SupportRequestModalView):
 *   build(opts)        returns a handles object (see "build()" below)
 *   t(key)             returns a translated string (exposed so the
 *                      trigger button's aria-label can reuse the
 *                      table)
 *
 * build() expects opts:
 *   triggered_from   string slug, embedded in payload
 *   context_summary  optional one-line context line shown in the dialog
 *   onSend(userMessage, replyEmail)  fired when the Send button is clicked
 *   onClose()                        fired when the dialog is closed
 *                                    (ESC, overlay click, Cancel/Close)
 *   loadPreview()                    Promise returning the preview payload
 *                                    (deferred so the view does not bind
 *                                    to a specific transport)
 *
 * build() returns:
 *   overlay, dialog, firstFocusable, userMessageInput, replyEmailInput
 *     raw DOM handles for the controller (focus, value reads)
 *   open()  / close()              show / hide the overlay
 *   markIdle() / markConfirming() / markSending() / markSuccess(refId)
 *   markCooldown(seconds) / markNetworkFailure() / markFailure(message?)
 *     state-driven UI flips; controller calls these as transport
 *     callbacks land. message is optional; null/undefined falls
 *     through to t('genericError').
 *   getState()                     returns the current state slug, for tests
 */

(function (window, document) {
    'use strict';

    var STYLE_TAG_ID = 'abj404-srb-styles';

    var STYLE_BLOCK = [
        '.abj404-srb-overlay { box-sizing: border-box; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", Arial, sans-serif; padding: 24px; }',
        '.abj404-srb-overlay * { box-sizing: border-box; }',
        '.abj404-srb-dialog { width: 100%; max-width: 560px; max-height: calc(100vh - 48px); overflow-y: auto; border-radius: 8px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.28), 0 0 0 1px rgba(0, 0, 0, 0.04); padding: 26px 30px 22px; color: #1f2937; }',
        '.abj404-srb-title { margin: 0 0 8px; font-size: 18px; font-weight: 600; line-height: 1.3; color: #111827; }',
        '.abj404-srb-explainer { margin: 0 0 16px; color: #4b5563; line-height: 1.55; font-size: 14px; }',
        '.abj404-srb-context-summary { margin: 0 0 16px; padding: 10px 12px; background: #f3f4f6; border-left: 3px solid #2271b1; border-radius: 3px; font-size: 13px; color: #374151; }',
        '.abj404-srb-categories-heading { margin: 0 0 6px; font-size: 13px; font-weight: 600; color: #1f2937; }',
        '.abj404-srb-categories { margin: 0 0 18px; padding-left: 22px; font-size: 13px; color: #4b5563; line-height: 1.55; }',
        '.abj404-srb-categories li { margin: 0 0 2px; list-style: disc; }',
        '.abj404-srb-payload-details { margin: 0 0 18px; border: 1px solid #e5e7eb; border-radius: 6px; overflow: hidden; background: #fff; }',
        '.abj404-srb-payload-details > summary { cursor: pointer; padding: 10px 14px; background: #f9fafb; font-weight: 500; color: #2271b1; user-select: none; outline-offset: -2px; }',
        '.abj404-srb-payload-details[open] > summary { border-bottom: 1px solid #e5e7eb; }',
        '.abj404-srb-payload-preview { margin: 0; padding: 12px 14px; background: #fff; color: #111827; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 12px; line-height: 1.5; max-height: 220px; overflow: auto; white-space: pre-wrap; word-break: break-word; }',
        '.abj404-srb-form { display: block; }',
        '.abj404-srb-field-label { display: block; margin: 0 0 14px; font-weight: 500; color: #1f2937; font-size: 13px; }',
        '.abj404-srb-field-label-text { display: block; margin-bottom: 5px; }',
        '.abj404-srb-user-message, .abj404-srb-reply-email { display: block; width: 100%; border: 1px solid #d1d5db; border-radius: 4px; padding: 8px 10px; font-size: 14px; line-height: 1.45; font-family: inherit; color: #111827; background: #fff; transition: border-color 0.15s ease, box-shadow 0.15s ease; }',
        '.abj404-srb-user-message:focus, .abj404-srb-reply-email:focus { border-color: #2271b1; outline: none; box-shadow: 0 0 0 2px rgba(34, 113, 177, 0.2); }',
        '.abj404-srb-user-message { resize: vertical; min-height: 96px; font-family: inherit; }',
        '.abj404-srb-consent { margin: 4px 0 16px; font-size: 12px; color: #4b5563; line-height: 1.5; }',
        '.abj404-srb-success, .abj404-srb-error { margin: 12px 0 4px; padding: 10px 14px; border-radius: 4px; font-size: 13px; line-height: 1.45; }',
        '.abj404-srb-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }',
        '.abj404-srb-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }',
        '.abj404-srb-buttons { display: flex; gap: 10px; justify-content: flex-end; align-items: center; margin-top: 22px; flex-wrap: wrap; }',
        '.abj404-srb-buttons .abj404-srb-send { min-width: 110px; }',
        '@media (max-width: 540px) { .abj404-srb-overlay { padding: 12px; } .abj404-srb-dialog { padding: 20px 18px 16px; max-height: calc(100vh - 24px); } .abj404-srb-buttons { flex-direction: column-reverse; align-items: stretch; } .abj404-srb-buttons .abj404-srb-send, .abj404-srb-buttons .abj404-srb-cancel { width: 100%; min-width: 0; } }'
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
        networkError: 'Network error: could not reach the server. Check your internet connection and try again.',
        genericError: 'Could not send report. Please try again later.'
    };

    function t(key) {
        if (window.wp && window.wp.i18n && typeof window.wp.i18n.__ === 'function') {
            switch (key) {
                case 'button':
                    return window.wp.i18n.__('Send debug log to developer', '404-solution');
                case 'modalTitle':
                    return window.wp.i18n.__('Send debug log to developer', '404-solution');
                case 'explainer':
                    return window.wp.i18n.__('This sends a one-time diagnostic report to the plugin developer so they can investigate the issue you are seeing.', '404-solution');
                case 'categoriesHeading':
                    return window.wp.i18n.__('This report includes:', '404-solution');
                case 'categorySiteUrl':
                    return window.wp.i18n.__('Site URL', '404-solution');
                case 'categoryVersions':
                    return window.wp.i18n.__('Plugin, PHP, WordPress, and database versions', '404-solution');
                case 'categoryActivePlugins':
                    return window.wp.i18n.__('List of active plugins', '404-solution');
                case 'categoryDebugLog':
                    return window.wp.i18n.__('Recent debug log excerpt', '404-solution');
                case 'categoryUserMessage':
                    return window.wp.i18n.__('Optional message and reply email you provide below', '404-solution');
                case 'showPayloadOpen':
                    return window.wp.i18n.__("Show what's in this report", '404-solution');
                case 'showPayloadClose':
                    return window.wp.i18n.__('Hide report contents', '404-solution');
                case 'loadingPreview':
                    return window.wp.i18n.__('Loading report contents...', '404-solution');
                case 'previewError':
                    return window.wp.i18n.__('Could not load preview. The full report is still safe to send.', '404-solution');
                case 'userMessageLabel':
                    return window.wp.i18n.__('What went wrong? (optional, helps us diagnose)', '404-solution');
                case 'replyEmailLabel':
                    return window.wp.i18n.__('Where should we reply? (optional)', '404-solution');
                case 'consent':
                    return window.wp.i18n.__('By clicking Send report, you consent to transmitting the information above to the plugin developer for support purposes. The data is used only to diagnose your issue and is not shared with third parties.', '404-solution');
                case 'send':
                    return window.wp.i18n.__('Send report', '404-solution');
                case 'cancel':
                    return window.wp.i18n.__('Cancel', '404-solution');
                case 'sending':
                    return window.wp.i18n.__('Sending...', '404-solution');
                case 'retry':
                    return window.wp.i18n.__('Retry', '404-solution');
                case 'close':
                    return window.wp.i18n.__('Close', '404-solution');
                case 'successPrefix':
                    return window.wp.i18n.__('Sent. Reference: ', '404-solution');
                case 'successSuffix':
                    return window.wp.i18n.__('. Thank you.', '404-solution');
                case 'cooldownTemplate':
                    return window.wp.i18n.__('You already sent a report recently. Try again in {minutes} minute(s).', '404-solution');
                case 'networkError':
                    return window.wp.i18n.__('Network error: could not reach the server. Check your internet connection and try again.', '404-solution');
                case 'genericError':
                    return window.wp.i18n.__('Could not send report. Please try again later.', '404-solution');
                default:
                    return I18N_FALLBACK[key];
            }
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

    function build(opts) {
        opts = opts || {};
        var triggeredFrom = String(opts.triggered_from || '');
        var contextSummary = opts.context_summary ? String(opts.context_summary) : '';
        var onSend = (typeof opts.onSend === 'function') ? opts.onSend : function () {};
        var onClose = (typeof opts.onClose === 'function') ? opts.onClose : function () {};
        var loadPreview = (typeof opts.loadPreview === 'function')
            ? opts.loadPreview
            : function () { return Promise.reject(new Error('no transport')); }; // allow-raw-error: caller-must-inject sentinel; preview expander's catch renders t('previewError')

        ensureStyles();

        var state = 'idle';
        var titleId = 'abj404-srb-title-' + Math.random().toString(36).slice(2, 8); // allow-direct-random: cosmetic id collision-avoidance, not security; same pattern as the original IIFE before the split
        var title = el('h2', { id: titleId, className: 'abj404-srb-title', text: t('modalTitle') });
        var explainer = el('p', { className: 'abj404-srb-explainer', text: t('explainer') });

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
                // Reset on close so the user can retry a failed preview by
                // closing + re-opening. Resetting inside the catch would
                // race with auto-toggle and overwrite the error message
                // with "Loading...".
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
        // Inline only the layout-mode / positioning props so the overlay
        // floats correctly even before the injected <style> tag has parsed.
        // Visual polish lives in STYLE_BLOCK above.
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

        sendButton.addEventListener('click', function () {
            onSend(userMessageInput.value, replyEmailInput.value);
        });
        cancelButton.addEventListener('click', function () {
            onClose();
        });
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) {
                onClose();
            }
        });
        dialog.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                onClose();
                return;
            }
            if (e.key === 'Tab') {
                trapFocus(e, dialog);
            }
        });

        function applyState(next) {
            state = next;
            successBlock.style.display = (state === 'success') ? 'block' : 'none';
            errorBlock.style.display = (state === 'failure' || state === 'cooldown') ? 'block' : 'none';
            formBlock.style.display = (state === 'confirming' || state === 'sending') ? 'block' : 'none';
            sendButton.disabled = (state === 'sending' || state === 'cooldown' || state === 'success');
            if (state === 'sending') {
                sendButton.textContent = t('sending');
            } else if (state === 'failure') {
                sendButton.textContent = t('retry');
            } else {
                sendButton.textContent = t('send');
            }
            if (state === 'success' || state === 'cooldown') {
                cancelButton.textContent = t('close');
            } else {
                cancelButton.textContent = t('cancel');
            }
        }

        return {
            overlay: overlay,
            dialog: dialog,
            firstFocusable: userMessageInput,
            userMessageInput: userMessageInput,
            replyEmailInput: replyEmailInput,
            open: function () { overlay.style.display = 'flex'; applyState('confirming'); },
            close: function () { overlay.style.display = 'none'; applyState('idle'); },
            markIdle: function () { applyState('idle'); },
            markConfirming: function () { applyState('confirming'); },
            markSending: function () {
                errorBlock.textContent = '';
                successBlock.textContent = '';
                applyState('sending');
            },
            markSuccess: function (refId) {
                successBlock.textContent = t('successPrefix') + String(refId || '') + t('successSuffix');
                applyState('success');
            },
            markCooldown: function (retryAfterSeconds) {
                var minutes = Math.max(1, Math.ceil(Number(retryAfterSeconds) / 60));
                errorBlock.textContent = t('cooldownTemplate').replace('{minutes}', String(minutes));
                applyState('cooldown');
            },
            markNetworkFailure: function () {
                errorBlock.textContent = t('networkError');
                applyState('failure');
            },
            markFailure: function (message) {
                errorBlock.textContent = message ? String(message) : t('genericError');
                applyState('failure');
            },
            getState: function () { return state; }
        };
    }

    window.ABJ404 = window.ABJ404 || {};
    window.ABJ404.SupportRequestModalView = {
        build: build,
        t: t
    };

})(window, document);
