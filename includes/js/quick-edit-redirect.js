/**
 * 404 Solution - Quick Edit Redirect Checkbox
 *
 * Shows the "Create redirect from old URL to new URL" checkbox in Quick Edit
 * ONLY when the user modifies the slug field.
 * Positions the checkbox inline with the slug field.
 */
(function($) {
    'use strict';

    // Wait for inlineEditPost to be available
    $(document).ready(function() {
        if (typeof inlineEditPost === 'undefined') {
            return;
        }

        // Store the original edit function
        var originalEdit = inlineEditPost.edit;

        // Override the edit function to set up slug monitoring
        inlineEditPost.edit = function(id) {
            // Call the original function first
            originalEdit.apply(this, arguments);

            // Get the post ID
            var postId = 0;
            if (typeof id === 'object') {
                postId = parseInt(this.getId(id));
            } else {
                postId = parseInt(id);
            }

            if (postId <= 0) {
                return;
            }

            // Find the Quick Edit row
            var $editRow = $('#edit-' + postId);
            var $slugInput = $editRow.find('input[name="post_name"]');
            var $checkboxContainer = $editRow.find('.abj404-quick-edit');
            var $checkbox = $editRow.find('input[name="abj404_create_redirect"]');

            if ($slugInput.length === 0 || $checkboxContainer.length === 0) {
                return;
            }

            // Move the checkbox container to appear right after the slug field
            var $slugWrapper = $slugInput.closest('.inline-edit-col > div, .inline-edit-col > label');
            if ($slugWrapper.length) {
                // Create an inline wrapper for the checkbox
                var $inlineCheckbox = $checkboxContainer.find('.inline-edit-group').first();
                if ($inlineCheckbox.length) {
                    // Style for inline display after slug
                    $inlineCheckbox.css({
                        'display': 'inline-flex',
                        'align-items': 'center',
                        'margin-left': '10px',
                        'font-size': '12px'
                    });
                    // Move after slug input
                    $inlineCheckbox.insertAfter($slugInput);
                    // Hide the original container
                    $checkboxContainer.hide();
                    // Use the inline checkbox as the container
                    $checkboxContainer = $inlineCheckbox;
                }
            }

            // Get the default value and original slug
            var $postRow = $('#post-' + postId);
            var $defaultSpan = $postRow.find('.abj404-redirect-default');
            var defaultValue = $defaultSpan.data('default');
            var originalSlug = $slugInput.val();

            // Initially hide the checkbox
            $checkboxContainer.hide();

            // Set checkbox to default value
            $checkbox.prop('checked', defaultValue === '1' || defaultValue === 1);

            // Monitor slug changes - show checkbox only when slug is modified
            $slugInput.off('input.abj404').on('input.abj404', function() {
                var currentSlug = $(this).val();
                if (currentSlug !== originalSlug && currentSlug !== '') {
                    $checkboxContainer.fadeIn(150);
                } else {
                    $checkboxContainer.fadeOut(150);
                }
            });
        };
    });
})(jQuery);
