<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/DatabaseCollationHelper.php';
require_once __DIR__ . '/../core/DatabaseMetadataLockWaitGuard.php';

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

    /** @var ABJ_404_Solution_DatabaseMetadataLockWaitGuard */
    private $metadataLockWaitGuard;

    /**
     * @param ABJ_404_Solution_Functions $functions
     * @param callable(string, array<string,mixed>): array<string,mixed> $queryRunner
     *   Runs a SQL query through the centralized error-handling pipeline and
     *   returns its result array. Supplied by DatabaseCore as a bound closure
     *   over queryAndGetResults() so this class needs no DatabaseCore reference.
     * @param ABJ_404_Solution_Logging|null $logger
     */
    public function __construct($functions, callable $queryRunner, $logger = null) {
        $this->f = $functions;
        $this->queryRunner = $queryRunner;
        $this->metadataLockWaitGuard = new ABJ_404_Solution_DatabaseMetadataLockWaitGuard($logger);
    }

    /** The engine answered, and the table is there. */
    const TABLE_PRESENT = 'present';

    /** The engine answered, and the table is not there. */
    const TABLE_ABSENT = 'absent';

    /** The engine did not answer, so presence is not known either way. */
    const TABLE_UNKNOWN = 'unknown';

    /**
     * Check if a database table exists.
     *
     * Answers the question callers who are about to CREATE or upgrade a table
     * ask -- "can I count on it being there?" -- so an unanswerable probe reads
     * as false, the same as absence. Callers deciding whether to SUPPRESS a read
     * must use {@see tableExistenceStatus()} instead, because for them the two
     * are not the same answer at all.
     *
     * @param string $tableName Full table name to check (including prefix)
     * @return bool
     */
    public function tableExists($tableName): bool {
        return $this->tableExistenceStatus((string)$tableName) === self::TABLE_PRESENT;
    }

    /**
     * Whether a table is there, is not there, or could not be asked about.
     *
     * SHOW TABLES LIKE answers with a name or with nothing, and wpdb renders
     * "nothing" as NULL -- the same NULL it returns when the query never ran at
     * all. A lost connection, a revoked SHOW grant and a driver that does not
     * speak the statement are therefore indistinguishable from a genuinely
     * missing table unless last_error is read alongside the value, which is the
     * pair getTableColumnNames() below already reads for the same reason.
     *
     * The distinction is the whole point of this method: a caller that
     * suppresses a query on "absent" turns a transient database fault into a
     * confident, query-free zero on screen if it also suppresses on "could not
     * ask" -- silent by construction, because no query means nothing for the
     * centralized error handler to log. Unknown belongs to the caller to decide,
     * and the safe decision is to attempt the read and let that handler speak.
     *
     * @param string $tableName Full table name to check (including prefix)
     * @return string One of TABLE_PRESENT, TABLE_ABSENT, TABLE_UNKNOWN.
     */
    public function tableExistenceStatus(string $tableName): string {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !is_callable(array($wpdb, 'get_var'))) {
            return self::TABLE_UNKNOWN;
        }
        // @utf8-audit: opt-out - tableExistenceStatus receives system-generated plugin table names from DAO/core callers.
        // DAO-bypass-approved: metadata table existence probe for system-generated plugin table names.
        $guarded = $this->metadataLockWaitGuard->runWithBoundedWait($wpdb, array(
            'description' => 'probing whether database table ' . $tableName . ' exists',
            'operation' => function () use ($wpdb, $tableName) {
                // DAO-bypass-approved: bounded metadata existence probe for a system-generated table name.
                return $wpdb->get_var("SHOW TABLES LIKE '" . esc_sql($tableName) . "'");
            },
        ));
        $table = $guarded['value'];
        if ($table == $tableName) {
            return self::TABLE_PRESENT;
        }
        // Read after the probe, never before: wpdb clears last_error at the
        // start of every query, so what is there now belongs to this one.
        $lastError = isset($wpdb->last_error) && is_string($wpdb->last_error) ? trim($wpdb->last_error) : '';
        return $lastError === '' ? self::TABLE_ABSENT : self::TABLE_UNKNOWN;
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
        $guarded = $this->metadataLockWaitGuard->runWithBoundedWait($wpdb, array(
            'description' => 'reading database columns for ' . $tableName,
            'operation' => function () use ($wpdb, $tableName) {
                // DAO-bypass-approved: bounded metadata column probe for a system-generated table name.
                return $wpdb->get_results("SHOW COLUMNS FROM `" . esc_sql($tableName) . "`", ARRAY_A);
            },
        ));
        $rows = $guarded['value'];
        if (!is_array($rows) || !empty($wpdb->last_error)) { return []; }
        $columns = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            // Case-insensitive: MySQL drivers return SHOW COLUMNS metadata
            // key casing inconsistently (Field vs field).
            $row = array_change_key_case($row, CASE_LOWER);
            if (isset($row['field']) && is_scalar($row['field'])) {
                $columns[] = (string)$row['field'];
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

        // Every template that uses this token pins it on an expression already
        // CONVERTed to utf8mb4, so the collation has to belong to that charset.
        // Handing back $wpdb->collate raw is what made a latin1-configured site
        // fail every one of those statements with errno 1253; the token is named
        // for the charset it is valid under so no future template can read it as
        // "whatever the site collation happens to be".
        $rawCollate = (isset($wpdb->collate) && is_scalar($wpdb->collate)) ? (string)$wpdb->collate : '';
        $replacements['{utf8mb4_collate}'] =
            ABJ_404_Solution_DatabaseCollationHelper::utf8mb4CollationOrFallback($rawCollate);

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
     * @return string A comma-separated list of quoted SQL literals, or '' when
     *   the setting is empty. Callers splice it into IN (...).
     */
    public function buildPostTypeSqlList(array $options): string {
        return $this->buildQuotedSqlList($options, 'recognized_post_types');
    }

    /**
     * @param array<string, mixed> $options
     * @return string A comma-separated list of quoted SQL literals, or '' when
     *   the setting is empty. Callers splice it into IN (...).
     */
    public function buildCategorySqlList(array $options): string {
        return $this->buildQuotedSqlList($options, 'recognized_categories');
    }

    /**
     * Turn one free-text setting into a list of quoted SQL literals safe to
     * splice into an IN (...) clause.
     *
     * The escaping lives here, at the only point that writes the quotes, and
     * not at the settings screen or the four call sites. Both of those were
     * tried by omission and failed: SettingsWordPressPolicy stores these values
     * through wp_kses_post(), an HTML sanitizer that does nothing whatever to a
     * single quote, and the call sites hand the fragment straight to
     * str_replace() against a .sql template. A value carrying a quote therefore
     * closed its own literal and ran as syntax inside three live queries
     * against wp_posts and wp_term_taxonomy -- a stored injection whose trigger
     * is separated from the write by however long it takes someone to ask for
     * published content.
     *
     * Escaped rather than allowlisted on purpose. recognized_post_types would
     * be safe under a strict [a-z0-9_-] identifier rule, but
     * recognized_categories is matched against lower(wp_terms.name) as well as
     * the taxonomy key (getPublishedCategories.sql), and a term name is display
     * text: "women's shoes" is a legitimate setting. One rule for both builders
     * is also what keeps them from drifting apart again, which is how one of
     * them ended up unescaped while three sibling list builders elsewhere in
     * the plugin were not.
     *
     * esc_sql() is the right primitive and not merely the conventional one: it
     * reaches mysqli_real_escape_string(), which honours the server's SQL mode
     * and switches to doubled quotes under NO_BACKSLASH_ESCAPES, where a
     * hand-rolled addslashes() would silently stop escaping. It is also a no-op
     * for values with nothing to escape, so ordinary post-type keys still
     * compare byte-identically.
     *
     * @param array<string, mixed> $options
     * @param string $optionName
     * @return string
     */
    private function buildQuotedSqlList(array $options, string $optionName): string {
        $rawValue = $options[$optionName] ?? '';
        // explodeNewlineOrComma() already lowercases, trims and drops empties.
        $values = $this->f->explodeNewlineOrComma(is_string($rawValue) ? $rawValue : '');

        $quoted = array();
        foreach ($values as $value) {
            // Sanitize BEFORE escaping, and do it here rather than trusting a
            // caller. esc_sql() reaches mysqli_real_escape_string(), which
            // escapes quotes and passes malformed byte sequences through
            // untouched; on a connection whose charset disagrees with those
            // bytes a truncated lead byte can absorb the escaping backslash and
            // hand the next quote to the parser as syntax. Pattern 10
            // ("invalid UTF-8 reaches SQL") is this project's own recurring
            // class, and these two settings are free-text textareas, so their
            // bytes are entirely attacker-chosen.
            //
            // It looked safe without this: explodeNewlineOrComma() lowercases,
            // and with mbstring loaded mb_strtolower() substitutes malformed
            // bytes as a side effect. MbStringAdapterPreg::strtolower() is
            // plain strtolower() and does not, so every host without the
            // mbstring extension -- a configuration this plugin supports on
            // purpose -- had no sanitization at all here. A security property
            // resting on an incidental side effect of a lowercasing call is not
            // a security property.
            $quoted[] = "'" . esc_sql($this->f->sanitizeInvalidUTF8($value)) . "'";
        }

        return implode(', ', $quoted);
    }

    /** @return void */
    public function setSqlBigSelects(): void {
        $ignoreErrorsOptions = array('log_errors' => false);
        ($this->queryRunner)("set session max_join_size = 18446744073709551615",
            $ignoreErrorsOptions);
        ($this->queryRunner)("set session sql_big_selects = 1", $ignoreErrorsOptions);
    }
}
