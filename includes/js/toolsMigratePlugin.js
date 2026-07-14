/**
 * Tools page: "Migrate from Another Plugin" two-step preview/confirm flow.
 *
 * Reads ajaxUrl, nonce, and translatable messages from a JSON blob on the
 * configuration carrier element's `data-abj404-migrate` attribute
 * (#abj404-migrate-config). Click "Preview Import" -> AJAX preview ->
 * show count + Confirm Import (or "Nothing to import" + Back).
 */
(function () {
    'use strict';

    // Migration preview is a one-shot admin action (reads the source
    // plugin's tables and counts rows to migrate). A generous bound avoids
    // false-positive aborts on a slow-but-working host while still
    // guaranteeing the spinner/disabled state cannot hang forever if
    // admin-ajax never responds (M501).
    var PREVIEW_TIMEOUT_MS = 30000;

    // Returns the translated config-unusable message when wp.i18n's locale
    // data is available, else the English fallback. The literal must be
    // passed directly to wp.i18n.__() (not through a variable) so make-pot
    // can statically extract it; mirrors the switch-dispatched literal
    // pattern in support-request-modal-view.js's t(key) for the same
    // situation: a user-facing string needed on a failure path where the
    // normal PHP-supplied, pre-translated config (cfg) is unavailable.
    function configUnusableMessage() {
        if (window.wp && window.wp.i18n && typeof window.wp.i18n.__ === 'function') {
            return window.wp.i18n.__('Could not load the migration tool configuration. Reload this page and try again.', '404-solution');
        }
        return 'Could not load the migration tool configuration. Reload this page and try again.';
    }

    function readConfig() {
        var el = document.getElementById('abj404-migrate-config');
        if (!el) { return null; }
        var raw = el.getAttribute('data-abj404-migrate');
        if (!raw) { return null; }
        try {
            return JSON.parse(raw);
        } catch (e) {
            // Malformed config JSON leaves ajaxUrl/nonce/messages entirely
            // unavailable, so Preview Import cannot function at all. Log for
            // diagnosis; the caller disables the control with a safe,
            // non-cfg-dependent message instead of wiring a click handler
            // that would silently do nothing.
            if (window.console && window.console.error) {
                window.console.error('404 Solution: abj404-migrate-config data-abj404-migrate attribute is not valid JSON', raw, e);
            }
            return null;
        }
    }

    var cfg = null;

    function showStep1() {
        document.getElementById('abj404-migrate-step1').style.display = '';
        document.getElementById('abj404-migrate-step2').style.display = 'none';
    }

    function showStep2(count, source, label) {
        document.getElementById('abj404-migrate-step1').style.display = 'none';
        document.getElementById('abj404-migrate-step2').style.display = '';
        var msgEl  = document.getElementById('abj404-migrate-preview-msg');
        var form   = document.getElementById('abj404-migrate-confirm-form');
        var noForm = document.getElementById('abj404-migrate-back-noform');
        if (count > 0) {
            msgEl.textContent = cfg.msgFound.replace('%d', count).replace('%s', label);
            document.getElementById('abj404-migrate-confirm-source').value = source;
            form.style.display = '';
            noForm.style.display = 'none';
        } else {
            msgEl.textContent = cfg.msgNone.replace('%s', label);
            form.style.display = 'none';
            noForm.style.display = '';
        }
    }

    function showError() {
        document.getElementById('abj404-migrate-step1').style.display = 'none';
        document.getElementById('abj404-migrate-step2').style.display = '';
        document.getElementById('abj404-migrate-preview-msg').textContent = cfg.msgError;
        document.getElementById('abj404-migrate-confirm-form').style.display = 'none';
        document.getElementById('abj404-migrate-back-noform').style.display = '';
    }

    document.addEventListener('DOMContentLoaded', function () {
        var previewBtn = document.getElementById('abj404-migrate-preview-btn');
        cfg = readConfig();
        if (!cfg) {
            // Config is unusable (missing feature, or the catch branch in
            // readConfig() already logged a parse failure): the preview
            // button has nowhere to send its AJAX request, so disable it
            // with an explanation instead of leaving a click handler
            // silently never wired.
            if (previewBtn) {
                previewBtn.disabled = true;
                previewBtn.title = configUnusableMessage();
            }
            return;
        }

        var backBtn    = document.getElementById('abj404-migrate-back-btn');
        var backBtn2   = document.getElementById('abj404-migrate-back-btn2');
        if (previewBtn) {
            previewBtn.addEventListener('click', function () {
                var select = document.getElementById('abj404-import-source');
                var source = select ? select.value : '';
                if (!source) { return; }
                var spinner = document.getElementById('abj404-migrate-preview-spinner');
                if (spinner) { spinner.style.display = ''; }
                previewBtn.disabled = true;
                var fd = new FormData();
                fd.append('action', 'abj404_crossPluginPreview');
                fd.append('nonce', cfg.nonce);
                fd.append('import_source', source);
                // A stalled admin-ajax response must not leave the button
                // disabled and the spinner visible forever (M501); abort and
                // let the existing generic .catch() below show msgError,
                // exactly as it already does for any other fetch failure.
                var controller = new AbortController();
                var timeoutId = setTimeout(function () { controller.abort(); }, PREVIEW_TIMEOUT_MS);
                // ajax-direct-approved: cross-plugin migration preview posts FormData and manages the two-step UI state locally.
                fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin', signal: controller.signal }) // allow-direct-network: cross-plugin migration preview; no project-wide adapter exists for this AJAX surface
                    .then(function (r) { clearTimeout(timeoutId); return r.json(); })
                    .then(function (resp) {
                        if (spinner) { spinner.style.display = 'none'; }
                        previewBtn.disabled = false;
                        if (resp && resp.success && resp.data) {
                            showStep2(parseInt(resp.data.count, 10) || 0, resp.data.source, resp.data.label);
                        } else {
                            showError();
                        }
                    })
                    .catch(function () {
                        clearTimeout(timeoutId);
                        if (spinner) { spinner.style.display = 'none'; }
                        previewBtn.disabled = false;
                        showError();
                    });
            });
        }
        if (backBtn)  { backBtn.addEventListener('click',  function () { showStep1(); }); }
        if (backBtn2) { backBtn2.addEventListener('click', function () { showStep1(); }); }
    });
})();
