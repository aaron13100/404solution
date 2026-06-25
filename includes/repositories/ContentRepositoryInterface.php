<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/PublishedContentLookupInterface.php';
require_once __DIR__ . '/OldSlugLookupInterface.php';

/**
 * Aggregate type for the content-area repository.
 *
 * Bundles the published-content lookup and old-slug lookup sub-interfaces and
 * declares the permalink-cache and spelling-cache contracts directly. The
 * permalink/spelling declarations used to live in their own segment interfaces
 * (PermalinkCacheRepositoryInterface / SpellingCacheRepositoryInterface), but no
 * caller ever narrowed to a single segment, so they were folded back here to
 * remove unrealized-ISP indirection. New code should depend on the smallest
 * sub-interface it actually uses; this composite is preserved so existing typed
 * callers (e.g. DataAccess delegate, AdminViewBuild, the ContentRepository
 * constructor) continue to compile.
 */
interface ABJ_404_Solution_ContentRepositoryInterface extends
    ABJ_404_Solution_PublishedContentLookupInterface,
    ABJ_404_Solution_OldSlugLookupInterface {

    // --- Permalink cache table: read, write, repopulate, truncate. The cache
    // exists because resolving a post ID -> permalink is too expensive to do
    // per-request on large sites, so a denormalized table keeps the answer ready.

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

    /** @return array<string, mixed> */
    public function updatePermalinkCache();

    /** @return array<string, mixed> */
    public function updatePermalinkCacheParentPages();

    /** @return int */
    public function getPermalinkCacheCount(): int;

    // --- Spelling cache: stores the result of an expensive Levenshtein scan
    // keyed on the requested URL, with explicit invalidation.

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
