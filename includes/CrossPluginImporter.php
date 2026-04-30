<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cross-plugin redirect importer.
 *
 * Detects installed redirect plugins by checking for their database tables,
 * provides a preview of available redirects, and imports them into the
 * 404 Solution redirects table.
 *
 * Supported sources:
 *   - Rank Math (rank_math_redirections)
 *   - Yoast SEO Premium (yoast_seo_redirects)
 *   - AIOSEO (aioseo_redirects)
 *   - Safe Redirect Manager (redirect_rule CPT)
 *   - Redirection plugin (redirection_items)
 */
class ABJ_404_Solution_CrossPluginImporter {

    /** @var ABJ_404_Solution_DataAccess */
    private $dao;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_DataAccess $dao
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct($dao, $logger) {
        $this->dao    = $dao;
        $this->logger = $logger;
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
     * Return a preview of redirects available from the given source plugin.
     *
     * @param string $source       One of 'rankmath', 'yoast', 'aioseo', 'safe-redirect-manager', 'redirection'
     * @param int    $previewLimit Maximum rows to return for preview
     * @return array<int, array<string, mixed>>
     */
    public function getImportPreview(string $source, int $previewLimit = 10): array {
        $rows = $this->readSource($source);
        return array_slice($rows, 0, $previewLimit);
    }

    /**
     * Import all redirects from the given source plugin.
     * Returns the number of redirects successfully imported.
     *
     * @param string $source
     * @return int
     */
    public function importFrom(string $source): int {
        $rows = $this->readSource($source);

        if (empty($rows)) {
            return 0;
        }

        $imported = 0;
        foreach ($rows as $row) {
            $sourceUrl = isset($row['source_url']) && is_string($row['source_url']) ? $row['source_url'] : '';
            $destUrl   = isset($row['dest_url'])   && is_string($row['dest_url'])   ? $row['dest_url']   : '';
            $code      = isset($row['code'])        && is_numeric($row['code'])      ? (int)$row['code']  : 301;
            $isRegex   = isset($row['is_regex'])    && (bool)$row['is_regex'];

            if ($sourceUrl === '' || $destUrl === '') {
                continue;
            }

            $status = $isRegex ? ABJ404_STATUS_REGEX : ABJ404_STATUS_MANUAL;

            // Determine destination type and resolve internal paths to post IDs.
            $resolved = $this->resolveDestinationType($destUrl);
            $type = $resolved['type'];
            $destUrl = $resolved['dest'];

            $result = $this->dao->setupRedirect(
                $sourceUrl,
                (string)$status,
                (string)$type,
                $destUrl,
                (string)$code,
                0
            );

            if ($result !== 0 && $result !== false) {
                $imported++;
            }
        }

        $this->logger->infoMessage(
            'CrossPluginImporter: imported ' . $imported . ' redirect(s) from "' . $source . '".'
        );

        return $imported;
    }

    // -------------------------------------------------------------------------
    // Private reader methods
    // -------------------------------------------------------------------------

    /**
     * Dispatch to the appropriate source-specific reader.
     *
     * @param string $source
     * @return array<int, array<string, mixed>>
     */
    private function readSource(string $source): array {
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
                    'CrossPluginImporter: unknown source "' . $source . '" — returning empty.'
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

        // DAO-bypass-approved: Reading external plugin's table (Rank Math) — DAO would auto-CREATE
        $rows = $wpdb->get_results(
            "SELECT source_url, dest_url, redirect_type, regex_flag
             FROM `{$tableName}`
             WHERE status = 'active'",
            ARRAY_A
        );

        if (!is_array($rows)) {
            return array();
        }

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

        // DAO-bypass-approved: Reading external plugin's table (Yoast) — DAO would auto-CREATE
        $rows = $wpdb->get_results(
            "SELECT origin, target, redirect_type
             FROM `{$tableName}`",
            ARRAY_A
        );

        if (!is_array($rows)) {
            return array();
        }

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

        // DAO-bypass-approved: Reading external plugin's table (AIOSEO) — DAO would auto-CREATE
        $rows = $wpdb->get_results(
            "SELECT source, target, type
             FROM `{$tableName}`
             WHERE status = 'active'",
            ARRAY_A
        );

        if (!is_array($rows)) {
            return array();
        }

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

        // DAO-bypass-approved: Reading external plugin's table (Redirection) — DAO would auto-CREATE
        $rows = $wpdb->get_results(
            "SELECT url, action_data, action_code, regex
             FROM `{$tableName}`
             WHERE status = 'enabled'",
            ARRAY_A
        );

        if (!is_array($rows)) {
            return array();
        }

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

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the redirect type and final destination for a given URL.
     *
     * External URLs (http/https) use ABJ404_TYPE_EXTERNAL with the URL as-is.
     * Internal paths are resolved via url_to_postid() — if a post ID is found,
     * ABJ404_TYPE_POST is used with the numeric ID. Otherwise ABJ404_TYPE_EXTERNAL
     * is used so the path is preserved and used as-is by the redirect pipeline.
     *
     * @param string $destUrl
     * @return array{type: int, dest: string}
     */
    private function resolveDestinationType(string $destUrl): array {
        if (preg_match('/^https?:\/\//i', $destUrl)) {
            return array('type' => ABJ404_TYPE_EXTERNAL, 'dest' => $destUrl);
        }

        if (function_exists('url_to_postid') && function_exists('home_url')) {
            $postId = url_to_postid(home_url($destUrl));
            if ($postId > 0) {
                return array('type' => ABJ404_TYPE_POST, 'dest' => (string)$postId);
            }
        }

        return array('type' => ABJ404_TYPE_EXTERNAL, 'dest' => $destUrl);
    }

    /**
     * Check whether a table exists using SHOW TABLES LIKE.
     *
     * @param string $tableName Fully-prefixed table name
     * @return bool
     */
    private function tableExists(string $tableName): bool {
        global $wpdb;

        if (!$wpdb || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_var')) {
            return false;
        }
        /** @var \wpdb $wpdb */

        // Use get_var so we get null on miss rather than an error.
        // DAO-bypass-approved: tableExists() probe for external plugin's table
        $result = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $tableName)
        );

        return $result !== null && $result !== false && $result !== '';
    }
}
