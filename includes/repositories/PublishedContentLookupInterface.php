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
}
