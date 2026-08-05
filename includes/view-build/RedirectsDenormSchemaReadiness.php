<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: exercised by RedirectsSortKeyUpgradeWindowTest

/**
 * Live introspection of the redirects table's denorm-column and sort-key index
 * readiness for the admin redirects/captured read (Denorm Step 3b).
 *
 * Owns the single authority "may the admin read serve off / ORDER BY these
 * denorm columns right now?" by probing the live schema: whether the Step 3a
 * four derived columns exist, whether each narrow sort-key column exists, and
 * whether the composite indexes backing each sort key plus the legacy-row drain
 * latch are present. Both the SHOW COLUMNS and SHOW INDEX probes are memoized
 * per instance, so each runs at most once per request. An empty/failed probe
 * yields an empty set, so every presence check degrades to false (the safe
 * fallback) -- schema-drift tolerance (defensive philosophy #1/#7).
 *
 * RedirectsViewLiveResolver composes one instance and exposes it via
 * schemaReadiness(); the admin read coordinator and the header UI read the same
 * shared instance so the query path and the header cannot drift on which sorts
 * are index-ready.
 */
class ABJ_404_Solution_RedirectsDenormSchemaReadiness {

    /** @var callable(string,array<string,mixed>,callable):mixed|null */
    private static $operationTracer = null;

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var array<string,bool>|null Memoized lowercased column-name set of the
     *  redirects table (one SHOW COLUMNS per request), consulted by both
     *  derivedColumnsPresent() and destSortKeyColumnPresent(). */
    private $redirectsColumnSetCache = null;

    /** @var array<string, array{name: string, columns: array<int, array{column: string, prefix: int|null}>, unique: bool}>|null
     *  Memoized live index DEFINITIONS of the redirects table, keyed by
     *  lowercased index name (one SHOW INDEX per request), consulted by
     *  sortKeyReadyForColumn() to confirm the composite indexes backing a narrow
     *  sort key can actually serve the sort before the read orders by it. */
    private $redirectsIndexSetCache = null;

