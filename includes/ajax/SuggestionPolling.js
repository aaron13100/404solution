/**
 * Poll for async suggestion computation results.
 * Replaces loading placeholder with actual suggestions when ready.
 */
(function($) {
    'use strict';

    var $placeholder = $('#abj404-suggestions-placeholder');

    // Exit if placeholder not found (page doesn't have async suggestions)
    if (!$placeholder.length) {
        return;
    }

    var requestedURL = $placeholder.data('requested-url');
    var pollInterval = 1000; // 1 second
    var maxAttempts = 90;    // 90 seconds max (handles slow hosts)
    var attempts = 0;

    // Exit if no URL provided
    if (!requestedURL) {
        console.warn('ABJ404: No requested URL found for suggestion polling');
        return;
    }

    /**
     * Poll the server for suggestion results
     */
    function pollForSuggestions() {
        attempts++;

        $.ajax({
            url: abj404_suggestions.ajax_url,
            type: 'POST',
            data: {
                action: 'abj404_poll_suggestions',
                url: requestedURL,
                _ajax_nonce: abj404_suggestions.nonce
            },
            success: function(response) {
                if (response.status === 'complete') {
                    // Replace placeholder with actual suggestions
                    $placeholder.replaceWith(response.html);
                } else if (response.status === 'pending' && attempts < maxAttempts) {
                    // Still computing - poll again
                    setTimeout(pollForSuggestions, pollInterval);
                } else if (response.status === 'not_found') {
                    // Transient not found - computation may not have started
                    // Keep polling for a few more attempts in case of race condition
                    if (attempts < 5) {
                        setTimeout(pollForSuggestions, pollInterval);
                    } else {
                        showFallbackMessage();
                    }
                } else {
                    // Timeout or error - show fallback message
                    showFallbackMessage();
                }
            },
            error: function(xhr, status, error) {
                console.error('ABJ404: Suggestion polling error:', status, error);
                // Retry a few times on network error
                if (attempts < 5) {
                    setTimeout(pollForSuggestions, pollInterval * 2);
                } else {
                    showFallbackMessage();
                }
            }
        });
    }

    /**
     * Show fallback message when suggestions can't be loaded
     */
    function showFallbackMessage() {
        var $loading = $placeholder.find('.abj404-loading');
        if ($loading.length) {
            $loading.html('<li class="abj404-no-suggestions">' +
                (abj404_suggestions.no_suggestions_text || 'Sorry, no suggestions available.') +
                '</li>');
        }
        // Remove skeleton animation
        $placeholder.find('.abj404-skeleton').removeClass('abj404-skeleton');
    }

    // Start polling immediately
    pollForSuggestions();

})(jQuery);
