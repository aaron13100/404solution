<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: covered by tests/ContentRepositoryDecompositionTest.php through ContentRepository facade entry points.

/**
 * Owns reads and writes for spelling cache rows.
 */
class ABJ_404_Solution_SpellingCacheRepository {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions $functions
     */
    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore, $functions) {
        $this->dbCore = $dbCore;
        $this->f = $functions;
    }

    /**
     * @param string $requestedURLRaw
     * @param mixed $returnValue
     * @return void
     */
    public function storeSpellingPermalinksToCache(string $requestedURLRaw, $returnValue): void {
        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/insertSpellingCache.sql");

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
     * @param string $requestedURLRaw
     * @return mixed
     */
    public function getSpellingPermalinksFromCache(string $requestedURLRaw) {
        $requestedURLRaw = $this->f->sanitizeInvalidUTF8($requestedURLRaw);
        // allow-unbounded-select: single-URL equality lookup (where url = one exact value); returns only the cache rows for that one URL
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

    /** @return void */
    public function deleteSpellingCache(): void {
        // @cache-write-audit: opt-out - spelling cache table is itself the cache being invalidated.
        $query = "truncate table {wp_abj404_spelling_cache}";
        $this->dbCore->queryAndGetResults($query);
    }
}
