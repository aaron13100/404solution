<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Public contract for published-content lookups and permalink/spelling cache operations.
 *
 * Extracted from DataAccess in Phase 1 of the DataAccess refactor. Callers that
 * need published-content queries, permalink cache reads/writes, or spelling
 * cache operations program against this interface.
 */
interface ABJ_404_Solution_ContentRepositoryInterface {

    // =========================================================================
    // Published content lookups (from DataAccessTrait_PublishedContent)
    // =========================================================================

    /**
     * @param string $slug
     * @param string $searchTerm
     * @param string $limitResults
     * @param string $orderResults
     * @param string $extraWhereClause
     * @return array<int, object>
     */
    public function getPublishedPagesAndPostsIDs($slug = '', $searchTerm = '',
        $limitResults = '', $orderResults = '', $extraWhereClause = '');

    /** @return array<int, object> */
    public function getPublishedImagesIDs();

    /**
     * @param string|null $slug
     * @param int|null $limit
     * @return array<int, object>
     */
    public function getPublishedTags($slug = null, $limit = null);

    /**
     * @param array<int, object> $rows
     * @return array<int, object>
     */
    public function addURLToTermsRows($rows);

    /**
     * @param int|null $term_id
     * @param string|null $slug
     * @param int|null $limit
     * @return array<int, object>
     */
    public function getPublishedCategories($term_id = null, $slug = null, $limit = null);

    // =========================================================================
    // Permalink cache (from DataAccessTrait_Maintenance + DataAccessTrait_Stats)
    // =========================================================================

    /** @return void */
    public function truncatePermalinkCacheTable(): void;

    /** @param int $post_id @return void */
    public function removeFromPermalinkCache(int $post_id): void;

    /**
     * @param int|string $id
     * @return string|null
     */
    public function getPermalinkFromCache($id);

    /**
     * @param array<int, int> $ids
     * @return array<int, object>
     */
    public function getPermalinksByIds(array $ids);

    /**
     * @param int|string $id
     * @return array<string, mixed>|null
     */
    public function getPermalinkEtcFromCache($id);

    /** @return array<int, array<string, mixed>>|null */
    public function getIDsNeededForPermalinkCache();

    /** @return array<string, mixed> */
    public function updatePermalinkCache();

    /** @return array<string, mixed> */
    public function updatePermalinkCacheParentPages();

    /** @return int */
    public function getPermalinkCacheCount(): int;

    // =========================================================================
    // Spelling cache (from DataAccessTrait_Maintenance)
    // =========================================================================

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

    // =========================================================================
    // Old slug lookup (from DataAccessTrait_Maintenance)
    // =========================================================================

    /**
     * @param int|string $post_id
     * @return string|null
     */
    public function getOldSlug($post_id);
}
