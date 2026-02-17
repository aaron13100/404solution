/**
 * 404 Solution - Options Page Accordion & Card Toggle
 * Handles collapsible sections on the options page
 *
 * @since 3.0.2
 */

/**
 * Global function to toggle card sections
 * Called from onclick attribute in card headers
 * @param {HTMLElement} header The card header element that was clicked
 */
function abj404ToggleCard(header) {
    var card = header.closest('.abj404-card');
    if (card) {
        card.classList.toggle('expanded');
        var isExpanded = card.classList.contains('expanded');
        header.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');

        // Save state to localStorage
        abj404SaveCardState();
    }
}

/**
 * Save current card state to localStorage
 */
function abj404SaveCardState() {
    var state = {};
    document.querySelectorAll('.abj404-card').forEach(function(card) {
        var cardId = card.getAttribute('data-card');
        if (cardId) {
            state[cardId] = card.classList.contains('expanded');
        }
    });

    try {
        localStorage.setItem('abj404_card_state', JSON.stringify(state));
    } catch (e) {
        console.log('404 Solution: Unable to save card state to localStorage');
    }
}

/**
 * Restore card state from localStorage
 */
function abj404RestoreCardState() {
    try {
        var savedState = localStorage.getItem('abj404_card_state');
        if (!savedState) return;

        var state = JSON.parse(savedState);

        document.querySelectorAll('.abj404-card').forEach(function(card) {
            var cardId = card.getAttribute('data-card');
            if (cardId && state.hasOwnProperty(cardId)) {
                if (state[cardId]) {
                    card.classList.add('expanded');
                    var header = card.querySelector('.abj404-card-header');
                    if (header) header.setAttribute('aria-expanded', 'true');
                } else {
                    card.classList.remove('expanded');
                    var header = card.querySelector('.abj404-card-header');
                    if (header) header.setAttribute('aria-expanded', 'false');
                }
            }
        });
    } catch (e) {
        console.log('404 Solution: Unable to restore card state from localStorage');
    }
}

/**
 * Show toast notification
 * @param {string} message The message to display
 * @param {string} type 'success' or 'error'
 */
function abj404ShowToast(message, type) {
    var toast = document.getElementById('abj404-toast');
    if (!toast) return;

    var messageEl = toast.querySelector('.abj404-toast-message');
    if (messageEl && message) {
        messageEl.textContent = message;
    }

    toast.classList.remove('error');
    if (type === 'error') {
        toast.classList.add('error');
    }

    toast.classList.add('show');

    // Auto-hide after 3 seconds
    setTimeout(function() {
        toast.classList.remove('show');
    }, 3000);
}

