<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves and reconciles MySQL collation for plugin tables and columns.
 *
 * Extracted from DatabaseCore as part of the (3/6) DatabaseCore decomposition.
 * Owns four cohesive responsibilities that all turn on knowing the effective
 * collation of plugin tables/columns so cross-collation comparisons do not
 * blow up plugin queries:
 *
 *   1. Sanitizing raw collation identifiers (strip non-word characters so they
 *      are safe to interpolate in SQL).
 *   2. Discovering the effective table-level and column-level collation via
 *      SHOW CREATE TABLE and information_schema, with safe fallbacks.
 *   3. Resolving the preferred utf8mb4 collation from the wpdb connection.
 *   4. Responding to a query-time collation mismatch by scheduling a
 *      schema-wide correction outside the foreground request.
 *
 * This class holds no DatabaseCore back-reference. It receives:
 *   - a query-runner callable bound over DatabaseCore::queryAndGetResults
 *     (signature: function(string, array<string,mixed>): array<string,mixed>);
 *   - a DDL reader callable bound over the table-name resolver
 *     (signature: function(string): string);
 *   - getter/setter callables for runtime flags
 *     (signatures: function(string): mixed, function(string, mixed, int): void);
 *   - the plugin logger and an optional clock.
 *
 * The recursion guard is a static property on this class (it must survive across
 * helper invocations within a single request) and is functionally identical to
 * the former DatabaseCore::$collationRecoveryInProgress.
 */
class ABJ_404_Solution_DatabaseCollationHelper {

    /** @var int Cooldown after a collation-recovery attempt (seconds). */
    const COLLATION_RECOVERY_COOLDOWN_SECONDS = 3600;

    /**
     * @var string Collation used whenever the site's own cannot be honoured.
     * Present on every MySQL 5.5.3+ and MariaDB build the plugin supports.
     */
    const DEFAULT_UTF8MB4_COLLATION = 'utf8mb4_unicode_ci';

    /** @var bool Prevent recursive collation-repair scheduling within one request. */
    private static $collationSchedulingInProgress = false;

    /** @var callable(string, array<string,mixed>): array<string,mixed> */
    private $queryRunner;

    /** @var callable(string): string */
    private $ddlReader;

    /** @var callable(string): mixed */
    private $runtimeFlagGetter;

    /** @var callable(string, mixed, int): void */
    private $runtimeFlagSetter;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_Clock|null */
    private $clock;

    /**
     * @param callable(string, array<string,mixed>): array<string,mixed> $queryRunner
     *   Runs a SQL query through the centralized error-handling pipeline and
     *   returns its result array. Bound by DatabaseCore over queryAndGetResults().
     * @param callable(string): string $ddlReader
     *   Returns the SHOW CREATE TABLE output for the given table name (or '').
     * @param callable(string): mixed $runtimeFlagGetter
     *   Returns the current value of a runtime flag (transient with option fallback).
     * @param callable(string, mixed, int): void $runtimeFlagSetter
     *   Persists a runtime-flag value with a TTL.
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_Clock|null $clock Optional; lazily resolved when null.
     */
    public function __construct(
        callable $queryRunner,
        callable $ddlReader,
        callable $runtimeFlagGetter,
        callable $runtimeFlagSetter,
        $logger,
        $clock = null
    ) {
        $this->queryRunner = $queryRunner;
        $this->ddlReader = $ddlReader;
        $this->runtimeFlagGetter = $runtimeFlagGetter;
        $this->runtimeFlagSetter = $runtimeFlagSetter;
        $this->logger = $logger;
        $this->clock = $clock;
    }

    /**
     * Reset the static recursion guard. Intended for test setUp/tearDown only.
     *
     * @return void
     */
    public static function resetRecursionGuardForTests(): void {
        self::$collationSchedulingInProgress = false;
    }

    /**
     * Sanitize a raw collation identifier so it is safe to interpolate in SQL.
     *
     * Strips every character that is not [A-Za-z0-9_].
     *
     * @param string $collation
     * @return string
     */
    public function sanitizeCollationIdentifier($collation): string {
        return self::sanitizeCollationName($collation);
    }

