/**
 * 404 Solution - Options Page Accordion
 * Handles collapsible sections on the options page
 *
 * @since 3.0.2
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        // Initialize accordion functionality
        initAccordion();

        // Initialize expand/collapse all button
        initExpandCollapseAll();

        // Restore saved accordion state from localStorage
        restoreAccordionState();
    });

    /**
     * Initialize accordion functionality for all sections
     */
    function initAccordion() {
        $('.abj404-accordion-header').each(function() {
            var $header = $(this);
            var $section = $header.closest('.abj404-accordion-section');
            var $content = $section.find('.abj404-accordion-content');

            // Click event for header
            $header.on('click', function() {
                toggleSection($section);
            });

            // Keyboard navigation
            $header.on('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    toggleSection($section);
                }
            });
        });
    }

    /**
     * Toggle a section open/closed
     * @param {jQuery} $section The section to toggle
     */
    function toggleSection($section) {
        var $header = $section.find('.abj404-accordion-header');
        var $content = $section.find('.abj404-accordion-content');
        var $toggle = $header.find('.abj404-accordion-toggle');
        var isExpanded = $header.attr('aria-expanded') === 'true';

        if (isExpanded) {
            // Collapse
            $content.slideUp(300);
            $header.attr('aria-expanded', 'false');
            $toggle.text('▼');
        } else {
            // Expand
            $content.slideDown(300);
            $header.attr('aria-expanded', 'true');
            $toggle.text('▲');
        }

        // Save state to localStorage
        saveAccordionState();
    }

    /**
     * Initialize expand/collapse all button
     */
    function initExpandCollapseAll() {
        var $button = $('#abj404-expand-collapse-all');

        $button.on('click', function() {
            var buttonText = $button.text().trim();

            if (buttonText === 'Expand All' || buttonText.indexOf('Expand') !== -1) {
                // Expand all sections
                expandAllSections();
                $button.text('Collapse All');
            } else {
                // Collapse all sections
                collapseAllSections();
                $button.text('Expand All');
            }

            // Save state
            saveAccordionState();
        });
    }

    /**
     * Expand all accordion sections
     */
    function expandAllSections() {
        $('.abj404-accordion-section').each(function() {
            var $section = $(this);
            var $header = $section.find('.abj404-accordion-header');
            var $content = $section.find('.abj404-accordion-content');
            var $toggle = $header.find('.abj404-accordion-toggle');

            $content.slideDown(300);
            $header.attr('aria-expanded', 'true');
            $toggle.text('▲');
        });
    }

    /**
     * Collapse all accordion sections
     */
    function collapseAllSections() {
        $('.abj404-accordion-section').each(function() {
            var $section = $(this);
            var $header = $section.find('.abj404-accordion-header');
            var $content = $section.find('.abj404-accordion-content');
            var $toggle = $header.find('.abj404-accordion-toggle');

            $content.slideUp(300);
            $header.attr('aria-expanded', 'false');
            $toggle.text('▼');
        });
    }

    /**
     * Save current accordion state to localStorage
     */
    function saveAccordionState() {
        var state = {};

        $('.abj404-accordion-section').each(function() {
            var $section = $(this);
            var sectionId = $section.data('section');
            var isExpanded = $section.find('.abj404-accordion-header').attr('aria-expanded') === 'true';

            state[sectionId] = isExpanded;
        });

        try {
            localStorage.setItem('abj404_accordion_state', JSON.stringify(state));
        } catch (e) {
            // localStorage not available or quota exceeded
            console.log('404 Solution: Unable to save accordion state to localStorage');
        }
    }

    /**
     * Restore accordion state from localStorage
     */
    function restoreAccordionState() {
        try {
            var savedState = localStorage.getItem('abj404_accordion_state');
            if (!savedState) {
                // No saved state - keep all collapsed (default)
                return;
            }

            var state = JSON.parse(savedState);
            var hasExpandedSections = false;

            $('.abj404-accordion-section').each(function() {
                var $section = $(this);
                var sectionId = $section.data('section');
                var $header = $section.find('.abj404-accordion-header');
                var $content = $section.find('.abj404-accordion-content');
                var $toggle = $header.find('.abj404-accordion-toggle');

                if (state[sectionId] === true) {
                    // Restore expanded state (without animation on page load)
                    $content.show();
                    $header.attr('aria-expanded', 'true');
                    $toggle.text('▲');
                    hasExpandedSections = true;
                }
            });

            // Update expand/collapse all button text based on state
            if (hasExpandedSections) {
                var allExpanded = Object.values(state).every(function(val) { return val === true; });
                if (allExpanded) {
                    $('#abj404-expand-collapse-all').text('Collapse All');
                }
            }
        } catch (e) {
            // localStorage not available or invalid data
            console.log('404 Solution: Unable to restore accordion state from localStorage');
        }
    }

})(jQuery);
