<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Permalink cache table: read, write, repopulate, truncate. The cache exists
 * because resolving a post ID -> permalink is too expensive to do per-request
 * on large sites, so a denormalized table keeps the answer ready.
 */
interface ABJ_404_Solution_PermalinkCacheRepositoryInterface {

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
}
