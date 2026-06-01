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

    function readConfig() {
        var el = document.getElementById('abj404-migrate-config');
        if (!el) { return null; }
        var raw = el.getAttribute('data-abj404-migrate');
        if (!raw) { return null; }
        try {
            return JSON.parse(raw);
        } catch (e) {
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
        cfg = readConfig();
        if (!cfg) { return; }

        var previewBtn = document.getElementById('abj404-migrate-preview-btn');
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
                // ajax-direct-approved: cross-plugin migration preview posts FormData and manages the two-step UI state locally.
                fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
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
