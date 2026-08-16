<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Index verification and repair for ABJ_404_Solution_DatabaseUpgradesEtc: for
 * every create*Table.sql, bring the live table's indexes into agreement with
 * the shipped definition -- adding what is missing, and rebuilding what exists
 * under the right name with the wrong definition.
 *
 * Extracted from DatabaseUpgradesEtc.php in 4.1.12. The eager targeted
 * column-add helpers it also carried for a while now live with the components
 * that own those columns' backfills: canonical_url on
 * {@see ABJ_404_Solution_DatabaseUpgradeCanonicalUrlBackfill}, the Step 3a
 * denorm columns on {@see ABJ_404_Solution_DatabaseUpgradeRedirectsDenormBackfill}.
 */
class ABJ_404_Solution_DatabaseUpgradeIndexes extends ABJ_404_Solution_DatabaseUpgradeComponent {

    /** Transient name prefix for the per-index rebuild-attempt marker. */
    private const REBUILD_GUARD_PREFIX = 'abj404_index_rebuilt_';

    /** How long a rebuild attempt suppresses a repeat of the same rebuild. */
    private const REBUILD_GUARD_SECONDS = 30 * 24 * 60 * 60;

    /** @return void */
    function createIndexes() {
	foreach ($this->upgrades()->bootstrapUpgrade()->discoverPermanentDDLFiles() as $ddlEntry) {
		$tableName = $this->dbCore->doTableNameReplacements($ddlEntry['placeholder']);
		$query = $this->dbCore->doTableNameReplacements($ddlEntry['ddlContent']);
		$this->verifyIndexes($tableName, $query);
	}
    }

