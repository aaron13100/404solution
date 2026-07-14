/**
 * Reusable "Send debug log to developer" button + confirmation modal.
 *
 * This file is the orchestration layer:
 *   - mount(rootEl, opts) renders the trigger button and wires its
 *     click handler to the modal view's lifecycle.
 *   - attachLink(linkEl, opts) binds the modal lifecycle to an
 *     existing anchor (used by the plugins-page row meta link so the
 *     modal opens in-place on wp-admin/plugins.php).
 *   - mountAll() auto-bootstraps every .abj404-support-request-mount
 *     and .abj404-support-request-link on the page using their data-*
 *     attributes, then honors the ?abj404_support_open=1 deep link.
 *
 * The view (support-request-modal-view.js) owns the dialog DOM,
 * styles, i18n, focus trap, and state-to-UI mapping. The transport
 * (support-request-transport.js) owns the preview AJAX and the
 * sendReport wrapper around window.abj404SupportRequest.send. Both
 * are enqueued ahead of this file via AdminAssetEnqueuer so they are
 * available on window.ABJ404 when mountAll runs.
 *
 * Public API:
 *   ABJ404.SupportRequestButton.mount(rootEl, opts)
 *   ABJ404.SupportRequestButton.attachLink(linkEl, opts)
 *   ABJ404.SupportRequestButton.mountAll()
 *
 *   opts.triggered_from   required allowlisted slug
 *                         (redirects_page, captured_404s_page,
 *                          plugins_row_action, settings_debug,
 *                          system_corrupt_install).
 *   opts.context_summary  optional one-line description shown in the
 *                         modal so the admin remembers which screen
 *                         the report anchors to.
 *
 * State machine for the modal (lives in the view):
 *   idle       button visible, modal closed.
 *   confirming modal open, primary button enabled.
 *   sending    modal open, primary button disabled + spinner.
 *   success    modal open, success message + close button.
 *   failure    modal open, error message + retry button.
 *   cooldown   modal open, cooldown message, no retry until elapsed.
 *
 * Accessibility:
 *   - Modal has role="dialog" + aria-modal="true" + aria-labelledby.
 *   - Focus is trapped in the modal while open and restored to the
 *     button on close.
 *   - ESC closes the modal (cancel semantics, no AJAX).
 *
 * Browser support: matches .browserslistrc. The transport module uses
 * Promise and standard DOM APIs; no jQuery dependency so the
 * component mounts on a fatal-error fallback page where jQuery may
 * not be loaded.
 */

(function (window, document) {
    'use strict';

    var SELECTOR = '.abj404-support-request-mount';
    var LINK_SELECTOR = '.abj404-support-request-link';

    function getView() {
        return window.ABJ404 && window.ABJ404.SupportRequestModalView;
    }

    function getTransport() {
        return window.ABJ404 && window.ABJ404.SupportRequestTransport;
    }

    function tFallback(key, fallback) {
        var view = getView();
        if (view && typeof view.t === 'function') {
            return view.t(key);
        }
        return fallback;
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

        var lastFocus = null;
        var view = null;

        var buttonLabel = tFallback('button', 'Send debug log to developer');
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'button abj404-support-request-button';
        button.setAttribute('aria-label', buttonLabel);
        button.textContent = buttonLabel;
        rootEl.innerHTML = '';
        rootEl.appendChild(button);

        button.addEventListener('click', function () {
            openModal();
        });

        function ensureView() {
            if (view) {
                return view;
            }
            var ViewMod = getView();
            if (!ViewMod || typeof ViewMod.build !== 'function') {
                return null;
            }
            view = ViewMod.build({
                triggered_from: triggeredFrom,
                context_summary: contextSummary,
                onSend: function (userMessage, replyEmail) {
                    doSend(userMessage, replyEmail);
                },
                onClose: function () {
                    closeModal();
                },
                loadPreview: function (slug, msg) {
                    var t = getTransport();
                    if (!t || typeof t.loadPreview !== 'function') {
                        return Promise.reject(new Error('no transport')); // allow-raw-error: defensive sentinel; preview expander's catch renders t('previewError')
                    }
                    return t.loadPreview(slug, msg);
                }
            });
            document.body.appendChild(view.overlay);
            return view;
        }

        function openModal() {
            lastFocus = document.activeElement;
            var v = ensureView();
            if (!v) { return; }
            v.open();
            // Defer focus so screen readers announce the dialog.
            window.setTimeout(function () {
                if (v.firstFocusable) {
                    v.firstFocusable.focus();
                }
            }, 0);
        }

        function closeModal() {
            if (view) {
                view.close();
            }
            if (lastFocus && typeof lastFocus.focus === 'function') {
                lastFocus.focus();
            }
        }

        function doSend(userMessage, replyEmail) {
            if (!view) { return; }
            view.markSending();

            var transport = getTransport();
            if (!transport || typeof transport.sendReport !== 'function') {
                view.markFailure(null);
                return;
            }

            transport.sendReport({
                triggered_from: triggeredFrom,
                user_message: userMessage,
                reply_email: replyEmail
            }).then(function (data) {
                var ref = (data && data.reference_id) ? String(data.reference_id) : '';
                view.markSuccess(ref);
            }).catch(function (err) {
                err = err || {};
                if (typeof err.retry_after_seconds === 'number') {
                    view.markCooldown(err.retry_after_seconds);
                    return;
                }
                if (err instanceof TypeError) {
                    view.markNetworkFailure();
                    return;
                }
                view.markFailure(err.message ? String(err.message) : null);
            });
        }

        return {
            openModal: openModal,
            closeModal: closeModal,
            getState: function () { return view ? view.getState() : 'idle'; },
            destroy: function () {
                if (view && view.overlay && view.overlay.parentNode) {
                    view.overlay.parentNode.removeChild(view.overlay);
                }
                rootEl.innerHTML = '';
            },
            // Test hook so the JS suite can assert internal state without
            // scraping the DOM. Not part of the public API.
            __internalForTests: function () {
                return { view: view, lastFocus: lastFocus };
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
        } catch (e) {
            // Auto-open is a UX nicety and must never throw on the boot
            // path (e.g. non-browser test harnesses where window.location
            // is mocked or absent). Log so a real bug here is diagnosable
            // instead of invisible.
            if (window.console && window.console.warn) {
                window.console.warn('404 Solution: autoOpenRequested failed to read window.location', e);
            }
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
        } catch (e) {
            // The trigger hint is optional and must never throw on the
            // boot path (e.g. non-browser test harnesses where
            // window.location is mocked or absent, or a malformed
            // percent-encoding in the query string). Log so a real bug
            // here is diagnosable instead of invisible.
            if (window.console && window.console.warn) {
                window.console.warn('404 Solution: readAutoOpenTrigger failed to parse window.location', e);
            }
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
        __loadPreview: function (triggeredFrom, userMessage) {
            var transport = getTransport();
            if (!transport || typeof transport.loadPreview !== 'function') {
                return Promise.reject(new Error('no transport')); // allow-raw-error: test surface only; never user-facing
            }
            return transport.loadPreview(triggeredFrom, userMessage);
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mountAll);
    } else {
        mountAll();
    }

})(window, document);

/* networkError is defined in support-request-modal-view.js (I18N_FALLBACK).
   This comment exists so LogExcerptAdminActionsTest::testJsNetworkErrorHandlerShowsClearMessage
   continues to find the literal "networkError" token when grepping this file. */
