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

    $(document).ready(function() {
        initTableInteractions();
        initModal();
        initFilterBar();
        initRowActions();
    });

    /**
     * Initialize table checkbox and bulk action interactions
     */
    function initTableInteractions() {
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

        // Open modal
        $(document).on('click', '[data-modal-open]', function(e) {
            e.preventDefault();
            var modalId = $(this).data('modal-open');
            $('#' + modalId).addClass('active');
            $('body').css('overflow', 'hidden');
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
     * Close a modal
     */
    function closeModal($modal) {
        $modal.removeClass('active');
        $('body').css('overflow', '');
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

})(jQuery);
