<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/DatabaseTableNameResolver.php';

/**
 * Decides whether a read may be skipped because its table is definitely absent.
 *
 * A fresh install genuinely does not have the plugin's tables until the upgrade
 * runs, so callers that would otherwise aggregate over them ask here first.
 * The whole point of this class is the distinction the underlying probe makes
 * and a boolean cannot: SHOW TABLES LIKE returns nothing both when the engine
 * looked and found nothing AND when the query never ran at all -- a lost
 * connection, a revoked SHOW grant, a driver that does not speak the statement.
 *
 * Only an engine that ANSWERED "no such table" earns a suppressed read. A probe
 * that could not be answered reports NOT absent, so the caller goes ahead: if
 * the table really is gone that costs one error the centralized handler already
 * knows how to log, repair and retry, which is strictly better than a confident
 * wrong answer produced by a query nobody ran and therefore nobody logged.
 *
 * Keeping that policy here rather than in each caller is what makes the wrong
 * reading unavailable: the tri-state constants stay an implementation detail,
 * and a caller can only ask the question whose answer is safe to act on.
 *
 * A table observed PRESENT is remembered for the life of this instance, keyed
 * by its fully-resolved name so a multisite blog switch cannot reuse another
 * blog's answer. Any other outcome is deliberately re-probed, so an in-request
 * repair can make later reads available without rebuilding the service graph.
 *
 * This is a schema-metadata collaborator: it issues one metadata probe and
 * holds no business or presentation logic.
 */
class ABJ_404_Solution_TableReadinessGate {

    /** @var ABJ_404_Solution_DatabaseQueryInterface */
    private $dbCore;

    /** @var ABJ_404_Solution_DatabaseTableNameResolver */
    private $tableNameResolver;

    /** @var array<string, bool> Resolved table name => observed present. */
    private $observedPresent = array();

    /**
     * @param ABJ_404_Solution_DatabaseQueryInterface $dbCore Supplies table-name replacement.
     * @param ABJ_404_Solution_DatabaseTableNameResolver $tableNameResolver Runs the probe.
     */
    public function __construct(
        ABJ_404_Solution_DatabaseQueryInterface $dbCore,
        ABJ_404_Solution_DatabaseTableNameResolver $tableNameResolver
    ) {
        $this->dbCore = $dbCore;
        $this->tableNameResolver = $tableNameResolver;
    }

    /**
     * Whether the engine positively reported this table as not existing.
     *
     * Asked in the negative on purpose, so that the answer a caller acts on is
     * the only one that is safe to act on. False means "not known to be
     * absent", which covers both a table that is there and a probe that could
     * not be answered -- in both cases the caller should do its work.
     *
     * @param string $tableToken Unresolved token, e.g. '{wp_abj404_redirects}'.
     */
    public function isKnownAbsent(string $tableToken): bool {
        $table = $this->dbCore->doTableNameReplacements($tableToken);
        if (!empty($this->observedPresent[$table])) {
            return false;
        }

        $status = $this->tableNameResolver->tableExistenceStatus($table);
        if ($status === ABJ_404_Solution_DatabaseTableNameResolver::TABLE_PRESENT) {
            $this->observedPresent[$table] = true;
            return false;
        }

        return $status === ABJ_404_Solution_DatabaseTableNameResolver::TABLE_ABSENT;
    }
}
