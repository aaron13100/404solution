<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parses the TABLE OPTIONS of a CREATE TABLE statement -- the engine, charset
 * and collation an engine writes after the closing paren of the body -- out of
 * either one of the plugin's own create*Table.sql templates or the text an
 * engine hands back from SHOW CREATE TABLE.
 *
 * The third member of the family alongside
 * {@see ABJ_404_Solution_CreateTableColumnParser} and
 * {@see ABJ_404_Solution_CreateTableIndexParser}: pure text in, structure out,
 * no database connection and no knowledge of what any engine currently reports.
 * Between them the three cover the whole statement -- the body's column entries,
 * the body's index entries, and everything after the body.
 *
 * Why this splits the statement instead of scanning it. A CREATE TABLE
 * statement has exactly two regions, and the charset/collation question has a
 * DIFFERENT answer in each. Inside the body,
 *
 *     `url` varchar(512) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
 *
 * is one column's override. After the body,
 *
 *     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
 *
 * is the table's default. No pattern applied to the concatenation of the two
 * can tell which region it landed in, and columns come first in real engine
 * output, so a scan of the whole statement reports the first COLUMN's charset
 * as the table's. Searching harder -- taking the last match, excluding a
 * keyword, requiring a preceding DEFAULT -- only moves which inputs it gets
 * wrong. Finding the boundary first makes the confusion impossible rather than
 * unlikely.
 *
 * That misread is not a near-miss a caller can second-guess. The collation
 * drift path reads "utf8mb3" off a utf8mb4 table as drift off the canonical
 * target and issues ALTER TABLE ... CONVERT against a table that never drifted,
 * on exactly the utf8mb3-to-utf8mb4 population already on record for errno 1253.
 *
 * Every method returns null for a statement with no balanced body (truncated
 * DDL, a partial read) rather than guessing. Callers have an exact source to
 * fall through to -- information_schema -- and a wrong answer is worse than no
 * answer for all of them.
 */
class ABJ_404_Solution_CreateTableOptionsParser {

