<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Spelling cache: stores the result of an expensive Levenshtein scan keyed on
 * the requested URL, with explicit invalidation.
 */
interface ABJ_404_Solution_SpellingCacheRepositoryInterface {

    /**
     * @param string $requestedURLRaw
     * @param mixed $returnValue
     * @return void
     */
    public function storeSpellingPermalinksToCache(string $requestedURLRaw, $returnValue): void;

    /**
     * @param string $requestedURLRaw
     * @return mixed
     */
    public function getSpellingPermalinksFromCache(string $requestedURLRaw);

    /** @return void */
    public function deleteSpellingCache(): void;
}