    /**
     * Sanitize a raw collation identifier (pure; no connection required).
     *
     * The instance method above delegates here so producers that own no
     * DatabaseCollationHelper -- the SQL template resolver and the admin table
     * query policy -- reach the same sanitizer instead of copying the regex.
     *
     * @param mixed $collation Raw identifier from wpdb, information_schema or DDL.
     * @return string Sanitized identifier, or '' when nothing survives.
     */
    public static function sanitizeCollationName($collation): string {
        if (!is_string($collation) || $collation === '') {
            return '';
        }
        $sanitized = preg_replace('/[^A-Za-z0-9_]/', '', $collation);
        return $sanitized !== null ? $sanitized : '';
    }

    /**
     * A collation guaranteed valid for CHARACTER SET utf8mb4.
     *
     * The one derivation every caller that CONVERTs (or CASTs) an expression to
     * a hard-coded utf8mb4 must use. MySQL rejects the statement outright --
     * "COLLATION 'x' is not valid for CHARACTER SET 'utf8mb4'", errno 1253 --
     * when the two halves name different families, and $wpdb->collate carries
     * whatever DB_COLLATE says, which on installs whose wp-config predates
     * utf8mb4 is routinely a latin1 or utf8mb3 collation.
     *
     * A site collation already in the utf8mb4 family is kept, so ordering and
     * comparison semantics stay the site's own; anything else is replaced,
     * because there is no way to honour it under a utf8mb4 expression at all.
     *
     * @param mixed $rawCollation Typically $wpdb->collate.
     * @return string A utf8mb4_* collation name, safe to interpolate.
     */
    public static function utf8mb4CollationOrFallback($rawCollation): string {
        $sanitized = self::sanitizeCollationName($rawCollation);
        if (self::isUtf8mb4Collation($sanitized)) {
            return $sanitized;
        }
        return self::DEFAULT_UTF8MB4_COLLATION;
    }

    /**
     * Whether a collation belongs to the utf8mb4 family.
     *
     * The sole owner of that rule. Callers that need the question answered but
     * keep their own control flow around the answer (pick this collation, or
     * fall back to a different source; emit this clause, or a simpler one) ask
     * here instead of re-testing the name, because a second copy of the rule is
     * how errno 1253 reached production in the first place.
     *
     * MySQL collation names are `<charset>_<...>`, so family membership is a
     * PREFIX test. A substring test also accepts a name that merely contains
     * "utf8mb4", which would pair a foreign collation with a hard-coded
     * `CHARACTER SET utf8mb4` -- the precise statement the engine rejects.
     *
     * @param mixed $collation Raw identifier from wpdb, information_schema or DDL.
     * @return bool
     */
    public static function isUtf8mb4Collation($collation): bool {
        $sanitized = self::sanitizeCollationName($collation);
        return $sanitized !== '' && stripos($sanitized, 'utf8mb4') === 0;
    }

    /**
     * The charset a collation belongs to, returned together with it.
     *
     * The mirror of {@see utf8mb4CollationOrFallback} for callers that must
     * honour a specific collation (a column's own, so a JOIN against it stays
     * sargable) and therefore cannot choose the charset independently. Returning
     * the pair rather than the charset alone is the point: a caller physically
     * cannot take one half from here and the other from somewhere else.
     *
     * MySQL collation names are `<charset>_<...>`, so the charset is the segment
     * before the first underscore; `binary` names both and has no underscore,
     * which the same rule handles. An unusable input falls back to the utf8mb4
     * pair rather than to a half-formed one.
     *
     * @param mixed $rawCollation Collation to honour (column, table or wpdb).
     * @return array{charset: string, collation: string} Consistent pair.
     */
    public static function charsetCollationPair($rawCollation): array {
        $collation = self::sanitizeCollationName($rawCollation);
        $charset = $collation === '' ? '' : self::sanitizeCollationName(explode('_', $collation, 2)[0]);
        if ($charset === '') {
            return array('charset' => 'utf8mb4', 'collation' => self::DEFAULT_UTF8MB4_COLLATION);
        }
        return array('charset' => $charset, 'collation' => $collation);
    }