    /**
     * @param string $tableName
     * @param string $createTableStatementGoal
     * @return void
     */
    function verifyIndexes($tableName, $createTableStatementGoal) {

		// get the indexes.
		// Pattern matches lines starting with "KEY" / "UNIQUE KEY" - handles composite indexes with commas inside parens
		// Indexes: treat the CREATE TABLE SQL as source of truth, and treat the database as truth
		// for what exists (SHOW INDEX). Avoid parsing SHOW CREATE TABLE output, which is vendor/format dependent.
		$goalSpecsByName = ABJ_404_Solution_CreateTableIndexParser::fromCreateTableSql($createTableStatementGoal);
		if (empty($goalSpecsByName)) {
			return;
		}

		// An index is verified by its DEFINITION, never by its name alone. An
		// index can exist under exactly the right name and still be the wrong
		// index: MySQL and MariaDB silently strip a dropped column out of every
		// index that named it and keep the rest, so a table that once ran a
		// build whose DDL predated a column comes back with, say,
		// idx_status_disabled_logshits_id defined as (status, disabled, id).
		// A name-only check called that present and left the admin sort it was
		// built for filesorting the whole table on every load, forever.
		$definitions = new ABJ_404_Solution_TableIndexDefinitions($this->dbCore);
		$liveDefinitions = $definitions->readLive($tableName);
		if ($liveDefinitions === null) {
			// Could not read the schema (missing table, denied permission, dead
			// connection). Never treat that as "no indexes exist" -- that would
			// issue DDL against a table we cannot even introspect.
			$this->logger->debugMessage("Skipping the index check on {$tableName}: its index metadata could not be read.");
			return;
		}

		// Read the columns next to the indexes, not between deciding and acting:
		// both loops below need it, and a table whose schema cannot be read is
		// not a table to issue ALTERs against for any reason.
		$existingColumns = $this->readExistingColumnNames($tableName);
		if ($existingColumns === null) {
			$this->logger->debugMessage("Skipping the index check on {$tableName}: its column metadata could not be read.");
			return;
		}

		$missingIndexNames = [];
		$driftedIndexNames = [];
		foreach ($goalSpecsByName as $indexName => $spec) {
			$live = $liveDefinitions[strtolower((string)$indexName)] ?? null;
			if (ABJ_404_Solution_IndexDefinitionComparator::signatureOfDdlSpec($spec) === null) {
				// Our OWN SQL template did not parse into a column list this
				// time -- a create*Table.sql truncated mid-write on a host that
				// hit its disk quota, say. There is no goal to compare against
				// and nothing safe to build: adding it would issue an ALTER
				// carrying whatever fragment failed to parse, and rebuilding to
				// it would drop a real index for one.
				$this->logger->debugMessage("Skipping index {$indexName} on {$tableName}: its shipped "
					. "definition could not be read out of the plugin's own SQL.");
			} else if ($live === null) {
				$missingIndexNames[] = $indexName;
			} else if (!ABJ_404_Solution_TableIndexDefinitions::isDescribable($live)) {
				// The index is present but the engine described it in a form we
				// cannot compare (a functional index reports no Column_name; a
				// driver that never reported Non_unique says nothing about its
				// uniqueness). Neither missing nor drifted: creating it would
				// collide with the name that already exists, and rebuilding it
				// would rewrite the table on a difference we never established.
				$this->logger->debugMessage("Leaving index {$indexName} on {$tableName} alone: "
					. "the engine reports it in a form this version cannot describe.");
			} else if (ABJ_404_Solution_IndexDefinitionComparator::isDriftedFromDdlSpec($live, $spec)) {
				$driftedIndexNames[] = $indexName;
			}
		}
		$missingIndexNames = $this->prioritizeMissingIndexNames($missingIndexNames);
		$driftedIndexNames = $this->prioritizeMissingIndexNames($driftedIndexNames);

		if (count($missingIndexNames) > 0) {
			$this->logger->infoMessage($this->getUpgradeRuntimeId() . ": On {$tableName} I'm adding missing indexes: " . implode(', ', $missingIndexNames));
		}

		foreach ($missingIndexNames as $indexName) {
			$spec = $goalSpecsByName[$indexName] ?? null;
			if (empty($spec) || !$this->indexColumnsAllExist($tableName, $spec, $existingColumns)) {
				continue;
			}
			$this->deleteSpellingCacheBeforeUniqueIndex($tableName, $spec);
			$this->indexWriter()->addIndex($tableName, $spec);
		}

		foreach ($driftedIndexNames as $indexName) {
			$spec = $goalSpecsByName[$indexName] ?? null;
			if (empty($spec) || !$this->indexColumnsAllExist($tableName, $spec, $existingColumns)) {
				continue;
			}
			$this->rebuildDriftedIndex($tableName, $spec,
				$liveDefinitions[strtolower((string)$indexName)]);
		}
	    }

