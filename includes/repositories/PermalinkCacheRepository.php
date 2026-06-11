<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: covered by tests/ContentRepositoryDecompositionTest.php through ContentRepository facade entry points.

/**
 * Owns reads and writes for the permalink cache table.
 */
class ABJ_404_Solution_PermalinkCacheRepository {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var mixed Options provider exposing getOptions(): array. */
    private $optionsProvider;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions $functions
     * @param mixed $optionsProvider Object exposing getOptions(): array.
     */
    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore, $functions, $optionsProvider) {
        $this->dbCore = $dbCore;
        $this->f = $functions;
        $this->optionsProvider = $optionsProvider;
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

    /** @return void */
    public function truncatePermalinkCacheTable(): void {
        $query = "truncate table {wp_abj404_permalink_cache}";
        $this->dbCore->queryAndGetResults($query);

        abj_service('ngram_filter')->invalidateCoverageCaches();
    }

    /** @param int $post_id @return void */
    public function removeFromPermalinkCache(int $post_id): void {
        $query = "delete from {wp_abj404_permalink_cache} where id = %d";
        $this->dbCore->queryAndGetResults($query, array('query_params' => array($post_id)));

        abj_service('ngram_filter')->invalidateCoverageCaches();
    }

    /**
     * @param int|string $id
     * @return string|null
     */
    public function getPermalinkFromCache($id) {
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

    /**
     * @param array<int, int|string> $ids
     * @return array<int, object>
     */
    public function getPermalinksByIds(array $ids) {
        if (empty($ids)) {
            return array();
        }
        $sanitized = array_map('absint', $ids);
        $placeholders = implode(',', $sanitized);
        $query = "select id, url from {wp_abj404_permalink_cache} where id in (" . $placeholders . ")";
        $query = $this->dbCore->doTableNameReplacements($query);
        $results = $this->dbCore->queryAndGetResults($query);
        return $this->objectRows($results['rows'] ?? array());
    }

    /**
     * @param int|string $id
     * @return array<string, mixed>|null
     */
    public function getPermalinkEtcFromCache($id) {
        $id = absint($id);
        $query = "select id, url, meta, url_length, post_parent from {wp_abj404_permalink_cache} where id = " . $id;
        $results = $this->dbCore->queryAndGetResults($query);

        $rows = is_array($results['rows']) ? $results['rows'] : array();
        if (empty($rows)) {
            return null;
        }

        if (!isset($rows[0]) || !is_array($rows[0])) {
            return null;
        }

        return $this->stringKeyedRow($rows[0]);
    }

    /** @return array<int, array<string, mixed>>|null */
    public function getIDsNeededForPermalinkCache() {
        $options = $this->getRuntimeOptions();
        $recognizedPostTypes = $this->dbCore->tableNameResolver()->buildPostTypeSqlList($options);
        if ($recognizedPostTypes === '') {
            return null;
        }

        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getIDsNeededForPermalinkCache.sql");
        $query = $this->f->str_replace('{recognizedPostTypes}', $recognizedPostTypes, $query);

        $results = $this->dbCore->queryAndGetResults($query);

        /** @var array<int, array<string, mixed>>|null $rows */
        $rows = $results['rows'];
        return $rows;
    }

    /** @return array<string, mixed> */
    public function updatePermalinkCache() {
        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ .
            "/../sql/updatePermalinkCache.sql");

        $this->dbCore->tableNameResolver()->setSqlBigSelects();

        $results = $this->dbCore->queryAndGetResults($query);

        return $results;
    }

    /** @return array<string, mixed> */
    public function updatePermalinkCacheParentPages() {
        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ .
            "/../sql/updatePermalinkCacheParentPages.sql");

        $depthSoFar = 0;
        $results = array();
        do {
            $results = $this->dbCore->queryAndGetResults($query);
            $depthSoFar++;
        } while ($results['rows_affected'] != 0 && $depthSoFar < 15);

        return $results;
    }

    /** @return int */
    public function getPermalinkCacheCount(): int {
        $table = $this->dbCore->doTableNameReplacements('{wp_abj404_permalink_cache}');
        return $this->dbCore->queryScalarInt("SELECT COUNT(*) FROM `{$table}`");
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
            } else if (is_array($row)) {
                $objects[] = (object)$row;
            }
        }
        return $objects;
    }

    /**
     * @param array<mixed, mixed> $row
     * @return array<string, mixed>
     */
    private function stringKeyedRow(array $row): array {
        $stringKeyed = array();
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $stringKeyed[$key] = $value;
            }
        }
        return $stringKeyed;
    }
}
