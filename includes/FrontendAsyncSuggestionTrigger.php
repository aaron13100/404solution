<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Starts asynchronous frontend suggestion generation when the 404 page needs it.
 */
class ABJ_404_Solution_FrontendAsyncSuggestionTrigger {

    /** @var ABJ_404_Solution_SpellChecker */
    private $spellChecker;

    /**
     * @param ABJ_404_Solution_SpellChecker $spellChecker
     */
    function __construct($spellChecker) {
        $this->spellChecker = $spellChecker;
    }

    /**
     * @param string $requestedURL
     * @return void
     */
    function triggerIfNeeded(string $requestedURL): void {
        if ($this->spellChecker->does404PageHaveSuggestionsShortcode()) {
            $this->spellChecker->triggerAndCleanupOnFailure($requestedURL);
        }
    }
}
