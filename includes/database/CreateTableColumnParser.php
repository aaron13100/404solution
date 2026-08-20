<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parses the COLUMN declarations out of a CREATE TABLE statement -- either one
 * of the plugin's own create*Table.sql templates or the text an engine hands
 * back from SHOW CREATE TABLE -- into an ordered list of column definitions.
 *
 * The column-side counterpart of {@see ABJ_404_Solution_CreateTableIndexParser}:
 * pure text in, structure out, no database connection and no knowledge of what
 * any engine currently reports.
 *
 * Why this splits the statement instead of scanning it. A CREATE TABLE body is
 * a comma-separated list in which only SOME entries are columns; the rest are
 * table constraints and index declarations. A pattern that hunts for
 * "<word> <word...>," anywhere in the text has no way to tell the two apart, so
 * the tail of
 *
 *     KEY `url` (`url`(190)) USING BTREE,
 *
 * reads as a column named `using` of type `btree`. The schema-diff path then
 * finds no such column on the live table, asks for it to be created, and issues
 * `ALTER TABLE ... ADD using btree` -- which fails, on every upgrade and every
 * cron run, forever (report 286: www.ssr-nu.nl, plugin 4.3.3, MySQL 8.0.30).
 *
 * Splitting the body on top-level commas FIRST, and then rejecting an entry by
 * the keyword it starts with, makes that whole class of misread impossible
 * rather than teaching one pattern about one more keyword. Every non-column
 * entry MySQL and MariaDB accept begins with one of a closed set of words, and
 * a column name that collides with one of them has to be quoted to be legal --
 * so the leading-keyword test is exact, not a heuristic.
 *
 * The parser is deliberately tolerant of input the production caller already
 * cleans up (SQL comments, mixed case, absent backticks): correctness must not
 * depend on a caller remembering to pre-process.
 */
class ABJ_404_Solution_CreateTableColumnParser {

    /**
     * The leading words that make a CREATE TABLE body entry something other
     * than a column. Recognised unquoted only: a column legitimately named
     * `key` or `check` has to be backtick-quoted for the engine to accept it,
     * and a quoted entry is always a column.
     *
     * `period` covers MariaDB's `PERIOD FOR SYSTEM_TIME (...)`. `using` and
     * `btree`/`hash` cannot begin a legal entry at all; they are listed so a
     * body that somehow reaches this point mid-index-clause still refuses
     * rather than inventing a column.
     *
     * @var array<int, string>
     */
    private const NON_COLUMN_LEADING_WORDS = array(
        'primary', 'unique', 'key', 'index', 'fulltext', 'spatial',
        'constraint', 'foreign', 'check', 'period', 'using', 'btree', 'hash',
    );

    /**
     * Extract the column definitions from a CREATE TABLE statement, in the
     * order the statement declares them.
     *
     * @param string $createTableSql
     * @return array<int, array{name: string, definition: string, type: string}>
     *     name: the column name with any quoting removed.
     *     definition: the whole entry as written, e.g. "`url` varchar(2048) not null".
     *     type: the entry with the leading name removed, e.g. "varchar(2048) not null".
     */
    public static function fromCreateTableSql($createTableSql): array {
        if (!is_string($createTableSql) || $createTableSql === '') {
            return array();
        }

        $columns = array();
        $body = self::tableBody(self::stripComments($createTableSql));
        foreach (self::splitTopLevel($body) as $entry) {
            $definition = self::collapseWhitespace($entry);
            if ($definition === '' || self::isNonColumnEntry($definition)) {
                continue;
            }

            $name = self::leadingIdentifier($definition);
            if ($name === '') {
                continue;
            }

            // A bare identifier with nothing after it is not a column
            // definition; every column carries at least a type. This matches
            // what the schema-diff path can actually act on -- it builds
            // `ALTER TABLE ... ADD <definition>` out of the remainder.
            $type = trim(substr($definition, strlen(self::leadingIdentifierText($definition))));
            if ($type === '') {
                continue;
            }

            $columns[] = array(
                'name' => $name,
                'definition' => $definition,
                'type' => $type,
            );
        }

        return $columns;
    }

