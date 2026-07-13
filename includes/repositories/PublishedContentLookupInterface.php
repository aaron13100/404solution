<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-side queries against published WordPress content (posts, pages, images,
 * tags, categories). Used by spell-check / suggestion ranking and by view
 * rendering pipelines that need a live snapshot of published content.
 */
interface ABJ_404_Solution_PublishedContentLookupInterface {

    /**
     * Find published posts and pages using named query criteria.
     * Unknown keys and non-scalar values are ignored for forward compatibility.
     *
     * @param array{slug?: string, search_term?: string, limit_results?: string, order_results?: string, extra_where_clause?: string} $criteria
     * @return array<int, object>
     */
    public function getPublishedPagesAndPostsIDs(array $criteria = array());

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
}
