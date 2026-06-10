<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves plugin table names and reads table schema metadata.
 *
 * Owns the pure, stateless half of the former DatabaseCore: expanding
 * {wp_*} placeholders to physical prefixed names, building plugin table
 * names from suffixes, checking table existence, reading column names and
 * CREATE TABLE DDL, and producing option-derived SQL value lists.
 *
 * This class holds no error-handling state. The two methods that must run
 * SQL (getCreateTableDDL, setSqlBigSelects) receive a query-runner callable
 * at construction time, so the resolver depends on a function rather than
 * on DatabaseCore itself (no cyclic coupling). The callable signature is
 * the same as DatabaseCore::queryAndGetResults():
 *   function(string $query, array<string,mixed> $options): array<string,mixed>
 */
class ABJ_404_Solution_DatabaseTableNameResolver {

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var callable(string, array<string,mixed>): array<string,mixed> */
    private $queryRunner;

    /**
     * @param ABJ_404_Solution_Functions $functions
     * @param callable(string, array<string,mixed>): array<string,mixed> $queryRunner
     *   Runs a SQL query through the centralized error-handling pipeline and
     *   returns its result array. Supplied by DatabaseCore as a bound closure
     *   over queryAndGetResults() so this class needs no DatabaseCore reference.
     */
    public function __construct($functions, callable $queryRunner) {
        $this->f = $functions;
        $this->queryRunner = $queryRunner;
    }

    /**
     * Check if a database table exists.
     *
     * @param string $tableName Full table name to check (including prefix)
     * @return bool
     */
    public function tableExists($tableName): bool {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !is_callable(array($wpdb, 'get_var'))) {
            return false;
        }
        // @utf8-audit: opt-out - tableExists receives system-generated plugin table names from DAO/core callers.
        // DAO-bypass-approved: metadata table existence probe for system-generated plugin table names.
        $table = $wpdb->get_var("SHOW TABLES LIKE '" . esc_sql($tableName) . "'");
        return ($table == $tableName);
    }

    /**
     * Get the column names of an actual database table via SHOW COLUMNS.
     *
     * @param string $tableName Full table name (including prefix)
     * @return array<int, string>
     */
    public function getTableColumnNames(string $tableName): array {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !is_callable(array($wpdb, 'get_results'))) { return []; }
        // @utf8-audit: opt-out - getTableColumnNames receives system-generated plugin table names only.
        // DAO-bypass-approved: metadata column probe for system-generated plugin table names.
        $rows = $wpdb->get_results("SHOW COLUMNS FROM `" . esc_sql($tableName) . "`", ARRAY_A);
        if (!is_array($rows) || !empty($wpdb->last_error)) { return []; }
        $columns = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['Field']) && is_scalar($row['Field'])) {
                $columns[] = (string)$row['Field'];
            }
        }
        return $columns;
    }

    /**
     * @param string $query
     * @return string
     */
    public function doTableNameReplacements($query): string {
        global $wpdb;

        $replacements = array();
        $tables = (isset($wpdb->tables) && is_array($wpdb->tables)) ? $wpdb->tables : array();
        $prefix = isset($wpdb->prefix) && is_scalar($wpdb->prefix) ? (string)$wpdb->prefix : 'wp_';
        foreach ($tables as $tableName) {
            if (!is_scalar($tableName)) { continue; }
            $tableNameStr = (string)$tableName;
            $replacements['{wp_' . $tableNameStr . '}'] = $prefix . $tableNameStr;
        }
        $replacements['{wp_users}'] = (isset($wpdb->users) && is_scalar($wpdb->users))
            ? (string)$wpdb->users : ($prefix . 'users');
        $replacements['{wp_prefix}'] = $prefix;
        $replacements['{wp_prefix_lower}'] = $this->getLowercasePrefix();

        $wpdbCollate = 'utf8mb4_unicode_ci';
        if (isset($wpdb->collate) && !empty($wpdb->collate)) {
            $sanitized = preg_replace('/[^A-Za-z0-9_]/', '', $wpdb->collate);
            if ($sanitized !== '' && $sanitized !== null) {
                $wpdbCollate = $sanitized;
            }
        }
        $replacements['{wpdb_collate}'] = $wpdbCollate;

        $query = $this->f->str_replace(array_keys($replacements), array_values($replacements), $query);

        $fpreg = ABJ_404_Solution_FunctionsPreg::getInstance();
        $query = $fpreg->regexReplace('[{]wp_abj404_(.*?)[}]',
            $this->getLowercasePrefix() . "abj404_\\1", $query);

        return $query !== null ? $query : '';
    }

    /** @return string */
    public function getLowercasePrefix(): string {
        global $wpdb;
        return $this->f->strtolower($wpdb->prefix ?? 'wp_');
    }

    /**
     * @param string $tableSuffix
     * @return string
     */
    public function getPrefixedTableName($tableSuffix): string {
        return $this->getLowercasePrefix() . ltrim($tableSuffix, '_');
    }

    /**
     * @param string $tableName
     * @return string
     */
    public function getCreateTableDDL($tableName): string {
        $query = "show create table " . $tableName;
        $result = ($this->queryRunner)($query, array('log_errors' => false, 'skip_repair' => true));
        $rows = $result['rows'];
        if (!is_array($rows) || empty($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            return '';
        }
        $row1 = array_values($rows[0]);
        $existingTableSQL = $row1[1] ?? '';
        return is_scalar($existingTableSQL) ? (string)$existingTableSQL : '';
    }

    /**
     * @param array<string, mixed> $options
     * @return string
     */
    public function buildPostTypeSqlList(array $options): string {
        $rptVal = $options['recognized_post_types'] ?? '';
        $postTypes = $this->f->explodeNewlineOrComma(is_string($rptVal) ? $rptVal : '');
        $recognizedPostTypes = '';
        foreach ($postTypes as $postType) {
            $recognizedPostTypes .= "'" . trim($this->f->strtolower($postType)) . "', ";
        }
        return rtrim($recognizedPostTypes, ", ");
    }

    /**
     * @param array<string, mixed> $options
     * @return string
     */
    public function buildCategorySqlList(array $options): string {
        $rcVal = $options['recognized_categories'] ?? '';
        $categories = $this->f->explodeNewlineOrComma(is_string($rcVal) ? $rcVal : '');
        $recognizedCategories = '';
        foreach ($categories as $category) {
            $recognizedCategories .= "'" . trim($this->f->strtolower($category)) . "', ";
        }
        return rtrim($recognizedCategories, ", ");
    }

    /** @return void */
    public function setSqlBigSelects(): void {
        $ignoreErrorsOptions = array('log_errors' => false);
        ($this->queryRunner)("set session max_join_size = 18446744073709551615",
            $ignoreErrorsOptions);
        ($this->queryRunner)("set session sql_big_selects = 1", $ignoreErrorsOptions);
    }
}