	    /**
	     * Rebuild one index whose live definition no longer matches the DDL.
	     *
	     * DROP and ADD go in a single ALTER so the index is never absent between
	     * two statements, and so the engine makes one pass over the table
	     * instead of two. The online-DDL hints are tried first and fall back to
	     * a plain ALTER exactly as the missing-index add does.
	     *
	     * Two guards keep this from ever becoming a recurring cost on a large
	     * table:
	     *
	     *  - the non-essential-write cooldown, so a host that is already out of
	     *    disk or in read-only is not handed a table rewrite; and
	     *  - a per-(table, index, goal) marker written BEFORE the attempt and
	     *    cleared only once the engine confirms it now reports what we asked
	     *    for. An engine that describes an index differently from the way we
	     *    wrote it (or an ALTER that dies partway) therefore costs one
	     *    rebuild per month, not one per upgrade tick. Changing the DDL
	     *    changes the goal signature, which re-arms the repair.
	     *
	     * @param string $tableName
	     * @param array{name: string, columns: string, unique: bool} $spec
	     * @param array{columns: array<int, array{column: string, prefix: int|null}>, unique: bool} $liveDefinition
	     * @return void
	     */
	    private function rebuildDriftedIndex($tableName, array $spec, array $liveDefinition): void {
	        if ($this->dbCore->noticeState()->shouldSkipNonEssentialDbWrites()) {
	            $this->logger->debugMessage("Deferring the rebuild of {$spec['name']} on {$tableName} " .
	                "until the database write cooldown ends.");
	            return;
	        }
	        $goalSignature = ABJ_404_Solution_IndexDefinitionComparator::signatureOfDdlSpec($spec);
	        if ($goalSignature === null) {
	            // verifyIndexes() already refuses an unreadable goal, so this is
	            // the belt to that braces: a rebuild whose target definition
	            // nobody could read has no target, and there is no version of
	            // "drop the real index first" that is safe without one.
	            return;
	        }
	        $guardName = self::REBUILD_GUARD_PREFIX . md5(strtolower($tableName) . '|' .
	            strtolower((string)$spec['name']) . '|' . $goalSignature);
	        if (function_exists('get_transient') && get_transient($guardName) !== false) {
	            return;
	        }
	        if (function_exists('set_transient')) {
	            set_transient($guardName, 1, self::REBUILD_GUARD_SECONDS);
	        }

	        $this->logger->infoMessage($this->getUpgradeRuntimeId() . ": On {$tableName} I'm rebuilding " .
	            "{$spec['name']}: the table has (" .
	            ABJ_404_Solution_TableIndexDefinitions::describeColumns($liveDefinition) .
	            ") but the schema defines " . trim((string)$spec['columns']) . ".");

	        $this->deleteSpellingCacheBeforeUniqueIndex($tableName, $spec);
	        $this->indexWriter()->addIndex($tableName, $spec, array('replace_existing' => true));

	        $after = (new ABJ_404_Solution_TableIndexDefinitions($this->dbCore))->readLive($tableName);
	        $rebuilt = is_array($after) ? ($after[strtolower((string)$spec['name'])] ?? null) : null;
	        if (is_array($rebuilt)
	                && ABJ_404_Solution_IndexDefinitionComparator::signatureOfLiveDefinition($rebuilt) === $goalSignature) {
	            if (function_exists('delete_transient')) {
	                delete_transient($guardName);
	            }
	            return;
	        }
	        $this->logger->warn("After rebuilding {$spec['name']} on {$tableName} the server still describes it " .
	            "differently from " . trim((string)$spec['columns']) . ". Treating that as a difference in how this " .
	            "server reports indexes rather than as schema drift, and not rebuilding it again.");
	    }

	    /**
	     * The lowercased column names the table actually has, so an index that
	     * references a column this install does not carry can be skipped rather
	     * than attempted (schema-drift tolerance, defensive philosophy #1/#7).
	     *
	     * Returns null when the probe could not be answered, never an empty list.
	     * "This table has no columns" is not a thing a live table can report, so
	     * an empty answer only ever meant "the read failed" -- and read as a
	     * result it says every index column is present, which is the one
	     * conclusion that issues DDL against a table we cannot introspect. That
	     * is the same inference readLive() refuses two probes earlier, and it
	     * reached production as "Key column 'canonical_url' doesn't exist in
	     * table" on any host that denies the column read.
	     *
	     * @param string $tableName
	     * @return array<int, string>|null
	     */
	    private function readExistingColumnNames($tableName): ?array {
	        $existingColumns = [];
	        $quotedTableName = ABJ_404_Solution_TableIndexDefinitions::quoteIdentifier($tableName);
	        if ($quotedTableName === null) {
	            // Not a name we can safely put in a statement, so the probe is
	            // unanswerable rather than empty -- same contract as readLive().
	            return null;
	        }
	        $showColResult = $this->dbCore->queryAndGetResults("SHOW COLUMNS FROM " . $quotedTableName);
	        $lastError = isset($showColResult['last_error']) && is_scalar($showColResult['last_error'])
	            ? (string)$showColResult['last_error'] : '';
	        if ($lastError !== '' || !is_array($showColResult['rows'] ?? null)) {
	            return null;
	        }
	        $showColRows = $showColResult['rows'];
	        foreach ($showColRows as $colRow) {
	            if (!is_array($colRow)) { continue; }
	            foreach ($colRow as $key => $value) {
	                if (strtolower((string)$key) === 'field' && is_scalar($value)) {
	                    $existingColumns[] = strtolower((string)$value);
	                    break;
	                }
	            }
	        }
	        if (empty($existingColumns)) {
	            // A live table always has columns, so a successful read that names
	            // none is not an answer either -- the rows came back in a shape
	            // this version cannot read. Reporting it as "no columns" would
	            // warn once per index about columns that are probably all there.
	            return null;
	        }
	        return $existingColumns;
	    }

