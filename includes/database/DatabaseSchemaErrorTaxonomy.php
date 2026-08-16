<?php
/**
 * Pure database schema / data-shape error taxonomy.
 *
 * Owns string-based classification of integrity failures: corrupted or
 * crashed tables (MyISAM "marked as crashed", "Incorrect key file"),
 * missing plugin tables, transient view-build table churn, and data-shape
 * issues (invalid UTF-8, collation mismatch). These are the error classes
 * the auto-repair, table-recreate, and collation-degrade policies act on.
 *
 * No side effects: callers reuse the matchers without inheriting recovery
 * behavior.
 */

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: covered through DatabaseInfrastructureErrorTaxonomy facade by tests/ErrorClassifierTest.php and tests/StagedBuildHostQuirksTest.php
class ABJ_404_Solution_DatabaseSchemaErrorTaxonomy {

    /**
     * Failures of the STATEMENT rather than of the host: the server answered,
     * and its answer was that the query cannot be run as written. Re-running it
     * produces the same answer however long the caller waits.
     *
     * @var array<int, string>
     */
    private const MALFORMED_STATEMENT_MARKERS = array(
        'error in your sql syntax',
        'error 1064',
        'errno 1064',
        'unknown column',
        'unknown table',
        'unknown database',
        "doesn't exist",
        'no such table',
    );

    /**
     * The engines' several ways of saying that a schema change is one the
     * schema already reflects. Every entry is an end state the caller asked
     * for, reported through an error channel because the caller was not the one
     * who brought it about. See {@see isRedundantSchemaChangeError()}.
     *
     * The DROP wording is listed three times on purpose: MySQL 5.7 says
     * "check that column/key exists", MySQL 8.0.29 shortened it to "check that
     * it exists", and MariaDB names the object type ("Can't DROP INDEX `x`").
     * One marker cannot cover an estate running 5.6 through 11.x.
     *
     * @var array<int, string>
     */
    private const REDUNDANT_SCHEMA_CHANGE_MARKERS = array(
        'duplicate key name',           // ER_DUP_KEYNAME 1061: ADD INDEX, that index is already there.
        'duplicate column name',        // ER_DUP_FIELDNAME 1060: ADD COLUMN, that column is already there.
        'already exists',               // ER_TABLE_EXISTS_ERROR 1050: CREATE/RENAME onto a name in use.
        'check that column/key exists', // ER_CANT_DROP_FIELD_OR_KEY 1091: the drop target is already gone.
        'check that it exists',         // Same, as MySQL 8.0.29+ and MariaDB word it.
    );

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /**
     * @param ABJ_404_Solution_Functions $functions
     */
    public function __construct($functions) {
        $this->f = $functions;
    }

    /** @param string $errorText @return bool */
    public function isCrashedTableError(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }
        return stripos($errorText, 'is marked as crashed') !== false;
    }

    /** @param string $errorText @return bool */
    public function isIncorrectKeyFileError(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }
        return stripos($errorText, 'Incorrect key file') !== false;
    }

    /** @param string $errorText @return bool */
    public function isMissingPluginTableError(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        if ($this->f->strpos($lower, '_abj404_logs_hits') !== false) {
            return false;
        }
        return ($this->f->strpos($lower, "doesn't exist") !== false &&
            $this->f->strpos($lower, '_abj404_') !== false);
    }

    /** @param string $errorText @return bool */
    public function isTransientViewBuildTableError(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        return ($this->f->strpos($lower, '_abj404_view_build') !== false ||
            $this->f->strpos($lower, '_abj404_view_done') !== false ||
            $this->f->strpos($lower, '_abj404_view_deleteme') !== false);
    }

    /**
     * Determine whether an error indicates invalid text/charset payload.
     *
     * @param mixed $errorText
     * @return bool
     */
    public function isInvalidDataError($errorText): bool {
        if (!is_string($errorText) || $errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        return (
            $this->f->strpos($lower, 'contains invalid data') !== false ||
            $this->f->strpos($lower, 'incorrect string value') !== false ||
            $this->f->strpos($lower, 'invalid utf8') !== false
        );
    }

    /**
     * True when the statement itself is wrong rather than the host being
     * temporarily unable to answer it: a syntax error, an unknown column, or a
     * table that does not exist.
     *
     * The distinction matters wherever a caller decides whether to try again.
     * A dropped connection is worth retrying; a query the server has already
     * rejected on its own terms is not, and retrying it on a short cadence only
     * buries the one report that would have said what is broken.
     *
     * Deliberately NOT part of {@see isInfrastructureSqlError()}: that union
     * drives notice state and repair for HOST problems, and a malformed
     * statement is neither. It is a defect in the plugin or in the install's
     * schema, and it belongs in front of a human.
     *
     * @param string $errorText
     * @return bool
     */
    public function isMalformedStatementError(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }
        // View-build scratch tables are created and dropped continuously, so
        // one of them missing is ordinary churn rather than a broken statement.
        // Reading it as permanent would stop a pipeline that recovers on its
        // own next pass.
        if ($this->isTransientViewBuildTableError($errorText)) {
            return false;
        }
        $lower = strtolower($errorText);
        foreach (self::MALFORMED_STATEMENT_MARKERS as $marker) {
            if ($this->f->strpos($lower, $marker) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * The statement asked for a schema change the schema already reflects: the
     * index, column or table it wanted to add is there, or the one it wanted to
     * drop is gone. The goal state was reached -- by somebody else.
     *
     * This exists because "ensure it exists" cannot be written any other way.
     * MySQL has no transactional DDL, so SHOW INDEX / SHOW COLUMNS and the
     * ALTER they authorize are two statements with a gap between them, and a
     * plugin update arrives on every concurrent request at once. Report 270
     * (dianthus.zuidplas.net, 2026-08-16 07:10:52) caught two front-end
     * requests inside that gap: both read the redirects table before either
     * wrote, both decided idx_status_disabled_timestamp_id was missing, one
     * won, and the loser reported the winner's success to the developer as
     * five ERROR lines. Closing the gap is not available; classifying its
     * outcome correctly is.
     *
     * NOT part of {@see ABJ_404_Solution_DatabaseInfrastructureErrorTaxonomy::isInfrastructureSqlError()}:
     * that union arms write-block cooldowns, notice state and repair passes for
     * a host in trouble, and a host that answered "already done" is not in
     * trouble. The only thing this class changes is which channel the answer is
     * recorded on.
     *
     * The matching is deliberately narrow. ER_DUP_ENTRY ("Duplicate entry '17'
     * for key 'PRIMARY'") is a row the write LOST, not a schema state it
     * reached, and it shares its first word with ER_DUP_KEYNAME; a marker loose
     * enough to cover both would silence real data failures. Anything not
     * listed keeps today's error channel, which is the safe direction to miss
     * in.
     *
     * @param string $errorText
     * @return bool
     */
    public function isRedundantSchemaChangeError(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        foreach (self::REDUNDANT_SCHEMA_CHANGE_MARKERS as $marker) {
            if ($this->f->strpos($lower, $marker) !== false) {
                return true;
            }
        }
        return false;
    }

    /** @param string $errorText @return bool */
    public function isCollationError(string $errorText): bool {
        if ($errorText === '') {
            return false;
        }
        $lower = strtolower($errorText);
        return ($this->f->strpos($lower, 'illegal mix of collations') !== false ||
            $this->f->strpos($lower, 'unknown collation') !== false ||
            $this->f->strpos($lower, 'collation') !== false && $this->f->strpos($lower, 'not valid') !== false);
    }
}
