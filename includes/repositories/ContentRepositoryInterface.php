<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/PublishedContentLookupInterface.php';
require_once __DIR__ . '/PermalinkCacheRepositoryInterface.php';
require_once __DIR__ . '/SpellingCacheRepositoryInterface.php';
require_once __DIR__ . '/OldSlugLookupInterface.php';

/**
 * Aggregate type that bundles the four content-area sub-interfaces. New code
 * should depend on the smallest sub-interface it actually uses; this composite
 * is preserved so existing typed callers (e.g. DataAccess delegate, AdminViewBuild,
 * the ContentRepository constructor) continue to compile.
 */
interface ABJ_404_Solution_ContentRepositoryInterface extends
    ABJ_404_Solution_PublishedContentLookupInterface,
    ABJ_404_Solution_PermalinkCacheRepositoryInterface,
    ABJ_404_Solution_SpellingCacheRepositoryInterface,
    ABJ_404_Solution_OldSlugLookupInterface {
}
