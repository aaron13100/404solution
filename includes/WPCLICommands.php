<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WP-CLI command group for 404 Solution.
 *
 * Registered as: wp abj404 <subcommand>
 *
 * Subcommands:
 *   list        — list redirects
 *   create      — create a manual redirect
 *   delete      — move a redirect to trash
 *   stats       — show summary statistics
 *   purge       — purge captured 404s
 *   import      — import redirects from a CSV file
 *   export      — export redirects to stdout or a file
 *   flush-cache — clear one or more caches
 *   test        — test which redirect would fire for a URL
 */
class ABJ_404_Solution_WPCLICommands extends \WP_CLI_Command {

    /**
     * List redirects.
     *
     * ## OPTIONS
     *
     * [--status=<status>]
     * : Filter by status. One of: manual, auto, captured, regex, ignored, later.
     *
     * [--format=<format>]
     * : Output format. One of: table, csv, json. Default: table.
     *
     * ## EXAMPLES
     *
     *     wp abj404 list
     *     wp abj404 list --status=manual --format=json
     *     wp abj404 list --status=captured --format=csv
     *
     * @subcommand list
     *
     * @param array<int, string>    $args
     * @param array<string, string> $assocArgs
     * @return void
     */
    public function list_redirects($args, $assocArgs) {
        require_once __DIR__ . '/DataAccess.php';

        $dao    = ABJ_404_Solution_DataAccess::getInstance();
        $status = isset($assocArgs['status']) ? strtolower(trim($assocArgs['status'])) : '';
        $format = isset($assocArgs['format']) ? strtolower(trim($assocArgs['format'])) : 'table';

        $validStatuses = array('', 'manual', 'auto', 'captured', 'regex', 'ignored', 'later');
        if ($status !== '' && !in_array($status, $validStatuses, true)) {
            \WP_CLI::error('Invalid --status value. Choose one of: ' . implode(', ', array_filter($validStatuses)));
            return;
        }

        // Map status string to numeric constant.
        $types = $this->statusStringToTypes($status);

        // Fetch all matching rows (max 2000 rows for CLI safety).
        $rows = $this->fetchRedirectRows($dao, $types, 2000);

        if (empty($rows)) {
            \WP_CLI::line('No redirects found.');
            return;
        }

        // Humanize the integer status column so output is readable.
        foreach ($rows as &$row) {
            $rawStatus = $row['status'] ?? 0;
            $row['status'] = $this->statusIntToLabel(is_numeric($rawStatus) ? (int)$rawStatus : 0);
        }
        unset($row);

        $fields = array('id', 'url', 'status', 'type', 'final_dest', 'code', 'disabled', 'timestamp');
        \WP_CLI\Utils\format_items($format, $rows, $fields);
    }

