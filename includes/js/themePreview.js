/**
 * 404 Solution Theme Preview
 *
 * Provides live preview of theme changes on the Options page.
 * When the user changes the theme dropdown, the theme is applied immediately
 * to the current page for preview. The theme is not persisted until the user
 * clicks the "Save Settings" button.
 */

(function($) {
    'use strict';

    // Wait for DOM to be ready
    $(document).ready(function() {
        var themeSelect = $('#admin_theme');

        if (themeSelect.length === 0) {
            // Theme selector not found, probably not on options page
            return;
        }

        /**
         * Apply theme to the html and body elements
         * @param {string} theme - Theme name (default, calm, mono, neon, obsidian)
         */
        function applyTheme(theme) {
            // Validate theme value
            var allowedThemes = ['default', 'calm', 'mono', 'neon', 'obsidian'];
            if (allowedThemes.indexOf(theme) === -1) {
                console.warn('Invalid theme selected:', theme);
                theme = 'default'; // Default fallback
            }

            // For 'default' theme, remove data-theme attribute to use WordPress defaults
            if (theme === 'default') {
                $('html, body').removeAttr('data-theme');
            } else {
                // Apply the theme to both html and body elements to match CSS selectors
                $('html, body').attr('data-theme', theme);
            }

            // Also update the select value if it doesn't match
            if (themeSelect.val() !== theme) {
                themeSelect.val(theme);
            }
        }

        /**
         * Handle theme selection change
         */
        themeSelect.on('change', function() {
            var selectedTheme = $(this).val();
            applyTheme(selectedTheme);
        });

        // Initialize: ensure both html and body have the correct theme on page load
        // This is redundant with the PHP script but provides a fallback
        var initialTheme = themeSelect.val() || 'default';
        if (!$('html').attr('data-theme') && initialTheme !== 'default') {
            applyTheme(initialTheme);
        }
    });

})(jQuery);
