/**
 * 404 Solution - Deactivation Modal Handler
 * Intercepts plugin deactivation and displays modal for user preferences
 *
 * @since 2.36.11
 */
(function($) {
    'use strict';

    // Wait for DOM ready
    $(document).ready(function() {

        // Get plugin slug from localized data
        var pluginSlug = abj404UninstallModal.pluginSlug;

        // Find the delete link for our plugin
        // WordPress uses data-slug attribute on plugin rows
        var $pluginRow = $('[data-slug="' + pluginSlug + '"]');

        if (!$pluginRow.length) {
            // Try alternative: find by plugin path in delete link
            $pluginRow = $('tr[data-plugin*="404-solution"]').first();
        }

        if (!$pluginRow.length) {
            return; // Plugin row not found
        }

        // Find the deactivate link within the plugin row
        var $deactivateLink = $pluginRow.find('.deactivate a');

        if (!$deactivateLink.length) {
            return; // Deactivate link not found (plugin might already be inactive)
        }

        // Store original deactivate URL
        var originalDeactivateUrl = $deactivateLink.attr('href');

        // Initialize jQuery UI Dialog modal
        var $modal = $('#abj404-uninstall-modal');

        $modal.dialog({
            title: '404 Solution - Deactivation Options',
            dialogClass: 'wp-dialog abj404-uninstall-dialog',
            autoOpen: false,
            draggable: false,
            width: 600,
            modal: true,
            resizable: false,
            closeOnEscape: true,
            position: {
                my: "center",
                at: "center",
                of: window
            },
            buttons: [
                {
                    text: 'Cancel',
                    class: 'button',
                    click: function() {
                        $(this).dialog('close');
                    }
                },
                {
                    text: 'Deactivate Plugin',
                    class: 'button button-primary button-danger',
                    click: function() {
                        handleDeactivation(originalDeactivateUrl);
                    }
                }
            ],
            open: function() {
                // Allow clicking overlay to close
                $('.ui-widget-overlay').on('click', function() {
                    $modal.dialog('close');
                });

                // Add close button styling
                $('.ui-dialog-titlebar-close').addClass('ui-button');
            },
            close: function() {
                // Reset form when closed
                resetForm();
            }
        });

        // Intercept deactivate link click
        $deactivateLink.on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $modal.dialog('open');
        });

        // Show/hide feedback fields when checkbox is toggled
        $('#abj404-send-feedback').on('change', function() {
            $('#abj404-feedback-fields').slideToggle(200);
        });

        /**
         * Handle deactivation: Save preferences via AJAX, then redirect to deactivate URL
         *
         * @param {string} deactivateUrl Original WordPress deactivate URL
         */
        function handleDeactivation(deactivateUrl) {
            // Gather user preferences
            // Explicitly convert booleans to 'true'/'false' strings for PHP compatibility
            var preferences = {
                action: 'abj404_save_uninstall_prefs',
                nonce: abj404UninstallModal.nonce,
                delete_redirects: !$('#abj404-keep-redirects').is(':checked') ? 'true' : 'false',
                delete_logs: !$('#abj404-keep-logs').is(':checked') ? 'true' : 'false',
                delete_cache: 'true', // Always delete cache
                send_feedback: $('#abj404-send-feedback').is(':checked') ? 'true' : 'false',
                uninstall_reason: $('input[name="abj404-reason"]:checked').val() || '',
                feedback_email: $('#abj404-feedback-email').val(),
                feedback_details: $('#abj404-feedback-details').val()
            };

            // Disable buttons during save
            var $buttons = $('.ui-dialog-buttonpane button');
            $buttons.prop('disabled', true);
            $buttons.filter('.button-danger').text('Saving...');

            // Show loading indicator
            $modal.find('.abj404-uninstall-content').css('opacity', '0.6');

            // Save preferences via AJAX
            console.log('404 Solution: Sending AJAX request with preferences:', preferences);

            $.post(ajaxurl, preferences)
                .done(function(response) {
                    console.log('404 Solution: AJAX Response received:', response);

                    if (response.success) {
                        // Preferences saved successfully
                        console.log('404 Solution: ✓ SUCCESS - ' + response.data.message);

                        // Update modal text with result
                        $modal.find('.abj404-uninstall-content').prepend(
                            '<div class="notice notice-success" style="margin-bottom:15px;"><p><strong>' +
                            response.data.message +
                            '</strong></p></div>'
                        );
                    } else {
                        // Save failed but continue anyway
                        console.warn('404 Solution: ✗ FAILED - ' + (response.data.message || 'Unknown error'));

                        // Update modal text with error
                        $modal.find('.abj404-uninstall-content').prepend(
                            '<div class="notice notice-warning" style="margin-bottom:15px;"><p><strong>' +
                            (response.data.message || 'Failed to save preferences') +
                            '</strong></p></div>'
                        );
                    }
                })
                .fail(function(jqXHR, textStatus, errorThrown) {
                    // AJAX failed but continue anyway
                    console.error('404 Solution: AJAX error:', textStatus, errorThrown);
                    console.error('404 Solution: Response:', jqXHR.responseText);
                })
                .always(function() {
                    // Brief delay to show feedback, then redirect to deactivate URL
                    console.log('404 Solution: Redirecting to deactivation in 3 seconds...');

                    // Re-enable buttons and update text
                    $buttons.prop('disabled', false);
                    $buttons.filter('.button-danger').text('Deactivating in 3...');

                    var countdown = 3;
                    var countdownInterval = setInterval(function() {
                        countdown--;
                        if (countdown > 0) {
                            $buttons.filter('.button-danger').text('Deactivating in ' + countdown + '...');
                        } else {
                            clearInterval(countdownInterval);
                            window.location.href = deactivateUrl;
                        }
                    }, 1000);
                });
        }

        /**
         * Reset form to default values
         */
        function resetForm() {
            // Reset checkboxes to defaults
            $('#abj404-keep-redirects').prop('checked', true);
            $('#abj404-keep-logs').prop('checked', true);
            $('#abj404-send-feedback').prop('checked', false);

            // Reset radio buttons
            $('input[name="abj404-reason"]').prop('checked', false);

            // Reset text inputs
            $('#abj404-feedback-email').val('');
            $('#abj404-feedback-details').val('');

            // Hide feedback fields
            $('#abj404-feedback-fields').hide();

            // Reset button states
            $('.ui-dialog-buttonpane button').prop('disabled', false);
            $('.ui-dialog-buttonpane .button-danger').text('Deactivate Plugin');

            // Reset opacity
            $modal.find('.abj404-uninstall-content').css('opacity', '1');
        }
    });

})(jQuery);
