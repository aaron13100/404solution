/**
 * 404 Solution - Deactivation Modal Handler
 * Intercepts plugin deactivation and displays modal for user preferences
 *
 * @since 2.36.11
 */
(function($) {
    'use strict';

    // Escape HTML entities to prevent XSS
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

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
            title: abj404UninstallModal.i18n.dialogTitle,
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
                    text: abj404UninstallModal.i18n.btnCancel,
                    class: 'button',
                    click: function() {
                        $(this).dialog('close');
                    }
                },
                {
                    text: abj404UninstallModal.i18n.btnSkipFeedback,
                    class: 'button button-link',
                    click: function() {
                        handleDeactivation(originalDeactivateUrl, false); // false = skip feedback
                    }
                },
                {
                    text: abj404UninstallModal.i18n.btnDeactivate,
                    class: 'button button-primary button-danger',
                    click: function() {
                        handleDeactivation(originalDeactivateUrl, true); // true = send feedback
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

        // Store the currently selected radio button for deselection functionality
        var currentlySelectedReason = null;

        // Show/hide conditional follow-up sections based on selected reason
        $('input[name="abj404-reason"]').on('click', function() {
            // Allow deselecting radio buttons by clicking again
            if (currentlySelectedReason === this) {
                // Clicking the same radio button again - deselect it
                $(this).prop('checked', false);
                currentlySelectedReason = null;
                // Hide all follow-up sections and clear their checkboxes
                $('.abj404-followup-section').slideUp(200, function() {
                    $(this).find('.abj404-issue-checkbox').prop('checked', false);
                });
            } else {
                // New selection
                currentlySelectedReason = this;
                var selectedValue = $(this).val();

                // Hide all follow-up sections first and clear their checkboxes
                $('.abj404-followup-section').slideUp(200, function() {
                    // Clear checkboxes in hidden sections to prevent stale values
                    $(this).find('.abj404-issue-checkbox').prop('checked', false);
                });

                // Show relevant follow-up section based on selection
                if (selectedValue === 'not-working') {
                    $('#abj404-followup-not-working').slideDown(200);
                    $('#abj404-followup-details').slideDown(200);
                } else if (selectedValue === 'performance') {
                    $('#abj404-followup-performance').slideDown(200);
                    $('#abj404-followup-details').slideDown(200);
                } else if (selectedValue === 'too-complicated') {
                    $('#abj404-followup-complicated').slideDown(200);
                    $('#abj404-followup-details').slideDown(200);
                } else if (selectedValue === 'found-better') {
                    $('#abj404-followup-better-plugin').slideDown(200);
                } else if (selectedValue === 'other') {
                    $('#abj404-followup-other').slideDown(200);
                }
            }
        });

        /**
         * Handle deactivation: Save preferences via AJAX, then redirect to deactivate URL
         *
         * @param {string} deactivateUrl Original WordPress deactivate URL
         * @param {boolean} sendFeedback Whether to send feedback email
         */
        function handleDeactivation(deactivateUrl, sendFeedback) {
            // Collect selected issue checkboxes
            var selectedIssues = [];
            $('.abj404-issue-checkbox:checked').each(function() {
                selectedIssues.push($(this).val());
            });

            // Gather user preferences
            // Explicitly convert booleans to 'true'/'false' strings for PHP compatibility
            var preferences = {
                action: 'abj404_save_uninstall_prefs',
                nonce: abj404UninstallModal.nonce,
                delete_redirects: !$('#abj404-keep-redirects').is(':checked') ? 'true' : 'false',
                delete_logs: !$('#abj404-keep-logs').is(':checked') ? 'true' : 'false',
                delete_cache: 'true', // Always delete cache
                send_feedback: sendFeedback ? 'true' : 'false',
                uninstall_reason: $('input[name="abj404-reason"]:checked').val() || '',
                selected_issues: selectedIssues.join(','),
                followup_details: $('#abj404-followup-details-text').val(),
                better_plugin_name: $('#abj404-better-plugin-name').val(),
                other_reason_text: $('#abj404-other-reason-text').val(),
                feedback_email: $('#abj404-feedback-email').val(),
                include_diagnostics: $('#abj404-include-diagnostics').is(':checked') ? 'true' : 'false'
            };

            // Disable buttons during save
            var $buttons = $('.ui-dialog-buttonpane button');
            $buttons.prop('disabled', true);
            $buttons.filter('.button-danger').text(abj404UninstallModal.i18n.btnSaving);

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

                        // Only show success notice if there's a message (when feedback was sent)
                        if (response.data.message) {
                            $modal.find('.abj404-uninstall-content').prepend(
                                '<div class="notice notice-success" style="margin-bottom:15px;"><p><strong>' +
                                escapeHtml(response.data.message) +
                                '</strong></p></div>'
                            );
                        }
                    } else {
                        // Save failed but continue anyway
                        console.warn('404 Solution: ✗ FAILED - ' + (response.data.message || 'Unknown error'));

                        // Update modal text with error
                        $modal.find('.abj404-uninstall-content').prepend(
                            '<div class="notice notice-warning" style="margin-bottom:15px;"><p><strong>' +
                            escapeHtml(response.data.message || 'Failed to save preferences') +
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
                    // Update button text and redirect immediately
                    $buttons.filter('.button-danger').text(abj404UninstallModal.i18n.btnDeactivating);
                    console.log('404 Solution: Redirecting to deactivation...');
                    window.location.href = deactivateUrl;
                });
        }

        /**
         * Reset form to default values
         */
        function resetForm() {
            // Reset checkboxes to defaults
            $('#abj404-keep-redirects').prop('checked', true);
            $('#abj404-keep-logs').prop('checked', true);
            $('#abj404-include-diagnostics').prop('checked', true);

            // Reset radio buttons
            $('input[name="abj404-reason"]').prop('checked', false);
            currentlySelectedReason = null; // Reset stored selection

            // Reset issue checkboxes
            $('.abj404-issue-checkbox').prop('checked', false);

            // Reset all text inputs and textareas
            $('#abj404-feedback-email').val('');
            $('#abj404-followup-details-text').val('');
            $('#abj404-better-plugin-name').val('');
            $('#abj404-other-reason-text').val('');

            // Hide all follow-up sections
            $('.abj404-followup-section').hide();

            // Reset button states
            $('.ui-dialog-buttonpane button').prop('disabled', false);

            // Reset opacity
            $modal.find('.abj404-uninstall-content').css('opacity', '1');

            // Remove any success/error notices
            $modal.find('.notice').remove();
        }
    });

})(jQuery);
