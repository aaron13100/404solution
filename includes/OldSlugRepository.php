<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: covered by tests/ContentRepositoryDecompositionTest.php through ContentRepository facade entry points.

/**
 * Reads WordPress old-slug metadata for post redirect matching.
 */
class ABJ_404_Solution_OldSlugRepository {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions $functions
     */
    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore, $functions) {
        $this->dbCore = $dbCore;
        $this->f = $functions;
    }

    /**
     * @param int|string $post_id
     * @return string|null
     */
    public function getOldSlug($post_id) {
        $post_id = absint($post_id);

        $query = "select meta_value from {wp_postmeta} \nwhere post_id = {post_id} " .
            " and meta_key = '_wp_old_slug' \n" .
            " order by meta_id desc";
        $query = $this->f->str_replace('{post_id}', (string)$post_id, $query);

        $results = $this->dbCore->queryAndGetResults($query);

        $rows = $results['rows'];
        if ($rows == null || empty($rows)) {
            return null;
        }

        $rows = is_array($rows) ? $rows : array();
        $row = is_array($rows[0] ?? null) ? $rows[0] : array();
        return isset($row['meta_value']) && is_string($row['meta_value']) ? $row['meta_value'] : null;
    }
}
