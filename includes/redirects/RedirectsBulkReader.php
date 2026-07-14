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

    /** @return array<int, array<string, mixed>> */
    public function getRedirectsWithRegEx(): array {
        $cached = ABJ_404_Solution_RedirectsRepository::getRegexRedirectsCache();
        $disabled = ABJ_404_Solution_RedirectsRepository::isRegexCacheDisabled();

        if ($cached !== null && !$disabled) {
            return $cached;
        }

        if ($disabled) {
            return $this->queryBuilder->queryRegexRedirects(ABJ_404_Solution_RedirectsRepository::REGEX_CACHE_MAX_COUNT + 1);
        }

        $results = $this->queryBuilder->queryRegexRedirects(ABJ_404_Solution_RedirectsRepository::REGEX_CACHE_MAX_COUNT + 1);

        if (count($results) <= ABJ_404_Solution_RedirectsRepository::REGEX_CACHE_MAX_COUNT) {
            ABJ_404_Solution_RedirectsRepository::setRegexRedirectsCache($results);
        } else {
            ABJ_404_Solution_RedirectsRepository::setRegexCacheDisabled(true);
        }

        return $results;
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
