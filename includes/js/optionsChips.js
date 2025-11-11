/**
 * Options Page Chips Navigation
 * Chips show hidden sections and scroll to them. X buttons hide sections.
 * All sections are visible by default.
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        calculateDynamicHeights();
        initializeChips();
        initializeScrollSpy();
        initializeHidingHeader();
    });

    function calculateDynamicHeights() {
        // Calculate actual heights for sticky positioning
        const adminBar = $('#wpadminbar');
        const navTabs = $('.nav-tab-wrapper');

        const adminBarHeight = (adminBar.length > 0 && adminBar.is(':visible')) ? adminBar.outerHeight() : 32;
        const tabsHeight = (navTabs.length > 0) ? navTabs.outerHeight() : 46;
        const chipsTopWithTabs = adminBarHeight + tabsHeight;

        // Set CSS custom properties for dynamic positioning
        document.documentElement.style.setProperty('--admin-bar-height', adminBarHeight + 'px');
        document.documentElement.style.setProperty('--chips-top-with-tabs', chipsTopWithTabs + 'px');
    }

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

    function initializeScrollSpy() {
        const sections = $('.abj404-options-section');
        const chips = $('.abj404-chip');

        if (sections.length === 0 || chips.length === 0) {
            return;
        }

        // Track all intersecting sections and their ratios
        let intersectingSections = new Map();
        let updateTimeout = null;

        // Use Intersection Observer to detect which section is in viewport
        const observerOptions = {
            root: null, // viewport
            rootMargin: '-100px 0px -20% 0px', // Account for sticky header, less sensitive
            threshold: [0, 0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9, 1.0] // Track intersection at multiple points
        };

        const observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                const sectionId = $(entry.target).data('section');

                if (entry.isIntersecting && $(entry.target).hasClass('visible')) {
                    // Store the intersection ratio for this section
                    intersectingSections.set(sectionId, entry.intersectionRatio);
                } else {
                    // Section is no longer intersecting, remove it
                    intersectingSections.delete(sectionId);
                }
            });

            // Debounce the update to avoid rapid changes during fast scrolling
            clearTimeout(updateTimeout);
            updateTimeout = setTimeout(function() {
                updateActiveChip();
            }, 100); // 100ms debounce
        }, observerOptions);

        function updateActiveChip() {
            if (intersectingSections.size === 0) {
                return;
            }

            // Find the section with the largest intersection ratio
            let maxRatio = 0;
            let activeSectionId = null;

            intersectingSections.forEach(function(ratio, sectionId) {
                if (ratio > maxRatio) {
                    maxRatio = ratio;
                    activeSectionId = sectionId;
                }
            });

            if (activeSectionId) {
                // Remove active class from all chips
                chips.removeClass('active');

                // Add active class to the chip with the largest intersection
                const activeChip = $('.abj404-chip[data-target="' + activeSectionId + '"]');
                activeChip.addClass('active');
            }
        }

        // Observe all sections
        sections.each(function() {
            observer.observe(this);
        });
    }

    function initializeHidingHeader() {
        const chipsContainer = $('.abj404-chips-container');

        // Only enable hiding header on Options page (where chips exist)
        if (chipsContainer.length === 0) {
            return; // Not Options page, skip hiding header
        }

        const navTabWrapper = $('.nav-tab-wrapper');

        if (navTabWrapper.length === 0) {
            return; // No tabs found
        }

        let lastScrollY = window.scrollY;
        let ticking = false;
        const scrollThreshold = 50; // Only trigger after scrolling 50px
        const topThreshold = 100; // Don't hide when near top of page

        function updateHeader() {
            const currentScrollY = window.scrollY;

            // Don't hide tabs when near the top
            if (currentScrollY < topThreshold) {
                navTabWrapper.removeClass('tabs-hidden');
                chipsContainer.removeClass('tabs-are-hidden');
                lastScrollY = currentScrollY;
                return;
            }

            // Check scroll direction
            if (currentScrollY > lastScrollY && currentScrollY > scrollThreshold) {
                // Scrolling down - hide tabs, move chips up
                navTabWrapper.addClass('tabs-hidden');
                chipsContainer.addClass('tabs-are-hidden');
            } else if (currentScrollY < lastScrollY) {
                // Scrolling up - show tabs, move chips down
                navTabWrapper.removeClass('tabs-hidden');
                chipsContainer.removeClass('tabs-are-hidden');
            }

            lastScrollY = currentScrollY;
        }

        window.addEventListener('scroll', function() {
            if (!ticking) {
                window.requestAnimationFrame(function() {
                    updateHeader();
                    ticking = false;
                });
                ticking = true;
            }
        });
    }

})(jQuery);
