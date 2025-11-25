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
                    if (response.success) {
                        // Reload the page to show the new mode
                        window.location.reload();
                    } else {
                        alert(response.data.message || 'Failed to update settings mode');
                        $buttons.prop('disabled', false);
                        $btn.removeClass('loading');
                    }
                },
                error: function() {
                    alert('An error occurred while updating settings mode');
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
