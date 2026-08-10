<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What one SHOW INDEX row actually said, field by field.
 *
 * This is the boundary between an engine's answer and the plugin's idea of an
 * index. The plugin runs on MySQL 5.6 to 8.x and MariaDB 10.3 to 11.x across
 * several drivers, and they do not agree on how to spell the same fact: the
 * row's keys arrive in varying case, Sub_part arrives as null or '' or 0 or
 * '190' for the same physical index, and every number may arrive as a string.
 * Reading those differences is a separate job from deciding what an index IS,
 * and it is the job this class does.
 *
 * ONE RULE RUNS THROUGH ALL OF IT: a value this version cannot represent
 * exactly is reported as unreadable, never as a default. The definitions these
 * readers feed are compared against the plugin's own DDL, and the repair path
 * answers a difference by rewriting the index -- so a field quietly read as 0,
 * or truncated toward one, spends a table rewrite on metadata nobody actually
 * read. Refusing costs a skipped comparison; guessing costs the table.
 *
 * Split out of {@see ABJ_404_Solution_TableIndexDefinitions}, which owns the
 * other half: what an index IS, once its rows can be read.
 */
class ABJ_404_Solution_ShowIndexRowReader {

    /**
     * A metadata row under the keys this reader looks fields up by.
     *
     * Drivers return SHOW INDEX and information_schema column names in varying
     * cases (defensive philosophy #5), so every lookup here is against
     * lowercased keys and every row passes through this first.
     *
     * @param array<string|int, mixed> $row
     * @return array<string, mixed>
     */
    public static function normalizedFields(array $row): array {
        $lowered = array();
        foreach ($row as $key => $value) {
            $lowered[strtolower((string)$key)] = $value;
        }
        return $lowered;
    }

    /**
     * The uniqueness the engine reported for one index row, or null when it did
     * not report it in a form this version can read.
     *
     * Non_unique is 0 for a unique index and 1 otherwise. Anything else -- the
     * field absent, an array or object where a flag was expected, a word, or a
     * number outside that two-value domain -- is metadata this version does not
     * understand. Reading it as a number is not enough on its own: a non-numeric
     * string casts to integer 0, and so does 0.5, and 0 is the value that means
     * UNIQUE, so "no" or "0.5" would otherwise read as a confident "this index
     * is unique" and invite a UNIQUE rebuild on a table that has duplicate rows.
     *
     * @param array<string, mixed> $fields Lowercased-key SHOW INDEX row.
     * @return bool|null
     */
    public static function readUniqueFlag(array $fields): ?bool {
        if (!isset($fields['non_unique'])) {
            return null;
        }
        $flag = self::readExactInteger($fields['non_unique'], 0);
        if ($flag === null || $flag > 1) {
            return null;
        }
        return $flag === 0;
    }

    /**
     * Where one SHOW INDEX row places its column, or NULL when this version
     * cannot read the placement.
     *
     * The row-level counterpart to {@see readUniqueFlag()}: that one answers
     * what a row says about its index's uniqueness, this one answers what it
     * says about its columns. Both return NULL for "the engine did not tell us
     * in a form we understand", and both leave the caller to record the index
     * as present but undescribable.
     *
     * Seq_in_index IS the column order, and the order is what the signature
     * comparison is FOR. Inventing one from arrival order when the engine did
     * not report it produces a definition that looks authoritative and compares
     * as drift against a DDL whose real order differs -- a needless rewrite of a
     * healthy index on a large table.
     *
     * A Sub_part we cannot read is likewise not "no prefix". Treating it as one
     * compares unequal to a DDL that DOES carry a prefix, which reports drift
     * and rebuilds a healthy index.
     *
     * @param array<string, mixed> $fields Lowercased-key SHOW INDEX row.
     * @param string $column Non-empty column name already read from the row.
     * @return array{position: int, entry: array{column: string, prefix: int|null}}|null
     */
    public static function readColumnPlacement(array $fields, string $column): ?array {
        $position = isset($fields['seq_in_index'])
            ? self::readExactInteger($fields['seq_in_index'], 1) : null;
        if ($position === null) {
            return null;
        }
        $prefix = self::readPrefix($fields['sub_part'] ?? null);
        if (!$prefix['readable']) {
            return null;
        }
        return array(
            'position' => $position,
            'entry' => array(
                'column' => strtolower($column),
                'prefix' => $prefix['prefix'],
            ),
        );
    }

