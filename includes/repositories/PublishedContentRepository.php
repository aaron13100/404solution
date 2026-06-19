<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: covered by tests/ContentRepositoryDecompositionTest.php through ContentRepository facade entry points.

require_once __DIR__ . '/TermUrlEnricher.php';

/**
 * Reads published posts, pages, images, tags, and categories from WordPress tables.
 */
class ABJ_404_Solution_PublishedContentRepository {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var mixed Options provider exposing getOptions(): array. */
    private $optionsProvider;

    /** @var ABJ_404_Solution_DatabaseErrorClassifier */
    private $errorClassifier;

    /** @var ABJ_404_Solution_DatabaseCollationHelper */
    private $collationHelper;

    /** @var ABJ_404_Solution_TermUrlEnricher */
    private $termUrlEnricher;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions $functions
     * @param ABJ_404_Solution_Logging $logging
     * @param mixed $optionsProvider Object exposing getOptions(): array.
     * @param ABJ_404_Solution_DatabaseErrorClassifier $errorClassifier
     * @param ABJ_404_Solution_DatabaseCollationHelper $collationHelper
     * @param ABJ_404_Solution_TermUrlEnricher|null $termUrlEnricher
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        $functions,
        $logging,
        $optionsProvider,
        $errorClassifier,
        $collationHelper,
        $termUrlEnricher = null
    ) {
        $this->dbCore = $dbCore;
        $this->f = $functions;
        $this->logger = $logging;
        $this->optionsProvider = $optionsProvider;
        $this->errorClassifier = $errorClassifier;
        $this->collationHelper = $collationHelper;
        $this->termUrlEnricher = $termUrlEnricher !== null ? $termUrlEnricher : new ABJ_404_Solution_TermUrlEnricher();
    }

    /** @return string */
    private function getPostsTableName(): string {
        global $wpdb;
        if (isset($wpdb->posts) && is_string($wpdb->posts) && $wpdb->posts !== '') {
            return $wpdb->posts;
        }
        $prefix = isset($wpdb->prefix) && is_string($wpdb->prefix) && $wpdb->prefix !== '' ? $wpdb->prefix : 'wp_';
        return $prefix . 'posts';
    }

    /** @return array<string, mixed> */
    private function getRuntimeOptions(): array {
        $provider = $this->optionsProvider !== null ? $this->optionsProvider : abj_service('options_repository');
        if (is_object($provider) && method_exists($provider, 'getOptions')) {
            $options = $provider->getOptions();
            return is_array($options) ? $options : array();
        }
        return array();
    }

    /**
     * @param string $slug
     * @param string $searchTerm
     * @param string $limitResults
     * @param string $orderResults
     * @param string $extraWhereClause
     * @return array<int, object>
     */
    public function getPublishedPagesAndPostsIDs($slug = '', $searchTerm = '',
        $limitResults = '', $orderResults = '', $extraWhereClause = '') {
        $postsTableName = $this->getPostsTableName();

        $options = $this->getRuntimeOptions();
        $recognizedPostTypes = $this->dbCore->tableNameResolver()->buildPostTypeSqlList($options);
        if ($recognizedPostTypes === '') {
            return array();
        }

        if (!$this->dbCore->tableNameResolver()->tableExists($postsTableName)) {
            $this->logger->errorMessage("WordPress posts table not found: " . $postsTableName .
                ". This may indicate an incorrect table prefix or database configuration issue.");
            return array();
        }

        $slugClause = $this->buildPostSlugClause($slug, $postsTableName);
        $queryParts = array(
            'recognizedPostTypes' => $recognizedPostTypes,
            'specifiedSlug' => $slugClause['clause'],
            'searchTerm' => $this->buildPostSearchClause($searchTerm),
            'extraWhereClause' => $this->buildExtraWhereClause($extraWhereClause),
            'limitResults' => $this->buildLimitClause($limitResults),
            'orderResults' => $this->buildOrderClause($orderResults),
        );
        $query = $this->buildPublishedPagesQuery($queryParts);

        $result = $this->dbCore->queryAndGetResults($query, array('result_type' => OBJECT));
        $queryError = is_string($result['last_error'] ?? '') ? ($result['last_error'] ?? '') : '';
        $rows = $this->objectRows($result['rows'] ?? array());

        $fallback = $this->applyPublishedPagesFallbacks($query, $queryError, $rows, $slugClause, $queryParts);
        $this->handlePublishedPagesQueryError($fallback['queryError'], $query);

        return $fallback['rows'];
    }

    /**
     * @param string $slug
     * @param string $postsTableName
     * @return array{slug: string, clause: string}
     */
    private function buildPostSlugClause($slug, string $postsTableName): array {
        if ($slug == "") {
            return array('slug' => '', 'clause' => '');
        }

        $cleanSlug = $this->f->sanitizeInvalidUTF8($slug);
        $columnCollation = $this->getPostNameColumnCollation($postsTableName);
        if ($columnCollation !== null && strpos(strtolower($columnCollation), 'utf8mb4') !== false) {
            $resolvedCollation = $this->collationHelper->sanitizeCollationIdentifier($columnCollation);
            if ($resolvedCollation === '') {
                $resolvedCollation = $this->collationHelper->getPreferredUtf8mb4Collation();
            }
            $clause = " */\n and CAST(wp_posts.post_name AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci = "
                . "'" . esc_sql($cleanSlug) . "' \n ";
            return array('slug' => $cleanSlug, 'clause' => str_replace('utf8mb4_unicode_ci', $resolvedCollation, $clause));
        }

        if (abj_service('sanitizer')->containsUtf8mb4Characters($cleanSlug)) {
            return array('slug' => $cleanSlug, 'clause' => '');
        }

        return array(
            'slug' => $cleanSlug,
            'clause' => " */\n and wp_posts.post_name = '" . esc_sql($cleanSlug) . "' \n ",
        );
    }

    /** @return string|null */
    private function getPostNameColumnCollation(string $postsTableName) {
        $collationResult = $this->dbCore->queryAndGetResults(
            "SELECT COLLATION_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = %s
             AND COLUMN_NAME = 'post_name'",
            array('query_params' => array($postsTableName), 'log_errors' => false)
        );
        $collationRows = isset($collationResult['rows']) && is_array($collationResult['rows']) ? $collationResult['rows'] : array();
        if (empty($collationRows) || !is_array($collationRows[0])) {
            return null;
        }

        $first = reset($collationRows[0]);
        return is_scalar($first) ? (string)$first : null;
    }

    /** @param string $searchTerm @return string */
    private function buildPostSearchClause($searchTerm): string {
        if ($searchTerm == "") {
            return '';
        }

        // Strip control characters and validate UTF-8 before SQL escaping.
        // Pattern 10: defense-in-depth against invalid-UTF-8 bytes reaching MySQL.
        $sanitized = sanitize_text_field($searchTerm);
        return " */\n and lower(wp_posts.post_title) like "
            . "'%" . esc_sql($this->f->strtolower($sanitized)) . "%' \n ";
    }

    /** @param string $extraWhereClause @return string */
    private function buildExtraWhereClause($extraWhereClause): string {
        return $extraWhereClause != "" ? " */\n " . $extraWhereClause : '';
    }

    /** @param string $limitResults @return string */
    private function buildLimitClause($limitResults): string {
        return !empty($limitResults) ? " */\n  limit " . $limitResults : '';
    }

    /** @param string $orderResults @return string */
    private function buildOrderClause($orderResults): string {
        return !empty($orderResults) ? " */\n  order by " . $orderResults : '';
    }

    /**
     * @param array{recognizedPostTypes: string, specifiedSlug: string, searchTerm: string, extraWhereClause: string, limitResults: string, orderResults: string} $queryParts
     * @return string
     */
    private function buildPublishedPagesQuery(array $queryParts): string {
        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getPublishedPagesAndPostsIDs.sql");
        $query = $this->dbCore->doTableNameReplacements($query);
        $query = $this->f->str_replace('{recognizedPostTypes}', $queryParts['recognizedPostTypes'], $query);
        $query = $this->f->str_replace('{specifiedSlug}', $queryParts['specifiedSlug'], $query);
        $query = $this->f->str_replace('{searchTerm}', $queryParts['searchTerm'], $query);
        $query = $this->f->str_replace('{extraWhereClause}', $queryParts['extraWhereClause'], $query);
        $query = $this->f->str_replace('{limit-results}', $queryParts['limitResults'], $query);
        $query = $this->f->str_replace('{order-results}', $queryParts['orderResults'], $query);
        return $query;
    }

    /**
     * @param string $query
     * @param string $queryError
     * @param array<int, object> $rows
     * @param array{slug: string, clause: string} $slugClause
     * @param array{recognizedPostTypes: string, specifiedSlug: string, searchTerm: string, extraWhereClause: string, limitResults: string, orderResults: string} $queryParts
     * @return array{queryError: string, rows: array<int, object>}
     */
    private function applyPublishedPagesFallbacks(
        string $query,
        string $queryError,
        array $rows,
        array $slugClause,
        array $queryParts
    ): array {
        $fallback = $this->applyCollationFallback($query, $queryError, $rows);
        return $this->applyInvalidDataSlugFallback($query, $fallback['queryError'], $fallback['rows'], $slugClause, $queryParts);
    }

    /**
     * @param string $query
     * @param string $queryError
     * @param array<int, object> $rows
     * @return array{queryError: string, rows: array<int, object>}
     */
    private function applyCollationFallback(string $query, string $queryError, array $rows): array {
        if (empty($queryError) || !$this->errorClassifier->taxonomy()->schema()->isCollationError($queryError)) {
            return array('queryError' => $queryError, 'rows' => $rows);
        }

        $fpreg = ABJ_404_Solution_FunctionsPreg::getInstance();
        $fallbackQuery = $fpreg->regexReplace(
            'CONVERT\(wpt\.name USING utf8mb4\) COLLATE [A-Za-z0-9_]+',
            'wpt.name',
            $query
        );
        $fallbackQuery = $fpreg->regexReplace(
            'CONVERT\(usefulterms\.grouped_terms USING utf8mb4\) COLLATE [A-Za-z0-9_]+',
            'usefulterms.grouped_terms',
            is_string($fallbackQuery) ? $fallbackQuery : $query
        );
        $fallbackResult = $this->dbCore->queryAndGetResults(
            is_string($fallbackQuery) ? $fallbackQuery : $query,
            array('result_type' => OBJECT, 'log_errors' => false)
        );
        $fallbackError = is_string($fallbackResult['last_error'] ?? '') ? ($fallbackResult['last_error'] ?? '') : '';
        if (!empty($fallbackError)) {
            return array('queryError' => $fallbackError, 'rows' => $rows);
        }

        return array('queryError' => '', 'rows' => $this->objectRows($fallbackResult['rows'] ?? array()));
    }

    /**
     * @param string $query
     * @param string $queryError
     * @param array<int, object> $rows
     * @param array{slug: string, clause: string} $slugClause
     * @param array{recognizedPostTypes: string, specifiedSlug: string, searchTerm: string, extraWhereClause: string, limitResults: string, orderResults: string} $queryParts
     * @return array{queryError: string, rows: array<int, object>}
     */
    private function applyInvalidDataSlugFallback(
        string $query,
        string $queryError,
        array $rows,
        array $slugClause,
        array $queryParts
    ): array {
        if (empty($queryError) || !$this->errorClassifier->taxonomy()->schema()->isInvalidDataError($queryError) ||
                $slugClause['slug'] === '' ||
                strpos($query, 'CAST(wp_posts.post_name AS CHAR CHARACTER SET utf8mb4)') === false) {
            return array('queryError' => $queryError, 'rows' => $rows);
        }

        $fallbackParts = $queryParts;
        // @utf8-audit: opt-out - $slugClause['slug'] is an internal post_name string already
        // selected from wp_posts (the same column we're comparing it against), not user input.
        $fallbackParts['specifiedSlug'] = " */\n and wp_posts.post_name = '" . esc_sql($slugClause['slug']) . "' \n ";
        $fallbackResult = $this->dbCore->queryAndGetResults(
            $this->buildPublishedPagesQuery($fallbackParts),
            array('result_type' => OBJECT, 'log_errors' => false)
        );
        $fallbackError = is_string($fallbackResult['last_error'] ?? '') ? ($fallbackResult['last_error'] ?? '') : '';
        if (!empty($fallbackError)) {
            return array('queryError' => $queryError, 'rows' => $rows);
        }

        return array('queryError' => '', 'rows' => $this->objectRows($fallbackResult['rows'] ?? array()));
    }

    private function handlePublishedPagesQueryError(string $queryError, string $query): void {
        if ($queryError === '') {
            return;
        }

        if (stripos($queryError, 'unknown column') !== false &&
                stripos($queryError, 'content_keywords') !== false) {
            $this->logger->warn("content_keywords column not yet available (DB migration pending): " . $queryError);
            return;
        }

        if (!$this->errorClassifier->classifyAndHandleInfrastructureError($queryError)) {
            $this->logger->errorMessage("Error executing query. Err: " . $queryError . ", Query: " . $query);
        }
    }

    /**
     * @param mixed $rows
     * @return array<int, object>
     */
    private function objectRows($rows): array {
        if (!is_array($rows)) {
            return array();
        }

        $objects = array();
        foreach ($rows as $row) {
            if (is_object($row)) {
                $objects[] = $row;
            }
        }
        return $objects;
    }

    /** @return array<int, object> */
    public function getPublishedImagesIDs() {
        $options = $this->getRuntimeOptions();
        $recognizedPostTypes = $this->dbCore->tableNameResolver()->buildPostTypeSqlList($options);
        if ($recognizedPostTypes === '') {
            return array();
        }

        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getPublishedImageIDs.sql");
        $query = $this->dbCore->doTableNameReplacements($query);
        $query = $this->f->str_replace('{recognizedPostTypes}', $recognizedPostTypes, $query);

        $result = $this->dbCore->queryAndGetResults($query, array('result_type' => OBJECT));
        $queryError = is_string($result['last_error'] ?? '') ? ($result['last_error'] ?? '') : '';
        if ($queryError && !$this->errorClassifier->classifyAndHandleInfrastructureError($queryError)) {
            $this->logger->errorMessage("Error executing query. Err: " . $queryError . ", Query: " . $query);
        }

        return $this->objectRows($result['rows'] ?? array());
    }

    /**
     * @param string|null $slug
     * @param int|null $limit
     * @return array<int, object>
     */
    public function getPublishedTags($slug = null, $limit = null) {
        $options = $this->getRuntimeOptions();
        $recognizedCategories = $this->dbCore->tableNameResolver()->buildCategorySqlList($options);

        if ($slug != null) {
            $slug = $this->f->sanitizeInvalidUTF8($slug);
            $slug = "*/ and wp_terms.slug = '" . esc_sql($slug) . "'\n";
        }

        $limitClause = '';
        if ($limit !== null && is_numeric($limit) && $limit > 0) {
            $limitClause = "LIMIT " . intval($limit);
        }

        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getPublishedTags.sql");
        $query = $this->f->str_replace('{slug}', $slug, $query);
        $query = $this->f->str_replace('{limit}', $limitClause, $query);
        $query = $this->dbCore->doTableNameReplacements($query);
        $query = $this->f->str_replace('{recognizedCategories}', $recognizedCategories, $query);

        $result = $this->dbCore->queryAndGetResults($query, array('result_type' => OBJECT));
        $queryError = is_string($result['last_error'] ?? '') ? ($result['last_error'] ?? '') : '';
        if ($queryError && !$this->errorClassifier->classifyAndHandleInfrastructureError($queryError)) {
            $this->logger->errorMessage("Error executing query. Err: " . $queryError . ", Query: " . $query);
        }
        $rows = $this->objectRows($result['rows'] ?? array());

        return $this->termUrlEnricher->addURLToTermsRows($rows);
    }

    /**
     * Cheap published-tag count using the SAME taxonomy filter as
     * getPublishedTags(), but COUNT(*) only (no rows loaded). Feeds the term
     * n-gram coverage readiness gate.
     *
     * @return int
     */
    public function getPublishedTagCount(): int {
        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getPublishedTagCount.sql");
        $query = $this->dbCore->doTableNameReplacements($query);
        return $this->dbCore->queryScalarInt($query, array('log_errors' => false));
    }

    /**
     * Cheap published-category count using the SAME taxonomy filter as
     * getPublishedCategories(), but COUNT(*) only (no rows loaded). Feeds the
     * term n-gram coverage readiness gate.
     *
     * @return int
     */
    public function getPublishedCategoryCount(): int {
        $options = $this->getRuntimeOptions();
        $recognizedCategories = $this->dbCore->tableNameResolver()->buildCategorySqlList($options);
        if ($recognizedCategories === '') {
            $recognizedCategories = "''";
        }
        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getPublishedCategoryCount.sql");
        $query = $this->f->str_replace('{recognizedCategories}', $recognizedCategories, $query);
        $query = $this->dbCore->doTableNameReplacements($query);
        return $this->dbCore->queryScalarInt($query, array('log_errors' => false));
    }

    /**
     * @param array<int, object> $rows
     * @return array<int, object>
     */
    public function addURLToTermsRows($rows) {
        return $this->termUrlEnricher->addURLToTermsRows($rows);
    }

    /**
     * @param int|null $term_id
     * @param string|null $slug
     * @param int|null $limit
     * @return array<int, object>
     */
    public function getPublishedCategories($term_id = null, $slug = null, $limit = null) {
        $options = $this->getRuntimeOptions();
        $recognizedCategories = $this->dbCore->tableNameResolver()->buildCategorySqlList($options);
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

        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getPublishedCategories.sql");
        $query = $this->f->str_replace('{recognizedCategories}', $recognizedCategories, $query);
        $query = $this->f->str_replace('{term_id}', $term_id !== null ? (string)$term_id : '', $query);
        $query = $this->f->str_replace('{slug}', $slug, $query);
        $query = $this->f->str_replace('{limit}', $limitClause, $query);
        $query = $this->dbCore->doTableNameReplacements($query);

        $result = $this->dbCore->queryAndGetResults($query, array('result_type' => OBJECT));
        $queryError = is_string($result['last_error'] ?? '') ? ($result['last_error'] ?? '') : '';
        if ($queryError && !$this->errorClassifier->classifyAndHandleInfrastructureError($queryError)) {
            $this->logger->errorMessage("Error executing query. Err: " . $queryError . ", Query: " . $query);
        }
        $rows = $this->objectRows($result['rows'] ?? array());

        return $this->termUrlEnricher->addURLToTermsRows($rows);
    }
}
