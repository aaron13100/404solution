<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: exercised by RedirectsDenormBackfillIntegrationTest (the sort-key drains run its per-chunk SQL)

/**
 * Data-access for the narrow LEFT(<source>, 191) sort-key columns
 * (dest_sort_key, url_sort_key) on wp_abj404_redirects, one explicit chunk of
 * redirect ids at a time.
 *
 * Owns the two per-chunk SQL operations the sort-key drain orchestrates:
 *
 *   - {@see queryChunkIds()} reads the next id chunk still carrying the
 *     `<target> IS NULL [AND <source> IS NOT NULL]` drainable sentinel, after a
 *     resumable id cursor; and
 *   - {@see populateForIds()} writes `<target> = LEFT(<source>, 191)` for an
 *     explicit chunk of ids.
 *
 * Stateless static helper, exactly like its sibling
 * {@see ABJ_404_Solution_RedirectsDenormChunkResolver}: the orchestrating drain
 * ({@see ABJ_404_Solution_DatabaseUpgradeRedirectsSortKeyBackfill}) passes the
 * DatabaseCore + logger per call and keeps the cursor / time-budget / latch
 * bookkeeping. Centralizing the chunk SQL here keeps the drain a pure
 * orchestration layer (cursor walk, deadline, latch) and this a pure data-access
 * layer (the two redirects-table statements), so the two never tangle.
 *
 * $targetColumn / $sourceColumn are class-internal literals (never request
 * input), so interpolating them is safe, the same way the table name and id
 * list are interpolated. Every statement routes through queryAndGetResults (the
 * centralized DAO error handler), so a read/write failure on a read-only /
 * disk-full host is logged there as a warning and the chunk simply stops
 * (returns null / false) rather than throwing or emailing.
 */
class ABJ_404_Solution_RedirectsSortKeyChunkStore {

    /**
     * Read the next chunk of redirect ids whose narrow sort key still needs
     * populating (NULL target, source present per the guard), after $afterId.
     *
     * @param ABJ_404_Solution_DatabaseCore $dbCore         The DAO used for the read.
     * @param ABJ_404_Solution_Logging      $logger         Warns on a stopping read error.
     * @param string                        $redirectsTable Fully-qualified redirects table name.
     * @param string                        $targetColumn   Class-internal literal column name.
     * @param string                        $sourceGuard    Pre-built " AND <source> IS NOT NULL" or ''.
     * @param int                           $chunkSize
     * @param int                           $afterId
     * @return array<int, int>|null List of ids (possibly empty), or null on a query error.
     */
    public static function queryChunkIds(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_Logging $logger,
        string $redirectsTable,
        string $targetColumn,
        string $sourceGuard,
        int $chunkSize,
        int $afterId
    ): ?array {
        $result = $dbCore->queryAndGetResults(
            "SELECT id FROM " . $redirectsTable .
            " WHERE id > " . (int)$afterId . " AND " . $targetColumn . " IS NULL" . $sourceGuard .
            " ORDER BY id ASC LIMIT " . $chunkSize
        );
        $lastError = isset($result['last_error']) && is_string($result['last_error']) ? $result['last_error'] : '';
        if ($lastError !== '') {
            $logger->warn("drainNarrowSortKey(" . $targetColumn . "): stopping after read error: " . $lastError);
            return null;
        }
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        $ids = array();
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['id']) && is_numeric($row['id'])) {
                $ids[] = (int)$row['id'];
            }
        }
        return $ids;
    }

    /**
     * Populate <target> = LEFT(<source>, 191) for an explicit chunk of ids.
     * Mirrors the sort-key statements {@see
     * ABJ_404_Solution_RedirectsDenormColumnSql::buildDestPublishedStatements}
     * appends, so the one-time drain and the per-chunk write stay in lockstep.
     *
     * @param ABJ_404_Solution_DatabaseCore $dbCore         The DAO used for the write.
     * @param ABJ_404_Solution_Logging      $logger         Warns on a stopping write error.
     * @param string                        $redirectsTable
     * @param string                        $targetColumn Class-internal literal column name.
     * @param string                        $sourceColumn Class-internal literal column name.
     * @param string                        $sourceGuard  Pre-built " AND <source> IS NOT NULL" or ''.
     * @param array<int, int>               $ids
     * @return bool True if the chunk wrote cleanly, false if the write errored.
     */
    public static function populateForIds(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_Logging $logger,
        string $redirectsTable,
        string $targetColumn,
        string $sourceColumn,
        string $sourceGuard,
        array $ids
    ): bool {
        $idList = implode(',', array_map('intval', $ids));
        if ($idList === '') {
            return true;
        }
        $result = $dbCore->queryAndGetResults(
            "UPDATE " . $redirectsTable .
            " SET " . $targetColumn . " = LEFT(" . $sourceColumn . ", 191)" .
            " WHERE 1 = 1" . $sourceGuard . " AND id IN (" . $idList . ")"
        );
        $lastError = isset($result['last_error']) && is_string($result['last_error']) ? $result['last_error'] : '';
        if ($lastError !== '') {
            $logger->warn("drainNarrowSortKey(" . $targetColumn . "): stopping after write error: " . $lastError);
            return false;
        }
        return true;
    }
}
