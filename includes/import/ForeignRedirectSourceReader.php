<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads redirect rows out of other redirect plugins' storage.
 *
 * This is the data-access half of the cross-plugin import feature: it detects
 * which source plugins are installed (by probing for their tables or custom
 * post type) and reads each source's rows into one common normalized shape
 * (`['source_url' => string, 'dest_url' => string, 'code' => int, 'is_regex' => bool]`).
 * It makes no decision about how those rows become 404 Solution redirects; that
 * business logic lives in {@see ABJ_404_Solution_CrossPluginImporter}.
 *
 * Supported sources:
 *   - Rank Math (rank_math_redirections)
 *   - Yoast SEO Premium (yoast_seo_redirects)
 *   - AIOSEO (aioseo_redirects)
 *   - Safe Redirect Manager (redirect_rule CPT)
 *   - Redirection plugin (redirection_items)
 */
class ABJ_404_Solution_ForeignRedirectSourceReader {

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_DatabaseQueryInterface|null */
    private $dbQuery;

    /**
     * @param mixed $redirectsRepository Used only to resolve a database query
     *                                   service when one is not supplied directly.
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_DatabaseQueryInterface|null $dbQuery
     */
    public function __construct($redirectsRepository, $logger, $dbQuery = null) {
        $this->logger = $logger;
        $this->dbQuery = $this->resolveDatabaseQuery($redirectsRepository, $dbQuery);
    }

    /**
     * Detect which source plugins are installed by checking for their DB tables
     * (or, for Safe Redirect Manager, by checking for the CPT).
     *
     * @return array<string, bool> e.g. ['rankmath' => true, 'redirection' => false, ...]
     */
    public function detectInstalledPlugins(): array {
        global $wpdb;

        if (!$wpdb) {
            return array('rankmath' => false, 'yoast' => false, 'aioseo' => false,
                         'redirection' => false, 'safe-redirect-manager' => false);
        }

        $tableMap = array(
            'rankmath'           => $wpdb->prefix . 'rank_math_redirections',
            'yoast'              => $wpdb->prefix . 'yoast_seo_redirects',
            'aioseo'             => $wpdb->prefix . 'aioseo_redirects',
            'redirection'        => $wpdb->prefix . 'redirection_items',
        );

        $detected = array();
        foreach ($tableMap as $slug => $tableName) {
            $detected[$slug] = $this->tableExists($tableName);
        }

        // Safe Redirect Manager uses a custom post type, not a dedicated table.
        // Detect it via the CPT registration rather than a table check.
        $detected['safe-redirect-manager'] = function_exists('post_type_exists') && post_type_exists('redirect_rule');

        return $detected;
    }

    /**
     * Read and normalize all redirect rows from the given source plugin.
     *
     * @param string $source One of 'rankmath', 'yoast', 'aioseo',
     *                       'safe-redirect-manager', 'redirection'
     * @return array<int, array<string, mixed>>
     */
    public function readSource(string $source): array {
        switch ($source) {
            case 'rankmath':
                return $this->readRankMath();
            case 'yoast':
                return $this->readYoast();
            case 'aioseo':
                return $this->readAIOSEO();
            case 'safe-redirect-manager':
                return $this->readSafeRedirectManager();
            case 'redirection':
                return $this->readRedirection();
            default:
                $this->logger->debugMessage(
                    'CrossPluginImporter: unknown source "' . $source . '". Returning empty.'
                );
                return array();
        }
    }

    /**
     * Read Rank Math redirections from rank_math_redirections table.
     *
     * @return array<int, array<string, mixed>>
     */
    private function readRankMath(): array {
        global $wpdb;

        $tableName = $wpdb->prefix . 'rank_math_redirections';
        if (!$this->tableExists($tableName)) {
            return array();
        }

        $rows = $this->querySourceRows(
            "SELECT source_url, dest_url, redirect_type, regex_flag
             FROM `{$tableName}`
             WHERE status = 'active'"
        );

        $result = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sourceUrl = isset($row['source_url']) && is_string($row['source_url']) ? trim($row['source_url']) : '';
            $destUrl   = isset($row['dest_url'])   && is_string($row['dest_url'])   ? trim($row['dest_url'])   : '';
            $code      = isset($row['redirect_type']) && is_numeric($row['redirect_type'])
                             ? (int)$row['redirect_type']
                             : 301;
            $isRegex   = !empty($row['regex_flag']) && $row['regex_flag'] != '0';

            if ($sourceUrl === '' || $destUrl === '') {
                continue;
            }
            $result[] = array(
                'source_url' => $sourceUrl,
                'dest_url'   => $destUrl,
                'code'       => $code,
                'is_regex'   => $isRegex,
            );
        }

