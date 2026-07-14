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
 * (`['source_url' => string, 'dest_url' => string, 'code' => int, 'is_regex' => bool]`),
 * or counts them without materializing rows. It makes no decision about how
 * those rows become 404 Solution redirects; that business logic lives in
 * {@see ABJ_404_Solution_CrossPluginImporter}. Actual query execution and
 * driver-error interpretation is delegated to
 * {@see ABJ_404_Solution_ForeignSourceQueryGateway}; this class owns only
 * the per-source schema knowledge (table/CPT names, column names, WHERE
 * filters) for each of the five supported sources.
 *
 * Supported sources:
 *   - Rank Math (rank_math_redirections)
 *   - Yoast SEO Premium (yoast_seo_redirects)
 *   - AIOSEO (aioseo_redirects)
 *   - Safe Redirect Manager (redirect_rule CPT)
 *   - Redirection plugin (redirection_items)
 */
class ABJ_404_Solution_ForeignRedirectSourceReader {

    /**
     * Row/post count per page for every paginated foreign-source read (M502,
     * 2026-07-14): `LIMIT`/`OFFSET` for table-backed readers, `posts_per_page`
     * for the CPT-backed Safe Redirect Manager reader. 500 keeps a page's PHP
     * array well within shared-hosting memory limits even at VARCHAR(2048)
     * source/dest width, while still finishing typical sites in 1-2 pages.
     */
    public const IMPORT_PAGE_SIZE = 500;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_ForeignSourceQueryGateway */
    private $queryGateway;

    /**
     * @param mixed $redirectsRepository Used only to resolve a database query
     *                                   service when one is not supplied directly.
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_DatabaseQueryInterface|null $dbQuery
     */
    public function __construct($redirectsRepository, $logger, $dbQuery = null) {
        $this->logger = $logger;
        $this->queryGateway = new ABJ_404_Solution_ForeignSourceQueryGateway($redirectsRepository, $logger, $dbQuery);
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
            $detected[$slug] = $this->queryGateway->tableExists($tableName);
        }

        // Safe Redirect Manager uses a custom post type, not a dedicated table.
        // Detect it via the CPT registration rather than a table check.
        $detected['safe-redirect-manager'] = function_exists('post_type_exists') && post_type_exists('redirect_rule');

