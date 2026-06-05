<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: covered by tests/ContentRepositoryDecompositionTest.php through ContentRepository facade entry points.

/**
 * Adds WordPress taxonomy permalink URLs to term result rows.
 */
class ABJ_404_Solution_TermUrlEnricher {

    /**
     * @param array<int, object> $rows
     * @return array<int, object>
     */
    public function addURLToTermsRows($rows) {
        global $wp_rewrite;
        $extraPermaStructureCache = array();
        foreach ($rows as $row) {
            $taxonomy = isset($row->taxonomy) ? (string)$row->taxonomy : '';
            if (!array_key_exists($taxonomy, $extraPermaStructureCache)) {
                $extraPermaStructureCache[$taxonomy] = $wp_rewrite->get_extra_permastruct($taxonomy);
            }
            $struct = $extraPermaStructureCache[$taxonomy];

            $slug = isset($row->slug) ? (string)$row->slug : '';
            $url = str_replace('%' . $taxonomy . '%', $slug, $struct);

            /** @var \stdClass $row */
            $row->url = $url;
        }

        return $rows;
    }
}