    /**
     * Create a manual redirect.
     *
     * ## OPTIONS
     *
     * --from=<url>
     * : The source URL (relative path starting with /, e.g. /old-page).
     *   Quote URLs containing & or ? to prevent shell interpretation:
     *   wp abj404 create --from='/search?q=foo' --to=/results
     *
     * [--to=<url>]
     * : The destination URL or path. Required unless --code is 410 or 451.
     *
     * [--code=<code>]
     * : HTTP redirect code. One of: 301, 302, 307, 308, 410, 451. Default: 301.
     *   410 and 451 serve a "Gone" / "Unavailable for Legal Reasons" page with no destination.
     *
     * [--regex]
     * : Treat the source URL as a regular expression.
     *
     * ## EXAMPLES
     *
     *     wp abj404 create --from=/old-page --to=/new-page
     *     wp abj404 create --from=/old-page --to=https://example.com/new --code=302
     *     wp abj404 create --from=/deleted-product --code=410
     *     wp abj404 create --from='/search?q=old' --to='/search?q=new'
     *
     * @param array<int, string>    $args
     * @param array<string, string> $assocArgs
     * @return void
     */
    public function create($args, $assocArgs) {
        require_once __DIR__ . '/DataAccess.php';

        $dao = ABJ_404_Solution_DataAccess::getInstance();

        $from  = isset($assocArgs['from']) ? trim($assocArgs['from']) : '';
        $to    = isset($assocArgs['to']) ? trim($assocArgs['to']) : '';
        $code  = isset($assocArgs['code']) ? (int)$assocArgs['code'] : 301;
        $regex = isset($assocArgs['regex']);

        if ($from === '') {
            \WP_CLI::error('--from is required.');
            return;
        }

        // Warn about missing leading slash — it's a common mistake that creates unmatchable rules.
        if ($from !== '' && $from[0] !== '/' && !preg_match('#^https?://#i', $from)) {
            \WP_CLI::warning("--from '{$from}' does not start with '/'. Incoming requests are matched against the path (e.g. /old-page), so this redirect may never fire.");
        }

        $validCodes = array(301, 302, 307, 308, 410, 451);
        if (!in_array($code, $validCodes, true)) {
            \WP_CLI::warning('Invalid redirect code; defaulting to 301. Valid codes: ' . implode(', ', $validCodes));
            $code = 301;
        }

        // 410 Gone and 451 Unavailable For Legal Reasons serve a terminal page — no destination needed.
        $isTerminalCode = in_array($code, array(410, 451), true);
        if ($to === '' && !$isTerminalCode) {
            \WP_CLI::error('--to is required (omit only when --code is 410 or 451).');
            return;
        }

        if ($isTerminalCode) {
            $dest = '0';
            $type = (string)ABJ404_TYPE_404_DISPLAYED;
        } else {
            $resolved = $this->resolveDestinationType($to);
            $type     = $resolved['type'];
            $dest     = $resolved['dest'];
        }

        $status     = $regex ? (string)ABJ404_STATUS_REGEX : (string)ABJ404_STATUS_MANUAL;
        $insertedId = $dao->setupRedirect($from, $status, $type, $dest, (string)$code, 0, 'wp-cli');

        if ($insertedId) {
            $displayDest = $isTerminalCode ? "(none — {$code})" : "{$to}";
            \WP_CLI::success("Redirect created (ID: {$insertedId}): {$from} → {$displayDest} [{$code}]");
        } else {
            \WP_CLI::error('Failed to create redirect. Check that the source URL is unique.');
        }
    }

    /**
     * Move a redirect to the trash.
     *
     * Accepts either a numeric ID or a source URL. When a URL is given the
     * plugin looks up the matching redirect and resolves it to an ID first.
     *
     * ## OPTIONS
     *
     * <id-or-url>
     * : The numeric ID of the redirect, or the source URL (e.g. /old-page).
     *
     * ## EXAMPLES
     *
     *     wp abj404 delete 42
     *     wp abj404 delete /old-page
     *     wp abj404 delete '/search?q=old'
     *
     * @param array<int, string>    $args
     * @param array<string, string> $assocArgs
     * @return void
     */
    public function delete($args, $assocArgs) {
        require_once __DIR__ . '/DataAccess.php';

        if (empty($args[0])) {
            \WP_CLI::error('Please provide a redirect ID or source URL.');
            return;
        }

        $dao = ABJ_404_Solution_DataAccess::getInstance();
        $arg = trim($args[0]);

        if (ctype_digit($arg)) {
            // Numeric argument — treat as ID.
            $id = (int)$arg;
            if ($id === 0) {
                \WP_CLI::error('Invalid redirect ID.');
                return;
            }
        } else {
            // Non-numeric — look up by source URL.
            $redirect = $dao->getExistingRedirectForURL($arg);
            if (!isset($redirect['id']) || (int)(is_scalar($redirect['id']) ? $redirect['id'] : 0) === 0) {
                \WP_CLI::error("No redirect found for URL: {$arg}");
                return;
            }
            $id = (int)(is_scalar($redirect['id']) ? $redirect['id'] : 0);
            \WP_CLI::line("Resolved '{$arg}' to redirect ID {$id}.");
        }

        $error = $dao->moveRedirectsToTrash($id, 1);

        if ($error === '') {
            \WP_CLI::success("Redirect ID {$id} moved to trash.");
        } else {
            \WP_CLI::error("No redirect with ID {$id} found, or database error: {$error}");
        }
    }

