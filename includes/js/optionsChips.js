/**
 * Options Page Chips Navigation
 * Provides chip-based navigation for showing/hiding option sections with auto-scroll
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        // Initialize chips functionality
        initializeChips();
    });

    function initializeChips() {
        const chips = $('.abj404-chip');
        const sections = $('.abj404-options-section');

        if (chips.length === 0 || sections.length === 0) {
            return; // No chips or sections found
        }

        // Apply initial visibility based on chip state
        applyChipVisibility();

        // Chip click handler
        chips.on('click', function() {
            const chip = $(this);
            const wasPressed = chip.attr('aria-pressed') === 'true';

            // Toggle chip state
            chip.attr('aria-pressed', String(!wasPressed));

            // Ensure at least one section is visible (default to basics)
            ensureAtLeastOneVisible();

            // Apply visibility changes
            applyChipVisibility();

            // On activation, scroll to the corresponding section
            if (!wasPressed) {
                const target = chip.data('target');
                scrollToSection(target);
            }
        });
    }

    function ensureAtLeastOneVisible() {
        const chips = $('.abj404-chip');
        const anyOn = chips.filter('[aria-pressed="true"]').length > 0;

        if (!anyOn) {
            // Default to showing the first section (basics/auto redirects)
            chips.first().attr('aria-pressed', 'true');
        }
    }

    function applyChipVisibility() {
        const chips = $('.abj404-chip');
        const sections = $('.abj404-options-section');

        // Get list of active chip targets
        const activeTargets = [];
        chips.filter('[aria-pressed="true"]').each(function() {
            activeTargets.push($(this).data('target'));
        });

        // Show/hide sections based on chip state
        sections.each(function() {
            const section = $(this);
            const sectionId = section.data('section');

            if (activeTargets.indexOf(sectionId) !== -1) {
                section.addClass('visible').show();
            } else {
                section.removeClass('visible').hide();
            }
        });
    }

    function scrollToSection(sectionId) {
        const section = $('.abj404-options-section[data-section="' + sectionId + '"]');

        if (section.length === 0) {
            return;
        }

        // Calculate scroll position accounting for sticky elements
        // WordPress admin bar height (handle cases where it might not exist)
        const adminBar = $('#wpadminbar');
        const headerHeight = (adminBar.length > 0 && adminBar.is(':visible')) ? adminBar.outerHeight() : 0;

        // Chips navigation height
        const chipsNav = $('.abj404-chips-nav');
        const chipsHeight = (chipsNav.length > 0) ? chipsNav.outerHeight() : 0;

        const offset = headerHeight + chipsHeight + 20; // Add some padding

        const targetPosition = section.offset().top - offset;

        // Smooth scroll to section
        $('html, body').animate({
            scrollTop: targetPosition
        }, 400);
    }

})(jQuery);
