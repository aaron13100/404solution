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
     * @return string
     */
    public function buildFilterTextClause(string $sub, array $tableOptions): string {
        $rawFilterText = $tableOptions['filterText'] ?? '';
        $rawFilterText = is_string($rawFilterText) ? $rawFilterText : '';
        if ($rawFilterText === '') {
            return '';
        }

        $filterText = $this->sanitizeFilterText($rawFilterText);
        if ($sub === 'abj404_redirects') {
            return "AND REPLACE(LOWER(CONCAT(url, '////', status_for_view, '////',"
                . " type_for_view, '////', dest_for_view, '////', code)), ' ', '')"
                . " LIKE REPLACE(LOWER('%" . $filterText . "%'), ' ', '')";
        }
        if ($sub === 'abj404_captured') {
            return "AND REPLACE(LOWER(url), ' ', '')"
                . " LIKE REPLACE(LOWER('%" . $filterText . "%'), ' ', '')";
        }
        return 'AND 0 = 1';
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

    /** @return array<string, string> */
    public function viewBuildOnlyTranslations(): array {
        return array(
            '{ABJ404_STATUS_MANUAL_text}' => __('Manual', '404-solution'),
            '{ABJ404_STATUS_AUTO_text}'   => __('Automatic', '404-solution'),
            '{ABJ404_STATUS_REGEX_text}'  => __('Regex', '404-solution'),
            '{ABJ404_TYPE_EXTERNAL_text}' => __('External', '404-solution'),
            '{ABJ404_TYPE_CAT_text}'      => __('Category', '404-solution'),
            '{ABJ404_TYPE_TAG_text}'      => __('Tag', '404-solution'),
            '{ABJ404_TYPE_HOME_text}'     => __('Home', '404-solution'),
            '{ABJ404_TYPE_404_DISPLAYED_text}' => __('(404 page)', '404-solution'),
            '{ABJ404_TYPE_SPECIAL_text}'  => __('Special', '404-solution'),
        );
    }
}
