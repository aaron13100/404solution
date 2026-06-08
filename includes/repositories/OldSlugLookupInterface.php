<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolve a post's previous slug (WordPress writes `_wp_old_slug` postmeta
 * when a slug changes). Used by the redirect-matching pipeline so a URL that
 * matched a now-renamed post still resolves.
 */
interface ABJ_404_Solution_OldSlugLookupInterface {

    /**
     * @param int|string $post_id
     * @return string|null
     */
    public function getOldSlug($post_id);
}
