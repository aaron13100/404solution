<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/TermUrlEnricher.php';

/**
 * Loads a bounded set of published taxonomy terms (categories or tags) by id.
 *
 * This is the bounded counterpart to PublishedContentRepository's
 * getPublishedCategories()/getPublishedTags() full-table scans. Given the
 * candidate term ids selected by the n-gram prefilter, it returns the SAME row
 * shape (term_id, name, slug, taxonomy, url via TermUrlEnricher) so downstream
 * scoring code is unchanged.
 *
 * The taxonomy filter mirrors the source queries:
 *  - 'category' -> the category set ('category', 'product_cat')
 *  - 'tag'      -> 'post_tag'
 *
 * Empty / invalid input short-circuits to an empty array with no query.
 */
class ABJ_404_Solution_PublishedTermsProvider {

    /** Term type for category archives. */
    const TYPE_CATEGORY = 'category';

    /** Term type for tag archives. */
    const TYPE_TAG = 'tag';

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_DatabaseErrorClassifier */
    private $errorClassifier;

    /** @var ABJ_404_Solution_TermUrlEnricher */
    private $termUrlEnricher;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions $functions
     * @param ABJ_404_Solution_Logging $logging
     * @param ABJ_404_Solution_DatabaseErrorClassifier|null $errorClassifier Defaults to $dbCore->errorClassifier().
     * @param ABJ_404_Solution_TermUrlEnricher|null $termUrlEnricher Defaults to a fresh enricher.
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        $functions,
        $logging,
        $errorClassifier = null,
        $termUrlEnricher = null
    ) {
        $this->dbCore = $dbCore;
        $this->f = $functions;
        $this->logger = $logging;
        $this->errorClassifier = $errorClassifier !== null ? $errorClassifier : $dbCore->errorClassifier();
        $this->termUrlEnricher = $termUrlEnricher !== null ? $termUrlEnricher : new ABJ_404_Solution_TermUrlEnricher();
    }

    /**
     * Fetch published terms of a single type by id, enriched with their URL.
     *
     * @param array<int, int|string> $termIds Candidate term ids (sanitized via absint).
     * @param string $type 'category' or 'tag'.
     * @return array<int, object> Same shape as getPublishedCategories()/getPublishedTags().
     */
    public function getTermsByIds(array $termIds, string $type): array {
        $sanitizedIds = $this->sanitizeIds($termIds);
        if (empty($sanitizedIds)) {
            return array();
        }

        $taxonomyFilter = $this->taxonomyFilterFor($type);
        if ($taxonomyFilter === '') {
            return array();
        }

        $idList = implode(', ', $sanitizedIds);

        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getPublishedTermsByIds.sql");
        $query = $this->f->str_replace('{taxonomyFilter}', $taxonomyFilter, $query);
        $query = $this->f->str_replace('{termIds}', $idList, $query);
        $query = $this->dbCore->doTableNameReplacements($query);

        $result = $this->dbCore->queryAndGetResults($query, array('result_type' => OBJECT));
        $queryError = is_string($result['last_error'] ?? '') ? ($result['last_error'] ?? '') : '';
        // allow-hidden-write-getter: logs query failures before returning empty rows, matching the sibling getters getPublishedCategories()/getPublishedTags() (centralized-error-handler convention, CLAUDE.md item 11).
        if ($queryError !== '' && !$this->errorClassifier->classifyAndHandleInfrastructureError($queryError)) {
            $this->logger->errorMessage("Error executing query. Err: " . $queryError . ", Query: " . $query);
        }

        $rows = $this->objectRows($result['rows'] ?? array());
        return $this->termUrlEnricher->addURLToTermsRows($rows);
    }

    /**
     * Map an n-gram cache type to its SQL taxonomy IN(...) list.
     *
     * @param string $type
     * @return string Quoted, comma-separated taxonomy list, or '' for unknown types.
     */
    private function taxonomyFilterFor(string $type): string {
        if ($type === self::TYPE_CATEGORY) {
            return "'category', 'product_cat'";
        }
        if ($type === self::TYPE_TAG) {
            return "'post_tag'";
        }
        return '';
    }

    /**
     * @param array<int, int|string> $termIds
     * @return array<int, int> De-duplicated, positive, integer ids.
     */
    private function sanitizeIds(array $termIds): array {
        $clean = array();
        foreach ($termIds as $id) {
            if (!is_scalar($id)) {
                continue;
            }
            $intId = absint($id);
            if ($intId > 0) {
                $clean[$intId] = $intId;
            }
        }
        return array_values($clean);
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
}