    /**
     * Show summary statistics.
     *
     * ## EXAMPLES
     *
     *     wp abj404 stats
     *
     * @param array<int, string>    $args
     * @param array<string, string> $assocArgs
     * @return void
     */
    public function stats($args, $assocArgs) {
        require_once __DIR__ . '/DataAccess.php';

        $dao      = ABJ_404_Solution_DataAccess::getInstance();
        $snapshot = $dao->getStatsDashboardSnapshot(false);
        // getStatsDashboardSnapshot always returns array{refreshed_at, hash, data}.
        $data = is_array($snapshot['data']) ? $snapshot['data'] : array();

        /** @var array<string, mixed> $dataAsStrMap */
        $dataAsStrMap = $data;
        $redirects = isset($dataAsStrMap['redirects']) && is_array($dataAsStrMap['redirects']) ? $dataAsStrMap['redirects'] : array();
        $captured  = isset($dataAsStrMap['captured']) && is_array($dataAsStrMap['captured']) ? $dataAsStrMap['captured'] : array();

        $rows = array(
            array('metric' => 'Auto (301)',     'count' => intval($redirects['auto301'] ?? 0)),
            array('metric' => 'Auto (302)',     'count' => intval($redirects['auto302'] ?? 0)),
            array('metric' => 'Manual (301)',   'count' => intval($redirects['manual301'] ?? 0)),
            array('metric' => 'Manual (302)',   'count' => intval($redirects['manual302'] ?? 0)),
            array('metric' => 'Trashed',        'count' => intval($redirects['trashed'] ?? 0)),
            array('metric' => 'Captured 404s',  'count' => intval($captured['captured'] ?? 0)),
            array('metric' => 'Ignored',        'count' => intval($captured['ignored'] ?? 0)),
            array('metric' => 'Captured trash', 'count' => intval($captured['trashed'] ?? 0)),
        );

        \WP_CLI\Utils\format_items('table', $rows, array('metric', 'count'));
    }

