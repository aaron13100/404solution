<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Whether a live index and the DDL that declares it are the same index, and the
 * canonical comparable form both reduce to in order to answer that.
 *
 * This is the third of three modules that together own the index picture, and
 * the only one that sees both halves:
 *
 *  - {@see ABJ_404_Solution_CreateTableIndexParser} turns DDL SOURCE TEXT (the
 *    plugin's own create*Table.sql templates) into specs. Regex over SQL.
 *  - {@see ABJ_404_Solution_TableIndexDefinitions} reads the LIVE ENGINE
 *    (SHOW INDEX) and normalizes whatever the driver reported into definitions.
 *  - this module reduces either representation to one signature string and
 *    compares them.
 *
 * Splitting the comparison out from the reader is what lets the reader stop
 * knowing that the DDL parser exists at all: the dependency runs one way, from
 * here down to both producers, and never back.
 *
 * THE CONTRACT, and the reason every method here can answer "no":
 *
 * An index that was not fully described has NO signature. Not an empty one, not
 * a placeholder -- none. "n:" looks like a signature and compares like one, but
 * it stands for an index with no columns, which cannot exist, so it compares
 * unequal to every real definition and the repair path reads that as drift. The
 * same is true of a live definition the engine described incompletely. Both
 * mean "cannot describe this index", and both keep meaning that all the way to
 * the caller.
 *
 * {@see isDriftedFromDdlSpec()} is where that meets the callers. It is
 * deliberately two-valued over a three-valued reality, because the response to
 * "differs" is a DROP INDEX plus ADD INDEX over the whole table (and, for the
 * spelling cache, emptying it first) while the response to "agrees" is nothing
 * at all. "Could not be established" therefore belongs with "agrees".
 *
 * Everything here is pure: two in-memory shapes in, a string or a verdict out.
 * No queries, no DDL, no formatting for humans.
 */
class ABJ_404_Solution_IndexDefinitionComparator {

    /**
     * A canonical, comparable string for one index definition, or NULL when the
     * column list does not describe an index that could exist.
     *
     * Two definitions are the same index exactly when their signatures match:
     * same uniqueness, same columns, in the same order, with the same prefix
     * lengths. Example: "n:status,disabled,logshits,id" or "n:url(190),disabled".
     *
     * An empty list reaches here from both sides: ddlColumnList() answers a
     * fragment it only partly understands with an empty list, and a live
     * definition assembled from rows this version could not read carries one
     * too. An entry with no readable column name voids the signature rather
     * than being skipped, for the same reason the DDL parser refuses a partial
     * parse: skipping produces a SHORTER list that reads as a complete
     * definition, and repairing a real index to that shorter shape is how a
     * read gap turns into deliberate data-structure damage.
     *
     * @param array<int, array{column: string, prefix: int|null}> $columnList
     * @param bool $unique
     * @return string|null
     */
    public static function signature(array $columnList, bool $unique): ?string {
        if (empty($columnList)) {
            return null;
        }
        $parts = array();
        foreach ($columnList as $column) {
            $name = isset($column['column']) && is_scalar($column['column'])
                ? strtolower(trim((string)$column['column'])) : '';
            if ($name === '') {
                return null;
            }
            $prefix = isset($column['prefix']) ? '(' . (int)$column['prefix'] . ')' : '';
            $parts[] = $name . $prefix;
        }
        return ($unique ? 'u:' : 'n:') . implode(',', $parts);
    }

    /**
     * The signature of a DDL spec produced by
     * {@see ABJ_404_Solution_CreateTableIndexParser::fromCreateTableSql()}, or
     * NULL when that spec's column fragment did not parse -- a truncated or
     * corrupted create*Table.sql, which defensive philosophy #7 says to expect.
     *
     * @param array{name: string, columns: string, unique: bool} $spec
     * @return string|null
     */
    public static function signatureOfDdlSpec(array $spec): ?string {
        return self::signature(
            ABJ_404_Solution_CreateTableIndexParser::ddlColumnList(
                isset($spec['columns']) ? (string)$spec['columns'] : ''),
            !empty($spec['unique'])
        );
    }

    /**
     * The signature of a live definition produced by
     * {@see ABJ_404_Solution_TableIndexDefinitions::readLive()}, or NULL when
     * the engine's description of it was incomplete.
     *
     * The describability check is repeated here rather than left to the caller
     * on purpose: a call site that forgets it gets a comparable string for an
     * index nobody described, and the only thing it can do with the resulting
     * mismatch is rewrite the table. Making the undescribable case impossible to
     * ask a comparable question about is cheaper than auditing every future
     * caller for the gate.
     *
     * @param array{name?: string, columns?: array<int, array{column: string, prefix: int|null}>, unique?: bool, describable?: bool} $definition
     * @return string|null
     */
    public static function signatureOfLiveDefinition(array $definition): ?string {
        if (!ABJ_404_Solution_TableIndexDefinitions::isDescribable($definition)) {
            return null;
        }
        $columns = isset($definition['columns']) && is_array($definition['columns'])
            ? $definition['columns'] : array();
        return self::signature($columns, !empty($definition['unique']));
    }

    /**
     * Whether a live index has been ESTABLISHED to differ from the DDL that
     * declares it.
     *
     * The one question the repair path acts on. Collapsing "could not be
     * established" into "not drifted" happens here, once, where the two
     * signatures are read, so that no caller has to re-derive that null is not
     * a difference. Comparing the signatures directly is exactly the mistake
     * this method exists to prevent: PHP reports null !== 'n:url(190)' as a
     * difference, and that difference is a table rewrite.
     *
     * @param array{columns?: array<int, array{column: string, prefix: int|null}>, unique?: bool, describable?: bool} $liveDefinition
     * @param array{name: string, columns: string, unique: bool} $ddlSpec
     * @return bool
     */
    public static function isDriftedFromDdlSpec(array $liveDefinition, array $ddlSpec): bool {
        $liveSignature = self::signatureOfLiveDefinition($liveDefinition);
        $goalSignature = self::signatureOfDdlSpec($ddlSpec);
        if ($liveSignature === null || $goalSignature === null) {
            return false;
        }
        return $liveSignature !== $goalSignature;
    }
}