    /**
     * Just the column names a CREATE TABLE statement declares, in order.
     *
     * Lower-cased, because every caller compares them against names read out
     * of another engine's DDL and identifier case is not significant to any
     * comparison the plugin makes.
     *
     * @param string $createTableSql
     * @return array<int, string>
     */
    public static function columnNames($createTableSql): array {
        $names = array();
        foreach (self::fromCreateTableSql($createTableSql) as $column) {
            $names[] = strtolower($column['name']);
        }
        return $names;
    }

    /**
     * Remove every SQL comment from a statement, leaving comment-like text
     * that happens to sit inside a quoted string exactly where it is.
     *
     * Comments have to go before anything else looks at the statement, because
     * prose is not SQL and reads as whatever the reader expects. The header of
     * createLookupTable.sql ends "...44 characters (Armed Forces Europe, Middle
     * East, & Canada)." -- an opening paren followed by a comma-separated list,
     * which is indistinguishable from a table body until the comment is gone.
     *
     * The caller strips comments too, for its own reasons (it compares column
     * DDL text, and a COMMENT clause is noise in that comparison). This is not
     * that: a parser that only works on pre-cleaned input is a parser whose
     * correctness depends on every caller remembering.
     *
     * @param string $sql
     * @return string
     */
    private static function stripComments($sql) {
        $sql = (string)$sql;
        $length = strlen($sql);
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if (self::opensQuotedRun($char)) {
                $end = self::endOfQuotedRun($sql, $i);
                $out .= substr($sql, $i, $end - $i + 1);
                $i = $end;
                continue;
            }

            $commentEnd = self::endOfCommentRun($sql, $i);
            if ($commentEnd !== null) {
                // One space, not nothing: a comment sitting between two tokens
                // is a separator, and closing the gap would join them.
                $out .= ' ';
                $i = $commentEnd;
                continue;
            }

            $out .= $char;
        }