(function($) {
    'use strict';

    $(document).ready(function() {
        // Initialize accordion functionality (for legacy accordion sections)
        initAccordion();

        // Initialize expand/collapse all button
        initExpandCollapseAll();

        // Restore saved accordion state from localStorage
        restoreAccordionState();

        // Restore saved card state from localStorage
        abj404RestoreCardState();

        // Initialize card keyboard navigation
        initCardKeyboardNav();

        // Tools import format hint (competitor migration UX)
        initImportFormatHint();
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
     * Check if user prefers reduced motion
     * @returns {boolean}
     */
    function prefersReducedMotion() {
        return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
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
        var duration = prefersReducedMotion() ? 0 : 300;

        if (isExpanded) {
            // Collapse
            $content.slideUp(duration);
            $header.attr('aria-expanded', 'false');
            $toggle.text('▼');
        } else {
            // Expand
            $content.slideDown(duration);
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

        // Initialize button text and state
        $button.text(abj404Accordion.expandAll);
        $button.data('state', 'collapsed');

        $button.on('click', function() {
            var state = $button.data('state');

            if (state === 'collapsed') {
                // Expand all sections
                expandAllSections();
                $button.text(abj404Accordion.collapseAll);
                $button.data('state', 'expanded');
            } else {
                // Collapse all sections
                collapseAllSections();
                $button.text(abj404Accordion.expandAll);
                $button.data('state', 'collapsed');
            }

            // Save state
            saveAccordionState();
        });
    }

    /**
     * Expand all accordion sections and cards
     */
    function expandAllSections() {
        var duration = prefersReducedMotion() ? 0 : 300;

        // Legacy accordion sections
        $('.abj404-accordion-section').each(function() {
            var $section = $(this);
            var $header = $section.find('.abj404-accordion-header');
            var $content = $section.find('.abj404-accordion-content');
            var $toggle = $header.find('.abj404-accordion-toggle');

            $content.slideDown(duration);
            $header.attr('aria-expanded', 'true');
            $toggle.text('▲');
        });

        // New card sections
        $('.abj404-card').each(function() {
            $(this).addClass('expanded');
            $(this).find('.abj404-card-header').attr('aria-expanded', 'true');
        });

        // Save card state
        abj404SaveCardState();
    }

    /**
     * Collapse all accordion sections and cards
     */
    function collapseAllSections() {
        var duration = prefersReducedMotion() ? 0 : 300;

        // Legacy accordion sections
        $('.abj404-accordion-section').each(function() {
            var $section = $(this);
            var $header = $section.find('.abj404-accordion-header');
            var $content = $section.find('.abj404-accordion-content');
            var $toggle = $header.find('.abj404-accordion-toggle');

            $content.slideUp(duration);
            $header.attr('aria-expanded', 'false');
            $toggle.text('▼');
        });

        // New card sections
        $('.abj404-card').each(function() {
            $(this).removeClass('expanded');
            $(this).find('.abj404-card-header').attr('aria-expanded', 'false');
        });

        // Save card state
        abj404SaveCardState();
    }

    /**
     * Detect import CSV format from first row and show a small hint on Tools > Import.
     */
    function initImportFormatHint() {
        var input = document.getElementById('abj404-import-file-input');
        var hint = document.getElementById('abj404-import-format-hint');
        if (!input || !hint || !window.FileReader) {
            return;
        }

        input.addEventListener('change', function() {
            hint.textContent = '';

            var file = input.files && input.files[0] ? input.files[0] : null;
            if (!file) {
                return;
            }

            var reader = new FileReader();
            reader.onload = function(evt) {
                var text = (evt && evt.target && typeof evt.target.result === 'string') ? evt.target.result : '';
                if (!text) {
                    return;
                }

                var firstLine = text.split(/\r?\n/).find(function(line) {
                    return line && line.trim() !== '';
                }) || '';
                if (!firstLine) {
                    return;
                }

                var delimiter = ',';
                var commaCount = firstLine.split(',').length;
                var semicolonCount = firstLine.split(';').length;
                var tabCount = firstLine.split('\t').length;
                if (semicolonCount > commaCount && semicolonCount >= tabCount) {
                    delimiter = ';';
                } else if (tabCount > commaCount && tabCount > semicolonCount) {
                    delimiter = '\t';
                }

                var headers = firstLine.split(delimiter).map(function(h) {
                    return h
                        .replace(/^\uFEFF/, '')
                        .toLowerCase()
                        .trim()
                        .replace(/\s+/g, '_')
                        .replace(/[^a-z0-9_]/g, '');
                });

                var has = function(name) { return headers.indexOf(name) !== -1; };
                var detected = 'Custom CSV format';
                if (has('source') && has('target') && has('regex')) {
                    detected = 'Detected format: Redirection CSV';
                } else if (has('redirect_from') && has('redirect_to')) {
                    detected = 'Detected format: Safe Redirect Manager CSV';
                } else if (has('request') && has('destination')) {
                    detected = 'Detected format: Simple 301 Redirects CSV';
                } else if (has('from_url') && has('to_url')) {
                    detected = 'Detected format: 404 Solution CSV';
                }

                hint.textContent = detected;
            };

            // Read a small slice only; we just need headers.
            reader.readAsText(file.slice(0, 8192));
        });
    }

    /**
     * Initialize keyboard navigation for card headers
     */
    function initCardKeyboardNav() {
        $('.abj404-card-header').on('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                abj404ToggleCard(this);
            }
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
                    $('#abj404-expand-collapse-all').text(abj404Accordion.collapseAll).data('state', 'expanded');
                }
            }
        } catch (e) {
            // localStorage not available or invalid data
            console.log('404 Solution: Unable to restore accordion state from localStorage');
        }
    }

})(jQuery);
