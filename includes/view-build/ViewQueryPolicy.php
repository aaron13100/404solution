<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Query policy for admin redirect and captured-url table reads.
 *
 * Owns safe status-filter resolution, score-range fragments, text-search
 * fragments, order-by allowlists, collation selection, and view-build labels.
 */
class ABJ_404_Solution_ViewQueryPolicy {

    /**
     * @param array<string, mixed> $tableOptions
     * @param string $columnPrefix
     * @return string
     */
    public function buildScoreRangeClause(array $tableOptions, string $columnPrefix): string {
        $rawScoreRange = $tableOptions['score_range'] ?? 'all';
        $scoreRange = is_string($rawScoreRange) ? $rawScoreRange : 'all';
        $col = $columnPrefix . 'score';
        $high = (int) ABJ_404_Solution_ScoreThresholds::HIGH;
        $medium = (int) ABJ_404_Solution_ScoreThresholds::MEDIUM;
        switch ($scoreRange) {
            case ABJ_404_Solution_ScoreThresholds::RANGE_HIGH:
                return 'AND ' . $col . ' >= ' . $high;
            case ABJ_404_Solution_ScoreThresholds::RANGE_MEDIUM:
                return 'AND ' . $col . ' >= ' . $medium . ' AND ' . $col . ' < ' . $high;
            case ABJ_404_Solution_ScoreThresholds::RANGE_LOW:
                return 'AND ' . $col . ' IS NOT NULL AND ' . $col . ' < ' . $medium;
            case ABJ_404_Solution_ScoreThresholds::MANUAL:
                return 'AND ' . $col . ' IS NULL';
            default:
                return '';
        }
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    public function resolveStatusTypeList(string $sub, array $tableOptions): string {
        global $abj404_redirect_types, $abj404_captured_types;
        $filter = $tableOptions['filter'] ?? 0;

        if ($filter == 0 || $filter == ABJ404_TRASH_FILTER) {
            if ($sub === 'abj404_redirects') {
                return $this->statusArrayToList(is_array($abj404_redirect_types) ? $abj404_redirect_types : array());
            }
            if ($sub === 'abj404_captured') {
                return $this->statusArrayToList(is_array($abj404_captured_types) ? $abj404_captured_types : array());
            }
            return '0';
        }
        if ($filter == ABJ404_STATUS_MANUAL) {
            return implode(', ', array(ABJ404_STATUS_MANUAL, ABJ404_STATUS_REGEX));
        }
        if ($filter == ABJ404_HANDLED_FILTER) {
            return implode(', ', array(ABJ404_STATUS_IGNORED, ABJ404_STATUS_LATER));
        }

        return $this->singleStatusFilter($filter);
    }

    /**
     * @param mixed $filter
     * @return string
     */
    private function singleStatusFilter($filter): string {
        if (!is_scalar($filter)) {
            return '0';
        }
        $raw = trim((string)$filter);
        if (!preg_match('/^\d+$/', $raw)) {
            return '0';
        }
        return (string)intval($raw);
    }

    /**
     * @param array<int, mixed> $types
     * @return string
     */
    private function statusArrayToList(array $types): string {
        $clean = array();
        foreach ($types as $type) {
            if (is_scalar($type)) {
                $clean[] = intval($type);
            }
        }
        return count($clean) > 0 ? implode(', ', $clean) : '0';
    }

    /** @param array<string, mixed> $tableOptions @return int */
    public function resolveTrashValue(array $tableOptions): int {
        return ($tableOptions['filter'] ?? 0) == ABJ404_TRASH_FILTER ? 1 : 0;
    }

    /**
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    public function resolveOrderByColumn(array $tableOptions): string {
        $rawOrderBy = $tableOptions['orderby'] ?? '';
        $orderBy = strtolower(is_string($rawOrderBy) ? $rawOrderBy : '');
        $allowed = array('url', 'status', 'type', 'code', 'score', 'timestamp',
            'logshits', 'last_used', 'final_dest', 'dest', 'id');
        if ($orderBy === 'dest' || $orderBy === 'final_dest') {
            return "CASE WHEN dest_for_view IS NULL OR dest_for_view = '' THEN 1 ELSE 0 END ASC, dest_for_view";
        }
        if (!in_array($orderBy, $allowed, true)) {
            return 'url';
        }
        return $orderBy;
    }

    /**
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    public function resolveOrderDirection(array $tableOptions): string {
        $rawOrderVal = $tableOptions['order'] ?? '';
        $rawOrderValStr = is_string($rawOrderVal) ? $rawOrderVal : '';
        $order = strtoupper((string)preg_replace('/[^a-zA-Z]/', '', trim($rawOrderValStr)));
        return $order === 'DESC' ? 'DESC' : 'ASC';
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param bool $singleTable When true, the post-type label predicate resolves
     *   the matching destination posts via a {wp_posts} subquery on final_dest
     *   rather than a wp_post_type column reference, because the single-table
     *   redirects read (Denorm Step 3b) has no denormalized wp_post_type column.
     * @param bool $destColumnAvailable When false, the destination-title match
     *   drops the dest_for_view column from the search expression (schema-drift
     *   tolerance: an old redirects table may lack it). The search then matches
     *   url/code/labels only.
     * @return string
     */
    public function buildFilterTextClause(string $sub, array $tableOptions, bool $singleTable = false, bool $destColumnAvailable = true): string {
        $rawFilterText = $tableOptions['filterText'] ?? '';
        $rawFilterText = is_string($rawFilterText) ? $rawFilterText : '';
        if ($rawFilterText === '') {
            return '';
        }

        $filterText = $this->sanitizeFilterText($rawFilterText);
        $collation = $this->resolveCollation($tableOptions);
        $needle = $this->normalizedSearchExpression("'%" . $filterText . "%'", $collation);
        if ($sub === 'abj404_redirects') {
            $predicates = $this->labelPredicatesForFilterText($filterText, $singleTable);
            $searchConcat = $destColumnAvailable
                ? "CONCAT(url, '////', dest_for_view, '////', code)"
                : "CONCAT(url, '////', code)";
            $predicates[] = $this->normalizedSearchExpression($searchConcat, $collation) . " LIKE " . $needle;
            return 'AND (' . implode(' OR ', $predicates) . ')';
        }
        if ($sub === 'abj404_captured') {
            return "AND " . $this->normalizedSearchExpression('url', $collation) . " LIKE " . $needle;
        }
        return 'AND 0 = 1';
    }

    /**
     * @param string $sqlExpression
     * @param string $collation
     * @return string
     */
    private function normalizedSearchExpression(string $sqlExpression, string $collation): string {
        return "REPLACE(LOWER(CONVERT(" . $sqlExpression . " USING utf8mb4) COLLATE "
            . $collation . "), ' ', '')";
    }

    /**
     * @param string $rawFilterText
     * @return string
     */
    public function sanitizeFilterText(string $rawFilterText): string {
        global $wpdb;
        $sanitized = str_replace(array('*', '/', '$'), '', $rawFilterText);
        if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'esc_like')) {
            /** @var wpdb $wpdb */
            $sanitized = $wpdb->esc_like($sanitized);
        } else {
            $sanitized = addcslashes($sanitized, '_%\\');
        }
        return esc_sql($sanitized);
    }

    /**
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    public function resolveCollation(array $tableOptions): string {
        global $wpdb;
        $wpdbCollate = 'utf8mb4_unicode_ci';
        $hasForcedCollate = false;
        if (array_key_exists('forceCollate', $tableOptions) && !empty($tableOptions['forceCollate'])) {
            $rawForceCollateVal = $tableOptions['forceCollate'];
            $rawForceCollate = is_string($rawForceCollateVal) ? $rawForceCollateVal : '';
            $forced = preg_replace('/[^A-Za-z0-9_]/', '', $rawForceCollate);
            if ($forced !== '') {
                $wpdbCollate = $forced;
                $hasForcedCollate = true;
            }
        }
        if (!$hasForcedCollate && isset($wpdb) && isset($wpdb->collate) && !empty($wpdb->collate)) {
            $wpdbCollate = preg_replace('/[^A-Za-z0-9_]/', '', $wpdb->collate);
        }
        return $wpdbCollate === '' ? 'utf8mb4_unicode_ci' : $wpdbCollate;
    }

    /**
     * @param string $filterText
     * @param bool $singleTable
     * @return array<int, string>
     */
    private function labelPredicatesForFilterText(string $filterText, bool $singleTable = false): array {
        $normalized = $this->normalizeSearchLabel($filterText);
        if ($normalized === '') {
            return array();
        }

        $statusMatches = $this->matchingLabelCodes($normalized, $this->statusSearchLabels());
        $typeMatches = $this->matchingLabelCodes($normalized, $this->typeSearchLabels());
        $predicates = array();
        if (count($statusMatches) > 0) {
            $predicates[] = 'status IN (' . implode(', ', $statusMatches) . ')';
        }
        if (count($typeMatches) > 0) {
            $predicates[] = 'type IN (' . implode(', ', $typeMatches) . ')';
        }
        $predicates = array_merge($predicates, $this->postTypeLabelPredicates($normalized, $singleTable));
        return $predicates;
    }

    /**
     * @param array<int, array<int, string>> $labelsByCode
     * @return array<int, int>
     */
    private function matchingLabelCodes(string $normalized, array $labelsByCode): array {
        $matches = array();
        foreach ($labelsByCode as $code => $labels) {
            foreach ($labels as $label) {
                if ($this->normalizeSearchLabel($label) === $normalized) {
                    $matches[] = (int)$code;
                    break;
                }
            }
        }
        return $matches;
    }

    /** @return array<int, array<int, string>> */
    private function statusSearchLabels(): array {
        return array(
            ABJ404_STATUS_MANUAL => array(__('Manual', '404-solution')),
            ABJ404_STATUS_AUTO => array(__('Auto', '404-solution'), __('Automatic', '404-solution')),
            ABJ404_STATUS_REGEX => array(__('Regex', '404-solution')),
        );
    }

    /** @return array<int, array<int, string>> */
    private function typeSearchLabels(): array {
        return array(
            ABJ404_TYPE_EXTERNAL => array(__('External', '404-solution')),
            ABJ404_TYPE_CAT => array(__('Category', '404-solution')),
            ABJ404_TYPE_TAG => array(__('Tag', '404-solution')),
            ABJ404_TYPE_HOME => array(__('Home', '404-solution')),
            ABJ404_TYPE_404_DISPLAYED => array(__('(404 page)', '404-solution')),
        );
    }

    /**
     * @param string $normalized
     * @param bool $singleTable When true, match the destination post type via a
     *   {wp_posts} subquery on final_dest instead of the denormalized
     *   wp_post_type column (which the single-table redirects read lacks).
     * @return array<int, string>
     */
    private function postTypeLabelPredicates(string $normalized, bool $singleTable = false): array {
        $matchingSlugs = $this->matchingPostTypeSlugs($normalized);
        if (count($matchingSlugs) === 0) {
            return array();
        }

        $quotedSlugs = array();
        foreach ($matchingSlugs as $slug) {
            // Post-type slugs are validated by register_post_type() to be
            // lowercase ASCII word/dash characters. Strip anything outside
            // that whitelist defensively before esc_sql() so a misregistered
            // (or scanner-injected) slug with invalid UTF-8 cannot reach SQL.
            $safeSlug = preg_replace('/[^a-zA-Z0-9_-]/', '', $slug);
            if ($safeSlug === null || $safeSlug === '') {
                continue;
            }
            $quotedSlugs[] = "'" . esc_sql($safeSlug) . "'";
        }
        if (count($quotedSlugs) === 0) {
            return array();
        }
        if ($singleTable) {
            // No wp_post_type column on wp_abj404_redirects: resolve the matching
            // destination posts by id. final_dest holds the numeric post id as a
            // string for POST-typed rows. The subquery only appears for a (rare)
            // post-type-label search, so it never affects the no-filter
            // single-table EXPLAIN plan asserted by the scale test.
            return array('(type = ' . (int)ABJ404_TYPE_POST
                . ' AND final_dest IN (SELECT ID FROM {wp_posts} WHERE post_type IN ('
                . implode(', ', $quotedSlugs) . ')))');
        }
        return array('(type = ' . (int)ABJ404_TYPE_POST . ' AND wp_post_type IN (' . implode(', ', $quotedSlugs) . '))');
    }

    /** @return array<int, string> */
    private function matchingPostTypeSlugs(string $normalized): array {
        $postTypes = $this->currentPostTypeSearchLabels();
        $matchingSlugs = array();
        foreach ($postTypes as $slug => $labels) {
            foreach ($labels as $label) {
                if ($this->normalizeSearchLabel($label) === $normalized) {
                    $matchingSlugs[] = (string)$slug;
                    break;
                }
            }
        }
        return array_values(array_unique($matchingSlugs));
    }

    /** @return array<string, array<int, string>> */
    private function currentPostTypeSearchLabels(): array {
        $labelsBySlug = array(
            'post' => array(__('Post', '404-solution'), 'Post'),
            'page' => array(__('Page', '404-solution'), 'Page'),
        );
        if (!function_exists('get_post_types')) {
            return $labelsBySlug;
        }

        $postTypes = get_post_types(array(), 'objects');
        if (!is_array($postTypes)) {
            return $labelsBySlug;
        }
        foreach ($postTypes as $slug => $postType) {
            $slugString = (string)$slug;
            if ($slugString === '' && is_object($postType) && property_exists($postType, 'name')
                && is_scalar($postType->name)) {
                $slugString = (string)$postType->name;
            }
            if ($slugString === '') {
                continue;
            }
            $labels = array(ucfirst(strtolower($slugString)));
            if (is_object($postType) && property_exists($postType, 'labels') && is_object($postType->labels)
                && property_exists($postType->labels, 'singular_name') && is_scalar($postType->labels->singular_name)) {
                $singular = trim((string)$postType->labels->singular_name);
                if ($singular !== '') {
                    $labels[] = $singular;
                }
            }
            $labelsBySlug[$slugString] = array_values(array_unique(array_merge(
                $labelsBySlug[$slugString] ?? array(),
                $labels
            )));
        }
        return $labelsBySlug;
    }

    private function normalizeSearchLabel(string $label): string {
        $clean = str_replace(array('*', '/', '$'), '', $label);
        return strtolower(str_replace(' ', '', trim($clean)));
    }
}
