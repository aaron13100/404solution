/**
 * Options Page Chips Navigation
 * Chips show hidden sections and scroll to them. X buttons hide sections.
 * All sections are visible by default.
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        initializeChips();
    });

    function initializeChips() {
        const chips = $('.abj404-chip');
        const sections = $('.abj404-options-section');
        const hideButtons = $('.abj404-section-hide');

        if (chips.length === 0 || sections.length === 0) {
            return; // No chips or sections found
        }

        // All sections visible by default - update chips to reflect this
        chips.attr('aria-pressed', 'true');
        sections.addClass('visible').show();

        // Chip click handler - shows hidden sections and scrolls to them
        chips.on('click', function() {
            const chip = $(this);
            const target = chip.data('target');
            const section = $('.abj404-options-section[data-section="' + target + '"]');

            if (section.length === 0) {
                return;
            }

            const isHidden = !section.hasClass('visible');

            if (isHidden) {
                // Show the section
                section.addClass('visible').show();
                chip.attr('aria-pressed', 'true');
            }

            // Always scroll to the section when chip is clicked
            scrollToSection(target);
        });

        // X button click handler - hides sections
        hideButtons.on('click', function() {
            const button = $(this);
            const sectionId = button.data('section');
            const section = $('.abj404-options-section[data-section="' + sectionId + '"]');
            const chip = $('.abj404-chip[data-target="' + sectionId + '"]');

            if (section.length === 0) {
                return;
            }

            // Hide the section
            section.removeClass('visible').hide();
            chip.attr('aria-pressed', 'false');

            // Check if all sections are now hidden
            ensureAtLeastOneVisible();
        });
    }

    function ensureAtLeastOneVisible() {
        const sections = $('.abj404-options-section');
        const visibleSections = sections.filter('.visible');

        if (visibleSections.length === 0) {
            // No sections visible - auto-reveal the first section (auto redirects)
            const firstSection = sections.first();
            const firstSectionId = firstSection.data('section');
            const firstChip = $('.abj404-chip[data-target="' + firstSectionId + '"]');

            firstSection.addClass('visible').show();
            firstChip.attr('aria-pressed', 'true');

            // Scroll to the revealed section
            scrollToSection(firstSectionId);
        }
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
        const chipsContainer = $('.abj404-chips-container');
        const chipsHeight = (chipsContainer.length > 0) ? chipsContainer.outerHeight() : 0;

        const offset = headerHeight + chipsHeight + 20; // Add some padding

        const targetPosition = section.offset().top - offset;

        // Smooth scroll to section
        $('html, body').animate({
            scrollTop: targetPosition
        }, 400);
    }

})(jQuery);
