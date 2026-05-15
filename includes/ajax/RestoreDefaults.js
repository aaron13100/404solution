/**
 * Restore Defaults Handler
 * Confirms with the admin, then calls the AJAX endpoint that overwrites
 * abj404_settings with PluginLogic::getDefaultOptions().
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        initRestoreDefaults();
    });

    function initRestoreDefaults() {
        var $button = $('#abj404-restore-defaults');
        if (!$button.length) {
            return;
        }
        var nonce = $button.data('nonce');
        var $modal = $('#abj404-restore-defaults-modal');
        var $confirm = $('#abj404-restore-defaults-confirm');
        var $cancel = $('#abj404-restore-defaults-cancel, #abj404-restore-defaults-cancel-2');

        $button.on('click', function(e) {
            e.preventDefault();
            $modal.addClass('active');
            $confirm.trigger('focus');
        });

        function closeModal() {
            $modal.removeClass('active');
        }

        $cancel.on('click', closeModal);
        $modal.on('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        $(document).on('keydown.abj404RestoreDefaults', function(e) {
            if (e.key === 'Escape' && $modal.hasClass('active')) {
                closeModal();
            }
        });

        $confirm.on('click', function(e) {
            e.preventDefault();
            $confirm.prop('disabled', true).addClass('loading');
            $cancel.prop('disabled', true);

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'abj404_restore_defaults',
                    nonce: nonce
                },
                success: function(response) {
                    if (response && typeof response === 'object' && response.success === true) {
                        window.location.reload();
                        return;
                    }
                    var serverMsg = '';
                    if (response && typeof response === 'object' && response.data) {
                        if (typeof response.data === 'string') {
                            serverMsg = response.data;
                        } else if (response.data.message) {
                            serverMsg = response.data.message;
                        }
                    }
                    alert(serverMsg || 'Failed to restore defaults');
                    $confirm.prop('disabled', false).removeClass('loading');
                    $cancel.prop('disabled', false);
                },
                error: function(jqXHR) {
                    var errMsg = 'An error occurred while restoring defaults';
                    try {
                        if (jqXHR && jqXHR.responseJSON) {
                            if (jqXHR.responseJSON.message) {
                                errMsg = jqXHR.responseJSON.message;
                            } else if (jqXHR.responseJSON.data) {
                                if (typeof jqXHR.responseJSON.data === 'string') {
                                    errMsg = jqXHR.responseJSON.data;
                                } else if (jqXHR.responseJSON.data.message) {
                                    errMsg = jqXHR.responseJSON.data.message;
                                }
                            }
                        }
                    // allow-silent-catch: response shape extraction is best-effort cosmetic; the alert() fallback below still surfaces a user-visible failure with a generic message and the original jqXHR is observable in the network panel
                    } catch (e2) {
                        // fall through to the generic alert
                    }
                    alert(errMsg);
                    $confirm.prop('disabled', false).removeClass('loading');
                    $cancel.prop('disabled', false);
                }
            });
        });
    }

})(jQuery);
