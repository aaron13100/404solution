<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST API controller for the abj404/v1 namespace.
 *
 * Registers routes for managing redirects, captured 404s, logs, stats,
 * and redirect simulation (POST /test).
 *
 * Authentication: WordPress REST nonce (cookie) or application passwords.
 * Permission: manage_options capability required for all endpoints.
 */
class ABJ_404_Solution_RestApiController {

    const NAMESPACE = 'abj404/v1';

    /** @var ABJ_404_Solution_DataAccess */
    private $dao;

    /** @var ABJ_404_Solution_PluginLogic */
    private $logic;

    /**
     * @param ABJ_404_Solution_DataAccess  $dao
     * @param ABJ_404_Solution_PluginLogic $logic
     */
    public function __construct($dao, $logic) {
        $this->dao   = $dao;
        $this->logic = $logic;
    }

    /** @return void */
    public function register() {
        add_action('rest_api_init', array($this, 'registerRoutes'));
        // Hide the plugin namespace from the public REST index to reduce fingerprinting.
        // Authenticated access is unaffected — routes still work normally.
        add_filter('rest_index_data', array($this, 'hideNamespaceFromIndex'));
    }

    /**
     * Remove this plugin's namespace from the publicly-enumerable namespace list
     * returned by GET /wp-json/. All routes remain accessible to authenticated
     * requests; this only prevents unauthenticated namespace discovery.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function hideNamespaceFromIndex($data) {
        if (is_array($data) && isset($data['namespaces']) && is_array($data['namespaces'])) {
            $data['namespaces'] = array_values(
                array_filter($data['namespaces'], function ($ns) {
                    return $ns !== self::NAMESPACE;
                })
            );
        }
        return $data;
    }

    /** @return void */
    public function registerRoutes() {
        register_rest_route(self::NAMESPACE, '/redirects', array(
            array(
                'methods'             => 'GET',
                'callback'            => array($this, 'getRedirects'),
                'permission_callback' => array($this, 'permissionCheck'),
                'args'                => array(
                    'page'     => array('type' => 'integer', 'default' => 1, 'minimum' => 1),
                    'per_page' => array('type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100),
                    'status'   => array('type' => 'string', 'default' => ''),
                    'filter'   => array('type' => 'string', 'default' => ''),
                ),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'createRedirect'),
                'permission_callback' => array($this, 'permissionCheck'),
                'args'                => array(
                    'from'  => array('type' => 'string', 'required' => true),
                    'to'    => array('type' => 'string', 'required' => true),
                    'code'  => array('type' => 'integer', 'default' => 301),
                    'regex' => array('type' => 'boolean', 'default' => false),
                ),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/redirects/(?P<id>\d+)', array(
            array(
                'methods'             => 'PUT',
                'callback'            => array($this, 'updateRedirect'),
                'permission_callback' => array($this, 'permissionCheck'),
                'args'                => array(
                    'id'    => array('type' => 'integer', 'required' => true),
                    'from'  => array('type' => 'string'),
                    'to'    => array('type' => 'string'),
                    'code'  => array('type' => 'integer'),
                    'regex' => array('type' => 'boolean'),
                ),
            ),
            array(
                'methods'             => 'DELETE',
                'callback'            => array($this, 'deleteRedirect'),
                'permission_callback' => array($this, 'permissionCheck'),
                'args'                => array(
                    'id' => array('type' => 'integer', 'required' => true),
                ),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/captured', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'getCaptured'),
            'permission_callback' => array($this, 'permissionCheck'),
            'args'                => array(
                'page'     => array('type' => 'integer', 'default' => 1, 'minimum' => 1),
                'per_page' => array('type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/captured/(?P<id>\d+)/redirect', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'createRedirectFromCaptured'),
            'permission_callback' => array($this, 'permissionCheck'),
            'args'                => array(
                'id'   => array('type' => 'integer', 'required' => true),
                'to'   => array('type' => 'string', 'required' => true),
                'code' => array('type' => 'integer', 'default' => 301),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/stats', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'getStats'),
            'permission_callback' => array($this, 'permissionCheck'),
        ));

        register_rest_route(self::NAMESPACE, '/logs', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'getLogs'),
            'permission_callback' => array($this, 'permissionCheck'),
            'args'                => array(
                'page'     => array('type' => 'integer', 'default' => 1, 'minimum' => 1),
                'per_page' => array('type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/test', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'testRedirect'),
            'permission_callback' => array($this, 'permissionCheck'),
            'args'                => array(
                'url' => array('type' => 'string', 'required' => true),
            ),
        ));
    }

    /**
     * @param \WP_REST_Request $request
     * @return bool
     */
    public function permissionCheck($request) {
        return current_user_can('manage_options');
    }

    /**
     * GET /redirects — list active redirects with optional filtering and pagination.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function getRedirects($request) {
        $rawPage    = $request->get_param('page');
        $rawPerPage = $request->get_param('per_page');
        $rawStatus  = $request->get_param('status');
        $rawFilter  = $request->get_param('filter');

        $page    = max(1, absint(is_scalar($rawPage) ? $rawPage : 1));
        $perPage = min(100, max(1, absint(is_scalar($rawPerPage) ? $rawPerPage : 20)));
        $status  = sanitize_text_field(is_scalar($rawStatus) ? (string)$rawStatus : '');
        $filter  = sanitize_text_field(is_scalar($rawFilter) ? (string)$rawFilter : '');

        // $sub is the tab/view name — always 'abj404_redirects' for this endpoint.
        // $statusFilter is the numeric status filter (0 = all active, or a specific status).
        $sub          = 'abj404_redirects';
        $statusFilter = $this->statusStringToNumericFilter($status);

        $tableOptions = array(
            'orderby' => 'url',
            'order'   => 'ASC',
            'paged'   => $page,
            'perpage' => $perPage,
            'filter'  => $statusFilter,
            'logsid'  => 0,
            'sub'     => $sub,
        );

        $rows  = $this->dao->getRedirectsForView($sub, $tableOptions);
        $total = $this->dao->getRedirectsForViewCount($sub, $tableOptions);

        $rows = is_array($rows) ? $rows : array();

        return new \WP_REST_Response(array(
            'items'       => array_values($rows),
            'total'       => intval($total),
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => max(1, (int)ceil(intval($total) / $perPage)),
        ), 200);
    }

    /**
     * POST /redirects — create a manual redirect.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function createRedirect($request) {
        $rawFrom  = $request->get_param('from');
        $rawTo    = $request->get_param('to');
        $rawCode  = $request->get_param('code');
        $rawRegex = $request->get_param('regex');

        $from  = trim(is_scalar($rawFrom) ? (string)$rawFrom : '');
        $to    = trim(is_scalar($rawTo) ? (string)$rawTo : '');
        $code  = absint(is_scalar($rawCode) ? $rawCode : 301);
        $regex = (bool)$rawRegex;

        if ($from === '') {
            return new \WP_Error('missing_from', __('The "from" URL is required.', '404-solution'), array('status' => 400));
        }
        if ($to === '') {
            return new \WP_Error('missing_to', __('The "to" URL is required.', '404-solution'), array('status' => 400));
        }
        if (!in_array($code, array(301, 302), true)) {
            $code = 301;
        }

        // Determine status and type.
        $status   = $regex ? (string)ABJ404_STATUS_REGEX : (string)ABJ404_STATUS_MANUAL;
        $resolved = $this->resolveDestinationType($to);
        $type     = $resolved['type'];
        $dest     = $resolved['dest'];

        $insertedId = $this->dao->setupRedirect($from, $status, (string)$type, $dest, (string)$code, 0, 'rest-api');

        if (!$insertedId) {
            return new \WP_Error('create_failed', __('Failed to create redirect.', '404-solution'), array('status' => 500));
        }

        return new \WP_REST_Response(array(
            'id'     => intval($insertedId),
            'from'   => $from,
            'to'     => $to,
            'code'   => $code,
            'status' => $status,
        ), 201);
    }

    /**
     * PUT /redirects/{id} — update an existing redirect.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function updateRedirect($request) {
        $rawId    = $request->get_param('id');
        $rawFrom  = $request->get_param('from');
        $rawTo    = $request->get_param('to');
        $rawCode  = $request->get_param('code');
        $rawRegex = $request->get_param('regex');

        $id   = absint(is_scalar($rawId) ? $rawId : 0);
        $from = trim(is_scalar($rawFrom) ? (string)$rawFrom : '');
        $to   = trim(is_scalar($rawTo) ? (string)$rawTo : '');
        $code = ($rawCode !== null) ? absint(is_scalar($rawCode) ? $rawCode : 301) : 301;

        if ($id <= 0) {
            return new \WP_Error('invalid_id', __('Invalid redirect ID.', '404-solution'), array('status' => 400));
        }
        if ($from === '' || $to === '') {
            return new \WP_Error('missing_params', __('Both "from" and "to" parameters are required.', '404-solution'), array('status' => 400));
        }
        if (!in_array($code, array(301, 302), true)) {
            $code = 301;
        }

        $isRegex    = (bool)$rawRegex;
        $statusType = $isRegex ? (string)ABJ404_STATUS_REGEX : (string)ABJ404_STATUS_MANUAL;
        $resolved   = $this->resolveDestinationType($to);
        $type       = $resolved['type'];
        $dest       = $resolved['dest'];

        $error = $this->dao->updateRedirect((int)$type, $dest, $from, $id, (string)$code, $statusType);

        if ($error !== '') {
            return new \WP_Error('update_failed', $error, array('status' => 500));
        }

        return new \WP_REST_Response(array(
            'id'     => $id,
            'from'   => $from,
            'to'     => $to,
            'code'   => $code,
            'status' => $statusType,
        ), 200);
    }

    /**
     * DELETE /redirects/{id} — move redirect to trash (not permanent delete).
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function deleteRedirect($request) {
        $rawId = $request->get_param('id');
        $id    = absint(is_scalar($rawId) ? $rawId : 0);

        if ($id <= 0) {
            return new \WP_Error('invalid_id', __('Invalid redirect ID.', '404-solution'), array('status' => 400));
        }

        $error = $this->dao->moveRedirectsToTrash($id, 1);

        if ($error !== '') {
            return new \WP_Error('trash_failed', $error, array('status' => 500));
        }

        return new \WP_REST_Response(array('trashed' => true, 'id' => $id), 200);
    }

    /**
     * GET /captured — list captured 404 URLs with pagination.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function getCaptured($request) {
        $rawPage    = $request->get_param('page');
        $rawPerPage = $request->get_param('per_page');

        $page    = max(1, absint(is_scalar($rawPage) ? $rawPage : 1));
        $perPage = min(100, max(1, absint(is_scalar($rawPerPage) ? $rawPerPage : 20)));

        $types = array(ABJ404_STATUS_CAPTURED, ABJ404_STATUS_IGNORED, ABJ404_STATUS_LATER);
        $total = $this->dao->getRecordCount($types, 0);

        global $wpdb;
        $redirectsTable = $this->dao->doTableNameReplacements('{wp_abj404_redirects}');
        $statusIn       = implode(', ', array_map('absint', $types));
        $limitStart     = ($page - 1) * $perPage;

        $query = $wpdb->prepare(
            "SELECT id, url, status, type, final_dest, code, timestamp, disabled
             FROM `{$redirectsTable}`
             WHERE status IN ({$statusIn}) AND disabled = 0
             ORDER BY url ASC
             LIMIT %d, %d",
            $limitStart,
            $perPage
        );
        $rows = $wpdb->get_results($query, ARRAY_A);
        $rows = is_array($rows) ? $rows : array();

        return new \WP_REST_Response(array(
            'items'       => array_values($rows),
            'total'       => intval($total),
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => max(1, (int)ceil(intval($total) / $perPage)),
        ), 200);
    }

    /**
     * POST /captured/{id}/redirect — promote a captured 404 to a manual redirect.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function createRedirectFromCaptured($request) {
        $rawId   = $request->get_param('id');
        $rawTo   = $request->get_param('to');
        $rawCode = $request->get_param('code');

        $id   = absint(is_scalar($rawId) ? $rawId : 0);
        $to   = trim(is_scalar($rawTo) ? (string)$rawTo : '');
        $code = absint(is_scalar($rawCode) ? $rawCode : 301);

        if ($id <= 0) {
            return new \WP_Error('invalid_id', __('Invalid captured 404 ID.', '404-solution'), array('status' => 400));
        }
        if ($to === '') {
            return new \WP_Error('missing_to', __('The "to" URL is required.', '404-solution'), array('status' => 400));
        }
        if (!in_array($code, array(301, 302), true)) {
            $code = 301;
        }

        // Load the captured row to get the "from" URL.
        $rows = $this->dao->getRedirectsByIDs(array($id));
        if (empty($rows)) {
            return new \WP_Error('not_found', __('Captured 404 not found.', '404-solution'), array('status' => 404));
        }
        $row  = $rows[0];
        $from = is_array($row) && isset($row['url']) && is_string($row['url']) ? $row['url'] : '';

        if ($from === '') {
            return new \WP_Error('bad_record', __('The captured 404 record has no URL.', '404-solution'), array('status' => 500));
        }

        $resolved = $this->resolveDestinationType($to);
        $type     = $resolved['type'];
        $dest     = $resolved['dest'];
        $error    = $this->dao->updateRedirect((int)$type, $dest, $from, $id, (string)$code, (string)ABJ404_STATUS_MANUAL);

        if ($error !== '') {
            return new \WP_Error('update_failed', $error, array('status' => 500));
        }

        return new \WP_REST_Response(array(
            'id'   => $id,
            'from' => $from,
            'to'   => $to,
            'code' => $code,
        ), 200);
    }

    /**
     * GET /stats — return summary statistics.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function getStats($request) {
        try {
            $snapshot = $this->dao->getStatsDashboardSnapshot(true);
            // getStatsDashboardSnapshot always returns array{refreshed_at, hash, data}.
            $data = is_array($snapshot['data']) ? $snapshot['data'] : array();

            $redirects = isset($data['redirects']) && is_array($data['redirects']) ? $data['redirects'] : array();
            $captured  = isset($data['captured']) && is_array($data['captured']) ? $data['captured'] : array();

            $stats = array(
                'redirects' => array(
                    'auto_301'   => intval(is_scalar($redirects['auto301'] ?? 0) ? $redirects['auto301'] : 0),
                    'auto_302'   => intval(is_scalar($redirects['auto302'] ?? 0) ? $redirects['auto302'] : 0),
                    'manual_301' => intval(is_scalar($redirects['manual301'] ?? 0) ? $redirects['manual301'] : 0),
                    'manual_302' => intval(is_scalar($redirects['manual302'] ?? 0) ? $redirects['manual302'] : 0),
                    'trashed'    => intval(is_scalar($redirects['trashed'] ?? 0) ? $redirects['trashed'] : 0),
                ),
                'captured' => array(
                    'captured' => intval(is_scalar($captured['captured'] ?? 0) ? $captured['captured'] : 0),
                    'ignored'  => intval(is_scalar($captured['ignored'] ?? 0) ? $captured['ignored'] : 0),
                    'trashed'  => intval(is_scalar($captured['trashed'] ?? 0) ? $captured['trashed'] : 0),
                ),
            );

            return new \WP_REST_Response($stats, 200);

        } catch (\Throwable $e) {
            return new \WP_Error('stats_error', $e->getMessage(), array('status' => 500));
        }
    }

    /**
     * GET /logs — return log entries with pagination.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function getLogs($request) {
        $rawPage    = $request->get_param('page');
        $rawPerPage = $request->get_param('per_page');

        $page    = max(1, absint(is_scalar($rawPage) ? $rawPage : 1));
        $perPage = min(100, max(1, absint(is_scalar($rawPerPage) ? $rawPerPage : 20)));

        $tableOptions = array(
            'orderby' => 'timestamp',
            'order'   => 'DESC',
            'paged'   => $page,
            'perpage' => $perPage,
            'logsid'  => 0,
            'filter'  => '',
        );

        $rows  = $this->dao->getLogRecords($tableOptions);
        $total = $this->dao->getLogsCount(0);

        $rows = is_array($rows) ? $rows : array();

        return new \WP_REST_Response(array(
            'items'       => array_values($rows),
            'total'       => intval($total),
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => max(1, (int)ceil(intval($total) / $perPage)),
        ), 200);
    }

    /**
     * POST /test — simulate URL matching: given a URL, return what redirect would fire.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function testRedirect($request) {
        $rawUrl = $request->get_param('url');
        $url    = trim(is_scalar($rawUrl) ? (string)$rawUrl : '');

        if ($url === '') {
            return new \WP_Error('missing_url', __('The "url" parameter is required.', '404-solution'), array('status' => 400));
        }

        // Normalize to relative path for lookup.
        $normalizedUrl = $this->logic->normalizeToRelativePath($url);
        if (!is_string($normalizedUrl) || $normalizedUrl === '') {
            $normalizedUrl = $url;
        }

        // Check for an existing redirect stored in the database.
        $redirect = $this->dao->getExistingRedirectForURL($normalizedUrl);

        if (!is_array($redirect) || empty($redirect) || !isset($redirect['id']) || intval($redirect['id']) === 0) {
            // Also check regex redirects.
            $regexRedirects = $this->dao->getRedirectsWithRegEx();
            $matchedRegex   = null;
            if (is_array($regexRedirects)) {
                foreach ($regexRedirects as $rr) {
                    if (!is_array($rr) || empty($rr['url'])) {
                        continue;
                    }
                    $pattern = is_string($rr['url']) ? $rr['url'] : '';
                    // Patterns are stored without delimiters; wrap in {} like regexMatch() does.
                    $delimited = '{' . $pattern . '}';
                    if ($pattern !== '' && @preg_match($delimited, $normalizedUrl) === 1) {
                        $matchedRegex = $rr;
                        break;
                    }
                }
            }

            if ($matchedRegex !== null) {
                $rrId   = is_scalar($matchedRegex['id'] ?? null) ? intval($matchedRegex['id']) : 0;
                $rrDest = is_scalar($matchedRegex['final_dest'] ?? null) ? (string)$matchedRegex['final_dest'] : '';
                $rrCode = is_scalar($matchedRegex['code'] ?? null) ? intval($matchedRegex['code']) : 301;

                return new \WP_REST_Response(array(
                    'matched'     => true,
                    'type'        => 'regex',
                    'redirect_id' => $rrId,
                    'from'        => $normalizedUrl,
                    'to'          => $rrDest,
                    'code'        => $rrCode,
                ), 200);
            }

            return new \WP_REST_Response(array(
                'matched' => false,
                'url'     => $normalizedUrl,
            ), 200);
        }

        // A stored redirect was found.
        $finalDest = isset($redirect['final_dest']) && is_string($redirect['final_dest']) ? $redirect['final_dest'] : '';

        return new \WP_REST_Response(array(
            'matched'     => true,
            'type'        => 'stored',
            'redirect_id' => intval(is_scalar($redirect['id']) ? $redirect['id'] : 0),
            'from'        => $normalizedUrl,
            'to'          => $finalDest,
            'code'        => intval(is_scalar($redirect['code'] ?? 301) ? ($redirect['code'] ?? 301) : 301),
            'status'      => intval(is_scalar($redirect['status'] ?? 0) ? ($redirect['status'] ?? 0) : 0),
        ), 200);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Map a status string to the 'sub' value expected by getRedirectsForView.
     *
     * @param string $status
     * @return string
     */
    /**
     * Convert a user-facing status string to the numeric filter value used by
     * getRedirectsForView/getRedirectsForViewCount.
     * '0' means "all active" (default).
     *
     * @param string $status
     * @return string
     */
    private function statusStringToNumericFilter($status) {
        switch (strtolower($status)) {
            case 'manual':
                return (string)ABJ404_STATUS_MANUAL;
            case 'auto':
                return (string)ABJ404_STATUS_AUTO;
            case 'regex':
                return (string)ABJ404_STATUS_REGEX;
            default:
                // 0 means "all active redirects".
                return '0';
        }
    }

    /**
     * Resolve the redirect type and final destination for a given URL.
     *
     * ABJ404_TYPE_HOME (5) means "redirect to the home page" — permalinkInfoToArray()
     * ignores the stored final_dest for this type. Internal paths must be resolved to
     * a post ID (ABJ404_TYPE_POST) or stored as ABJ404_TYPE_EXTERNAL so the URL is
     * preserved and used as-is by the redirect pipeline.
     *
     * @param string $to The destination URL provided by the API caller.
     * @return array{type: int, dest: string}
     */
    private function resolveDestinationType($to) {
        // External URLs (http/https).
        if ($this->looksLikeExternalUrl($to)) {
            return array('type' => (int)ABJ404_TYPE_EXTERNAL, 'dest' => $to);
        }

        // Home page: root path or empty string.
        $trimmed = trim($to, '/ ');
        if ($trimmed === '') {
            return array('type' => (int)ABJ404_TYPE_HOME, 'dest' => (string)ABJ404_TYPE_HOME);
        }

        // Try to resolve the internal path to a WordPress post/page.
        if (function_exists('url_to_postid')) {
            $postId = url_to_postid(home_url($to));
            if ($postId > 0) {
                return array('type' => (int)ABJ404_TYPE_POST, 'dest' => (string)$postId);
            }
        }

        // Unresolvable internal path — use EXTERNAL type so the URL is stored
        // and used as-is by the redirect pipeline (FrontendRequestPipeline uses
        // $redirectFinalDest directly for EXTERNAL type).
        return array('type' => (int)ABJ404_TYPE_EXTERNAL, 'dest' => $to);
    }

    /**
     * Return true if the URL starts with http:// or https://, indicating an external URL.
     *
     * @param string $url
     * @return bool
     */
    private function looksLikeExternalUrl($url) {
        return (strncasecmp($url, 'http://', 7) === 0 || strncasecmp($url, 'https://', 8) === 0);
    }
}
