<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The canonical definition of a table index -- its ordered column list, the
 * per-column prefix lengths, and uniqueness -- expressed identically whether it
 * was read from the live engine (SHOW INDEX) or parsed out of one of the
 * plugin's create*Table.sql templates, so the two can actually be compared.
 *
 * This module owns the live-engine reader and the comparison. Turning DDL
 * SOURCE TEXT into a spec is a regex parser over SQL rather than a
 * normalization of driver metadata, and lives next door in
 * {@see ABJ_404_Solution_CreateTableIndexParser}; the comparison below consumes
 * its output, so both representations still reduce to a signature here, which
 * is the property the paragraph below is about.
 *
 * Why this exists as its own module: the plugin used to hold those two halves
 * in different places and never compared them. The upgrade path asked "is there
 * an index with this NAME?" (SHOW INDEX ... WHERE Key_name = ...) and the admin
 * read gate asked the same name-only question of its own SHOW INDEX probe.
 * Neither looked at what the index actually contained. That is not a
 * hypothetical gap: MySQL and MariaDB silently REMOVE a dropped column from
 * every index that names it, keeping the index and its name (an index whose
 * only column is dropped is dropped with it). So a table that ran a plugin
 * build whose DDL predated a column -- a downgrade, a rolled-back beta -- comes
 * back with, for example, `idx_status_disabled_logshits_id` still present but
 * defined as (status, disabled, id). Every later upgrade saw the name, declared
 * the index present, and moved on; the admin sort it was built for filesorted
 * the whole table forever after. One module owning BOTH representations is what
 * makes the comparison the natural operation instead of an optional extra.
 *
 * Everything here is read-only: it reads schema metadata and normalizes it. It
 * issues no DDL and makes no repair decisions -- that is
 * {@see ABJ_404_Solution_DatabaseUpgradeIndexes}'s job.
 *
 * Normalization follows defensive philosophy #3 (normalize before comparing)
 * and #5 (case-insensitive metadata access): SHOW INDEX column names come back
 * in varying case depending on the driver, and index/column names are compared
 * case-insensitively because MySQL identifiers are.
 */
class ABJ_404_Solution_TableIndexDefinitions {

    /** @var ABJ_404_Solution_DatabaseQueryInterface */
    private $dbCore;

    /**
     * Error logging is intentionally delegated to queryAndGetResults (the
     * centralized DAO error handler), so no logger dependency is held here.
     *
     * @param ABJ_404_Solution_DatabaseQueryInterface $dbCore
     */
    public function __construct(ABJ_404_Solution_DatabaseQueryInterface $dbCore) {
        $this->dbCore = $dbCore;
    }

    /**
     * Every index the engine reports for a table, keyed by LOWERCASED index
     * name, each with its columns in Seq_in_index order.
     *
     * Returns NULL when the probe could not be answered (missing table, denied
     * permission, dead connection). That is deliberately distinct from an empty
     * array: "I could not read the schema" and "this table has no indexes" call
     * for opposite responses, and conflating them is how a repair pass would
     * decide every index on an unreadable table is missing and start issuing
     * DDL against it. Callers must handle null explicitly.
     *
     * @param string $tableName Fully-qualified table name.
     * @return array<string, array{name: string, columns: array<int, array{column: string, prefix: int|null}>, unique: bool}>|null
     */
    public function readLive(string $tableName) {
        $quotedTableName = self::quoteIdentifier($tableName);
        if ($quotedTableName === null) {
            // Not a name we can safely put in a statement. Report it the same
            // way an unanswerable probe is reported -- "unknown", not "no
            // indexes" -- so no caller reads it as a table needing every index
            // rebuilt.
            return null;
        }
        $result = $this->dbCore->queryAndGetResults("SHOW INDEX FROM " . $quotedTableName,
            array('log_errors' => false));
        $lastError = isset($result['last_error']) && is_scalar($result['last_error'])
            ? (string)$result['last_error'] : '';
        if ($lastError !== '' || !is_array($result['rows'] ?? null)) {
            return null;
        }
        return self::fromShowIndexRows(array_values($result['rows']));
    }

