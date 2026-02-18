/**
 * 404 Solution - Modern Table Interactions
 * Handles checkbox selection, bulk actions, modal, row actions, and filtering
 *
 * @since 3.0.3
 */

(function($) {
    'use strict';

    // State
    var selectedCount = 0;
    var allCheckboxes = [];
    var selectAllCheckbox = null;
    var timeAgoIntervalHandle = null;

    $(document).ready(function() {
        window.abj404InitTableInteractions();
        initModal();
        initFilterBar();
        initRowActions();
        initTimeAgo();
    });

    /**
     * Initialize table checkbox and bulk action interactions
     * Exposed globally for reinitialization after AJAX table refresh
     */
    window.abj404InitTableInteractions = function() {
        var $table = $('.abj404-table');
        if (!$table.length) return;

        allCheckboxes = $table.find('tbody input[type="checkbox"]');
        selectAllCheckbox = $table.find('thead input[type="checkbox"]');

        // Select all checkbox
        selectAllCheckbox.on('change', function() {
            var isChecked = $(this).prop('checked');
            allCheckboxes.prop('checked', isChecked);
            updateSelectionCount();
        });

        // Individual checkboxes
        allCheckboxes.on('change', function() {
            updateSelectionCount();
            updateSelectAllState();
        });

        // Initial state
        updateSelectionCount();
    }

    /**
     * Update the selection count and toggle bulk actions bar
     */
    function updateSelectionCount() {
        selectedCount = allCheckboxes.filter(':checked').length;
        var $bulkActions = $('.abj404-bulk-actions');
        var $selectionInfo = $bulkActions.find('.abj404-selection-info strong');

        if (selectedCount > 0) {
            $bulkActions.addClass('active');
            $selectionInfo.text(selectedCount);
        } else {
            $bulkActions.removeClass('active');
        }

        // Enable/disable the legacy apply buttons too
        var $applyButtons = $('input[name="abj404action"]').closest('form').find('input[type="submit"]');
        $applyButtons.prop('disabled', selectedCount === 0);
    }

    /**
     * Update the "select all" checkbox state based on individual selections
     */
    function updateSelectAllState() {
        if (!selectAllCheckbox.length) return;

        var totalCount = allCheckboxes.length;
        var checkedCount = allCheckboxes.filter(':checked').length;

        if (checkedCount === 0) {
            selectAllCheckbox.prop('checked', false);
            selectAllCheckbox.prop('indeterminate', false);
        } else if (checkedCount === totalCount) {
            selectAllCheckbox.prop('checked', true);
            selectAllCheckbox.prop('indeterminate', false);
        } else {
            selectAllCheckbox.prop('checked', false);
            selectAllCheckbox.prop('indeterminate', true);
        }
    }

    /**
     * Clear all selections
     */
    window.abj404ClearSelection = function() {
        allCheckboxes.prop('checked', false);
        if (selectAllCheckbox.length) {
            selectAllCheckbox.prop('checked', false);
            selectAllCheckbox.prop('indeterminate', false);
        }
        updateSelectionCount();
    };

    /**
     * Initialize modal functionality
     */
    function initModal() {
        var $modal = $('.abj404-modal');
        if (!$modal.length) return;

        // Add ARIA attributes to modals
        $modal.each(function() {
            var $m = $(this);
            $m.attr('role', 'dialog');
            $m.attr('aria-modal', 'true');

            // Find the modal title for aria-labelledby
            var $title = $m.find('.abj404-modal-header h2');
            if ($title.length) {
                var titleId = $m.attr('id') + '-title';
                $title.attr('id', titleId);
                $m.attr('aria-labelledby', titleId);
            }
        });

        // Open modal
        $(document).on('click', '[data-modal-open]', function(e) {
            e.preventDefault();
            var $trigger = $(this);
            var modalId = $trigger.data('modal-open');
            var $targetModal = $('#' + modalId);

            // Store the trigger element to return focus on close
            $targetModal.data('trigger', $trigger);

            $targetModal.addClass('active');
            $('body').css('overflow', 'hidden');

            // Focus the first focusable element in the modal
            var $focusable = $targetModal.find('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])').filter(':visible');
            if ($focusable.length) {
                $focusable.first().focus();
            }

            // Trap focus within modal
            trapFocus($targetModal);
        });

        // Close modal - close button
        $modal.find('.abj404-modal-close').on('click', function() {
            closeModal($(this).closest('.abj404-modal'));
        });

        // Close modal - clicking outside
        $modal.on('click', function(e) {
            if (e.target === this) {
                closeModal($(this));
            }
        });

        // Close modal - escape key
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') {
                var $activeModal = $('.abj404-modal.active');
                if ($activeModal.length) {
                    closeModal($activeModal);
                }
            }
        });
    }

    /**
     * Trap focus within modal for accessibility
     */
    function trapFocus($modal) {
        $modal.on('keydown.trapFocus', function(e) {
            if (e.key !== 'Tab') return;

            var $focusable = $modal.find('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])').filter(':visible');
            var $first = $focusable.first();
            var $last = $focusable.last();

            if (e.shiftKey) {
                // Shift+Tab: if on first element, go to last
                if (document.activeElement === $first[0]) {
                    e.preventDefault();
                    $last.focus();
                }
            } else {
                // Tab: if on last element, go to first
                if (document.activeElement === $last[0]) {
                    e.preventDefault();
                    $first.focus();
                }
            }
        });
    }

    /**
     * Close a modal
     */
    function closeModal($modal) {
        $modal.removeClass('active');
        $('body').css('overflow', '');

        // Remove focus trap
        $modal.off('keydown.trapFocus');

        // Return focus to trigger element
        var $trigger = $modal.data('trigger');
        if ($trigger && $trigger.length) {
            $trigger.focus();
        }
    }

    /**
     * Open the add redirect modal
     */
    window.abj404OpenAddRedirectModal = function() {
        $('#abj404-add-redirect-modal').addClass('active');
        $('body').css('overflow', 'hidden');
    };

    /**
     * Close the add redirect modal
     */
    window.abj404CloseAddRedirectModal = function() {
        $('#abj404-add-redirect-modal').removeClass('active');
        $('body').css('overflow', '');
    };

    /**
     * Initialize filter bar functionality
     * Note: Server-side filtering is handled by view_updater.js on Enter key press.
     * This function only handles non-search interactions.
     */
    function initFilterBar() {
        // Server-side search filtering is handled by view_updater.js
        // which binds to input[name=searchFilter] and triggers on Enter key.
        // No client-side filtering here to avoid conflicts.
    }

    /**
     * Initialize row action buttons
     */
    function initRowActions() {
        // Handle AJAX trash action (existing functionality enhancement)
        $(document).on('click', '.abj404-action-btn.ajax-trash', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var url = $btn.data('url');

            if (!url) return;

            // Confirm action
            if (!confirm($btn.data('confirm') || 'Are you sure?')) {
                return;
            }

            $btn.addClass('loading').prop('disabled', true);

            $.ajax({
                url: url,
                type: 'POST',
                success: function(response) {
                    if (response.success) {
                        // Remove the row with animation
                        $btn.closest('tr').fadeOut(300, function() {
                            $(this).remove();
                            updateSelectionCount();
                        });

                        // Show success toast
                        abj404ShowToast(response.data.message || 'Action completed', 'success');
                    } else {
                        abj404ShowToast(response.data.message || 'Action failed', 'error');
                        $btn.removeClass('loading').prop('disabled', false);
                    }
                },
                error: function() {
                    abj404ShowToast('An error occurred', 'error');
                    $btn.removeClass('loading').prop('disabled', false);
                }
            });
        });
    }

    /**
     * Show toast notification
     */
    window.abj404ShowToast = function(message, type) {
        var $toast = $('#abj404-toast');

        // Create toast if it doesn't exist
        if (!$toast.length) {
            $toast = $('<div id="abj404-toast" class="abj404-toast"><span class="abj404-toast-message"></span></div>');
            $('body').append($toast);
        }

        var $message = $toast.find('.abj404-toast-message');
        $message.text(message);

        $toast.removeClass('error success').addClass(type || 'success').addClass('show');

        // Auto-hide after 3 seconds
        setTimeout(function() {
            $toast.removeClass('show');
        }, 3000);
    };

    /**
     * Utility: Get URL parameter
     */
    function getUrlParam(param) {
        var urlParams = new URLSearchParams(window.location.search);
        return urlParams.get(param);
    }

    /**
     * Toggle regex explanation info
     */
    window.abj404ToggleRegexInfo = function(event) {
        event.preventDefault();
        var $info = $('.abj404-regex-info');
        var $toggle = $('.abj404-regex-toggle');

        if ($info.is(':visible')) {
            $info.slideUp(200);
            $toggle.text('(Explain)');
        } else {
            $info.slideDown(200);
            $toggle.text('(Hide Info)');
        }
    };

    /**
     * Initialize dynamic time-ago updates
     * Updates elements with class 'abj404-time-ago' and data-timestamp attribute
     */
    function initTimeAgo() {
        var $timeElements = $('.abj404-time-ago[data-timestamp]');
        if (!$timeElements.length) return;

        // Update immediately and then every N seconds
        updateTimeAgo($timeElements);
        if (timeAgoIntervalHandle !== null) {
            return;
        }
        timeAgoIntervalHandle = setInterval(function() {
            updateTimeAgo($('.abj404-time-ago[data-timestamp]'));
        }, 10000);
    }

    // Expose re-init hook for AJAX table replacements.
    window.abj404InitTimeAgo = initTimeAgo;

    /**
     * Update time-ago text for all matching elements
     */
    function updateTimeAgo($elements) {
        var now = Math.floor(Date.now() / 1000);

        $elements.each(function() {
            var $el = $(this);
            var timestamp = parseInt($el.attr('data-timestamp'), 10);
            if (!timestamp || isNaN(timestamp)) return;

            var diff = now - timestamp;
            // Handle negative diff (future timestamp due to clock skew)
            if (diff < 0) diff = 0;

            var text = formatTimeAgo(diff);
            $el.text(text);
        });
    }

    /**
     * Format seconds difference as human-readable time ago
     * Uses localized strings from abj404_time_ago if available
     */
    function formatTimeAgo(seconds) {
        // Get localized strings or use English defaults
        var strings = window.abj404_time_ago || {
            second: 'second',
            seconds: 'seconds',
            minute: 'minute',
            minutes: 'minutes',
            hour: 'hour',
            hours: 'hours',
            day: 'day',
            days: 'days',
            ago: 'ago'
        };

        var value, unit;

        if (seconds < 60) {
            value = seconds;
            unit = (value === 1) ? strings.second : strings.seconds;
        } else if (seconds < 3600) {
            value = Math.floor(seconds / 60);
            unit = (value === 1) ? strings.minute : strings.minutes;
        } else if (seconds < 86400) {
            value = Math.floor(seconds / 3600);
            unit = (value === 1) ? strings.hour : strings.hours;
        } else {
            value = Math.floor(seconds / 86400);
            unit = (value === 1) ? strings.day : strings.days;
        }

        return value + ' ' + unit + ' ' + strings.ago;
    }

})(jQuery);
