/**
 * Simple/Advanced Mode Toggle Handler
 * Handles switching between settings modes via AJAX.
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        initModeToggle();
    });

    /**
     * Initialize the mode toggle buttons.
     */
    function initModeToggle() {
        var $toggleContainer = $('.abj404-mode-toggle');
        if (!$toggleContainer.length) {
            return;
        }

        var $buttons = $toggleContainer.find('.abj404-mode-btn');
        var nonce = $toggleContainer.data('nonce');

        // Handle "Switch to Advanced Mode" link in the hint
        $(document).on('click', '.abj404-switch-to-advanced', function(e) {
            e.preventDefault();
            // Trigger click on the Advanced Mode button
            var $advancedBtn = $toggleContainer.find('.abj404-mode-btn[data-mode="advanced"]');
            if ($advancedBtn.length && !$advancedBtn.hasClass('active')) {
                $advancedBtn.trigger('click');
            }
        });

        $buttons.on('click', function(e) {
            e.preventDefault();

            var $btn = $(this);
            var mode = $btn.data('mode');

            // Don't do anything if already active
            if ($btn.hasClass('active')) {
                return;
            }

            // Disable buttons during request
            $buttons.prop('disabled', true);
            $btn.addClass('loading');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'abj404_toggle_settings_mode',
                    mode: mode,
                    nonce: nonce
                },
                success: function(response) {
                    // Validate shape before reading fields: an upstream
                    // gateway / WAF / plugin conflict can return HTML or
                    // a non-object body even when the request asked for
                    // JSON. Treat any malformed body as a failed save.
                    if (response && typeof response === 'object' && response.success === true) {
                        // Reload the page to show the new mode
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
                    alert(serverMsg || 'Failed to update settings mode');
                    $buttons.prop('disabled', false);
                    $btn.removeClass('loading');
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    // Shared describe-and-record seam (abj404-admin-ajax.js), guarded
                    // so a missing asset degrades to today's generic message instead
                    // of throwing out of the error handler and showing nothing at all.
                    var errMsg = 'An error occurred while updating settings mode';
                    if (typeof abj404AdminAjaxErrorMessage === 'function') {
                        errMsg = abj404AdminAjaxErrorMessage(jqXHR, {
                            fallback: errMsg,
                            source: 'settings-mode-toggle',
                            errorThrown: errorThrown
                        });
                    }
                    alert(errMsg);
                    $buttons.prop('disabled', false);
                    $btn.removeClass('loading');
                }
            });
        });

        // Keyboard accessibility
        $buttons.on('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                $(this).trigger('click');
            }
        });
    }

})(jQuery);
