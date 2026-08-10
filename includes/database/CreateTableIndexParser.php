<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parses the KEY / UNIQUE KEY declarations out of the plugin's own
 * create*Table.sql templates into structured index specs.
 *
 * This is the DDL-source half of the index picture: pure text in, structure
 * out, with no database connection and no knowledge of what any engine
 * currently reports. The live-metadata half, and the comparison that decides
 * whether the two agree, belong to
 * {@see ABJ_404_Solution_TableIndexDefinitions}, which is where they can be
 * compared as one operation.
 *
 * The parser's governing rule, and the reason it refuses rather than guesses:
 * a fragment it only PARTLY understands must yield nothing at all. Scraping the
 * recognisable names out of an unrecognised fragment produces a SHORTER column
 * list that looks like a complete definition, and the repair path would then
 * rebuild a real index to that shorter shape -- turning a parse gap into
 * deliberate data-structure damage. Every caller already reads an empty result
 * as "cannot describe this index", never as "this index has no columns".
 */
class ABJ_404_Solution_CreateTableIndexParser {

    /**
     * Extract index specs from a CREATE TABLE statement (plugin SQL templates),
     * keyed by index name exactly as the DDL spells it.
     *
     * Only plain KEY / UNIQUE KEY definitions are recognised. FULLTEXT and
     * SPATIAL keys are deliberately not matched: the plugin ships none, and
     * silently mis-parsing one into a plain key would let the repair path
     * rebuild it as the wrong kind of index.
     *
     * @param string $createTableSql
     * @return array<string, array{name: string, columns: string, unique: bool}>
     */
    public static function fromCreateTableSql($createTableSql): array {
        if (!is_string($createTableSql) || $createTableSql === '') {
            return array();
        }

        $matches = array();
        preg_match_all('/^\\s*(?:unique\\s+)?key\\s+.+?\\s*$/im', $createTableSql, $matches);

        $specsByName = array();
        foreach ($matches[0] as $line) {
            $spec = self::parseIndexDdlLine($line);
            if (empty($spec) || empty($spec['name'])) {
                continue;
            }
            $specsByName[$spec['name']] = $spec;
        }

        return $specsByName;
    }

    /**
     * Parse one index DDL line from our CREATE TABLE SQL into a structured spec.
     *
     * Accepts forms like:
     * - KEY `name` (`col`(190), `other`)
     * - UNIQUE KEY `name` (`col`)
     * - KEY `name` (`col`) USING BTREE
     *
     * Returns null if the line doesn't look like a KEY/UNIQUE KEY definition.
     *
     * @param string $indexDDL
     * @return array{name: string, columns: string, unique: bool}|null
     */
    public static function parseIndexDdlLine($indexDDL) {
        $indexDDL = trim((string)$indexDDL);
        // Tolerate a trailing comma -- the line-extracting regex pulls each
        // KEY definition out as-is from the surrounding CREATE TABLE list,
        // and any KEY that isn't the LAST one will end with a comma. Same
        // canonical form either way.
        $indexDDL = rtrim($indexDDL, ',');
        $matches = array();
        if (!preg_match('/^(unique\\s+)?key\\s+`?([^`\\s]+)`?\\s*(\\(.+\\))\\s*(?:using\\s+\\w+)?\\s*$/i', $indexDDL, $matches)) {
            return null;
        }

        return array(
            'name' => $matches[2],
            'columns' => $matches[3],
            'unique' => !empty($matches[1]),
        );
    }

    /**
     * The ordered (column, prefix) list a DDL column fragment describes.
     *
     * Input is the parenthesised fragment a spec carries, e.g.
     * "(`status`, `disabled`, `logshits`, `id`)" or "(`url`(190), `disabled`)".
     * Backticks are required (every shipped create*Table.sql uses them, and
     * DDLColumnParsingRobustnessTest enforces it), so a fragment written some
     * other way yields an empty list -- which the repair path treats as
     * "cannot describe this index", never as "this index has no columns".
     *
     * @param string $columnsSql
     * @return array<int, array{column: string, prefix: int|null}>
     */
    public static function ddlColumnList($columnsSql): array {
        $fragment = trim((string)$columnsSql);
        $columns = array();
        $matches = array();
        preg_match_all('/`([^`]+)`\\s*(?:\\(\\s*(\\d+)\\s*\\))?/', $fragment, $matches,
            PREG_SET_ORDER);
        foreach ($matches as $match) {
            // A (0) prefix and no prefix at all are the same physical index, and
            // the live reader already reports a Sub_part of 0 as "no prefix".
            // Spelling it `url(0)` here while the live side spells it `url`
            // makes two descriptions of one index compare as drift, and the
            // repair path answers drift by rewriting the table. MySQL rejects a
            // zero-length key part, so no create*Table.sql the plugin ships can
            // reach this -- but the two sides of a comparison agreeing about
            // what a value MEANS should not rest on the value never occurring.
            $prefix = isset($match[2]) ? (int)$match[2] : null;
            $columns[] = array(
                'column' => strtolower($match[1]),
                'prefix' => ($prefix === null || $prefix <= 0) ? null : $prefix,
            );
        }

        // Verify the whole fragment was accounted for, not just the parts that
        // happened to match. See the class comment: a partial parse that looks
        // complete is worse than no parse at all.
        $remainder = preg_replace('/`[^`]+`\\s*(?:\\(\\s*\\d+\\s*\\))?/', '', $fragment);
        $remainder = trim((string)$remainder, " \t\n\r\0\x0B(),");
        if ($remainder !== '') {
            return array();
        }

        return $columns;
    }
}
