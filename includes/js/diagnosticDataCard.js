/**
 * Browser behavior for the Options-tab "Your Diagnostic Data" card.
 *
 * Owns the two user-facing one-shot actions on the card:
 *   - Download: POSTs to WordPress admin-ajax, then creates a local Blob
 *     download from the returned JSON. No server-generated URL is used.
 *   - Delete: opens the Restore-Defaults-style modal, POSTs confirm=1, and
 *     surfaces success/failure with inline native notices.
 */

(function (window, document, $) {
    'use strict';

    var SELECTOR = '.abj404-diagnostic-data-card';

    function ajaxUrl() {
        if (typeof window.ajaxurl === 'string' && window.ajaxurl) {
            return window.ajaxurl;
        }
        if (window.ABJ404 && window.ABJ404.ajaxurl) {
            return String(window.ABJ404.ajaxurl);
        }
        return '/wp-admin/admin-ajax.php';
    }

    function text(key) {
        var strings = {
            download: 'Download my data',
            preparing: 'Preparing...',
            deleteConfirm: 'Yes, delete permanently',
            deleting: 'Deleting...',
            tryAgain: 'Try again',
            downloaded: 'Downloaded {count} records to your browser.',
            deleted: 'Deleted {count} stored reports for this site.',
            unexpected: 'Unexpected diagnostics response. Try again shortly.',
            genericFailure: "Couldn't reach the diagnostics server. Try again shortly.",
            nothingStored: 'Nothing currently stored.'
        };
        return strings[key] || key;
    }

    function countRows(data) {
        if (!data || !Array.isArray(data.rows)) {
            return null;
        }
        return data.rows.length;
    }

    function deleteCount(data) {
        if (!data || typeof data.deleted !== 'number' || !isFinite(data.deleted)) {
            return null;
        }
        return data.deleted;
    }

    function clearNotice(container) {
        if (container) {
            container.innerHTML = '';
        }
    }

    function showNotice(container, type, message) {
        if (!container) {
            return;
        }
        container.innerHTML = '';
        var notice = document.createElement('div');
        notice.className = 'notice notice-' + type;
        notice.setAttribute('role', type === 'success' ? 'status' : 'alert');
        var paragraph = document.createElement('p');
        paragraph.textContent = message;
        notice.appendChild(paragraph);
        container.appendChild(notice);
    }

    function responseMessage(jqXHR) {
        var body = jqXHR && jqXHR.responseJSON ? jqXHR.responseJSON : null;
        if (body && body.data && typeof body.data.message === 'string') {
            return body.data.message;
        }
        if (body && typeof body.message === 'string') {
            return body.message;
        }
        return text('genericFailure');
    }

    function setButton(button, disabled, label) {
        if (!button) {
            return;
        }
        button.disabled = !!disabled;
        if (label) {
            button.textContent = label;
        }
    }

    function createDownload(data, downloadDate) {
        var filenameDate = downloadDate || 'today';
        var blob = new window.Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
        var url = window.URL.createObjectURL(blob);
        var anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = '404-solution-diagnostic-data-' + filenameDate + '.json';
        document.body.appendChild(anchor);
        anchor.click();
        if (anchor.parentNode) {
            anchor.parentNode.removeChild(anchor);
        }
        window.URL.revokeObjectURL(url);
    }

    function mount(card) {
        if (!card || card.getAttribute('data-abj404-diagnostic-mounted') === '1') {
            return;
        }
        card.setAttribute('data-abj404-diagnostic-mounted', '1');

        var downloadButton = card.querySelector('.abj404-diagnostic-data-download');
        var deleteButton = card.querySelector('.abj404-diagnostic-data-delete');
        var noticeContainer = card.querySelector('.abj404-diagnostic-data-notice');
        var modal = card.querySelector('.abj404-diagnostic-data-modal');
        var modalNotice = card.querySelector('.abj404-diagnostic-data-modal-notice');
        var cancelButtons = Array.prototype.slice.call(card.querySelectorAll('.abj404-diagnostic-data-cancel'));
        var confirmButton = card.querySelector('.abj404-diagnostic-data-confirm');
        var emptyNote = card.querySelector('.abj404-diagnostic-data-empty-note');
        var lastFocus = null;

        if (downloadButton) {
            downloadButton.addEventListener('click', function () {
                clearNotice(noticeContainer);
                setButton(downloadButton, true, text('preparing'));
                window.abj404AdminAjax({
                    url: ajaxUrl(),
                    type: 'POST',
                    data: {
                        action: 'abj404_privacy_export',
                        nonce: downloadButton.getAttribute('data-nonce') || card.getAttribute('data-export-nonce') || ''
                    },
                    success: function (response) {
                        var data = response && response.success === true ? response.data : null;
                        var rowCount = countRows(data);
                        if (rowCount === null) {
                            showNotice(noticeContainer, 'error', text('unexpected'));
                            return;
                        }
                        createDownload(data, card.getAttribute('data-download-date') || '');
                        showNotice(noticeContainer, 'success', text('downloaded').replace('{count}', String(rowCount)));
                    },
                    error: function (jqXHR) {
                        showNotice(noticeContainer, 'error', responseMessage(jqXHR));
                    },
                    complete: function () {
                        setButton(downloadButton, false, text('download'));
                    }
                });
            });
        }

        function openModal() {
            if (!modal) {
                return;
            }
            lastFocus = document.activeElement;
            clearNotice(modalNotice);
            modal.hidden = false;
            modal.classList.add('active');
            if (cancelButtons[0]) {
                cancelButtons[0].focus();
            }
        }

        function closeModal() {
            if (!modal) {
                return;
            }
            modal.hidden = true;
            modal.classList.remove('active');
            setModalLoading(false, text('deleteConfirm'));
            if (lastFocus && typeof lastFocus.focus === 'function') {
                lastFocus.focus();
            }
        }

        function setModalLoading(disabled, confirmLabel) {
            cancelButtons.forEach(function (button) {
                button.disabled = !!disabled;
            });
            if (confirmButton) {
                confirmButton.disabled = !!disabled;
                confirmButton.textContent = confirmLabel;
                if (disabled) {
                    confirmButton.classList.add('loading');
                } else {
                    confirmButton.classList.remove('loading');
                }
            }
        }

        function markDeleted(count) {
            closeModal();
            showNotice(noticeContainer, 'success', text('deleted').replace('{count}', String(count)));
            setButton(downloadButton, true, text('download'));
            setButton(deleteButton, true, 'Delete my data');
            if (emptyNote) {
                emptyNote.hidden = false;
                emptyNote.textContent = text('nothingStored');
            }
        }

        if (deleteButton) {
            deleteButton.addEventListener('click', function () {
                openModal();
            });
        }

        cancelButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                closeModal();
            });
        });

        if (modal) {
            modal.addEventListener('click', function (event) {
                if (event.target === modal) {
                    closeModal();
                }
            });
            // Scoped to the modal element itself (not document): the listener's
            // lifetime is tied to the modal node's own lifetime, so if the card's
            // DOM node is ever discarded and replaced (admin page re-render / AJAX
            // content swap), the old listener is discarded along with it instead
            // of leaking on document forever.
            modal.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && !modal.hidden) {
                    closeModal();
                }
            });
        }

        if (confirmButton) {
            confirmButton.addEventListener('click', function () {
                clearNotice(modalNotice);
                setModalLoading(true, text('deleting'));
                window.abj404AdminAjax({
                    url: ajaxUrl(),
                    type: 'POST',
                    data: {
                        action: 'abj404_privacy_delete',
                        nonce: deleteButton ? (deleteButton.getAttribute('data-nonce') || card.getAttribute('data-delete-nonce') || '') : '',
                        confirm: '1'
                    },
                    success: function (response) {
                        var data = response && response.success === true ? response.data : null;
                        var count = deleteCount(data);
                        if (count === null) {
                            showNotice(modalNotice, 'error', text('unexpected'));
                            setModalLoading(false, text('tryAgain'));
                            return;
                        }
                        markDeleted(count);
                    },
                    error: function (jqXHR) {
                        showNotice(modalNotice, 'error', responseMessage(jqXHR));
                        setModalLoading(false, text('tryAgain'));
                    }
                });
            });
        }
    }

    function mountAll() {
        Array.prototype.forEach.call(document.querySelectorAll(SELECTOR), mount);
    }

    window.ABJ404 = window.ABJ404 || {};
    window.ABJ404.DiagnosticDataCard = {
        mount: mount,
        mountAll: mountAll
    };

    if ($ && typeof $ === 'function') {
        $(mountAll);
    }

})(window, document, window.jQuery);