    /**
     * Get the table-level default collation for a given table.
     *
     * Queries SHOW CREATE TABLE for the COLLATE clause; falls back to
     * information_schema.TABLES.TABLE_COLLATION; then to utf8mb4_unicode_ci.
     * Result is validated through sanitizeCollationIdentifier().
     *
     * @param string $tableName Fully-qualified table name (including prefix).
     * @return string
     */
    public function getTableCollationString(string $tableName): string {
        $fallback = 'utf8mb4_unicode_ci';
        $ddl = ($this->ddlReader)($tableName);

        // The TABLE-level collation is a table option, written after the
        // closing paren of the body. A column may carry a COLLATE of its own
        // and columns come first, so reading the first COLLATE anywhere in the
        // statement answers a different question than the one asked -- and this
        // method's answer decides the collation every cross-collation
        // comparison is coerced to. The plugin's own staging templates now
        // state a per-column COLLATE, so the two are not hypothetically
        // distinguishable, they routinely differ.
        $tableDefault = ABJ_404_Solution_CreateTableOptionsParser::tableCharsetAndCollation($ddl);
        $declaredCollation = ($tableDefault === null) ? null : $tableDefault['collation'];
        if ($declaredCollation !== null) {
            $sanitized = $this->sanitizeCollationIdentifier($declaredCollation);
            return $sanitized !== '' ? $sanitized : $fallback;
        }
        global $wpdb;
        if (isset($wpdb) && method_exists($wpdb, 'prepare')) {
            /** @var wpdb $wpdb */
            $sql = $wpdb->prepare(
                "SELECT TABLE_COLLATION FROM information_schema.TABLES "
                . "WHERE TABLE_SCHEMA = DATABASE() "
                . "AND TABLE_NAME = %s "
                . "LIMIT 1",
                $tableName
            );
            if (is_string($sql) && $sql !== '') {
                $result = ($this->queryRunner)($sql, array('log_errors' => false));
                $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
                if (!empty($rows) && is_array($rows[0])) {
                    $row = array_change_key_case($rows[0]);
                    $collation = $row['table_collation'] ?? '';
                    if (is_string($collation) && $collation !== '') {
                        $sanitized = $this->sanitizeCollationIdentifier($collation);
                        return $sanitized !== '' ? $sanitized : $fallback;
                    }
                }
            }
        }
        return $fallback;
    }

