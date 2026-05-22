<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/ContentRepositoryInterface.php';

/**
 * Published-content lookups, permalink cache, and spelling cache operations.
 *
 * Extracted from the DataAccess monolith (Phase 1 of the DataAccess refactor).
 * Methods originate from three sources:
 *   - DataAccessTrait_PublishedContent (entirely absorbed)
 *   - DataAccessTrait_Maintenance (permalink/spelling cache methods relocated)
 *   - DataAccessTrait_Stats (permalink cache update methods relocated)
 *
 * Receives a DatabaseCore instance for all query execution.
 */
class ABJ_404_Solution_ContentRepository implements ABJ_404_Solution_ContentRepositoryInterface {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_Logging|null $logging
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        $functions = null,
        $logging = null
    ) {
        $this->dbCore = $dbCore;
        $this->f = $functions !== null ? $functions : abj_service('functions');
        $this->logger = $logging !== null ? $logging : abj_service('logging');
    }

    // =========================================================================
    // Published content lookups (from DataAccessTrait_PublishedContent)
    // =========================================================================

    /** @return string */
    private function getPostsTableName(): string {
        global $wpdb;
        if (isset($wpdb->posts) && is_string($wpdb->posts) && $wpdb->posts !== '') {
            return $wpdb->posts;
        }
        $prefix = isset($wpdb->prefix) && is_string($wpdb->prefix) && $wpdb->prefix !== '' ? $wpdb->prefix : 'wp_';
        return $prefix . 'posts';
    }

    /** @inheritDoc */
    function getPublishedPagesAndPostsIDs($slug = '', $searchTerm = '',
        $limitResults = '', $orderResults = '', $extraWhereClause = '') {
        global $wpdb;
        $abj404logic = abj_service('plugin_logic');
        $postsTableName = $this->getPostsTableName();

        $options = $abj404logic->getOptions();
        $recognizedPostTypes = $this->dbCore->buildPostTypeSqlList($options);
        if ($recognizedPostTypes === '') {
            return array();
        }

        if (!$this->dbCore->tableExists($postsTableName)) {
            $this->logger->errorMessage("WordPress posts table not found: " . $postsTableName .
                ". This may indicate an incorrect table prefix or database configuration issue.");
            return array();
        }

        if ($slug != "") {
            $slug = $this->f->sanitizeInvalidUTF8($slug);

            $collationResult = $this->dbCore->queryAndGetResults(
                "SELECT COLLATION_NAME FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = %s
                 AND COLUMN_NAME = 'post_name'",
                array('query_params' => array($postsTableName), 'log_errors' => false)
            );
            $collationRows = isset($collationResult['rows']) && is_array($collationResult['rows']) ? $collationResult['rows'] : array();
            $columnCollation = null;
            if (!empty($collationRows) && is_array($collationRows[0])) {
                $first = reset($collationRows[0]);
                $columnCollation = is_scalar($first) ? (string)$first : null;
            }
            if ($columnCollation !== null && strpos(strtolower($columnCollation), 'utf8mb4') !== false) {
                $resolvedCollation = $this->dbCore->sanitizeCollationIdentifier($columnCollation);
                if ($resolvedCollation === '') {
                    $resolvedCollation = $this->dbCore->getPreferredUtf8mb4Collation();
                }
                $specifiedSlug = " */\n and CAST(wp_posts.post_name AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci = "
                        . "'" . esc_sql($slug) . "' \n ";
                $specifiedSlug = str_replace('utf8mb4_unicode_ci', $resolvedCollation, $specifiedSlug);
            } else {
                // latin1 databases cannot safely compare utf8mb4 casts; use the native column comparison unless the slug contains 4-byte characters.
                if ($this->f->containsUtf8mb4Characters($slug)) {
                    $specifiedSlug = '';
                } else {
                    $specifiedSlug = " */\n and wp_posts.post_name = "
                            . "'" . esc_sql($slug) . "' \n ";
                }
            }
        } else {
            $specifiedSlug = '';
        }

        if ($searchTerm != "") {
            $searchTerm = " */\n and lower(wp_posts.post_title) like "
                . "'%" . esc_sql($this->f->strtolower($searchTerm)) . "%' \n ";
        } else {
            $searchTerm = '';
        }

        if ($extraWhereClause != "") {
            $extraWhereClause = " */\n " . $extraWhereClause;
        }

        if (!empty($limitResults)) {
            $limitResults = " */\n  limit " . $limitResults;
        }
        if (!empty($orderResults)) {
            $orderResults = " */\n  order by " . $orderResults;
        }

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getPublishedPagesAndPostsIDs.sql");
        $query = $this->dbCore->doTableNameReplacements($query);
        $query = $this->f->str_replace('{recognizedPostTypes}', $recognizedPostTypes, $query);
        $query = $this->f->str_replace('{specifiedSlug}', $specifiedSlug, $query);
        $query = $this->f->str_replace('{searchTerm}', $searchTerm, $query);
        $query = $this->f->str_replace('{extraWhereClause}', $extraWhereClause, $query);
        $query = $this->f->str_replace('{limit-results}', $limitResults, $query);
        $query = $this->f->str_replace('{order-results}', $orderResults, $query);

        $result = $this->dbCore->queryAndGetResults($query, array('result_type' => OBJECT));
        $queryError = is_string($result['last_error'] ?? '') ? ($result['last_error'] ?? '') : '';
        $rows = is_array($result['rows']) ? $result['rows'] : array();

        if (!empty($queryError) && $this->dbCore->isCollationError($queryError)) {
            $fpreg = ABJ_404_Solution_FunctionsPreg::getInstance();
            $fallbackQuery = $fpreg->regexReplace(
                'CONVERT\(wpt\.name USING utf8mb4\) COLLATE [A-Za-z0-9_]+',
                'wpt.name', $query);
            $fallbackQuery = $fpreg->regexReplace(
                'CONVERT\(usefulterms\.grouped_terms USING utf8mb4\) COLLATE [A-Za-z0-9_]+',
                'usefulterms.grouped_terms', is_string($fallbackQuery) ? $fallbackQuery : $query);
            $fallbackResult = $this->dbCore->queryAndGetResults(
                is_string($fallbackQuery) ? $fallbackQuery : $query,
                array('result_type' => OBJECT, 'log_errors' => false));
            $queryError = is_string($fallbackResult['last_error'] ?? '') ? ($fallbackResult['last_error'] ?? '') : '';
            if (empty($queryError)) {
                $rows = is_array($fallbackResult['rows']) ? $fallbackResult['rows'] : array();
            }
        }

        if (!empty($queryError) && $this->dbCore->isInvalidDataError($queryError) &&
                $slug != "" && strpos($query, 'CAST(wp_posts.post_name AS CHAR CHARACTER SET utf8mb4)') !== false) {
            $fallbackSpecifiedSlug = " */\n and wp_posts.post_name = '" . esc_sql($slug) . "' \n ";
            $fallbackQuery = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getPublishedPagesAndPostsIDs.sql");
            $fallbackQuery = $this->dbCore->doTableNameReplacements($fallbackQuery);
            $fallbackQuery = $this->f->str_replace('{recognizedPostTypes}', $recognizedPostTypes, $fallbackQuery);
            $fallbackQuery = $this->f->str_replace('{specifiedSlug}', $fallbackSpecifiedSlug, $fallbackQuery);
            $fallbackQuery = $this->f->str_replace('{searchTerm}', $searchTerm, $fallbackQuery);
            $fallbackQuery = $this->f->str_replace('{extraWhereClause}', $extraWhereClause, $fallbackQuery);
            $fallbackQuery = $this->f->str_replace('{limit-results}', $limitResults, $fallbackQuery);
            $fallbackQuery = $this->f->str_replace('{order-results}', $orderResults, $fallbackQuery);
            $fallbackResult = $this->dbCore->queryAndGetResults($fallbackQuery, array('result_type' => OBJECT, 'log_errors' => false));
            $fallbackError = is_string($fallbackResult['last_error'] ?? '') ? ($fallbackResult['last_error'] ?? '') : '';
            if (empty($fallbackError)) {
                $queryError = '';
                $rows = is_array($fallbackResult['rows']) ? $fallbackResult['rows'] : array();
            }
        }

        if ($queryError) {
            if (stripos($queryError, 'unknown column') !== false &&
                    stripos($queryError, 'content_keywords') !== false) {
                $this->logger->warn("content_keywords column not yet available (DB migration pending): " . $queryError);
            } else if (!$this->dbCore->classifyAndHandleInfrastructureError($queryError)) {
                $this->logger->errorMessage("Error executing query. Err: " . $queryError . ", Query: " . $query);
            }
        }

        return $rows;
    }

    /** @inheritDoc */
    function getPublishedImagesIDs() {
        global $wpdb;
        $abj404logic = abj_service('plugin_logic');

        $options = $abj404logic->getOptions();
        $recognizedPostTypes = $this->dbCore->buildPostTypeSqlList($options);
        if ($recognizedPostTypes === '') {
            return array();
        }

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getPublishedImageIDs.sql");
        $query = $this->dbCore->doTableNameReplacements($query);
        $query = $this->f->str_replace('{recognizedPostTypes}', $recognizedPostTypes, $query);

        $result = $this->dbCore->queryAndGetResults($query, array('result_type' => OBJECT));
        $queryError = is_string($result['last_error'] ?? '') ? ($result['last_error'] ?? '') : '';
        if ($queryError && !$this->dbCore->classifyAndHandleInfrastructureError($queryError)) {
            $this->logger->errorMessage("Error executing query. Err: " . $queryError . ", Query: " . $query);
        }

        return is_array($result['rows']) ? $result['rows'] : array();
    }

    /** @inheritDoc */
    function getPublishedTags($slug = null, $limit = null) {
        global $wpdb;
        $abj404logic = abj_service('plugin_logic');

        $options = $abj404logic->getOptions();
        $recognizedCategories = $this->dbCore->buildCategorySqlList($options);

        if ($slug != null) {
            $slug = $this->f->sanitizeInvalidUTF8($slug);
            $slug = "*/ and wp_terms.slug = '" . esc_sql($slug) . "'\n";
        }

        $limitClause = '';
        if ($limit !== null && is_numeric($limit) && $limit > 0) {
            $limitClause = "LIMIT " . intval($limit);
        }

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getPublishedTags.sql");
        $query = $this->f->str_replace('{slug}', $slug, $query);
        $query = $this->f->str_replace('{limit}', $limitClause, $query);
        $query = $this->dbCore->doTableNameReplacements($query);
        $query = $this->f->str_replace('{recognizedCategories}', $recognizedCategories, $query);

        $result = $this->dbCore->queryAndGetResults($query, array('result_type' => OBJECT));
        $queryError = is_string($result['last_error'] ?? '') ? ($result['last_error'] ?? '') : '';
        if ($queryError && !$this->dbCore->classifyAndHandleInfrastructureError($queryError)) {
            $this->logger->errorMessage("Error executing query. Err: " . $queryError . ", Query: " . $query);
        }
        $rows = is_array($result['rows']) ? $result['rows'] : array();

        $rows = $this->addURLToTermsRows($rows);

        return $rows;
    }

    /** @inheritDoc */
    function addURLToTermsRows($rows) {
        global $wp_rewrite;
        $extraPermaStructureCache = array();
        foreach ($rows as $row) {
            $taxonomy = isset($row->taxonomy) ? (string)$row->taxonomy : '';
            if (!array_key_exists($taxonomy, $extraPermaStructureCache)) {
                $extraPermaStructureCache[$taxonomy] = $wp_rewrite->get_extra_permastruct($taxonomy);
            }
            $struct = $extraPermaStructureCache[$taxonomy];

            $slug = isset($row->slug) ? (string)$row->slug : '';
            $url = str_replace('%' . $taxonomy . '%', $slug, $struct);

            /** @var \stdClass $row */
            $row->url = $url;
        }

        return $rows;
    }

    /** @inheritDoc */
    function getPublishedCategories($term_id = null, $slug = null, $limit = null) {
        global $wpdb;
        $abj404logic = abj_service('plugin_logic');

        $options = $abj404logic->getOptions();
        $recognizedCategories = $this->dbCore->buildCategorySqlList($options);
        if ($recognizedCategories === '') {
            $recognizedCategories = "''";
        }

        if ($term_id != null) {
            $term_id = "*/ and {wp_terms}.term_id = " . intval($term_id) . "\n";
        }

        if ($slug != null) {
            $slug = $this->f->sanitizeInvalidUTF8($slug);
            $slug = "*/ and {wp_terms}.slug = '" . esc_sql($slug) . "'\n";
        }

        $limitClause = '';
        if ($limit !== null && is_numeric($limit) && $limit > 0) {
            $limitClause = "LIMIT " . intval($limit);
        }

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getPublishedCategories.sql");
        $query = $this->f->str_replace('{recognizedCategories}', $recognizedCategories, $query);
        $query = $this->f->str_replace('{term_id}', $term_id !== null ? (string)$term_id : '', $query);
        $query = $this->f->str_replace('{slug}', $slug, $query);
        $query = $this->f->str_replace('{limit}', $limitClause, $query);
        $query = $this->dbCore->doTableNameReplacements($query);

        $result = $this->dbCore->queryAndGetResults($query, array('result_type' => OBJECT));
        $queryError = is_string($result['last_error'] ?? '') ? ($result['last_error'] ?? '') : '';
        if ($queryError && !$this->dbCore->classifyAndHandleInfrastructureError($queryError)) {
            $this->logger->errorMessage("Error executing query. Err: " . $queryError . ", Query: " . $query);
        }
        $rows = is_array($result['rows']) ? $result['rows'] : array();

        $rows = $this->addURLToTermsRows($rows);

        return $rows;
    }

    // =========================================================================
    // Permalink cache (from DataAccessTrait_Maintenance + DataAccessTrait_Stats)
    // =========================================================================

    /** @inheritDoc */
    function truncatePermalinkCacheTable(): void {
        $query = "truncate table {wp_abj404_permalink_cache}";
        $this->dbCore->queryAndGetResults($query);

        abj_service('ngram_filter')->invalidateCoverageCaches();
    }

    /** @inheritDoc */
    function removeFromPermalinkCache(int $post_id): void {
        $query = "delete from {wp_abj404_permalink_cache} where id = %d";
        $this->dbCore->queryAndGetResults($query, array('query_params' => array($post_id)));

        abj_service('ngram_filter')->invalidateCoverageCaches();
    }

    /** @inheritDoc */
    function getPermalinkFromCache($id) {
        $id = absint($id);
        $query = "select url from {wp_abj404_permalink_cache} where id = " . $id;
        $results = $this->dbCore->queryAndGetResults($query);

        $rows = is_array($results['rows']) ? $results['rows'] : array();
        if (empty($rows)) {
            return null;
        }

        $row1 = is_array($rows[0] ?? null) ? $rows[0] : array();
        return isset($row1['url']) && is_string($row1['url']) ? $row1['url'] : null;
    }

    /** @inheritDoc */
    function getPermalinksByIds(array $ids) {
        if (empty($ids)) {
            return array();
        }
        $sanitized = array_map('absint', $ids);
        $placeholders = implode(',', $sanitized);
        $query = "select id, url from {wp_abj404_permalink_cache} where id in (" . $placeholders . ")";
        $query = $this->dbCore->doTableNameReplacements($query);
        $results = $this->dbCore->queryAndGetResults($query);
        return is_array($results['rows']) ? $results['rows'] : array();
    }

    /** @inheritDoc */
    function getPermalinkEtcFromCache($id) {
        $id = absint($id);
        $query = "select id, url, meta, url_length, post_parent from {wp_abj404_permalink_cache} where id = " . $id;
        $results = $this->dbCore->queryAndGetResults($query);

        $rows = is_array($results['rows']) ? $results['rows'] : array();
        if (empty($rows)) {
            return null;
        }

        return is_array($rows[0] ?? null) ? $rows[0] : null;
    }

    /** @inheritDoc */
    function getIDsNeededForPermalinkCache() {
        $abj404logic = abj_service('plugin_logic');

        $options = $abj404logic->getOptions();
        $recognizedPostTypes = $this->dbCore->buildPostTypeSqlList($options);
        if ($recognizedPostTypes === '') {
            return null;
        }

        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getIDsNeededForPermalinkCache.sql");
        $query = $this->f->str_replace('{recognizedPostTypes}', $recognizedPostTypes, $query);

        $results = $this->dbCore->queryAndGetResults($query);

        /** @var array<int, array<string, mixed>>|null $rows */
        $rows = $results['rows'];
        return $rows;
    }

    /** @inheritDoc */
    function updatePermalinkCache() {
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ .
            "/sql/updatePermalinkCache.sql");

        $this->dbCore->setSqlBigSelects();

        $results = $this->dbCore->queryAndGetResults($query);

        return $results;
    }

    /** @inheritDoc */
    function updatePermalinkCacheParentPages() {
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ .
            "/sql/updatePermalinkCacheParentPages.sql");

        $depthSoFar = 0;
        $results = array();
        do {
            $results = $this->dbCore->queryAndGetResults($query);
            $depthSoFar++;
        } while ($results['rows_affected'] != 0 && $depthSoFar < 15);

        return $results;
    }

    /** @inheritDoc */
    function getPermalinkCacheCount(): int {
        $table = $this->dbCore->doTableNameReplacements('{wp_abj404_permalink_cache}');
        return $this->dbCore->queryScalarInt("SELECT COUNT(*) FROM `{$table}`");
    }

    // =========================================================================
    // Spelling cache (from DataAccessTrait_Maintenance)
    // =========================================================================

    /** @inheritDoc */
    function storeSpellingPermalinksToCache(string $requestedURLRaw, $returnValue): void {
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/insertSpellingCache.sql");

        $cleanURL = $this->f->sanitizeInvalidUTF8($requestedURLRaw);

        $query = $this->f->str_replace('{url}', esc_sql($cleanURL), $query);
        $jsonEncoded = json_encode($returnValue);
        $query = $this->f->str_replace('{matchdata}', esc_sql(is_string($jsonEncoded) ? $jsonEncoded : ''), $query);

        $this->dbCore->queryAndGetResults($query);
    }

    /**
     * @cache-write-audit: opt-out -- spelling_cache is itself the cache;
     * SpellChecker recomputes lookups on demand from {wp_abj404_redirects}
     * and {wp_abj404_permalink_cache}, neither of which derives a transient
     * from spelling_cache rows.
     *
     * @inheritDoc
     */
    function getSpellingPermalinksFromCache(string $requestedURLRaw) {
        $requestedURLRaw = $this->f->sanitizeInvalidUTF8($requestedURLRaw);
        $query = "select id, url, matchdata from {wp_abj404_spelling_cache} where url = '" . esc_sql($requestedURLRaw) . "'";
        $results = $this->dbCore->queryAndGetResults($query);

        $rows = is_array($results['rows']) ? $results['rows'] : array();

        if (empty($rows)) {
            return array();
        }

        $row = is_array($rows[0] ?? null) ? $rows[0] : array();
        $json = isset($row['matchdata']) && is_string($row['matchdata']) ? $row['matchdata'] : '';
        $returnValue = json_decode($json, true);

        return $returnValue;
    }

    /** @inheritDoc */
    function deleteSpellingCache(): void {
        // @cache-write-audit: opt-out - spelling cache table is itself the cache being invalidated.
        $query = "truncate table {wp_abj404_spelling_cache}";
        $this->dbCore->queryAndGetResults($query);
    }

    // =========================================================================
    // Old slug lookup (from DataAccessTrait_Maintenance)
    // =========================================================================

    /** @inheritDoc */
    function getOldSlug($post_id) {
        $post_id = absint($post_id);

        $query = "select meta_value from {wp_postmeta} \nwhere post_id = {post_id} " .
            " and meta_key = '_wp_old_slug' \n" .
            " order by meta_id desc";
        $query = $this->f->str_replace('{post_id}', (string)$post_id, $query);

        $results = $this->dbCore->queryAndGetResults($query);

        $rows = $results['rows'];
        if ($rows == null || empty($rows)) {
            return null;
        }

        $rows = is_array($rows) ? $rows : array();
        $row = is_array($rows[0] ?? null) ? $rows[0] : array();
        return isset($row['meta_value']) && is_string($row['meta_value']) ? $row['meta_value'] : null;
    }
}
