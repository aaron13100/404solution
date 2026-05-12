<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Published-content lookup queries used by the suggestion engine to find
 * matching destinations for 404 URLs (posts, pages, images, tags, categories).
 *
 * Split from DataAccessTrait_Redirects in 4.1.x to keep both files under the
 * 1500-line modularity cap. These methods are semantically distinct from
 * redirect lifecycle code: they read WordPress core tables (wp_posts,
 * wp_terms) to enumerate candidate destinations, not the plugin's own
 * redirects table.
 */
trait ABJ_404_Solution_DataAccess_PublishedContentTrait {

    /** Returns rows with the IDs of the published items.
     * @global type $wpdb
     * @global type $abj404logic
     * @global type $abj404dao
     * @global type $abj404logging
     * @param string $slug only get results for this slug. (empty means all posts)
     * @param string $searchTerm use this string in a LIKE on the sql.
     * @param string $limitResults
     * @param string $orderResults
     * @param string $extraWhereClause use this string in a where on the sql.
     * @return array<int, object>
     */
    function getPublishedPagesAndPostsIDs($slug = '', $searchTerm = '',
    	$limitResults = '', $orderResults = '', $extraWhereClause = '') {
        global $wpdb;
        $abj404logic = abj_service('plugin_logic');

        // Fix for missing table error (reported by 2 users - 4% of errors)
        // Check if wp_posts table exists before querying
        if (!$this->tableExists($wpdb->posts)) {
            $this->logger->errorMessage("WordPress posts table not found: " . $wpdb->posts .
                ". This may indicate an incorrect table prefix or database configuration issue.");
            return array(); // Return empty array instead of crashing
        }

        // get the valid post types
        $options = $abj404logic->getOptions();
        $recognizedPostTypes = $this->buildPostTypeSqlList($options);
        if ($recognizedPostTypes === '') {
            return array();
        }
        // ----------------

        if ($slug != "") {
            // Sanitize invalid UTF-8 before SQL to prevent database errors
            // (fixes bug: URLs like %9F%9F%9F%9F-%9F%9F%9F-1.png cause "invalid data" errors)
            $slug = $this->f->sanitizeInvalidUTF8($slug);

            // Check if the post_name column supports utf8mb4 collation
            // (fixes bug: Arabic sites on latin1 databases get "invalid data" errors)
            // Note: Check actual column collation, not database default - on mixed setups
            // the database may be latin1 but wp_posts.post_name is utf8mb4
            $collationResult = $this->queryAndGetResults(
                "SELECT COLLATION_NAME FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = %s
                 AND COLUMN_NAME = 'post_name'",
                array('query_params' => array($wpdb->posts), 'log_errors' => false)
            );
            $collationRows = isset($collationResult['rows']) && is_array($collationResult['rows']) ? $collationResult['rows'] : array();
            $columnCollation = null;
            if (!empty($collationRows) && is_array($collationRows[0])) {
                $first = reset($collationRows[0]);
                $columnCollation = is_scalar($first) ? (string)$first : null;
            }
            if ($columnCollation !== null && strpos(strtolower($columnCollation), 'utf8mb4') !== false) {
                // Column supports utf8mb4 - use CAST for proper Unicode comparison
                $resolvedCollation = $this->sanitizeCollationIdentifier($columnCollation);
                if ($resolvedCollation === '') {
                    $resolvedCollation = $this->getPreferredUtf8mb4Collation();
                }
                $specifiedSlug = " */\n and CAST(wp_posts.post_name AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci = "
                        . "'" . esc_sql($slug) . "' \n ";
                $specifiedSlug = str_replace('utf8mb4_unicode_ci', $resolvedCollation, $specifiedSlug);
            } else {
                // Legacy column (latin1, utf8, etc.) - use simple comparison.
                // 4-byte UTF-8 characters (emoji, rare CJK, etc.) cannot exist in a
                // utf8mb3/latin1 column, so skip the slug comparison entirely to avoid
                // "Illegal mix of collations" errors.
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

        // load the query and do the replacements.
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getPublishedPagesAndPostsIDs.sql");
        $query = $this->doTableNameReplacements($query);
        $query = $this->f->str_replace('{recognizedPostTypes}', $recognizedPostTypes, $query);
        $query = $this->f->str_replace('{specifiedSlug}', $specifiedSlug, $query);
        $query = $this->f->str_replace('{searchTerm}', $searchTerm, $query);
        $query = $this->f->str_replace('{extraWhereClause}', $extraWhereClause, $query);
        $query = $this->f->str_replace('{limit-results}', $limitResults, $query);
        $query = $this->f->str_replace('{order-results}', $orderResults, $query);

        $result = $this->queryAndGetResults($query, array('result_type' => OBJECT));
        $queryError = is_string($result['last_error'] ?? '') ? ($result['last_error'] ?? '') : '';
        $rows = is_array($result['rows']) ? $result['rows'] : array();

        // Collation-error fallback: if CONVERT(... USING utf8mb4) COLLATE still fails
        // (e.g. MySQL version quirk), retry without any COLLATE forcing. This is the
        // pre-4.1.4 behavior that relies on implicit collation resolution.
        if (!empty($queryError) && $this->isCollationError($queryError)) {
            $fpreg = ABJ_404_Solution_FunctionsPreg::getInstance();
            $fallbackQuery = $fpreg->regexReplace(
                'CONVERT\(wpt\.name USING utf8mb4\) COLLATE [A-Za-z0-9_]+',
                'wpt.name', $query);
            $fallbackQuery = $fpreg->regexReplace(
                'CONVERT\(usefulterms\.grouped_terms USING utf8mb4\) COLLATE [A-Za-z0-9_]+',
                'usefulterms.grouped_terms', is_string($fallbackQuery) ? $fallbackQuery : $query);
            $fallbackResult = $this->queryAndGetResults(
                is_string($fallbackQuery) ? $fallbackQuery : $query,
                array('result_type' => OBJECT, 'log_errors' => false));
            $queryError = is_string($fallbackResult['last_error'] ?? '') ? ($fallbackResult['last_error'] ?? '') : '';
            if (empty($queryError)) {
                $rows = is_array($fallbackResult['rows']) ? $fallbackResult['rows'] : array();
            }
        }

        if (!empty($queryError) && $this->isInvalidDataError($queryError) &&
                $slug != "" && strpos($query, 'CAST(wp_posts.post_name AS CHAR CHARACTER SET utf8mb4)') !== false) {
            // Compatibility fallback: retry once without CAST/COLLATE for environments
            // where mixed encodings still reject utf8mb4 coercion.
            $fallbackSpecifiedSlug = " */\n and wp_posts.post_name = '" . esc_sql($slug) . "' \n ";
            $fallbackQuery = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getPublishedPagesAndPostsIDs.sql");
            $fallbackQuery = $this->doTableNameReplacements($fallbackQuery);
            $fallbackQuery = $this->f->str_replace('{recognizedPostTypes}', $recognizedPostTypes, $fallbackQuery);
            $fallbackQuery = $this->f->str_replace('{specifiedSlug}', $fallbackSpecifiedSlug, $fallbackQuery);
            $fallbackQuery = $this->f->str_replace('{searchTerm}', $searchTerm, $fallbackQuery);
            $fallbackQuery = $this->f->str_replace('{extraWhereClause}', $extraWhereClause, $fallbackQuery);
            $fallbackQuery = $this->f->str_replace('{limit-results}', $limitResults, $fallbackQuery);
            $fallbackQuery = $this->f->str_replace('{order-results}', $orderResults, $fallbackQuery);
            $fallbackResult = $this->queryAndGetResults($fallbackQuery, array('result_type' => OBJECT, 'log_errors' => false));
            $fallbackError = is_string($fallbackResult['last_error'] ?? '') ? ($fallbackResult['last_error'] ?? '') : '';
            if (empty($fallbackError)) {
                $queryError = '';
                $rows = is_array($fallbackResult['rows']) ? $fallbackResult['rows'] : array();
            }
        }

        // check for errors (use $queryError which tracks the latest attempt)
        if ($queryError) {
            // "Unknown column 'plc.content_keywords'" occurs during the DB migration window
            // when the column hasn't been added yet (e.g. sync lock was stuck for ~24h).
            // Degrade to warning so it doesn't generate email reports for every 404 hit.
            if (stripos($queryError, 'unknown column') !== false &&
                    stripos($queryError, 'content_keywords') !== false) {
                $this->logger->warn("content_keywords column not yet available (DB migration pending): " . $queryError);
            } else if (!$this->classifyAndHandleInfrastructureError($queryError)) {
                $this->logger->errorMessage("Error executing query. Err: " . $queryError . ", Query: " . $query);
            }
        }

        return $rows;
    }

    /** Returns rows with the IDs of the published images.
     * @return array<int, object>
     */
    function getPublishedImagesIDs() {
        global $wpdb;
        $abj404logic = abj_service('plugin_logic');

        // get the valid post types
        $options = $abj404logic->getOptions();
        $recognizedPostTypes = $this->buildPostTypeSqlList($options);
        if ($recognizedPostTypes === '') {
            return array();
        }
        // ----------------

        // load the query and do the replacements.
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getPublishedImageIDs.sql");
        $query = $this->doTableNameReplacements($query);
        $query = $this->f->str_replace('{recognizedPostTypes}', $recognizedPostTypes, $query);

        $result = $this->queryAndGetResults($query, array('result_type' => OBJECT));
        $queryError = is_string($result['last_error'] ?? '') ? ($result['last_error'] ?? '') : '';
        if ($queryError && !$this->classifyAndHandleInfrastructureError($queryError)) {
            $this->logger->errorMessage("Error executing query. Err: " . $queryError . ", Query: " . $query);
        }

        return is_array($result['rows']) ? $result['rows'] : array();
    }

    /** Returns rows with the defined terms (tags).
     * @param string|null $slug
     * @param int|null $limit
     * @return array<int, object>
     */
    function getPublishedTags($slug = null, $limit = null) {
        global $wpdb;
        $abj404logic = abj_service('plugin_logic');

        // get the valid post types
        $options = $abj404logic->getOptions();

        $recognizedCategories = $this->buildCategorySqlList($options);

        if ($slug != null) {
            // Sanitize invalid UTF-8 before SQL to prevent database errors
            $slug = $this->f->sanitizeInvalidUTF8($slug);
            $slug = "*/ and wp_terms.slug = '" . esc_sql($slug) . "'\n";
        }

        $limitClause = '';
        if ($limit !== null && is_numeric($limit) && $limit > 0) {
            $limitClause = "LIMIT " . intval($limit);
        }

        // load the query and do the replacements.
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getPublishedTags.sql");
        $query = $this->f->str_replace('{slug}', $slug, $query);
        $query = $this->f->str_replace('{limit}', $limitClause, $query);
        $query = $this->doTableNameReplacements($query);
        $query = $this->f->str_replace('{recognizedCategories}', $recognizedCategories, $query);

        $result = $this->queryAndGetResults($query, array('result_type' => OBJECT));
        $queryError = is_string($result['last_error'] ?? '') ? ($result['last_error'] ?? '') : '';
        if ($queryError && !$this->classifyAndHandleInfrastructureError($queryError)) {
            $this->logger->errorMessage("Error executing query. Err: " . $queryError . ", Query: " . $query);
        }
        $rows = is_array($result['rows']) ? $result['rows'] : array();

        $rows = $this->addURLToTermsRows($rows);

        return $rows;
    }

    /**
     * @param array<int, object> $rows
     * @return array<int, object>
     */
    function addURLToTermsRows($rows) {
    	// add url data
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

    		// TODO verify one of the urls?
    		/*
    		if (!$verifiedOne) {
    			$id = $row->term_id;
    			$link = get_tag_link($id);
    			$link = get_category_link($id);
    			// $link should equal $url
		    	$verifiedOne = true;
    		}
    		*/

    		/** @var \stdClass $row */
    		$row->url = $url;
    	}

    	return $rows;
    }

    /** Returns rows with the defined categories.
     * @param int|null $term_id
     * @param string|null $slug
     * @param int|null $limit
     * @return array<int, object>
     */
    function getPublishedCategories($term_id = null, $slug = null, $limit = null) {
        global $wpdb;
        $abj404logic = abj_service('plugin_logic');

        // get the valid post types
        $options = $abj404logic->getOptions();

        $recognizedCategories = $this->buildCategorySqlList($options);
        if ($recognizedCategories === '') {
            $recognizedCategories = "''";
        }

        if ($term_id != null) {
            // Cast to integer for safety even though term_id is currently always null from callers
            $term_id = "*/ and {wp_terms}.term_id = " . intval($term_id) . "\n";
        }

        if ($slug != null) {
            // Sanitize invalid UTF-8 before SQL to prevent database errors
            $slug = $this->f->sanitizeInvalidUTF8($slug);
            $slug = "*/ and {wp_terms}.slug = '" . esc_sql($slug) . "'\n";
        }

        $limitClause = '';
        if ($limit !== null && is_numeric($limit) && $limit > 0) {
            $limitClause = "LIMIT " . intval($limit);
        }

        // load the query and do the replacements.
        $query = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/sql/getPublishedCategories.sql");
        $query = $this->f->str_replace('{recognizedCategories}', $recognizedCategories, $query);
        $query = $this->f->str_replace('{term_id}', $term_id !== null ? (string)$term_id : '', $query);
        $query = $this->f->str_replace('{slug}', $slug, $query);
        $query = $this->f->str_replace('{limit}', $limitClause, $query);
        $query = $this->doTableNameReplacements($query);

        $result = $this->queryAndGetResults($query, array('result_type' => OBJECT));
        $queryError = is_string($result['last_error'] ?? '') ? ($result['last_error'] ?? '') : '';
        if ($queryError && !$this->classifyAndHandleInfrastructureError($queryError)) {
            $this->logger->errorMessage("Error executing query. Err: " . $queryError . ", Query: " . $query);
        }
        $rows = is_array($result['rows']) ? $result['rows'] : array();

        $rows = $this->addURLToTermsRows($rows);

        return $rows;
    }
}
