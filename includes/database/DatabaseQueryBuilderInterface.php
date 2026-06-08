<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reusable SQL-string builders shared by every DAO query that filters by
 * post type or category, and the session-tuning hook that lets large
 * queries run.
 */
interface ABJ_404_Solution_DatabaseQueryBuilderInterface {

    /**
     * Build a SQL-safe list from recognized_post_types option.
     *
     * @param array<string, mixed> $options
     * @return string
     */
    public function buildPostTypeSqlList(array $options): string;

    /**
     * Build a SQL-safe list from recognized_categories option.
     *
     * @param array<string, mixed> $options
     * @return string
     */
    public function buildCategorySqlList(array $options): string;

    /**
     * Set SQL session variables to allow large queries.
     *
     * @return void
     */
    public function setSqlBigSelects(): void;
}
