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
 * This module owns the live-engine side: the SHOW INDEX probe, and the folding
 * of the rows it returns into per-index definitions under the describability
 * invariant below. What one ROW said, field by field, is a different job --
 * coping with the case its keys arrive in and the several spellings each engine
 * uses for the same number -- and lives in
 * {@see ABJ_404_Solution_ShowIndexRowReader}. Its two neighbours own the other
 * halves of the picture. Turning DDL SOURCE TEXT into
 * a spec is a regex parser over SQL rather than a normalization of driver
 * metadata, and lives in {@see ABJ_404_Solution_CreateTableIndexParser};
 * reducing either representation to a comparable signature, and deciding
 * whether the two agree, lives in
 * {@see ABJ_404_Solution_IndexDefinitionComparator}. The dependency runs one
 * way, from the comparator down to both producers, which is what lets this
 * reader stay ignorant of the DDL parser entirely.
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
 * the whole table forever after. Having one place that answers what an index
 * ACTUALLY CONTAINS is what makes comparing it the natural operation instead of
 * an optional extra.
 *
 * Everything here is read-only: it reads schema metadata and normalizes it. It
 * issues no DDL and makes no repair decisions -- that is
 * {@see ABJ_404_Solution_DatabaseUpgradeIndexes}'s job.
 *
 * Normalization follows defensive philosophy #3 (normalize before comparing)
 * and #5 (case-insensitive metadata access): SHOW INDEX column names come back
 * in varying case depending on the driver, and index/column names are compared
 * case-insensitively because MySQL identifiers are.
 *
 * The contract this reader owes its consumers: AN UNKNOWN STAYS UNKNOWN. Every
 * field of an index's identity -- its column order, its prefix lengths, its
 * uniqueness -- is either read or it is not, and a field that was not read
 * makes the index undescribable rather than taking a default. An index left
 * with no readable columns is not describable either, because there is no such
 * index. {@see isDescribable()} is how that answer travels; the comparator
 * refuses to produce a signature for anything it says no to, so the caller
 * cannot end up comparing an index nobody described and reading the mismatch as
 * a reason to rewrite the table.
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
        $showIndexResult = $this->dbCore->queryAndGetResults("SHOW INDEX FROM " . $quotedTableName,
            array('log_errors' => false));
        $lastError = isset($showIndexResult['last_error']) && is_scalar($showIndexResult['last_error'])
            ? (string)$showIndexResult['last_error'] : '';
        if ($lastError !== '' || !is_array($showIndexResult['rows'] ?? null)) {
            return null;
        }
        return self::fromShowIndexRows(array_values($showIndexResult['rows']));
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
     * Public because the column probe next to this one needs the same grammar:
     * two reads of the same table that disagree about which names are safe to
     * interpolate is how one of them ends up interpolating a name the other
     * would have refused.
     *
     * @param string $tableName
     * @return string|null
     */
    public static function quoteIdentifier(string $tableName): ?string {
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
     * An absent answer is an unknown, not a yes. Every producer sets the flag
     * today, so the default never decides anything -- but a default of TRUE
     * means the first producer that ever forgets it gets a table rewrite rather
     * than a skip, which is the one direction this flag exists to prevent.
     *
     * @param array{describable?: bool} $definition
     * @return bool
     */
    public static function isDescribable(array $definition): bool {
        return isset($definition['describable']) && $definition['describable'] === true;
    }

    /**
     * Assemble SHOW INDEX rows into per-index definitions.
     *
     * Split from readLive() so the assembly is exercisable against captured
     * driver output without a live server, which is what lets the whole
     * engine-variance matrix be tested at all: the row key case, and whether
     * Sub_part arrives as null / '' / '0' / '190', differ across drivers and
     * engines, and each row is read through
     * {@see ABJ_404_Solution_ShowIndexRowReader} before it gets here.
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
        $uniqueReported = array();
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
            $fields = ABJ_404_Solution_ShowIndexRowReader::normalizedFields($row);
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
            $key = strtolower($name);
            if (!isset($bySeq[$key])) {
                $names[$key] = $name;
                $bySeq[$key] = array();
                // Placeholder only. It is meaningless while $opaque[$key] is
                // set, and the block immediately below is what decides whether
                // it ever becomes meaningful.
                $unique[$key] = false;
            }
            // Uniqueness is part of an index's identity exactly as its column
            // order and prefix lengths are, so it gets the same treatment they
            // do: unreadable means undescribable, never a default. Reading a
            // missing Non_unique as "not unique" made the three UNIQUE KEYs the
            // plugin ships compare as drifted against their own DDL, and the
            // repair path answers drift by emptying the spelling cache and
            // rewriting the index -- destruction over metadata nobody read.
            // Rows that contradict each other describe two different indexes,
            // so taking the first one's word for it picks one at random.
            $reportedUnique = ABJ_404_Solution_ShowIndexRowReader::readUniqueFlag($fields);
            if ($reportedUnique === null
                    || (isset($uniqueReported[$key]) && $uniqueReported[$key] !== $reportedUnique)) {
                $opaque[$key] = true;
            } else {
                $uniqueReported[$key] = $reportedUnique;
                $unique[$key] = $reportedUnique;
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
                $opaque[$key] = true;
                continue;
            }
            $placement = ABJ_404_Solution_ShowIndexRowReader::readColumnPlacement($fields, $column);
            if ($placement === null) {
                $opaque[$key] = true;
                continue;
            }
            $seq = $placement['position'];
            $entry = $placement['entry'];
            if (isset($bySeq[$key][$seq]) && $bySeq[$key][$seq] !== $entry) {
                // Two rows disagreeing about which column sits at one position
                // describe two different indexes, exactly as two rows
                // disagreeing about uniqueness do. Letting the later row win
                // silently drops a column, and an index reported with two
                // columns and recorded with one compares as drift against its
                // own DDL. An identical repeat contradicts nothing and is kept.
                $opaque[$key] = true;
                continue;
            }
            $bySeq[$key][$seq] = $entry;
        }

        $definitions = array();
        foreach ($bySeq as $key => $columns) {
            ksort($columns);
            // SHOW INDEX numbers an index's columns 1..n, so a missing number
            // is a row that never ARRIVED -- a truncated result, a row lost
            // between server and client -- rather than one this version could
            // not read. Nothing above catches that: every skip path marks the
            // index opaque, but a row that was never delivered was never
            // skipped, so the flag stays clean. What is left is a SUBSET of the
            // index's columns in an order the index does not have, and a wrong
            // order compares as drift exactly as a wrong column does.
            // array_values() below discards the numbers, so this is the last
            // point at which the gap can be seen at all.
            $positionsComplete = array_keys($columns) === range(1, count($columns));
            $definitions[$key] = array(
                'name' => $names[$key],
                'columns' => array_values($columns),
                'unique' => $unique[$key],
                // One undescribable row is enough to make the whole index
                // unsafe to compare: with a row missing, the remaining column
                // ORDER is not the index's real order, and a wrong order
                // compares as drift and triggers a needless table rewrite.
                //
                // An index with no readable columns left is stated here rather
                // than left to follow from the skips above. It follows today --
                // every skip sets $opaque -- but "no columns" is a description
                // of an index that cannot exist, and the invariant that such a
                // thing is never handed out as comparable should not depend on
                // a future skip path remembering to set the flag.
                'describable' => !isset($opaque[$key]) && count($columns) > 0
                    && $positionsComplete,
            );
        }
        return $definitions;
    }

    /**
     * Whether a live definition actually indexes the named column.
     *
     * This is the question the admin read gate has to ask before ordering by a
     * narrow sort key: an index can be present under the right name and still
     * not contain the column the sort needs, in which case ORDER BY on it
     * filesorts the whole partition.
     *
     * @param array{columns?: array<int, array{column: string, prefix: int|null}>, describable?: bool} $definition
     * @param string $column
     * @return bool
     */
    public static function containsColumn(array $definition, string $column): bool {
        $needle = strtolower($column);
        if ($needle === '') {
            return false;
        }
        if (!self::isDescribable($definition)) {
            // The column list of an undescribable index is a SUBSET of its
            // columns -- a row this version could not read, or one that never
            // arrived, is simply absent from it. The sort-readiness gate reads
            // a yes here as proof the index can serve an ORDER BY, and a wrong
            // yes tells the read path a sort is index-ordered while it
            // filesorts the whole captured partition. No is the same fallback
            // that gate already takes when the probe itself is unreadable, and
            // deciding it here means no future caller has to remember to.
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

}
