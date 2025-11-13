/**
 * 404 Solution - Uninstall Modal Handler
 * Intercepts plugin deletion and displays modal for user preferences
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

        // Find the delete link within the plugin row
        var $deleteLink = $pluginRow.find('.delete a');

        if (!$deleteLink.length) {
            return; // Delete link not found (plugin might be active)
        }

        // Store original delete URL
        var originalDeleteUrl = $deleteLink.attr('href');

        // Initialize jQuery UI Dialog modal
        var $modal = $('#abj404-uninstall-modal');

        $modal.dialog({
            title: '404 Solution - Uninstall Options',
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
                    text: 'Uninstall Plugin',
                    class: 'button button-primary button-danger',
                    click: function() {
                        handleUninstall(originalDeleteUrl);
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

        // Intercept delete link click
        $deleteLink.on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $modal.dialog('open');
        });

        // Show/hide feedback fields when checkbox is toggled
        $('#abj404-send-feedback').on('change', function() {
            $('#abj404-feedback-fields').slideToggle(200);
        });

        /**
         * Handle uninstall: Save preferences via AJAX, then redirect to delete URL
         *
         * @param {string} deleteUrl Original WordPress delete URL
         */
        function handleUninstall(deleteUrl) {
            // Gather user preferences
            var preferences = {
                action: 'abj404_save_uninstall_prefs',
                nonce: abj404UninstallModal.nonce,
                delete_redirects: !$('#abj404-keep-redirects').is(':checked'),
                delete_logs: !$('#abj404-keep-logs').is(':checked'),
                delete_cache: true, // Always delete cache
                send_feedback: $('#abj404-send-feedback').is(':checked'),
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
            $.post(ajaxurl, preferences)
                .done(function(response) {
                    if (response.success) {
                        // Preferences saved successfully
                        console.log('404 Solution: Uninstall preferences saved');
                    } else {
                        // Save failed but continue anyway
                        console.warn('404 Solution: Failed to save preferences, continuing with defaults');
                    }
                })
                .fail(function(jqXHR, textStatus, errorThrown) {
                    // AJAX failed but continue anyway
                    console.error('404 Solution: AJAX error, continuing with defaults');
                })
                .always(function() {
                    // Always redirect to delete URL (even if save failed)
                    // The uninstall.php will use defaults if preferences weren't saved
                    window.location.href = deleteUrl;
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
            $('.ui-dialog-buttonpane .button-danger').text('Uninstall Plugin');

            // Reset opacity
            $modal.find('.abj404-uninstall-content').css('opacity', '1');
        }
    });

})(jQuery);
