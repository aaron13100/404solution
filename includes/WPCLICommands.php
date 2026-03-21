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
 *   list     — list redirects
 *   create   — create a manual redirect
 *   delete   — move a redirect to trash
 *   stats    — show summary statistics
 *   purge    — purge captured 404s
 */
class ABJ_404_Solution_WPCLICommands extends \WP_CLI_Command {

    /**
     * List redirects.
     *
     * ## OPTIONS
     *
     * [--status=<status>]
     * : Filter by status. One of: manual, auto, captured, regex.
     *
     * [--format=<format>]
     * : Output format. One of: table, csv, json. Default: table.
     *
     * ## EXAMPLES
     *
     *     wp abj404 list
     *     wp abj404 list --status=manual --format=json
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

        // Map status string to numeric constant.
        $types = $this->statusStringToTypes($status);

        // Fetch all matching rows using a simple paginated loop (max 2000 rows for CLI safety).
        $rows = $this->fetchRedirectRows($dao, $types, 2000);

        if (empty($rows)) {
            \WP_CLI::line('No redirects found.');
            return;
        }

        $fields = array('id', 'url', 'status', 'type', 'final_dest', 'code', 'disabled', 'timestamp');
        \WP_CLI\Utils\format_items($format, $rows, $fields);
    }

    /**
     * Create a manual redirect.
     *
     * ## OPTIONS
     *
     * --from=<url>
     * : The source URL (relative path, e.g. /old-page).
     *
     * --to=<url>
     * : The destination URL or path.
     *
     * [--code=<code>]
     * : HTTP redirect code. One of: 301, 302. Default: 301.
     *
     * [--regex]
     * : Treat the source URL as a regular expression.
     *
     * ## EXAMPLES
     *
     *     wp abj404 create --from=/old-page --to=/new-page
     *     wp abj404 create --from=/old-page --to=https://example.com/new --code=302
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
        $code  = isset($assocArgs['code']) ? absint($assocArgs['code']) : 301;
        $regex = isset($assocArgs['regex']);

        if ($from === '') {
            \WP_CLI::error('--from is required.');
            return;
        }
        if ($to === '') {
            \WP_CLI::error('--to is required.');
            return;
        }
        if (!in_array($code, array(301, 302), true)) {
            \WP_CLI::warning('Invalid redirect code; defaulting to 301.');
            $code = 301;
        }

        $status    = $regex ? (string)ABJ404_STATUS_REGEX : (string)ABJ404_STATUS_MANUAL;
        $type      = $this->detectType($to);
        $insertedId = $dao->setupRedirect($from, $status, $type, $to, (string)$code, 0, 'wp-cli');

        if ($insertedId) {
            \WP_CLI::success("Redirect created (ID: {$insertedId}): {$from} -> {$to} [{$code}]");
        } else {
            \WP_CLI::error('Failed to create redirect. Check that the source URL is unique.');
        }
    }

    /**
     * Move a redirect to the trash.
     *
     * ## OPTIONS
     *
     * <id>
     * : The ID of the redirect to trash.
     *
     * ## EXAMPLES
     *
     *     wp abj404 delete 42
     *
     * @param array<int, string>    $args
     * @param array<string, string> $assocArgs
     * @return void
     */
    public function delete($args, $assocArgs) {
        require_once __DIR__ . '/DataAccess.php';

        if (empty($args[0])) {
            \WP_CLI::error('Please provide a redirect ID.');
            return;
        }

        $id  = absint($args[0]);
        $dao = ABJ_404_Solution_DataAccess::getInstance();

        $error = $dao->moveRedirectsToTrash($id, 1);

        if ($error === '') {
            \WP_CLI::success("Redirect ID {$id} moved to trash.");
        } else {
            \WP_CLI::error("Failed to trash redirect: {$error}");
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
     * ## EXAMPLES
     *
     *     wp abj404 purge captured
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

        $table = $wpdb->prefix . 'abj404_redirects';
        $statusIn = implode(', ', array(
            ABJ404_STATUS_CAPTURED,
            ABJ404_STATUS_IGNORED,
            ABJ404_STATUS_LATER,
        ));

        $deleted = $wpdb->query(
            "DELETE FROM `{$table}` WHERE status IN ({$statusIn}) AND disabled = 0"
        );

        if ($deleted === false) {
            \WP_CLI::error('Database error: ' . $wpdb->last_error);
            return;
        }

        \WP_CLI::success("Purged {$deleted} captured 404 entries.");
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

        $table = $wpdb->prefix . 'abj404_redirects';
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
            case 'regex':
                return array(ABJ404_STATUS_REGEX);
            default:
                return array();
        }
    }

    /**
     * Detect the redirect type constant for a destination URL.
     *
     * @param string $to
     * @return string
     */
    private function detectType($to) {
        if (strncasecmp($to, 'http://', 7) === 0 || strncasecmp($to, 'https://', 8) === 0) {
            return (string)ABJ404_TYPE_EXTERNAL;
        }
        return (string)ABJ404_TYPE_HOME;
    }
}