    /**
     * A table name rendered as a quoted SQL identifier, or null when it is not
     * one.
     *
     * `SHOW INDEX` takes an identifier, which cannot be a bound parameter, so
     * the name is validated against the identifier grammar and then quoted
     * per segment. Quoting the whole of "db.table" as one unit would name a
     * table with a dot in it, which is why the split is not cosmetic.
     * Unquoted MySQL identifiers are ASCII letters, digits, underscore and
     * dollar, plus U+0080 and above; anything else (a backtick, a space, a
     * semicolon) means this is not a plugin table name and the probe is
     * refused rather than escaped into something plausible.
     *
     * @param string $tableName
     * @return string|null
     */
    private static function quoteIdentifier(string $tableName): ?string {
        if ($tableName === '') {
            return null;
        }

        $segments = explode('.', $tableName);
        $quoted = array();
        foreach ($segments as $segment) {
            if ($segment === '' || !preg_match('/^[A-Za-z0-9_$\x{0080}-\x{FFFF}]+$/u', $segment)) {
                return null;
            }
            $quoted[] = '`' . $segment . '`';
        }

        return implode('.', $quoted);
    }

    /**
     * Whether a live definition was fully describable from the rows the engine
     * reported.
     *
     * A false answer means "this index exists, but we cannot say what it
     * contains" -- so it must be compared against nothing and repaired by
     * nothing. Callers that treat an absent index as missing must consult this
     * before concluding anything about an index that IS present.
     *
     * @param array{describable?: bool} $definition
     * @return bool
     */
    public static function isDescribable(array $definition): bool {
        return !isset($definition['describable']) || $definition['describable'] === true;
    }