        return $out;
    }

    /**
     * Whether this character opens a quoted run.
     *
     * @param string $char
     * @return bool
     */
    private static function opensQuotedRun($char) {
        return $char === '`' || $char === "'" || $char === '"';
    }

    /**
     * The index of the closing quote of the quoted run that STARTS at $start.
     *
     * A run that is never closed (DDL truncated mid-string) ends at the last
     * character. That prevents any apparent closing parenthesis inside the
     * broken string from balancing the table body; tableBody() then returns
     * the fail-closed empty result the schema-diff caller recognizes.
     *
     * The single definition of what quoting means here. It used to be inlined
     * in each of the three scanners below, which is three chances for the
     * backslash and doubled-quote rules to drift apart.
     *
     * @param string $sql
     * @param int $start Index of the opening quote.
     * @return int
     */
    private static function endOfQuotedRun($sql, $start) {
        $quote = $sql[$start];
        $length = strlen($sql);

        for ($i = $start + 1; $i < $length; $i++) {
            $char = $sql[$i];

            // Backticks take no backslash escapes; a backslash inside one is
            // an ordinary character.
            if ($char === '\\' && $quote !== '`' && $i + 1 < $length) {
                $i++;
                continue;
            }
            if ($char === $quote) {
                // A doubled quote is an escaped quote, not the end of the run.
                if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                    $i++;
                    continue;
                }
                return $i;
            }
        }

        return $length - 1;
    }

    /**
     * The index of the last character of the comment that STARTS at $start, or
     * null when no comment starts there.
     *
     * @param string $sql
     * @param int $start
     * @return int|null
     */
    private static function endOfCommentRun($sql, $start) {
        $length = strlen($sql);
        $char = $sql[$start];

        // Block comment, including the /*! ... */ version-gated form an engine
        // can emit in SHOW CREATE TABLE output.
        if ($char === '/' && $start + 1 < $length && $sql[$start + 1] === '*') {
            $end = strpos($sql, '*/', $start + 2);
            return ($end === false) ? $length - 1 : $end + 1;
        }

        // Line comment. MySQL requires whitespace (or end of input) after the
        // double dash, which is what keeps it apart from a subtraction.
        $isDoubleDash = ($char === '-' && $start + 1 < $length && $sql[$start + 1] === '-'
            && ($start + 2 >= $length || preg_match('/\s/', $sql[$start + 2]) === 1));
        if ($isDoubleDash || $char === '#') {
            // Stop ON the newline, not past it: the line break is not part of
            // the comment and still separates what follows.
            return $start + strcspn($sql, "\r\n", $start) - 1;
        }

        return null;
    }

    /**
     * The parenthesised body of the CREATE TABLE statement -- the part between
     * the opening paren that follows the table name and its matching close.
     *
     * A statement with no balanced close (truncated DDL, a partial read) yields
     * nothing. There is no reliable way to prove that all column declarations
     * arrived before the truncation point, and the schema-diff caller treats an
     * empty list as the fail-closed "unparseable, do not touch this table"
     * signal.
     *
     * @param string $createTableSql
     * @return string
     */
    private static function tableBody($createTableSql) {
        $sql = (string)$createTableSql;
        $length = strlen($sql);
        $depth = 0;
        $bodyStart = -1;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if (self::opensQuotedRun($char)) {
                // A paren inside a quoted identifier or string is content.
                $i = self::endOfQuotedRun($sql, $i);
                continue;
            }

            if ($char === '(') {
                if ($bodyStart === -1) {
                    $bodyStart = $i + 1;
                }
                $depth++;
                continue;
            }

            if ($char === ')' && $bodyStart !== -1) {
                $depth--;
                if ($depth === 0) {
                    return substr($sql, $bodyStart, $i - $bodyStart);
                }
            }
        }

        return '';
    }

    /**
     * Split a CREATE TABLE body on the commas that separate its entries,
     * leaving alone the commas inside parentheses (`decimal(5,2)`, an index's
     * column list) and inside quoted text (a COMMENT string).
     *
     * @param string $body
     * @return array<int, string>
     */
    private static function splitTopLevel($body) {
        $sql = (string)$body;
        $length = strlen($sql);
        $entries = array();
        $current = '';
        $depth = 0;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if (self::opensQuotedRun($char)) {
                // A comma or paren inside a COMMENT string is content, not a
                // separator.
                $end = self::endOfQuotedRun($sql, $i);
                $current .= substr($sql, $i, $end - $i + 1);
                $i = $end;
                continue;
            }

            if ($char === '(') {
                $depth++;
                $current .= $char;
                continue;
            }

            if ($char === ')') {
                if ($depth > 0) {
                    $depth--;
                }
                $current .= $char;
                continue;
            }

            if ($char === ',' && $depth === 0) {
                $entries[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $entries[] = $current;

        return $entries;
    }

    /**
     * Whether a body entry declares something other than a column.
     *
     * A quoted leading identifier is always a column: the engine requires the
     * quoting precisely because the bare word would have been read as a
     * keyword.
     *
     * @param string $definition
     * @return bool
     */
    private static function isNonColumnEntry($definition) {
        $matches = array();
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_$]*)/', (string)$definition, $matches)) {
            return false;
        }
        return in_array(strtolower($matches[1]), self::NON_COLUMN_LEADING_WORDS, true);
    }

    /**
     * The column name an entry starts with, unquoted, or '' when the entry does
     * not start with an identifier at all.
     *
     * @param string $definition
     * @return string
     */
    private static function leadingIdentifier($definition) {
        $matches = array();
        if (preg_match('/^`((?:[^`]|``)*)`/', (string)$definition, $matches)) {
            return str_replace('``', '`', $matches[1]);
        }
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_$]*)/', (string)$definition, $matches)) {
            return $matches[1];
        }
        return '';
    }

    /**
     * The leading identifier exactly as the entry spells it, quoting included,
     * so the remainder of the entry can be taken by offset.
     *
     * @param string $definition
     * @return string
     */
    private static function leadingIdentifierText($definition) {
        $matches = array();
        if (preg_match('/^`(?:[^`]|``)*`/', (string)$definition, $matches)) {
            return $matches[0];
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_$]*/', (string)$definition, $matches)) {
            return $matches[0];
        }
        return '';
    }

    /**
     * One entry on one line: leading and trailing space removed, and every
     * internal run of whitespace reduced to a single space.
     *
     * The two sides of a schema comparison are written by different authors --
     * a .sql file the plugin ships and whatever SHOW CREATE TABLE emits -- and
     * they indent and wrap differently. Normalising here means the comparison
     * never sees a difference that is only layout.
     *
     * @param string $entry
     * @return string
     */
    private static function collapseWhitespace($entry) {
        $collapsed = preg_replace('/\s+/', ' ', (string)$entry);
        return trim($collapsed === null ? (string)$entry : $collapsed);
    }
}