    /**
     * Error logging is intentionally delegated to queryAndGetResults (the
     * centralized DAO error handler), so no logger dependency is held here.
     *
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     */
    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore) {
        $this->dbCore = $dbCore;
    }

    /** @param callable(string,array<string,mixed>,callable):mixed|null $tracer */
    public static function setOperationTracer($tracer): void {
        self::$operationTracer = $tracer;
    }

    /**
     * Whether the four Step 3a denorm columns exist on wp_abj404_redirects.
     *
     * Schema-drift tolerance (defensive philosophy #1/#7): a site whose
     * column-add ALTER never completed serves off the base columns alone (the
     * write-back is then skipped, with nowhere to write). Memoized per instance
     * so the SHOW COLUMNS probe runs at most once per request.
     *
     * @return bool
     */
    public function derivedColumnsPresent(): bool {
        return isset($this->redirectsColumnSet()['dest_for_view']);
    }

    /**
     * Whether the indexable Destination sort key column (dest_sort_key, added
     * after the Step 3a four) exists on wp_abj404_redirects. The admin read uses
     * it for an index-ordered Destination sort; when it is absent (an install
     * mid-upgrade, before the column-add ALTER ran) the read falls back to the
     * CASE-on-dest_for_view filesort. Memoized via the shared column-set probe.
     *
     * @return bool
     */
    public function destSortKeyColumnPresent(): bool {
        return isset($this->redirectsColumnSet()['dest_sort_key']);
    }

    /**
     * Whether the indexable URL sort key column (url_sort_key) exists on
     * wp_abj404_redirects. The admin read uses it for an index-ordered URL sort;
     * when it is absent (an install mid-upgrade, before the column-add ALTER ran)
     * the read falls back to the raw-url filesort. Memoized via the shared
     * column-set probe.
     *
     * @return bool
     */
    public function urlSortKeyColumnPresent(): bool {
        return isset($this->redirectsColumnSet()['url_sort_key']);
    }

    /**
     * The single authority for "may the admin read ORDER BY this narrow sort-key
     * column right now, index-ordered?" -- consulted by BOTH the query path
     * (AdminViewReadCoordinator, which sets the _abj404_*_sort_key_present table
     * options the ViewQueryBuilder reads) AND the header UI (ViewReadService::
     * isSortReadyForOrderby, which disables the sort link with a progress tooltip).
     * Centralised here so those two paths cannot drift: a sort the query refuses
     * to order by must also be the one the header disables, and vice versa.
     *
     * Ready requires ALL THREE, because a filesort on the captured majority can
     * exceed a shared host's max_statement_time:
     *   1. the column exists (the column-add ALTER ran);
     *   2. EVERY composite index backing it exists (the index-add ALTER ran -- it
     *      can fail or lag independently of the column-add and the drain, e.g.
     *      disk full or online-DDL refused, leaving an ORDER BY on the key as an
     *      unindexed filesort); and
     *   3. the one-time legacy-row drain has converged (the backfill latch is set,
     *      so no row still carries a NULL key that would bucket to id order).
     * Any one missing means the only correct serve is the wide-column filesort, so
     * the read falls back to the safe default instead of ordering by the key.
     *
     * @param string $column url_sort_key | dest_sort_key.
     * @return bool
     */
    public function sortKeyReadyForColumn(string $column): bool {
        return self::trace('readiness_evaluation', array('column' => $column), function () use ($column): bool {
            if (!$this->sortKeySchemaAvailableForColumn($column)) {
                return false;
            }
            $latch = ABJ_404_Solution_RedirectsDenormColumnSql::sortKeyBackfillLatchOption($column);
            return $latch !== '' && function_exists('get_option') && self::trace(
                'latch_option_read',
                array('column' => $column, 'option' => $latch),
                static fn(): bool => get_option($latch) === '1'
            );
        });
    }

    /**
     * Whether the backing schema for a narrow sort-key column exists: the column
     * itself and every composite index that can serve the ORDER BY. This is the
     * permanent-vs-temporary half of readiness; a false result means no drain can
     * make the sort ready until the upgrade/self-heal DDL repairs the table.
     *
     * @param string $column url_sort_key | dest_sort_key.
     * @return bool
     */
    public function sortKeySchemaAvailableForColumn(string $column): bool {
        return self::trace('schema_readiness', array('column' => $column), function () use ($column): bool {
            if (!isset($this->redirectsColumnSet()[$column])) {
                return false;
            }
            return $this->sortKeyCompositeIndexesPresent($column);
        });
    }

    /**
     * Whether every composite index registered for a narrow sort-key column can
     * actually serve an ORDER BY on that column. A column with no registered
     * composites is treated as never index-ready (the safe default).
     *
     * Each index must exist AND contain the sort column. The name alone is not
     * evidence: MySQL and MariaDB silently strip a dropped column out of every
     * index that named it and keep the rest under the same name, so a table
     * that once ran a build predating url_sort_key carries
     * idx_status_disabled_url_sort_id defined as (status, disabled, id). Taking
     * the name as proof told the read path a sort was index-ordered while it
     * filesorted the whole captured partition -- the exact failure this gate
     * exists to prevent.
     *
     * @param string $column url_sort_key | dest_sort_key.
     * @return bool
     */
    private function sortKeyCompositeIndexesPresent(string $column): bool {
        $required = ABJ_404_Solution_RedirectsDenormColumnSql::sortKeyCompositeIndexNames($column);
        if (empty($required)) {
            return false;
        }
        $present = $this->redirectsIndexSet();
        foreach ($required as $indexName) {
            $definition = $present[strtolower($indexName)] ?? null;
            if (!is_array($definition)
                    || !ABJ_404_Solution_TableIndexDefinitions::containsColumn($definition, $column)) {
                return false;
            }
        }
        return true;
    }

    /**
     * The live index definitions of wp_abj404_redirects, keyed by lowercased
     * index name, fetched once per request via a single SHOW INDEX. Same
     * per-request memoization and schema-drift tolerance as redirectsColumnSet():
     * an unreadable probe yields an empty set, so every index check degrades to
     * false (the safe fallback). Runs only when the admin redirects view is
     * rendered -- not on the frontend 404 hot path.
     *
     * @return array<string, array{name: string, columns: array<int, array{column: string, prefix: int|null}>, unique: bool}>
     */
    private function redirectsIndexSet(): array {
        if ($this->redirectsIndexSetCache !== null) {
            return $this->redirectsIndexSetCache;
        }
        $definitions = self::trace('index_schema_probe', array(), function () {
            $table = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
            return (new ABJ_404_Solution_TableIndexDefinitions($this->dbCore))->readLive($table);
        });
        $this->redirectsIndexSetCache = is_array($definitions) ? $definitions : array();
        return $this->redirectsIndexSetCache;
    }

    /**
     * The lowercased column-name set of wp_abj404_redirects, fetched once per
     * request via a single SHOW COLUMNS. Schema-drift tolerance (defensive
     * philosophy #1/#7): a site whose column-add ALTER never completed is served
     * off whatever columns it does have. An empty/failed probe yields an empty
     * set, so every presence check degrades to false (the safe fallback).
     *
     * @return array<string,bool>
     */
    private function redirectsColumnSet(): array {
        if ($this->redirectsColumnSetCache !== null) {
            return $this->redirectsColumnSetCache;
        }
        $result = self::trace('column_schema_probe', array(), function (): array {
            $table = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
            return $this->dbCore->queryAndGetResults("SHOW COLUMNS FROM " . $table,
                array('log_errors' => false));
        });
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        $set = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $key => $value) {
                if (strtolower((string)$key) === 'field' && is_scalar($value)) {
                    $set[strtolower((string)$value)] = true;
                    break;
                }
            }
        }
        $this->redirectsColumnSetCache = $set;
        return $set;
    }

    /**
     * @template T
     * @param array<string,mixed> $fields
     * @param callable():T $work
     * @return T
     */
    private static function trace(string $operation, array $fields, callable $work) {
        if (self::$operationTracer === null) {
            return $work();
        }
        return (self::$operationTracer)($operation, $fields, $work);
    }
}