	    /**
	     * Whether every column an index spec names exists on the table. Applies
	     * to the rebuild path as much as the add path: dropping a drifted index
	     * whose replacement cannot be created would leave the table with neither.
	     *
	     * @param string $tableName
	     * @param array{name: string, columns: string, unique: bool} $spec
	     * @param array<int, string> $existingColumns
	     * @return bool
	     */
	    private function indexColumnsAllExist($tableName, array $spec, array $existingColumns): bool {
	        $indexColNames = [];
	        foreach (ABJ_404_Solution_CreateTableIndexParser::ddlColumnList($spec['columns']) as $column) {
	            $indexColNames[] = $column['column'];
	        }
	        $missingCols = array_diff($indexColNames, $existingColumns);
	        if (empty($missingCols)) {
	            return true;
	        }
	        $this->logger->warn("Skipping index {$spec['name']} on {$tableName}: " .
	            "column(s) " . implode(', ', $missingCols) . " do not exist in the table.");
	        return false;
	    }

	    /**
	     * Adding (or rebuilding) the spelling cache's UNIQUE index fails if the
	     * table already holds duplicate rows, which is exactly the state a
	     * missing unique index allows. Emptying a cache costs nothing.
	     *
	     * @param string $tableName
	     * @param array{name: string, columns: string, unique: bool} $spec
	     * @return void
	     */
	    private function deleteSpellingCacheBeforeUniqueIndex($tableName, array $spec): void {
	        $spellingCacheTableName = $this->dbCore->doTableNameReplacements('{wp_abj404_spelling_cache}');
	        if (strtolower($tableName) == $spellingCacheTableName && !empty($spec['unique'])) {
	            $this->contentRepo->deleteSpellingCache();
	        }
	    }

	    /**
	     * Put redirect admin-view performance indexes before lower-impact recovery
	     * indexes. Each index is still added by its own ALTER TABLE statement; this
	     * only controls which missing index is attempted first on weak hosts.
	     *
	     * @param array<int, string> $missingIndexNames
	     * @return array<int, string>
	     */
	    private function prioritizeMissingIndexNames(array $missingIndexNames): array {
	        if (count($missingIndexNames) < 2) {
	            return $missingIndexNames;
	        }
	        $originalPosition = array();
	        foreach ($missingIndexNames as $i => $name) {
	            $originalPosition[$name] = $i;
	        }
	        $priority = array_flip(array(
	            'idx_dest_for_view_id',
	            'idx_status_disabled_timestamp_id',
	            'idx_status_disabled_url_sort_id',
	            'idx_disabled_url_sort_id',
	            'idx_status_disabled_dest_sort_id',
	            'idx_disabled_dest_sort_id',
	            'idx_status_disabled_logshits_id',
	            'idx_disabled_logshits_id',
	            'idx_status_disabled_last_used_id',
	            'idx_disabled_last_used_id',
	            'idx_status_disabled_score_id',
	            'idx_disabled_score_id',
	            'idx_status_disabled',
	            'idx_url_disabled_status',
	            'idx_canonical_url',
	        ));
	        usort($missingIndexNames, function ($a, $b) use ($priority, $originalPosition) {
	            $pa = array_key_exists($a, $priority) ? $priority[$a] : 1000;
	            $pb = array_key_exists($b, $priority) ? $priority[$b] : 1000;
	            if ($pa === $pb) {
	                return ($originalPosition[$a] ?? 0) <=> ($originalPosition[$b] ?? 0);
	            }
	            return $pa <=> $pb;
	        });
	        return $missingIndexNames;
	    }