    /**
     * Purge captured 404s or other data sets.
     *
     * ## OPTIONS
     *
     * <type>
     * : What to purge. Currently only "captured" is supported.
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp abj404 purge captured
     *     wp abj404 purge captured --yes
     *
     * @param array<int, string>    $args
     * @param array<string, string> $assocArgs
     * @return void
     */
    public function purge($args, $assocArgs) {
        $type = isset($args[0]) ? strtolower(trim($args[0])) : '';

        if ($type !== 'captured') {
            \WP_CLI::error('Only "captured" is a valid purge target. Usage: wp abj404 purge captured');
            return;
        }

        require_once __DIR__ . '/DataAccess.php';

        global $wpdb;
        $dao = ABJ_404_Solution_DataAccess::getInstance();

        $table = $dao->doTableNameReplacements('{wp_abj404_redirects}');
        $statusIn = implode(', ', array(
            ABJ404_STATUS_CAPTURED,
            ABJ404_STATUS_IGNORED,
            ABJ404_STATUS_LATER,
        ));

        // Count before confirming so the user knows the blast radius.
        $count = (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}` WHERE status IN ({$statusIn}) AND disabled = 0"
        );

        if ($count === 0) {
            \WP_CLI::line('No captured 404 entries to purge.');
            return;
        }

        \WP_CLI::confirm("This will permanently delete {$count} captured 404 entr" . ($count === 1 ? 'y' : 'ies') . '. Continue?', $assocArgs);

        $deleted = $wpdb->query(
            "DELETE FROM `{$table}` WHERE status IN ({$statusIn}) AND disabled = 0"
        );

        if ($deleted === false) {
            \WP_CLI::error('Database error: ' . $wpdb->last_error);
            return;
        }

        \WP_CLI::success("Purged {$deleted} captured 404 entries.");
    }

    /**
     * Import redirects from a CSV file.
     *
     * ## OPTIONS
     *
     * <file>
     * : Path to the CSV file to import.
     *
     * [--dry-run]
     * : Preview the import without writing to the database.
     *
     * ## EXAMPLES
     *
     *     wp abj404 import redirects.csv
     *     wp abj404 import redirects.csv --dry-run
     *
     * @subcommand import
     *
     * @param array<int, string>    $args
     * @param array<string, string> $assocArgs
     * @return void
     */
    public function import_redirects($args, $assocArgs) {
        if (empty($args[0])) {
            \WP_CLI::error('Please provide a path to the CSV file. Usage: wp abj404 import <file>');
            return;
        }

        $filePath = $args[0];
        if (!file_exists($filePath)) {
            \WP_CLI::error("File not found: {$filePath}");
            return;
        }

        $dryRun = isset($assocArgs['dry-run']);

        require_once __DIR__ . '/DataAccess.php';
        require_once __DIR__ . '/ImportExportService.php';

        $dao     = ABJ_404_Solution_DataAccess::getInstance();
        $logging = ABJ_404_Solution_Logging::getInstance();
        $svc     = new ABJ_404_Solution_ImportExportService($dao, $logging);

        $fileHandle = fopen($filePath, 'r');
        if ($fileHandle === false) {
            \WP_CLI::error("Could not open file: {$filePath}");
            return;
        }

        // Detect delimiter by reading a sample then rewinding.
        $delimiter = $svc->detectCsvDelimiterFromFile($fileHandle);
        rewind($fileHandle);

        $headerColumns  = null;
        $processedRows  = 0;
        $validRows      = 0;
        $invalidRows    = 0;
        $anyIssuesToNote = array();

        while (($row = fgetcsv($fileHandle, 0, $delimiter, '"', '\\')) !== false) {
            $data = array_map(function($v) {
                return trim((string)$v);
            }, $row);

            // Skip blank lines.
            if (count($data) === 1 && $data[0] === '') {
                continue;
            }

            // Detect and consume the header row.
            if ($headerColumns === null && $svc->isCompatibleImportHeaderRow($data)) {
                $headerColumns = $svc->normalizeImportHeaders($data);
                continue;
            }

            $dataArray = ($headerColumns !== null)
                ? $svc->mapImportRowByHeaders($data, $headerColumns)
                : $svc->mapImportRowWithoutHeaders($data);

            if (isset($dataArray['error'])) {
                fclose($fileHandle);
                \WP_CLI::error($dataArray['error']);
                return;
            }

            // Skip header-literal rows that slipped through.
            if (isset($dataArray['from_url']) &&
                    ($dataArray['from_url'] === 'from_url' || $dataArray['from_url'] === 'request')) {
                continue;
            }

            $processedRows++;
            $issues = $svc->loadDataArrayFromFile($dataArray, $dryRun);
            if (count($issues) > 0) {
                $invalidRows++;
            } else {
                $validRows++;
            }
            $anyIssuesToNote = array_merge($anyIssuesToNote, $issues);
        }
        fclose($fileHandle);

        if ($dryRun) {
            \WP_CLI::line("Dry run: valid={$validRows}, invalid={$invalidRows}, total={$processedRows}");
            foreach (array_slice($anyIssuesToNote, 0, 20) as $issue) {
                \WP_CLI::warning($issue);
            }
            return;
        }

        if (count($anyIssuesToNote) > 0) {
            foreach (array_slice($anyIssuesToNote, 0, 20) as $issue) {
                \WP_CLI::warning($issue);
            }
        }
        \WP_CLI::success("Import complete. Valid={$validRows}, invalid={$invalidRows}, total={$processedRows}");
    }

    /**
     * Export redirects to stdout or a file.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. One of: native, redirection, htaccess, nginx, cloudflare, netlify, vercel.
     *   Default: native.
     *
     * [--output=<file>]
     * : Write output to this file path instead of stdout.
     *
     * ## EXAMPLES
     *
     *     wp abj404 export
     *     wp abj404 export --format=htaccess
     *     wp abj404 export --format=native --output=redirects.csv
     *
     * @subcommand export
     *
     * @param array<int, string>    $args
     * @param array<string, string> $assocArgs
     * @return void
     */
    public function export_redirects($args, $assocArgs) {
        require_once __DIR__ . '/DataAccess.php';
        require_once __DIR__ . '/ImportExportService.php';

        $format = isset($assocArgs['format']) ? strtolower(trim($assocArgs['format'])) : 'native';
        $output = isset($assocArgs['output']) ? trim($assocArgs['output']) : '';

        $dao     = ABJ_404_Solution_DataAccess::getInstance();
        $logging = ABJ_404_Solution_Logging::getInstance();
        $svc     = new ABJ_404_Solution_ImportExportService($dao, $logging);

        $serverFormats = array('htaccess', 'nginx', 'cloudflare', 'netlify', 'vercel');
        if (in_array($format, $serverFormats, true)) {
            switch ($format) {
                case 'htaccess':
                    $content = $svc->generateHtaccessRules();
                    break;
                case 'nginx':
                    $content = $svc->generateNginxRules();
                    break;
                case 'cloudflare':
                    $content = $svc->generateCloudflareWorkerScript();
                    break;
                case 'netlify':
                    $content = $svc->generateNetlifyRedirects();
                    break;
                default: // vercel
                    $content = $svc->generateVercelRedirects();
                    break;
            }

            if ($output !== '') {
                if (file_put_contents($output, $content) === false) {
                    \WP_CLI::error("Could not write to file: {$output}");
                    return;
                }
                \WP_CLI::success("Exported {$format} rules to: {$output}");
            } else {
                echo $content;
            }
            return;
        }

        // CSV-based formats (native, redirection).
        $tempFile = sys_get_temp_dir() . '/abj404_export_' . time() . '.csv';

        if ($format === 'redirection') {
            $nativeTemp = sys_get_temp_dir() . '/abj404_export_native_' . time() . '.csv';
            $dao->doRedirectsExport($nativeTemp);
            $error = $svc->convertExportCsvToRedirectionFormat($nativeTemp, $tempFile);
            @unlink($nativeTemp);
            if ($error !== '') {
                \WP_CLI::error("Export conversion failed: {$error}");
                return;
            }
        } else {
            $dao->doRedirectsExport($tempFile);
        }

        if (!file_exists($tempFile)) {
            \WP_CLI::line('No redirects to export.');
            @unlink($tempFile);
            return;
        }

        if ($output !== '') {
            if (!rename($tempFile, $output)) {
                // rename may fail across filesystems; fall back to copy+delete.
                if (!copy($tempFile, $output)) {
                    @unlink($tempFile);
                    \WP_CLI::error("Could not write to file: {$output}");
                    return;
                }
                @unlink($tempFile);
            }
            \WP_CLI::success("Exported {$format} redirects to: {$output}");
        } else {
            $csv = file_get_contents($tempFile);
            @unlink($tempFile);
            if ($csv === false) {
                \WP_CLI::error('Could not read export temp file.');
                return;
            }
            echo $csv;
        }
    }

    /**
     * Flush one or more internal caches.
     *
     * ## OPTIONS
     *
     * [--type=<type>]
     * : Which cache to flush. One of: spelling, ngram, permalink, all. Default: all.
     *
     * ## EXAMPLES
     *
     *     wp abj404 flush-cache
     *     wp abj404 flush-cache --type=spelling
     *     wp abj404 flush-cache --type=permalink
     *
     * @subcommand flush-cache
     *
     * @param array<int, string>    $args
     * @param array<string, string> $assocArgs
     * @return void
     */
    public function flush_cache($args, $assocArgs) {
        require_once __DIR__ . '/DataAccess.php';

        $type = isset($assocArgs['type']) ? strtolower(trim($assocArgs['type'])) : 'all';

        $validTypes = array('spelling', 'ngram', 'permalink', 'all');
        if (!in_array($type, $validTypes, true)) {
            \WP_CLI::error("Invalid type. Choose one of: " . implode(', ', $validTypes));
            return;
        }

        global $wpdb;
        $dao = ABJ_404_Solution_DataAccess::getInstance();
        $flushed = array();

        if ($type === 'spelling' || $type === 'all') {
            $dao->deleteSpellingCache();
            $flushed[] = 'spelling';
        }

        if ($type === 'permalink' || $type === 'all') {
            $dao->truncatePermalinkCacheTable();
            $flushed[] = 'permalink';
        }

        if ($type === 'ngram' || $type === 'all') {
            $ngramTable = $dao->doTableNameReplacements('{wp_abj404_ngram_cache}');
            $wpdb->query("TRUNCATE TABLE `{$ngramTable}`");
            // Reset the initialized flag so the cache is rebuilt on the next request.
            delete_option('abj404_ngram_cache_initialized');
            delete_option('abj404_ngram_rebuild_offset');
            $flushed[] = 'ngram';
        }

        \WP_CLI::success('Flushed caches: ' . implode(', ', $flushed));
    }

    /**
     * Test which stored redirect would fire for a given URL.
     *
     * Checks manual, auto, and regex redirects stored in the database.
     * Does NOT simulate the full spelling/suggestion matching pipeline —
     * a result of "No redirect found" means no stored rule matches, but
     * the plugin might still generate a page-suggestion redirect at runtime.
     *
     * ## OPTIONS
     *
     * <url>
     * : The URL to test (relative path, e.g. /old-page, or absolute URL).
     *   Quote URLs containing & or ? to prevent shell expansion:
     *   wp abj404 test '/search?q=old&page=2'
     *
     * ## EXAMPLES
     *
     *     wp abj404 test /old-page
     *     wp abj404 test https://example.com/old-page
     *     wp abj404 test '/products?id=42'
     *
     * @subcommand test
     *
     * @param array<int, string>    $args
     * @param array<string, string> $assocArgs
     * @return void
     */
    public function test_redirect($args, $assocArgs) {
        if (empty($args[0])) {
            \WP_CLI::error('Please provide a URL to test. Usage: wp abj404 test <url>');
            return;
        }

        require_once __DIR__ . '/DataAccess.php';
        require_once __DIR__ . '/Functions.php';

        $url = trim($args[0]);
        $dao = ABJ_404_Solution_DataAccess::getInstance();

        // Check for an exact match (manual or auto redirect).
        $exact = $dao->getExistingRedirectForURL($url);
        if (isset($exact['id']) && (int)(is_scalar($exact['id']) ? $exact['id'] : 0) !== 0) {
            $dest   = isset($exact['final_dest']) && is_scalar($exact['final_dest']) ? (string)$exact['final_dest'] : '';
            $code   = isset($exact['code']) && is_scalar($exact['code']) ? (string)$exact['code'] : '301';
            $exactId = is_scalar($exact['id']) ? (string)$exact['id'] : '?';
            \WP_CLI::success("Exact match found (ID: {$exactId}): {$url} → {$dest} [{$code}]");
            return;
        }

        // Check for a regex match.
        $regexRedirects = $dao->getRedirectsWithRegEx();
        $f = ABJ_404_Solution_Functions::getInstance();
        foreach ($regexRedirects as $row) {
            $pattern = isset($row['url']) && is_scalar($row['url']) ? (string)$row['url'] : '';
            if ($pattern === '') {
                continue;
            }
            $matches = array();
            if ($f->regexMatch($pattern, $url, $matches)) {
                $dest = isset($row['final_dest']) && is_scalar($row['final_dest']) ? (string)$row['final_dest'] : '';
                $code = isset($row['code']) && is_scalar($row['code']) ? (string)$row['code'] : '301';
                $id   = isset($row['id']) && is_scalar($row['id']) ? (string)$row['id'] : '?';
                \WP_CLI::success("Regex match found (ID: {$id}, pattern: {$pattern}): {$url} → {$dest} [{$code}]");
                return;
            }
        }

        \WP_CLI::line("No redirect found for: {$url}");
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Fetch redirect rows for the given status types (max $limit rows).
     *
     * @param ABJ_404_Solution_DataAccess $dao
     * @param array<int, int>             $types Numeric status constants; empty = all redirects.
     * @param int                         $limit
     * @return array<int, array<string, mixed>>
     */
    private function fetchRedirectRows($dao, array $types, $limit) {
        global $wpdb;

        $table = $dao->doTableNameReplacements('{wp_abj404_redirects}');
        $limit = absint($limit);

        if (!empty($types)) {
            $statusIn = implode(', ', array_map('absint', $types));
            $where    = "WHERE status IN ({$statusIn})";
        } else {
            $where = '';
        }

        $query = "SELECT id, url, status, type, final_dest, code, disabled, timestamp
                  FROM `{$table}`
                  {$where}
                  ORDER BY url ASC
                  LIMIT {$limit}";

        $rows = $wpdb->get_results($query, ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    /**
     * Map a status string to an array of numeric type constants.
     *
     * @param string $status
     * @return array<int, int>
     */
    private function statusStringToTypes($status) {
        switch ($status) {
            case 'manual':
                return array(ABJ404_STATUS_MANUAL);
            case 'auto':
                return array(ABJ404_STATUS_AUTO);
            case 'captured':
                return array(ABJ404_STATUS_CAPTURED);
            case 'ignored':
                return array(ABJ404_STATUS_IGNORED);
            case 'later':
                return array(ABJ404_STATUS_LATER);
            case 'regex':
                return array(ABJ404_STATUS_REGEX);
            default:
                return array();
        }
    }

    /**
     * Map a numeric status constant to a human-readable label for list output.
     *
     * @param int $status
     * @return string
     */
    private function statusIntToLabel($status) {
        switch ($status) {
            case ABJ404_STATUS_MANUAL:   return 'manual';
            case ABJ404_STATUS_AUTO:     return 'auto';
            case ABJ404_STATUS_CAPTURED: return 'captured';
            case ABJ404_STATUS_IGNORED:  return 'ignored';
            case ABJ404_STATUS_LATER:    return 'later';
            case ABJ404_STATUS_REGEX:    return 'regex';
            default:                     return (string)$status;
        }
    }

    /**
     * Resolve the redirect type and final destination for a given URL.
     *
     * ABJ404_TYPE_HOME means "redirect to the home page" — the stored
     * final_dest is ignored. Internal paths must be resolved to a post ID
     * (ABJ404_TYPE_POST) or stored as ABJ404_TYPE_EXTERNAL so the URL is
     * preserved and used as-is by the redirect pipeline.
     *
     * @param string $to
     * @return array{type: string, dest: string}
     */
    private function resolveDestinationType($to) {
        if (strncasecmp($to, 'http://', 7) === 0 || strncasecmp($to, 'https://', 8) === 0) {
            return array('type' => (string)ABJ404_TYPE_EXTERNAL, 'dest' => $to);
        }

        $trimmed = trim($to, '/ ');
        if ($trimmed === '') {
            return array('type' => (string)ABJ404_TYPE_HOME, 'dest' => (string)ABJ404_TYPE_HOME);
        }

        if (function_exists('url_to_postid') && function_exists('home_url')) {
            $postId = url_to_postid(home_url($to));
            if ($postId > 0) {
                return array('type' => (string)ABJ404_TYPE_POST, 'dest' => (string)$postId);
            }
        }

        return array('type' => (string)ABJ404_TYPE_EXTERNAL, 'dest' => $to);
    }
}