        return $result;
    }

    /**
     * Read Yoast SEO Premium redirects from yoast_seo_redirects table.
     *
     * @return array<int, array<string, mixed>>
     */
    private function readYoast(): array {
        global $wpdb;

        $tableName = $wpdb->prefix . 'yoast_seo_redirects';
        if (!$this->tableExists($tableName)) {
            return array();
        }

        $rows = $this->querySourceRows(
            "SELECT origin, target, redirect_type
             FROM `{$tableName}`"
        );

        $result = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sourceUrl = isset($row['origin']) && is_string($row['origin']) ? trim($row['origin']) : '';
            $destUrl   = isset($row['target']) && is_string($row['target']) ? trim($row['target']) : '';
            $code      = isset($row['redirect_type']) && is_numeric($row['redirect_type'])
                             ? (int)$row['redirect_type']
                             : 301;

            if ($sourceUrl === '' || $destUrl === '') {
                continue;
            }
            $result[] = array(
                'source_url' => $sourceUrl,
                'dest_url'   => $destUrl,
                'code'       => $code,
                'is_regex'   => false,
            );
        }

        return $result;
    }

    /**
     * Read AIOSEO redirects from aioseo_redirects table.
     *
     * @return array<int, array<string, mixed>>
     */
    private function readAIOSEO(): array {
        global $wpdb;

        $tableName = $wpdb->prefix . 'aioseo_redirects';
        if (!$this->tableExists($tableName)) {
            return array();
        }

        $rows = $this->querySourceRows(
            "SELECT source, target, type
             FROM `{$tableName}`
             WHERE status = 'active'"
        );

        $result = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sourceUrl = isset($row['source']) && is_string($row['source']) ? trim($row['source']) : '';
            $destUrl   = isset($row['target']) && is_string($row['target']) ? trim($row['target']) : '';
            $code      = isset($row['type']) && is_numeric($row['type']) ? (int)$row['type'] : 301;

            if ($sourceUrl === '' || $destUrl === '') {
                continue;
            }
            $result[] = array(
                'source_url' => $sourceUrl,
                'dest_url'   => $destUrl,
                'code'       => $code,
                'is_regex'   => false,
            );
        }

        return $result;
    }

    /**
     * Read Safe Redirect Manager redirects via the redirect_rule custom post type.
     *
     * @return array<int, array<string, mixed>>
     */
    private function readSafeRedirectManager(): array {
        if (!function_exists('get_posts')) {
            return array();
        }

        $posts = get_posts(array(
            'post_type'      => 'redirect_rule',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
        ));

        if (!is_array($posts)) {
            return array();
        }

        $result = array();
        foreach ($posts as $post) {
            if (!is_object($post)) {
                continue;
            }
            $postId = (int)$post->ID;
            if ($postId === 0) {
                continue;
            }

            $from = get_post_meta($postId, '_redirect_rule_from', true);
            $to   = get_post_meta($postId, '_redirect_rule_to', true);
            $code = get_post_meta($postId, '_redirect_rule_status_code', true);

            $from = is_string($from) ? trim($from) : '';
            $to   = is_string($to)   ? trim($to)   : '';
            $code = is_numeric($code) ? (int)$code : 301;

            if ($from === '' || $to === '') {
                continue;
            }
            $result[] = array(
                'source_url' => $from,
                'dest_url'   => $to,
                'code'       => $code,
                'is_regex'   => false,
            );
        }

        return $result;
    }

    /**
     * Read Redirection plugin redirects from redirection_items table.
     *
     * @return array<int, array<string, mixed>>
     */
    private function readRedirection(): array {
        global $wpdb;

        $tableName = $wpdb->prefix . 'redirection_items';
        if (!$this->tableExists($tableName)) {
            return array();
        }

        $rows = $this->querySourceRows(
            "SELECT url, action_data, action_code, regex
             FROM `{$tableName}`
             WHERE status = 'enabled'"
        );

        $result = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sourceUrl = isset($row['url'])         && is_string($row['url'])         ? trim($row['url'])         : '';
            $destUrl   = isset($row['action_data'])  && is_string($row['action_data'])  ? trim($row['action_data'])  : '';
            $code      = isset($row['action_code'])  && is_numeric($row['action_code'])
                             ? (int)$row['action_code']
                             : 301;
            $isRegex   = !empty($row['regex']) && $row['regex'] != '0';

            if ($sourceUrl === '' || $destUrl === '') {
                continue;
            }
            $result[] = array(
                'source_url' => $sourceUrl,
                'dest_url'   => $destUrl,
                'code'       => $code,
                'is_regex'   => $isRegex,
            );
        }

        return $result;
    }

    /**
     * Check whether a table exists using SHOW TABLES LIKE.
     *
     * @param string $tableName Fully-prefixed table name
     * @return bool
     */
    private function tableExists(string $tableName): bool {
        if (!$this->dbQuery instanceof ABJ_404_Solution_DatabaseQueryInterface) {
            $this->logger->warn(
                'CrossPluginImporter: cannot check source table "' . $tableName . '" because no database query service is available.'
            );
            return false;
        }

        $result = $this->dbQuery->queryAndGetResults(
            'SHOW TABLES LIKE %s',
            array(
                'query_params' => array($tableName),
                'result_type' => defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A',
                'log_errors' => false,
                'skip_repair' => true,
            )
        );

        if ($this->queryFailed($result)) {
            $this->logger->warn(
                'CrossPluginImporter: source table probe failed for "' . $tableName . '". Error: ' .
                $this->queryErrorMessage($result)
            );
            return false;
        }

        return !empty($result['rows']) && is_array($result['rows']);
    }

    /**
     * Read external source-plugin rows through the centralized query pipeline.
     *
     * @param string $sql
     * @return array<int, array<string, mixed>>
     */
    private function querySourceRows(string $sql): array {
        if (!$this->dbQuery instanceof ABJ_404_Solution_DatabaseQueryInterface) {
            $this->logger->warn('CrossPluginImporter: cannot read source rows because no database query service is available.');
            return array();
        }

        $result = $this->dbQuery->queryAndGetResults(
            $sql,
            array('result_type' => defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A')
        );

        if ($this->queryFailed($result)) {
            $this->logger->warn(
                'CrossPluginImporter: source row query failed. Error: ' . $this->queryErrorMessage($result)
            );
            return array();
        }

        $rows = $result['rows'] ?? array();
        if (!is_array($rows)) {
            return array();
        }

        $normalizedRows = array();
        foreach ($rows as $row) {
            if (is_array($row)) {
                $normalizedRows[] = $row;
            }
        }
        return $normalizedRows;
    }

    /**
     * @param array<string, mixed> $result
     * @return bool
     */
    private function queryFailed(array $result): bool {
        if (($result['timed_out'] ?? false) === true) {
            return true;
        }
        return $this->queryErrorMessage($result) !== '';
    }

    /**
     * @param array<string, mixed> $result
     * @return string
     */
    private function queryErrorMessage(array $result): string {
        if (($result['timed_out'] ?? false) === true) {
            return 'query timed out';
        }

        $error = $result['last_error'] ?? '';
        if ($error === '') {
            return '';
        }
        if (is_scalar($error)) {
            return (string)$error;
        }
        if (is_object($error) && method_exists($error, '__toString')) {
            return (string)$error;
        }
        return 'non-scalar database error of type ' . gettype($error);
    }

    /**
     * Resolve the database query service without requiring existing callers
     * to pass the optional constructor argument.
     *
     * @param mixed $redirectsRepository
     * @param mixed $dbQuery
     * @return ABJ_404_Solution_DatabaseQueryInterface|null
     */
    private function resolveDatabaseQuery($redirectsRepository, $dbQuery) {
        if ($dbQuery instanceof ABJ_404_Solution_DatabaseQueryInterface) {
            return $dbQuery;
        }

        if (is_object($redirectsRepository) && method_exists($redirectsRepository, 'getDbCore')) {
            $candidate = $redirectsRepository->getDbCore();
            if ($candidate instanceof ABJ_404_Solution_DatabaseQueryInterface) {
                return $candidate;
            }
        }

        return null;
    }
}
