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
 *   4. Auto-recovering from a collation-mismatch error at query time: under a
 *      static recursion guard plus a 1-hour cooldown, run correctCollations()
 *      and retry the failing query.
 *
 * This class holds no DatabaseCore back-reference. It receives:
 *   - a query-runner callable bound over DatabaseCore::queryAndGetResults
 *     (signature: function(string, array<string,mixed>): array<string,mixed>);
 *   - a DDL reader callable bound over the table-name resolver
 *     (signature: function(string): string);
 *   - getter/setter callables for runtime flags
 *     (signatures: function(string): mixed, function(string, mixed, int): void);
 *   - a result-harvester callable bound over DatabaseWpdbResultHarvester
 *     (signature: function(array<string,mixed>): void, by-reference);
 *   - the plugin logger and an optional clock.
 *
 * The recursion guard is a static property on this class (it must survive across
 * helper invocations within a single request) and is functionally identical to
 * the former DatabaseCore::$collationRecoveryInProgress.
 */
class ABJ_404_Solution_DatabaseCollationHelper {

    /** @var int Cooldown after a collation-recovery attempt (seconds). */
    const COLLATION_RECOVERY_COOLDOWN_SECONDS = 3600;

    /** @var bool Prevent recursive collation auto-recovery within one request. */
    private static $collationRecoveryInProgress = false;

    /** @var callable(string, array<string,mixed>): array<string,mixed> */
    private $queryRunner;

    /** @var callable(string): string */
    private $ddlReader;

    /** @var callable(string): mixed */
    private $runtimeFlagGetter;

    /** @var callable(string, mixed, int): void */
    private $runtimeFlagSetter;

    /** @var callable(array<string,mixed>): void */
    private $resultHarvester;

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
     * @param callable(array<string,mixed>): void $resultHarvester
     *   Copies wpdb->last_error / rows_affected / insert_id into the result array,
     *   by reference. Bound by DatabaseCore over DatabaseWpdbResultHarvester.
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_Clock|null $clock Optional; lazily resolved when null.
     */
    public function __construct(
        callable $queryRunner,
        callable $ddlReader,
        callable $runtimeFlagGetter,
        callable $runtimeFlagSetter,
        callable $resultHarvester,
        $logger,
        $clock = null
    ) {
        $this->queryRunner = $queryRunner;
        $this->ddlReader = $ddlReader;
        $this->runtimeFlagGetter = $runtimeFlagGetter;
        $this->runtimeFlagSetter = $runtimeFlagSetter;
        $this->resultHarvester = $resultHarvester;
        $this->logger = $logger;
        $this->clock = $clock;
    }

    /**
     * Reset the static recursion guard. Intended for test setUp/tearDown only.
     *
     * @return void
     */
    public static function resetRecursionGuardForTests(): void {
        self::$collationRecoveryInProgress = false;
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
        if (!is_string($collation) || $collation === '') {
            return '';
        }
        $sanitized = preg_replace('/[^A-Za-z0-9_]/', '', $collation);
        return $sanitized !== null ? $sanitized : '';
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
        if (preg_match('/COLLATE[= ]([A-Za-z0-9_]+)/i', $ddl, $m)) {
            $sanitized = $this->sanitizeCollationIdentifier($m[1]);
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
     * Return the preferred utf8mb4 collation for this wpdb connection.
     *
     * If wpdb->collate already names a utf8mb4_* collation, use it; otherwise
     * fall back to utf8mb4_unicode_ci.
     *
     * @return string
     */
    public function getPreferredUtf8mb4Collation(): string {
        global $wpdb;
        if (isset($wpdb) && isset($wpdb->collate) && !empty($wpdb->collate)) {
            $wpdbCollation = $this->sanitizeCollationIdentifier((string)$wpdb->collate);
            if ($wpdbCollation !== '' && stripos($wpdbCollation, 'utf8mb4') !== false) {
                return $wpdbCollation;
            }
        }
        return 'utf8mb4_unicode_ci';
    }

    /**
     * Auto-recover from a collation mismatch detected at query time.
     *
     * Under a static recursion guard and a 1-hour cooldown, invokes
     * correctCollations() to converge plugin-table collations, then flushes
     * the wpdb connection and retries the original query.
     *
     * @param string $query
     * @param array<string, mixed> $result Passed by reference.
     * @param bool $producesRows Whether the query returns result rows.
     * @param 'OBJECT'|'OBJECT_K'|'ARRAY_A'|'ARRAY_N' $resultType wpdb output type for get_results().
     * @return void
     */
    public function recoverFromCollationMismatchAndRetry(string $query, array &$result, bool $producesRows, string $resultType): void {
        if (self::$collationRecoveryInProgress) {
            return;
        }

        $cooldownKey = 'abj404_collation_recovery_cooldown';
        $cooldownUntil = ($this->runtimeFlagGetter)($cooldownKey);
        $onCooldown = is_scalar($cooldownUntil) && (int)$cooldownUntil > $this->clock()->now();

        if (!$onCooldown) {
            self::$collationRecoveryInProgress = true;
            try {
                $this->logger->infoMessage("Collation mismatch detected: running correctCollations() to converge plugin tables."); // allow-em-dash: original string from DataAccessTrait_Maintenance had em dash, replaced with colon
                if (class_exists('ABJ_404_Solution_DatabaseUpgradesEtc')) {
                    $upgrades = abj_service('database_upgrades');
                    if (method_exists($upgrades, 'correctCollations')) {
                        $upgrades->components()->collationDriftUpgrade()->correctCollations();
                    }
                }
            } catch (Throwable $e) {
                $this->logger->warn("correctCollations() threw during collation auto-recovery: " . $e->getMessage());
            } finally {
                self::$collationRecoveryInProgress = false;
                ($this->runtimeFlagSetter)(
                    $cooldownKey,
                    $this->clock()->now() + self::COLLATION_RECOVERY_COOLDOWN_SECONDS,
                    self::COLLATION_RECOVERY_COOLDOWN_SECONDS
                );
            }
        }

        global $wpdb;
        /** @var wpdb $wpdb */
        $wpdb->flush();
        if ($producesRows) {
            $result['rows'] = $wpdb->get_results($query, $resultType);
        } else {
            $wpdb->query($query);
            $result['rows'] = array();
        }
        ($this->resultHarvester)($result);

        if ($result['last_error'] === '') {
            $this->logger->debugMessage("Collation auto-recovery succeeded; query retry passed.");
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
