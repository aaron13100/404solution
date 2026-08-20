<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The DDL-issuing half of the table-index domain: given a table and the index
 * definition the shipped schema asks for, bring the engine to that state and
 * say honestly what happened.
 *
 * {@see ABJ_404_Solution_TableIndexDefinitions} is the read half and says so in
 * its own header ("Everything here is read-only ... It issues no DDL and makes
 * no repair decisions"). This is the counterpart it names. Between them sits
 * {@see ABJ_404_Solution_IndexDefinitionComparator}, which both use to ask
 * whether two definitions agree.
 *
 * What lives here is everything that depends on how an ENGINE behaves rather
 * than on what the plugin's schema wants: which syntax this server accepts
 * (MariaDB 10.5+ takes ADD INDEX IF NOT EXISTS, MySQL never has), whether the
 * online-DDL hints are worth trying before a plain ALTER, and what each answer
 * it gives back actually means. The decision of WHICH indexes need repairing,
 * in what order, and how often a rebuild may be re-attempted stays with
 * {@see ABJ_404_Solution_DatabaseUpgradeIndexes}, which is schema policy and
 * changes for entirely different reasons.
 *
 * The interpretation half is the reason this is a module and not a function.
 * An ADD INDEX has three outcomes, not two: it worked, it failed, or it was
 * redundant because another process made the same change first -- and the third
 * one reached the state the caller wanted. Report 270 (dianthus.zuidplas.net,
 * 2026-08-16 07:10:52) is what the third outcome looks like when it is handled
 * as the second: two concurrent requests both read SHOW INDEX before either
 * wrote, both decided idx_status_disabled_timestamp_id was missing, and the
 * loser answered the winner's success with a retry that could not succeed and
 * five ERROR lines about an index that was there. Every caller that emits index
 * DDL needs that distinction, and before this module there were two copies of
 * the emit path and only one of them made it.
 *
 * Being told the name is taken is NOT taken as proof the goal was met: this
 * domain exists because an index can carry exactly the right name and the wrong
 * columns (MySQL silently narrows an index when a column it names is dropped).
 * So the schema is read back and compared, and only a match is recorded as an
 * index that is present.
 */
class ABJ_404_Solution_TableIndexWriter {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore Runs the statements and owns the error taxonomy.
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct($dbCore, $logger) {
        $this->dbCore = $dbCore;
        $this->logger = $logger;
    }

    /**
     * Bring one index into existence with the definition the schema asks for.
     *
     * @param string $tableName Fully-qualified table name.
     * @param array{name: string, columns: string, unique: bool} $spec The index the schema defines.
     *        `columns` carries its own parentheses, e.g. "(`a`, `b`(190))".
     * @param array{replace_existing?: bool, try_online_first?: bool} $options
     *        replace_existing: drop the same-named index in the SAME ALTER first, so a drifted
     *          index is swapped without the table ever being without it (default false).
     *        try_online_first: attempt ALGORITHM=INPLACE, LOCK=NONE and fall back to a plain
     *          ALTER if the server or storage engine rejects the hints (default true). The
     *          logsv2 composite passes false: its repair is a DROP and an ADD in one statement
     *          on a table that can be multi-GB, and it has always issued that plainly.
     * @return void
     */
    public function addIndex(string $tableName, array $spec, array $options = array()): void {
        $replaceExisting = !empty($options['replace_existing']);
        $tryOnlineFirst = !array_key_exists('try_online_first', $options)
            || !empty($options['try_online_first']);

        $statement = $this->buildAddIndexStatement(array(
            'tableName' => $tableName,
            'spec' => $spec,
            'online' => $tryOnlineFirst,
            'replaceExisting' => $replaceExisting,
        ));
        $lastError = $this->runStatement($statement);

        if ($lastError !== '' && $tryOnlineFirst) {
            if ($this->recordRedundantChange(array(
                'tableName' => $tableName,
                'spec' => $spec,
                'lastError' => $lastError,
            ))) {
                return;
            }
            if (!$this->isOnlineDdlHintRejection($lastError)) {
                $this->logger->errorMessage("Failed to add index {$spec['name']} to {$tableName}: " .
                    $lastError . " (query: {$statement})");
                return;
            }
            $this->logger->warn("Online index add for {$spec['name']} on {$tableName} failed; " .
                "retrying without online DDL hints: " . $lastError . " (query: {$statement})");
            $statement = $this->buildAddIndexStatement(array(
                'tableName' => $tableName,
                'spec' => $spec,
                'online' => false,
                'replaceExisting' => $replaceExisting,
            ));
            $lastError = $this->runStatement($statement);
        }

        if ($lastError !== '') {
            if ($this->recordRedundantChange(array(
                'tableName' => $tableName,
                'spec' => $spec,
                'lastError' => $lastError,
            ))) {
                return;
            }
            $this->logger->errorMessage("Failed to add index {$spec['name']} to {$tableName}: " .
                $lastError . " (query: {$statement})");
            return;
        }

        $this->logger->infoMessage("I added an index: " . $statement);
    }

    /**
     * @param string $statement
     * @return string The engine's error, or '' when it had none.
     */
    private function runStatement(string $statement): string {
        $result = $this->dbCore->queryAndGetResults($statement);
        return isset($result['last_error']) && is_scalar($result['last_error'])
            ? (string)$result['last_error'] : '';
    }

    /**
     * Whether the engine rejected the optional online-DDL clauses themselves.
     * Other failures (permissions, disk, connection, syntax) must not be
     * repeated as a bare ALTER that cannot correct their cause.
     *
     * @param string $lastError What the engine said.
     * @return bool
     */
    private function isOnlineDdlHintRejection(string $lastError): bool {
        $lower = strtolower($lastError);
        $namesOnlineClause = strpos($lower, 'lock=none') !== false
            || strpos($lower, 'algorithm=inplace') !== false;
        $rejectsClause = strpos($lower, 'not supported') !== false
            || strpos($lower, 'unsupported') !== false;
        return $namesOnlineClause && $rejectsClause;
    }

    /**
     * Whether the statement failed only because the change had already been
     * made and -- when it had -- what the table actually ended up carrying.
     *
     * Retrying such a statement cannot change the answer: the name is taken
     * either way, which is the state the caller asked for. Reporting it as a
     * failed index add describes a table that has the index. So the caller
     * stops here, and what gets recorded is what the schema now says, read
     * back rather than assumed.
     *
     * @param array{tableName: string, spec: array{name: string, columns: string, unique: bool},
     *        lastError: string} $request
     * @return bool True when the change was already applied and the caller must stop.
     */
    private function recordRedundantChange(array $request): bool {
        $tableName = $request['tableName'];
        $spec = $request['spec'];
        $lastError = $request['lastError'];
        if (!$this->dbCore->sqlErrorReporter()->isRedundantSchemaChangeError($lastError)) {
            return false;
        }

        $indexName = (string)$spec['name'];
        $goalSignature = ABJ_404_Solution_IndexDefinitionComparator::signatureOfDdlSpec($spec);
        $liveDefinitions = (new ABJ_404_Solution_TableIndexDefinitions($this->dbCore))->readLive($tableName);
        if ($liveDefinitions === null) {
            $this->logger->warn("Index {$indexName} on {$tableName} was already there when this " .
                "process tried to add it, and the table's index metadata could not be read back to " .
                "confirm what it contains. Leaving it for the next upgrade tick to check.");
            return true;
        }

        $liveDefinition = $liveDefinitions[strtolower($indexName)] ?? null;
        if ($goalSignature !== null && is_array($liveDefinition)
                && ABJ_404_Solution_IndexDefinitionComparator::signatureOfLiveDefinition($liveDefinition)
                    === $goalSignature) {
            $this->logger->infoMessage("Index {$indexName} on {$tableName} was added by another " .
                "process while this one was building it, and it matches the shipped definition.");
            return true;
        }

        // The name is held by something other than what the schema asks for.
        // The plugin still works (worst case a sort is slower), and the drift
        // branch of verifyIndexes() rebuilds a mismatch on a later pass, so this
        // is recorded rather than reported.
        $this->logger->warn("Index {$indexName} on {$tableName} was already there when this process " .
            "tried to add it, but the server does not describe it as " . trim((string)$spec['columns']) .
            ". Leaving it for the drift check to repair.");
        return true;
    }

    /**
     * Build a valid ALTER TABLE ... ADD INDEX statement for THIS server.
     *
     * @param array{tableName: string, spec: array{name: string, columns: string, unique: bool},
     *        online: bool, replaceExisting: bool} $request The table/index definition and
     *        statement policy. replaceExisting emits "drop index `n`, add ..." so a drifted
     *        index is swapped in one statement.
     * @return string
     */
    private function buildAddIndexStatement(array $request): string {
        $tableName = $request['tableName'];
        $spec = $request['spec'];
        $online = $request['online'];
        $replaceExisting = $request['replaceExisting'];
        global $wpdb;
        /** @var \wpdb $wpdb */
        $serverVersion = is_object($wpdb) && method_exists($wpdb, 'db_version') ? ($wpdb->db_version() ?: '') : '';
        $serverInfo = is_object($wpdb) && property_exists($wpdb, 'db_server_info') ? ($wpdb->db_server_info ?? '') : '';

        $isMaria = stripos($serverInfo, 'mariadb') !== false || stripos($serverVersion, 'maria') !== false;
        $cleanedVersion = preg_replace('/[^\d\.]/', '', $serverVersion) ?? '';
        $supportsIfNotExists = $isMaria && version_compare($cleanedVersion, '10.5', '>=');

        $indexName = (string)$spec['name'];
        $indexType = !empty($spec['unique']) ? 'unique index' : 'index';
        $ifNotExists = ($supportsIfNotExists && !$replaceExisting) ? ' if not exists' : '';
        $onlineClause = $online ? ', ALGORITHM=INPLACE, LOCK=NONE' : '';
        $dropClause = $replaceExisting ? " drop index `" . $indexName . "`," : '';

        return "alter table " . $tableName . $dropClause . " add " . $indexType . $ifNotExists .
            " `" . $indexName . "` " . trim((string)$spec['columns']) . $onlineClause;
    }
}