        return $detected;
    }

    /**
     * Read and normalize redirect rows from the given source plugin as a
     * generator that yields one normalized row at a time.
     *
     * Each per-source reader pages in IMPORT_PAGE_SIZE-row chunks (table-
     * backed: `LIMIT`/`OFFSET`; CPT-backed Safe Redirect Manager: paged
     * get_posts()) rather than one unbounded read (M502, 2026-07-14: a
     * source table with tens of thousands of rows previously materialized
     * the entire result set in PHP memory before a row was written). The
     * caller ({@see ABJ_404_Solution_CrossPluginImporter::importFrom()})
     * consumes this row-by-row, so at most one page is ever held in memory.
     *
     * @param string $source One of 'rankmath', 'yoast', 'aioseo',
     *                       'safe-redirect-manager', 'redirection'
     * @return \Generator<int, array<string, mixed>>
     */
    public function readSource(string $source): \Generator {
        switch ($source) {
            case 'rankmath':
                yield from $this->readRankMath();
                return;
            case 'yoast':
                yield from $this->readYoast();
                return;
            case 'aioseo':
                yield from $this->readAIOSEO();
                return;
            case 'safe-redirect-manager':
                yield from $this->readSafeRedirectManager();
                return;
            case 'redirection':
                yield from $this->readRedirection();
                return;
            default:
                $this->logger->debugMessage(
                    'CrossPluginImporter: unknown source "' . $source . '". Returning empty.'
                );
                return;
        }
    }

    /**
     * Count redirect rows available from the given source plugin without
     * materializing the full row set (M502, 2026-07-14: the AJAX preview
     * handler previously called readSource() -- which fully reads every row
     * from the source plugin's storage -- solely to count(), risking memory
     * or time exhaustion on a large source history).
     *
     * The four sources backed by a dedicated DB table get a real
     * `SELECT COUNT(*)` mirroring the WHERE clause of the matching
     * read*() method above. Safe Redirect Manager is a custom post type,
     * not a table, so it is counted with wp_count_posts() -- a single
     * grouped-by-status COUNT query WordPress core already provides,
     * not a per-row get_posts() fetch. All five sources therefore get a
     * genuine count-only path; none require unserializing a full options
     * blob (this plugin's cross-plugin sources are table/CPT-backed only).
     *
     * @param string $source One of 'rankmath', 'yoast', 'aioseo',
     *                       'safe-redirect-manager', 'redirection'
     * @return int
     */
    public function countSource(string $source): int {
        global $wpdb;

        switch ($source) {
            case 'rankmath':
                return $this->countTableRows($wpdb->prefix . 'rank_math_redirections', "status = 'active'");
            case 'yoast':
                return $this->countTableRows($wpdb->prefix . 'yoast_seo_redirects', '');
            case 'aioseo':
                return $this->countTableRows($wpdb->prefix . 'aioseo_redirects', "status = 'active'");
            case 'redirection':
                return $this->countTableRows($wpdb->prefix . 'redirection_items', "status = 'enabled'");
            case 'safe-redirect-manager':
                return $this->countSafeRedirectManager();
            default:
                $this->logger->debugMessage(
                    'CrossPluginImporter: unknown source "' . $source . '" for count. Returning 0.'
                );
                return 0;
        }
    }

    /**
     * Page through a table-backed SELECT in IMPORT_PAGE_SIZE-row chunks,
     * appending `ORDER BY \`id\` ASC LIMIT <n> OFFSET <n>` to $baseSql and
     * re-issuing the query until a page returns fewer than IMPORT_PAGE_SIZE
     * rows. Centralizes the pagination loop so every table-backed reader
     * below shares one implementation (M502: previously each reader issued
     * its own single unbounded SELECT with no LIMIT).
     *
     * Every source table this class reads from (rank_math_redirections,
     * yoast_seo_redirects, aioseo_redirects, redirection_items) has an
     * auto-increment `id` primary key, so ORDER BY id ASC gives stable,
     * gap-free paging.
     *
     * IMPORT_PAGE_SIZE and $offset are internally-generated integers (never
     * derived from request input), so inlining them into the SQL string is
     * safe; {@see ABJ_404_Solution_ForeignSourceQueryGateway::queryRows()}
     * takes a plain SQL string with no placeholder/param support.
     *
     * @param string $baseSql SELECT ... FROM ... [WHERE ...], without ORDER BY/LIMIT/OFFSET
     * @return \Generator<int, array<string, mixed>> Raw (un-normalized) rows
     */
    private function pageTableQuery(string $baseSql): \Generator {
        $offset = 0;
        while (true) {
            $sql = $baseSql . ' ORDER BY `id` ASC LIMIT ' . (int)self::IMPORT_PAGE_SIZE . ' OFFSET ' . (int)$offset;
            $rows = $this->queryGateway->queryRows($sql);

            if (empty($rows)) {
                return;
            }

            foreach ($rows as $row) {
                yield $row;
            }

            if (count($rows) < self::IMPORT_PAGE_SIZE) {
                return;
            }
            $offset += self::IMPORT_PAGE_SIZE;
        }
    }

    /**
     * Read Rank Math redirections from rank_math_redirections table.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function readRankMath(): \Generator {
        global $wpdb;

        $tableName = $wpdb->prefix . 'rank_math_redirections';
        if (!$this->queryGateway->tableExists($tableName)) {
            return;
        }

        foreach ($this->pageTableQuery(
            "SELECT source_url, dest_url, redirect_type, regex_flag
             FROM `{$tableName}`
             WHERE status = 'active'"
        ) as $row) {
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
            yield array(
                'source_url' => $sourceUrl,
                'dest_url'   => $destUrl,
                'code'       => $code,
                'is_regex'   => $isRegex,
            );
        }
    }

    /**
     * Read Yoast SEO Premium redirects from yoast_seo_redirects table.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function readYoast(): \Generator {
        global $wpdb;

        $tableName = $wpdb->prefix . 'yoast_seo_redirects';
        if (!$this->queryGateway->tableExists($tableName)) {
            return;
        }

        foreach ($this->pageTableQuery(
            "SELECT origin, target, redirect_type
             FROM `{$tableName}`"
        ) as $row) {
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
            yield array(
                'source_url' => $sourceUrl,
                'dest_url'   => $destUrl,
                'code'       => $code,
                'is_regex'   => false,
            );
        }
    }

    /**
     * Read AIOSEO redirects from aioseo_redirects table.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function readAIOSEO(): \Generator {
        global $wpdb;

        $tableName = $wpdb->prefix . 'aioseo_redirects';
        if (!$this->queryGateway->tableExists($tableName)) {
            return;
        }

        foreach ($this->pageTableQuery(
            "SELECT source, target, type
             FROM `{$tableName}`
             WHERE status = 'active'"
        ) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sourceUrl = isset($row['source']) && is_string($row['source']) ? trim($row['source']) : '';
            $destUrl   = isset($row['target']) && is_string($row['target']) ? trim($row['target']) : '';
            $code      = isset($row['type']) && is_numeric($row['type']) ? (int)$row['type'] : 301;

            if ($sourceUrl === '' || $destUrl === '') {
                continue;
            }
            yield array(
                'source_url' => $sourceUrl,
                'dest_url'   => $destUrl,
                'code'       => $code,
                'is_regex'   => false,
            );
        }
    }

    /**
     * Read Safe Redirect Manager redirects via the redirect_rule custom post
     * type, one IMPORT_PAGE_SIZE page of posts at a time (M502: previously
     * `posts_per_page => -1` loaded every published redirect_rule post --
     * plus a get_post_meta() lookup per post -- into memory in one call).
     * Pages via WP_Query's standard `paged` parameter until a page returns
     * fewer posts than the page size.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function readSafeRedirectManager(): \Generator {
        if (!function_exists('get_posts')) {
            return;
        }

        $paged = 1;
        while (true) {
            $posts = get_posts(array(
                'post_type'      => 'redirect_rule',
                'posts_per_page' => self::IMPORT_PAGE_SIZE,
                'paged'          => $paged,
                'post_status'    => 'publish',
                'orderby'        => 'ID',
                'order'          => 'ASC',
            ));

            if (!is_array($posts) || empty($posts)) {
                return;
            }

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
                yield array(
                    'source_url' => $from,
                    'dest_url'   => $to,
                    'code'       => $code,
                    'is_regex'   => false,
                );
            }

            if (count($posts) < self::IMPORT_PAGE_SIZE) {
                return;
            }
            $paged++;
        }
    }

    /**
     * Read Redirection plugin redirects from redirection_items table.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function readRedirection(): \Generator {
        global $wpdb;

        $tableName = $wpdb->prefix . 'redirection_items';
        if (!$this->queryGateway->tableExists($tableName)) {
            return;
        }

        foreach ($this->pageTableQuery(
            "SELECT url, action_data, action_code, regex
             FROM `{$tableName}`
             WHERE status = 'enabled'"
        ) as $row) {
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
            yield array(
                'source_url' => $sourceUrl,
                'dest_url'   => $destUrl,
                'code'       => $code,
                'is_regex'   => $isRegex,
            );
        }
    }

    /**
     * Issue a COUNT(*) against a source-plugin table, through the same
     * gateway queryRows() uses for full reads, mirroring the WHERE clause
     * the row-reading method for that source applies. Returns 0 (rather
     * than throwing) when the table is absent or the query fails -- the
     * caller treats "nothing importable" and "can't tell" the same way the
     * existing preview path already does.
     *
     * @param string $tableName   Fully-prefixed table name
     * @param string $whereClause SQL WHERE condition without the "WHERE " keyword, or '' for none
     * @return int
     */
    private function countTableRows(string $tableName, string $whereClause): int {
        if (!$this->queryGateway->tableExists($tableName)) {
            return 0;
        }

        $sql = "SELECT COUNT(*) AS cnt FROM `{$tableName}`";
        if ($whereClause !== '') {
            $sql .= " WHERE {$whereClause}";
        }

        $rows = $this->queryGateway->queryRows($sql);
        if (empty($rows) || !is_array($rows[0])) {
            return 0;
        }

        // Case-insensitive key lookup: information_schema-style result keys
        // vary in case across MySQL/MariaDB drivers/versions (defensive
        // coding rule: case-insensitive metadata access).
        foreach ($rows[0] as $key => $value) {
            if (strcasecmp((string)$key, 'cnt') === 0) {
                return is_numeric($value) ? (int)$value : 0;
            }
        }
        return 0;
    }

    /**
     * Count Safe Redirect Manager rows via wp_count_posts(), matching the
     * post_status filter readSafeRedirectManager() applies ('publish').
     * wp_count_posts() runs a single grouped COUNT query against wp_posts;
     * unlike get_posts(), it never loads full post objects or postmeta, so
     * it is the count-only counterpart for a CPT-backed source exactly as
     * SELECT COUNT(*) is for a DB-table-backed source.
     *
     * @return int
     */
    private function countSafeRedirectManager(): int {
        if (!function_exists('post_type_exists') || !post_type_exists('redirect_rule')) {
            return 0;
        }
        if (!function_exists('wp_count_posts')) {
            return 0;
        }

        $counts = wp_count_posts('redirect_rule');
        if (!is_object($counts) || !isset($counts->publish)) {
            return 0;
        }
        return is_numeric($counts->publish) ? (int)$counts->publish : 0;
    }
}