    /**
     * Get the column-level collation for a specific column in a table.
     *
     * Queries information_schema.COLUMNS for the COLLATION_NAME. Falls back
     * to getTableCollationString() if the column query fails, then ultimately
     * to utf8mb4_unicode_ci. Result is validated through
     * sanitizeCollationIdentifier().
     *
     * @param string $tableName  Fully-qualified table name (including prefix).
     * @param string $columnName Column name to look up.
     * @return string
     */
    public function getColumnCollationString(string $tableName, string $columnName): string {
        $fallback = 'utf8mb4_unicode_ci';
        global $wpdb;
        if (!isset($wpdb) || !method_exists($wpdb, 'prepare')) {
            return $this->getTableCollationString($tableName);
        }
        /** @var wpdb $wpdb */
        $sql = $wpdb->prepare(
            "SELECT COLLATION_NAME FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_NAME = %s "
            . "AND COLUMN_NAME = %s "
            . "LIMIT 1",
            $tableName,
            $columnName
        );
        if (!is_string($sql) || $sql === '') {
            return $this->getTableCollationString($tableName);
        }
        $result = ($this->queryRunner)($sql, array('log_errors' => false));
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows) || !is_array($rows[0])) {
            return $this->getTableCollationString($tableName);
        }
        $row = array_change_key_case($rows[0]);
        $collation = $row['collation_name'] ?? '';
        if (!is_string($collation) || $collation === '') {
            return $this->getTableCollationString($tableName);
        }
        $sanitized = $this->sanitizeCollationIdentifier($collation);
        return $sanitized !== '' ? $sanitized : $fallback;
    }

    /**
     * Coerce a SQL expression to the charset and collation of an indexed
     * comparison column without wrapping that indexed column.
     *
     * Mixed-collation sites are common during upgrades and partial restores.
     * Applying CONVERT/COLLATE only to the non-indexed operand makes the
     * equality deterministic while leaving the target column sargable.
     * Callers must supply an internally constructed SQL expression; this
     * method sanitizes metadata identifiers, not arbitrary SQL text.
     *
     * @param string $expression SQL expression used opposite the target column.
     * @param array{table: string, column: string} $targetColumn Indexed target column metadata.
     * @return string
     */
    public function coerceExpressionToColumnCollation(string $expression, array $targetColumn): string {
        $tableName = $targetColumn['table'] ?? '';
        $columnName = $targetColumn['column'] ?? '';
        if ($tableName === '' || $columnName === '') {
            throw new InvalidArgumentException('Target table and column are required for a collation-safe SQL comparison.');
        }

        $rawCollation = $this->getColumnCollationString($tableName, $columnName);
        if (self::sanitizeCollationName($rawCollation) === '') {
            throw new InvalidArgumentException('Target column collation could not be converted to a safe SQL identifier.');
        }
        $pair = self::charsetCollationPair($rawCollation);

        return 'CONVERT(' . $expression . ' USING ' . $pair['charset'] . ') COLLATE ' . $pair['collation'];
    }

    /**
     * Return the preferred utf8mb4 collation for this wpdb connection.
     *
     * If wpdb->collate already names a utf8mb4_* collation, use it; otherwise
     * fall back to utf8mb4_unicode_ci.
     *
     * @return string
     */
    public function getPreferredUtf8mb4Collation(): string {
        global $wpdb;
        $rawCollation = (isset($wpdb) && isset($wpdb->collate) && is_scalar($wpdb->collate))
            ? (string)$wpdb->collate : '';
        return self::utf8mb4CollationOrFallback($rawCollation);
    }

    /**
     * Schedule broad collation correction outside the foreground request.
     *
     * correctCollations() discovers every plugin table and may issue ALTER
     * TABLE ... CONVERT for each drifted table. Running that work inline can
     * exhaust an admin AJAX request on large sites, so the query that detected
     * the mismatch keeps its normal degraded error result while a dedicated,
     * deduplicated WP-Cron event performs the repair. Scheduler failures remain
     * retryable and are logged with the adapter's underlying failure detail.
     *
     * @return void
     */
    public function scheduleCollationRecovery(): void {
        if (self::$collationSchedulingInProgress) {
            return;
        }

        $cooldownKey = 'abj404_collation_recovery_cooldown';
        $cooldownUntil = ($this->runtimeFlagGetter)($cooldownKey);
        $onCooldown = is_scalar($cooldownUntil) && (int)$cooldownUntil > $this->clock()->now();

        if ($onCooldown) {
            return;
        }

        self::$collationSchedulingInProgress = true;
        try {
            $scheduler = abj_cron_scheduler();
            $scheduled = $scheduler->scheduleSingleIfMissing(
                ABJ_404_Solution_CronScheduler::HOOK_REPAIR_COLLATIONS,
                1
            );
            if ($scheduled) {
                ($this->runtimeFlagSetter)(
                    $cooldownKey,
                    $this->clock()->now() + self::COLLATION_RECOVERY_COOLDOWN_SECONDS,
                    self::COLLATION_RECOVERY_COOLDOWN_SECONDS
                );
                $this->logger->infoMessage(
                    'Collation mismatch detected: schema correction scheduled for background repair.'
                );
                return;
            }
            $this->logger->warn(
                'Could not schedule background collation repair: ' . $scheduler->lastFailureDetail()
            );
        } catch (Throwable $e) {
            $this->logger->warn(
                'Could not schedule background collation repair: ' . $e->getMessage()
            );
        } finally {
            self::$collationSchedulingInProgress = false;
        }
    }

    /**
     * Lazily resolve the clock instance.
     *
     * @return ABJ_404_Solution_Clock
     */
    private function clock() {
        if ($this->clock === null) {
            if (class_exists('ABJ_404_Solution_ServiceContainer')) {
                $resolved = ABJ_404_Solution_ServiceContainer::safeGet('clock');
                if ($resolved instanceof ABJ_404_Solution_Clock) {
                    $this->clock = $resolved;
                    return $this->clock;
                }
            }
            $this->clock = new ABJ_404_Solution_SystemClock();
        }
        return $this->clock;
    }
}