    /**
     * Read a reported Sub_part as either "indexes the whole column" or a prefix
     * length, and say whether it could be read at all.
     *
     * Deciding readability and producing the value used to be two methods, and
     * they disagreed: the gate accepted every numeric value, then the normalizer
     * turned a negative one into "no prefix" and truncated a fractional one. A
     * value the gate calls readable and the normalizer silently changes is the
     * whole defect, so there is now one reader and the two answers come out of
     * it together.
     *
     * NULL, an empty string and 0 all legitimately mean "indexes the whole
     * column". A prefix length is a whole number of characters, so a fractional
     * or negative one is metadata this version does not understand, and must
     * not be flattened into "no prefix" or truncated toward one.
     *
     * @param mixed $subPart
     * @return array{readable: bool, prefix: int|null}
     */
    private static function readPrefix($subPart): array {
        if ($subPart === null) {
            return array('readable' => true, 'prefix' => null);
        }
        if (!is_scalar($subPart) || is_bool($subPart)) {
            return array('readable' => false, 'prefix' => null);
        }
        if (trim((string)$subPart) === '') {
            return array('readable' => true, 'prefix' => null);
        }
        $length = self::readExactInteger($subPart, 0);
        if ($length === null) {
            return array('readable' => false, 'prefix' => null);
        }
        return array('readable' => true, 'prefix' => $length === 0 ? null : $length);
    }

    /**
     * The whole number a metadata field reports, or NULL when the value is not
     * an exact integer at or above the smallest one its domain allows.
     *
     * Every SHOW INDEX field this class reads is a whole number over a known
     * range -- a column position from 1, a prefix length from 0, a uniqueness
     * flag of 0 or 1 -- and every one of them was previously admitted by
     * is_numeric() and then cast with (int). That pair accepts values it cannot
     * represent and answers with a confident wrong one: '1.5' becomes 1, '-1'
     * becomes a position ahead of the first column, '0.5' becomes the 0 that
     * means UNIQUE. The comparison those values feed answers a difference with
     * destructive DDL, so a value that does not survive the round trip is not a
     * value this version can read.
     *
     * Booleans are refused rather than cast: no engine reports one, and (string)
     * renders true as '1' while rendering false as '', so accepting them would
     * read one of the two as a confident flag and the other as absent.
     *
     * @param mixed $value
     * @param int $minimum Smallest value the field's documented domain allows.
     * @return int|null
     */
    private static function readExactInteger($value, int $minimum): ?int {
        if (!is_scalar($value) || is_bool($value)) {
            return null;
        }
        $text = trim((string)$value);
        if ($text === '' || !is_numeric($text)) {
            return null;
        }
        // Integrality is decided from the TEXT, never from the number the text
        // converts to. Past 2^53 a double has no room left for the fractional
        // part it was handed, so '9007199254740992.5' arrives already rounded
        // to a whole number: a floor() check downstream sees nothing wrong and
        // hands back a prefix length the server never reported, which the
        // comparator answers with destructive DDL. Every field this reads is a
        // plain whole number in every engine that reports it, so a value
        // written any other way -- a fraction, an exponent -- is one this
        // version does not read, and unreadable means undescribable rather
        // than a guess.
        if (!preg_match('/\A[+-]?[0-9]+\z/', $text)) {
            return null;
        }
        $number = $text + 0;
        if (is_float($number)) {
            // A digit run too long for an int converts to a float instead:
            // still whole, but possibly infinite and possibly outside the range
            // an int holds. (int) answers both with a different value.
            if (!is_finite($number)
                    || $number < (float)PHP_INT_MIN || $number >= (float)PHP_INT_MAX) {
                return null;
            }
        }
        $integer = (int)$number;
        return $integer < $minimum ? null : $integer;
    }
}