    /**
     * The table-options section: everything after the close paren that matches
     * the body's opening paren, with quoted runs and SQL comments blanked out.
     *
     * Public because the section is the boundary itself, and a caller reading a
     * table option this class does not name yet should scope its own read to
     * the section rather than reinventing the split.
     *
     * The scan starts at the CREATE TABLE keyword rather than at the start of
     * the input, so a balanced paren pair that precedes it cannot be mistaken
     * for the body. Text that is not a CREATE TABLE statement at all has no
     * body and no options: an engine error string carries parens of its own
     * ("ERROR 1146 (42S02): Table ... doesn't exist"), and reading ": Table ...
     * doesn't exist" as this table's options is exactly the confident-wrong
     * answer the class exists to rule out.
     *
     * @param string $createTableSql Raw SHOW CREATE TABLE output or a DDL template.
     * @return string|null The table-options text, or null when there is no balanced body.
     */
    public static function tableOptionsSection($createTableSql): ?string {
        if (!is_string($createTableSql) || $createTableSql === '') {
            return null;
        }

        $sql = self::blankQuotedRunsAndComments($createTableSql);

        $statementStart = array();
        if (!preg_match('/\bCREATE\s+(?:TEMPORARY\s+)?TABLE\b/i', $sql, $statementStart, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $length = strlen($sql);
        $depth = 0;
        $bodyOpened = false;

        for ($i = (int)$statementStart[0][1]; $i < $length; $i++) {
            $char = $sql[$i];

            if ($char === '(') {
                $bodyOpened = true;
                $depth++;
                continue;
            }

            if ($char === ')' && $bodyOpened) {
                $depth--;
                if ($depth === 0) {
                    return substr($sql, $i + 1);
                }
            }
        }

        return null;
    }

    /**
     * The table's default charset and collation, as the statement declares them.
     *
     * Either half may be null: a statement is free to state one and leave the
     * other implicit. Deriving the missing half is the CALLER's decision, not
     * this parser's -- the answer depends on what the caller is going to do with
     * it, and one of them must additionally keep the pair inside a single
     * charset family (errno 1253). This returns what is written, nothing more.
     *
     * Matches the several spellings MySQL and MariaDB emit across versions:
     * CHARSET=x, DEFAULT CHARSET=x, CHARACTER SET=x, DEFAULT CHARACTER SET x,
     * CHARSET = x, and the same shapes for COLLATE.
     *
     * @param string $createTableSql Raw SHOW CREATE TABLE output or a DDL template.
     * @return array{charset: string|null, collation: string|null}|null
     *     Null when the statement has no readable table-options section.
     */
    public static function tableCharsetAndCollation($createTableSql): ?array {
        $options = self::tableOptionsSection($createTableSql);
        if ($options === null) {
            return null;
        }

        $charsetMatch = array();
        $collationMatch = array();

        // (?:\s*=\s*|\s+) requires either "=" (with optional surrounding
        // spaces) or at least one space, so "CHARSETX" cannot match.
        preg_match(
            '/(?:DEFAULT\s+)?(?:CHARSET|CHARACTER\s+SET)(?:\s*=\s*|\s+)([\w\d]+)/i',
            $options,
            $charsetMatch
        );
        preg_match(
            '/(?:DEFAULT\s+)?COLLATE(?:\s*=\s*|\s+)([\w\d_]+)/i',
            $options,
            $collationMatch
        );

        return array(
            'charset' => isset($charsetMatch[1]) ? $charsetMatch[1] : null,
            'collation' => isset($collationMatch[1]) ? $collationMatch[1] : null,
        );
    }

    /**
     * Whether the statement declares a charset or a collation AS A TABLE
     * OPTION, i.e. whether it already carries a table-level default.
     *
     * The question a producer asks before appending one of its own, and it has
     * to be asked of the options section alone. A per-column
     * `CHARACTER SET x COLLATE y` inside the body answers a DIFFERENT question,
     * so a whole-statement scan reads one column's override as proof the whole
     * table is covered and skips the default the table still needs. The plugin's
     * own staging templates carry per-column charsets, so the two are not
     * hypothetically distinguishable -- they routinely differ.
     *
     * A statement with no readable table-options section declares no table-level
     * default, which is the answer a producer needs: append one.
     *
     * @param string $createTableSql Raw SHOW CREATE TABLE output or a DDL template.
     * @return bool
     */
    public static function declaresTableCharsetOrCollation($createTableSql): bool {
        $declared = self::tableCharsetAndCollation($createTableSql);

        return $declared !== null
            && ($declared['charset'] !== null || $declared['collation'] !== null);
    }

    /**
     * The storage engine the statement declares, or null when it declares none
     * (or the statement has no readable table-options section).
     *
     * @param string $createTableSql Raw SHOW CREATE TABLE output or a DDL template.
     * @return string|null
     */
    public static function tableEngine($createTableSql): ?string {
        $options = self::tableOptionsSection($createTableSql);
        if ($options === null) {
            return null;
        }

        $engineMatch = array();
        preg_match('/ENGINE(?:\s*=\s*|\s+)([\w]+)/i', $options, $engineMatch);

        return isset($engineMatch[1]) ? $engineMatch[1] : null;
    }

    /**
     * Replace every quoted run and every SQL comment with an equal number of
     * spaces, leaving the rest of the statement untouched.
     *
     * Quoted runs go because a table COMMENT is free text that may quote a
     * charset the table no longer uses, and table options are order-independent
     * in SQL, so that COMMENT is free to sit before the real ones. Comments go
     * because prose is not SQL: the header of the plugin's own
     * createLookupTable.sql ends "...(Armed Forces Europe, Middle East, &
     * Canada)." -- an opening paren that would otherwise open the "body" before
     * the real one does.
     *
     * Blanking rather than deleting keeps every remaining character at its
     * original offset, so the paren scan reads the same structure the engine
     * wrote.
     *
     * @param string $sql
     * @return string Same length as the input.
     */
    private static function blankQuotedRunsAndComments($sql) {
        $sql = (string)$sql;
        $length = strlen($sql);
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $end = null;

            if ($char === '`' || $char === "'" || $char === '"') {
                $end = self::endOfQuotedRun($sql, $i);

            } else if ($char === '/' && $i + 1 < $length && $sql[$i + 1] === '*') {
                // Block comment, including the /*! ... */ version-gated form an
                // engine can emit in SHOW CREATE TABLE output.
                $close = strpos($sql, '*/', $i + 2);
                $end = ($close === false) ? $length - 1 : $close + 1;

            } else if ($char === '#'
                    || ($char === '-' && $i + 1 < $length && $sql[$i + 1] === '-'
                        && ($i + 2 >= $length || preg_match('/\s/', $sql[$i + 2]) === 1))) {
                // A line comment ends AT the newline; the break itself still
                // separates what follows. MySQL requires whitespace (or end of
                // input) after the double dash, which is what keeps it apart
                // from a subtraction.
                $end = max($i, $i + strcspn($sql, "\r\n", $i) - 1);
            }

            if ($end === null) {
                $out .= $char;
                continue;
            }

            $out .= str_repeat(' ', $end - $i + 1);
            $i = $end;
        }

        return $out;
    }

    /**
     * The offset of the closing quote of the quoted run that STARTS at $start.
     *
     * A run that is never closed (DDL truncated mid-string) ends at the last
     * character rather than being reported as an error: such a statement has no
     * balanced body either, so the caller's answer is already "unreadable" and
     * there is nothing after the run left to misread.
     *
     * @param string $sql
     * @param int $start Offset of the opening quote.
     * @return int
     */
    private static function endOfQuotedRun($sql, $start) {
        $quote = $sql[$start];
        $length = strlen($sql);

        for ($i = $start + 1; $i < $length; $i++) {
            $char = $sql[$i];

            // Backticks take no backslash escapes; a backslash inside one is an
            // ordinary character.
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
}
