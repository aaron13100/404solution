<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Specialized, non-paginated reads of the redirects table for callers that
 * don't go through the staged admin-list pipeline.
 *
 * Owns:
 *   - doRedirectsExport: stream the redirects table to a CSV temp file
 *   - getRedirectsWithRegEx: regex redirects with a static request-scoped cache
 *   - getManualRedirectsWithRegexMetachars: manual redirects whose URL
 *     contains regex metacharacters (for the matcher's wildcard fallback)
 *   - getExtraDataToPermalinkSuggestions: post metadata for suggestion ids
 *
 * Extracted from ViewReadService in the i805 decomposition. The regex
 * cache is still owned by RedirectsRepository (its static cache holds the
 * per-request state); this reader just consults it.
 */
class ABJ_404_Solution_RedirectsBulkReader {

    /** Number of rows fetched by each keyset page once caching is unsafe. */
    const REGEX_READ_BATCH_SIZE = 250;

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_ViewQueryBuilder */
    private $queryBuilder;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_ViewQueryBuilder $queryBuilder
     * @param ABJ_404_Solution_Functions $f
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_ViewQueryBuilder $queryBuilder,
        $f
    ) {
        $this->dbCore = $dbCore;
        $this->queryBuilder = $queryBuilder;
        $this->f = $f;
    }

    /**
     * Stream the export query straight to a CSV temp file via mysqli to keep
     * the row buffer bounded on large redirect tables.
     *
     * @param string $tempFile
     * @return void
     */
    public function doRedirectsExport(string $tempFile): void {
        global $wpdb;

        if (file_exists($tempFile)) {
            ABJ_404_Solution_FileSystemService::safeUnlink($tempFile);
        }

        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getRedirectsExport.sql");
        $query = $this->dbCore->doTableNameReplacements($query);

        $result = mysqli_query($wpdb->dbh, $query);
        if ($result instanceof \mysqli_result) {
            $fh = fopen($tempFile, 'w');
            if ($fh === false) {
                mysqli_free_result($result);
                return;
            }
            // try/finally: mysqli's default error mode (MYSQLI_REPORT_ERROR |
            // MYSQLI_REPORT_STRICT since PHP 8.1) throws mysqli_sql_exception
            // on a dropped connection mid-fetch. Without a guaranteed close
            // here, that exception would skip fclose($fh) and leak the file
            // handle. Same resource-lifecycle shape as
            // includes/import/ImportService.php::doImportFile().
            try {
                fputcsv($fh, array('from_url', 'status', 'type', 'to_url', 'wp_type', 'engine', 'code'), ',', '"', '\\');

                while (($row = mysqli_fetch_array($result, MYSQLI_ASSOC))) {
                    fputcsv($fh, array(
                        $row['from_url'],
                        $row['status'],
                        $row['type'],
                        $row['to_url'],
                        $row['type_wp'],
                        isset($row['engine']) ? $row['engine'] : '',
                        isset($row['code']) ? $row['code'] : '301'
                    ), ',', '"', '\\');
                }
            } finally {
                fclose($fh);
                mysqli_free_result($result);
            }
        }
    }

    /** @return iterable<int, array<string, mixed>> */
    public function getRedirectsWithRegEx(): iterable {
        $cached = ABJ_404_Solution_RedirectsRepository::getRegexRedirectsCache();
        $disabled = ABJ_404_Solution_RedirectsRepository::isRegexCacheDisabled();

        if ($cached !== null && !$disabled) {
            return $cached;
        }

        if ($disabled) {
            return $this->iterateAllRegexRedirectsInBatches();
        }

        $results = $this->queryBuilder->queryRegexRedirects(ABJ_404_Solution_RedirectsRepository::REGEX_CACHE_MAX_COUNT + 1);

        if (count($results) <= ABJ_404_Solution_RedirectsRepository::REGEX_CACHE_MAX_COUNT) {
            ABJ_404_Solution_RedirectsRepository::setRegexRedirectsCache($results);
        } else {
            ABJ_404_Solution_RedirectsRepository::setRegexCacheDisabled(true);
            return $this->iterateAllRegexRedirectsInBatches($results);
        }

        return $results;
    }

    /**
     * Stream a complete regex redirect read without allowing either a SQL
     * result set or the PHP row collection to grow without bound. The optional
     * leading rows are the cache-threshold probe and are reused so the common
     * 51+ path does not reread them.
     *
     * @param array<int, array<string, mixed>> $leadingRows
     * @return iterable<int, array<string, mixed>>
     */
    private function iterateAllRegexRedirectsInBatches(array $leadingRows = array()): iterable {
        // DESIGN-AUDIT-OK(2026-08-21, owner): A total-work cap would silently make later valid regex rules unreachable, reproducing Troy's 51-rule defect.
        // The admin-owned finite table is streamed in 250-row pages, holds bounded memory, and stops as soon as a caller finds a match.
        $afterId = $this->greatestRedirectId($leadingRows);
        foreach ($leadingRows as $row) {
            yield $row;
        }

        do {
            $page = $this->queryBuilder->queryRegexRedirectsPage(array(
                'after_id' => $afterId,
                'limit' => self::REGEX_READ_BATCH_SIZE,
            ));
            if (empty($page)) {
                break;
            }

            $nextAfterId = $this->greatestRedirectId($page);
            if ($nextAfterId <= $afterId) {
                throw new UnexpectedValueException(
                    'Regex redirect keyset page did not advance past id ' . $afterId . '.'
                );
            }

            foreach ($page as $row) {
                yield $row;
            }
            $afterId = $nextAfterId;
        } while (count($page) === self::REGEX_READ_BATCH_SIZE);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function greatestRedirectId(array $rows): int {
        $greatestId = 0;
        foreach ($rows as $row) {
            $id = isset($row['id']) && is_scalar($row['id']) ? (int)$row['id'] : 0;
            $greatestId = max($greatestId, $id);
        }
        return $greatestId;
    }

    /** @return array<int, array<string, mixed>> */
    // DESIGN-AUDIT-OK(2026-06-19, owner): status=MANUAL AND disabled=0 seeks via
    //   idx_status_disabled to the few hand-made MANUAL rows first, so INSTR() runs only
    //   over that bounded subset, not a full table scan. A LIMIT would drop valid manual
    //   regex redirects (changes results), so no cap. Reviewed + accepted 2026-06-18.
    public function getManualRedirectsWithRegexMetachars(): array {
        $query = "select \n  {wp_abj404_redirects}.id,\n  {wp_abj404_redirects}.url,\n  {wp_abj404_redirects}.status,\n"
                . "  {wp_abj404_redirects}.type,\n  {wp_abj404_redirects}.final_dest,\n  {wp_abj404_redirects}.code,\n"
                . "  {wp_abj404_redirects}.timestamp,\n {wp_posts}.id as wp_post_id\n ";
        $query .= "from {wp_abj404_redirects}\n " .
                "  LEFT OUTER JOIN {wp_posts} \n " .
                "    on {wp_abj404_redirects}.final_dest = {wp_posts}.id \n ";

        $query .= "where status = " . ABJ404_STATUS_MANUAL . " \n " .
                "     and disabled = 0 \n " .
                "     and (INSTR(`url`, '*') > 0 " .
                "       OR INSTR(`url`, '[') > 0 " .
                "       OR INSTR(`url`, ']') > 0 " .
                "       OR INSTR(`url`, '|') > 0 " .
                "       OR INSTR(`url`, '^') > 0 " .
                "       OR INSTR(`url`, '\\\\') > 0 " .
                "       OR INSTR(`url`, '{') > 0 " .
                "       OR INSTR(`url`, '}') > 0)";
        $results = $this->dbCore->queryAndGetResults($query);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = is_array($results['rows']) ? $results['rows'] : array();
        return $rows;
    }

    /**
     * @param array<int, string> $postIDs
     * @return array<int, mixed>
     */
    public function getExtraDataToPermalinkSuggestions(array $postIDs): array {
        $postIDs = array_map('absint', $postIDs);
        $postIDJoined = implode(', ', $postIDs);

        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getAdditionalPostData.sql");
        $query = $this->f->str_replace('{IDS_TO_INCLUDE}', $postIDJoined, $query);
        $query = $this->dbCore->doTableNameReplacements($query);
        $query = $this->f->doNormalReplacements($query);

        $results = $this->dbCore->queryAndGetResults($query);

        /** @var array<int, mixed> $rows */
        $rows = is_array($results['rows']) ? $results['rows'] : array();
        return $rows;
    }
}