	    /**
	     * @param string $logsTable
	     * @param string|null $createSqlOverride
	     * @return void
	     */
	    public function ensureLogsCompositeIndex($logsTable, $createSqlOverride = null) {
	        $indexName = 'idx_requested_url_timestamp';
	        $createSql = is_string($createSqlOverride) ? $createSqlOverride : ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../../sql/createLogTable.sql");
	        $specsByName = ABJ_404_Solution_CreateTableIndexParser::fromCreateTableSql($createSql);
	        $spec = $specsByName[$indexName] ?? null;
	        if (empty($spec)) {
	            $this->logger->errorMessage("Failed to add {$indexName} to {$logsTable}: index definition not found in createLogTable.sql");
	            return;
	        }
	        if (ABJ_404_Solution_IndexDefinitionComparator::signatureOfDdlSpec($spec) === null) {
	            $this->logger->errorMessage("Failed to add {$indexName} to {$logsTable}: its definition in " .
	                "createLogTable.sql did not parse into a column list.");
	            return;
	        }

	        // Same rule as verifyIndexes(): the NAME being present proves nothing.
	        // A logsv2 table whose requested_url column was ever dropped carries
	        // this composite narrowed to (timestamp) alone.
	        $liveDefinitions = (new ABJ_404_Solution_TableIndexDefinitions($this->dbCore))->readLive($logsTable);
	        if ($liveDefinitions === null) {
	            return;
	        }
	        $live = $liveDefinitions[strtolower($indexName)] ?? null;
	        if (is_array($live)
	                && !ABJ_404_Solution_IndexDefinitionComparator::isDriftedFromDdlSpec($live, $spec)) {
	            // Present, and no difference from the DDL was established --
	            // either because it agrees, or because the engine described it in
	            // a form this version cannot compare. Both mean the same thing to
	            // a DROP INDEX + ADD INDEX on logsv2, which unlike the
	            // verifyIndexes() repair carries no once-per-month marker and
	            // would therefore re-run on every upgrade tick.
	            return;
	        }
	        // Preflight the columns on the emit path itself, the same gate
	        // verifyIndexes() applies to both of its loops. Established drift is
	        // not authorization to build: the comment above says this composite
	        // narrows when requested_url is dropped, and a table that lost the
	        // column presents exactly that way. Emitting anyway answers
	        // "Key column 'requested_url' doesn't exist in table" -- the line
	        // production reports carry -- and because the repair is a DROP and an
	        // ADD in one ALTER, an engine that applied it in halves would leave
	        // logsv2 with neither index. Probing here rather than beside the
	        // readLive() call keeps the read off the ticks that return early, and
	        // leaves no path to the builder that skips the check.
	        $existingColumns = $this->readExistingColumnNames($logsTable);
	        if ($existingColumns === null) {
	            $this->logger->debugMessage("Skipping {$indexName} on {$logsTable}: its column metadata could not be read.");
	            return;
	        }
	        if (!$this->indexColumnsAllExist($logsTable, $spec, $existingColumns)) {
	            return;
	        }

	        // try_online_first is off because this repair has always been issued
	        // plainly: it is a DROP and an ADD in one statement over a table that
	        // is multi-GB on busy sites, and which algorithm an engine picks for
	        // that is not something to change while fixing how its answer is read.
	        $this->indexWriter()->addIndex($logsTable, $spec, array(
	            'replace_existing' => is_array($live),
	            'try_online_first' => false,
	        ));
	    }

	    /**
	     * The engine-facing half of index repair: what statement this server
	     * takes, and what its answer means. Built per call from the two
	     * collaborators this component already holds, the same way it builds
	     * ABJ_404_Solution_TableIndexDefinitions.
	     *
	     * @return ABJ_404_Solution_TableIndexWriter
	     */
	    private function indexWriter(): ABJ_404_Solution_TableIndexWriter {
	        return new ABJ_404_Solution_TableIndexWriter($this->dbCore, $this->logger);
	    }

}