    /**
     * Assemble SHOW INDEX rows into per-index definitions.
     *
     * Split from readLive() so the row-shape normalization is exercisable
     * against captured driver output (the row key case, and whether Sub_part
     * arrives as null / '' / '0' / '190', differ across drivers and engines)
     * without a live server.
     *
     * Returns NULL when a row cannot be read at all, which is a failed probe
     * rather than a description of the table -- the same "unknown, not empty"
     * contract readLive() carries, for the same reason.
     *
     * @param array<int, mixed> $rows Raw SHOW INDEX rows, associative.
     * @return array<string, array{name: string, columns: array<int, array{column: string, prefix: int|null}>, unique: bool, describable: bool}>|null
     */
    public static function fromShowIndexRows(array $rows) {
        $names = array();
        $unique = array();
        $bySeq = array();
        $opaque = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                // A row shape we cannot read at all means this SHOW INDEX
                // answer is not a description of the table. Returning the rest
                // as if it were complete is what lets a real index be reported
                // absent and re-created; the caller must be told the probe
                // failed instead.
                return null;
            }
            $fields = self::lowercaseKeys($row);
            $name = isset($fields['key_name']) && is_scalar($fields['key_name'])
                ? (string)$fields['key_name'] : '';
            $column = isset($fields['column_name']) && is_scalar($fields['column_name'])
                ? (string)$fields['column_name'] : '';
            if ($name === '') {
                // A row with no index name cannot be filed under any index, so
                // some part of this table's definition is unaccounted for. Same
                // reasoning as above: fail the probe rather than under-report.
                return null;
            }
            if ($column === '') {
                // A MariaDB/MySQL functional index reports a NULL Column_name and
                // carries the expression in Expression instead. The plugin ships
                // none, and a definition we cannot describe must never be judged
                // as drifted.
                //
                // Dropping the row is NOT how to achieve that: an index whose
                // rows all vanish is absent from the returned map, and an absent
                // index reads as MISSING to the repair path, which then issues
                // CREATE INDEX for a name that already exists. Record the index
                // as present and mark it undescribable instead, so comparison
                // and repair both skip it.
                $key = strtolower($name);
                $names[$key] = $name;
                $opaque[$key] = true;
                if (!isset($bySeq[$key])) {
                    $unique[$key] = isset($fields['non_unique'])
                        && is_scalar($fields['non_unique'])
                        && (int)$fields['non_unique'] === 0;
                    $bySeq[$key] = array();
                }
                continue;
            }
            $key = strtolower($name);
            if (!isset($bySeq[$key])) {
                $names[$key] = $name;
                // Non_unique is 0 for a unique index. A driver that omits the
                // field leaves the index non-unique, which is what every plugin
                // index except the spelling-cache one is.
                $unique[$key] = isset($fields['non_unique'])
                    && is_scalar($fields['non_unique'])
                    && (int)$fields['non_unique'] === 0;
                $bySeq[$key] = array();
            }
            // Seq_in_index IS the column order, and the order is what the
            // signature comparison is FOR. Inventing one from arrival order
            // when the engine did not report it produces a definition that
            // looks authoritative and compares as drift against a DDL whose
            // real order differs -- a needless rewrite of a healthy index on a
            // large table. Mark it undescribable instead.
            if (!isset($fields['seq_in_index']) || !is_numeric($fields['seq_in_index'])) {
                $opaque[$key] = true;
                continue;
            }
            $seq = (int)$fields['seq_in_index'];
            $subPart = $fields['sub_part'] ?? null;
            if (!self::isReadablePrefix($subPart)) {
                // A Sub_part we cannot read is not "no prefix". Treating it as
                // one compares unequal to a DDL that DOES carry a prefix, which
                // reports drift and rebuilds a healthy index.
                $opaque[$key] = true;
                continue;
            }
            $bySeq[$key][$seq] = array(
                'column' => strtolower($column),
                'prefix' => self::normalizePrefix($subPart),
            );
        }

        $definitions = array();
        foreach ($bySeq as $key => $columns) {
            ksort($columns);
            $definitions[$key] = array(
                'name' => $names[$key],
                'columns' => array_values($columns),
                'unique' => $unique[$key],
                // One undescribable row is enough to make the whole index
                // unsafe to compare: with a row missing, the remaining column
                // ORDER is not the index's real order, and a wrong order
                // compares as drift and triggers a needless table rewrite.
                'describable' => !isset($opaque[$key]),
            );
        }
        return $definitions;
    }

    /**
     * A canonical, comparable string for one index definition.
     *
     * Two definitions are the same index exactly when their signatures match:
     * same uniqueness, same columns, in the same order, with the same prefix
     * lengths. Example: "n:status,disabled,logshits,id" or "n:url(190),disabled".
     *
     * @param array<int, array{column: string, prefix: int|null}> $columnList
     * @param bool $unique
     * @return string
     */
    public static function signature(array $columnList, bool $unique): string {
        $parts = array();
        foreach ($columnList as $column) {
            $name = isset($column['column']) ? strtolower((string)$column['column']) : '';
            if ($name === '') {
                continue;
            }
            $prefix = isset($column['prefix']) ? '(' . (int)$column['prefix'] . ')' : '';
            $parts[] = $name . $prefix;
        }
        return ($unique ? 'u:' : 'n:') . implode(',', $parts);
    }

    /**
     * The signature of a DDL spec produced by
     * {@see ABJ_404_Solution_CreateTableIndexParser::fromCreateTableSql()}.
     *
     * @param array{name: string, columns: string, unique: bool} $spec
     * @return string
     */
    public static function signatureOfDdlSpec(array $spec): string {
        return self::signature(
            ABJ_404_Solution_CreateTableIndexParser::ddlColumnList(
                isset($spec['columns']) ? (string)$spec['columns'] : ''),
            !empty($spec['unique'])
        );
    }

    /**
     * The signature of a live definition produced by {@see readLive()}.
     *
     * @param array{name?: string, columns?: array<int, array{column: string, prefix: int|null}>, unique?: bool} $definition
     * @return string
     */
    public static function signatureOfLiveDefinition(array $definition): string {
        $columns = isset($definition['columns']) && is_array($definition['columns'])
            ? $definition['columns'] : array();
        return self::signature($columns, !empty($definition['unique']));
    }

    /**
     * Whether a live definition actually indexes the named column.
     *
     * This is the question the admin read gate has to ask before ordering by a
     * narrow sort key: an index can be present under the right name and still
     * not contain the column the sort needs, in which case ORDER BY on it
     * filesorts the whole partition.
     *
     * @param array{columns?: array<int, array{column: string, prefix: int|null}>} $definition
     * @param string $column
     * @return bool
     */
    public static function containsColumn(array $definition, string $column): bool {
        $needle = strtolower($column);
        if ($needle === '') {
            return false;
        }
        $columns = isset($definition['columns']) && is_array($definition['columns'])
            ? $definition['columns'] : array();
        foreach ($columns as $indexedColumn) {
            if (isset($indexedColumn['column']) && strtolower((string)$indexedColumn['column']) === $needle) {
                return true;
            }
        }
        return false;
    }

    /**
     * A human-readable rendering of a live definition, for log lines and
     * diagnostic payloads: "status, disabled, logshits, id".
     *
     * @param array{columns?: array<int, array{column: string, prefix: int|null}>} $definition
     * @return string
     */
    public static function describeColumns(array $definition): string {
        $columns = isset($definition['columns']) && is_array($definition['columns'])
            ? $definition['columns'] : array();
        $parts = array();
        foreach ($columns as $column) {
            $name = isset($column['column']) ? (string)$column['column'] : '';
            if ($name === '') {
                continue;
            }
            $parts[] = $name . (isset($column['prefix']) ? '(' . (int)$column['prefix'] . ')' : '');
        }
        return implode(', ', $parts);
    }

    /**
     * Lowercase a metadata row's keys so field lookup is case-insensitive
     * (defensive philosophy #5: drivers return SHOW INDEX / information_schema
     * column names in varying cases).
     *
     * @param array<string|int, mixed> $row
     * @return array<string, mixed>
     */
    private static function lowercaseKeys(array $row): array {
        $lowered = array();
        foreach ($row as $key => $value) {
            $lowered[strtolower((string)$key)] = $value;
        }
        return $lowered;
    }

    /**
     * Whether a reported Sub_part is one we can interpret at all.
     *
     * NULL, an empty string and 0 all legitimately mean "indexes the whole
     * column" and are readable. A non-empty value that is not a number is
     * metadata this version does not understand, and must not be quietly
     * flattened into "no prefix".
     *
     * @param mixed $subPart
     * @return bool
     */
    private static function isReadablePrefix($subPart): bool {
        if ($subPart === null) {
            return true;
        }
        if (!is_scalar($subPart)) {
            return false;
        }
        $value = trim((string)$subPart);
        return $value === '' || is_numeric($value);
    }

    /**
     * Normalize a reported Sub_part into "no prefix" (null) or a positive
     * prefix length.
     *
     * Engines and drivers report a full-column index part as NULL, as an empty
     * string, or (rarely) as 0; all three mean the same thing and must compare
     * equal to a DDL fragment that carries no (n) suffix, or the repair path
     * would rebuild every full-column index on every run.
     *
     * Callers must have cleared the value through isReadablePrefix() first: a
     * value this cannot read is undescribable metadata, not "no prefix".
     *
     * @param mixed $subPart
     * @return int|null
     */
    private static function normalizePrefix($subPart) {
        if ($subPart === null || !is_scalar($subPart)) {
            return null;
        }
        $value = trim((string)$subPart);
        if ($value === '' || !is_numeric($value) || (int)$value <= 0) {
            return null;
        }
        return (int)$value;
    }
}
