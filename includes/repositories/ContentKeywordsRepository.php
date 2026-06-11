<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads and updates permalink-cache content keyword payloads.
 */
class ABJ_404_Solution_ContentKeywordsRepository {

    /**
     * Hard upper bound on rows returned per batch. Caller-supplied limits
     * above this are clamped; this is the safety backstop that keeps a
     * misconfigured caller from issuing an effectively unbounded query
     * over wp_posts on large sites.
     */
    const MAX_LIMIT = 5000;

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;
    /** @var ABJ_404_Solution_Functions */
    private $functions;
    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions $functions
     * @param ABJ_404_Solution_Logging $logging
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        $functions,
        $logging
    ) {
        $this->dbCore = $dbCore;
        $this->functions = $functions;
        $this->logger = $logging;
    }

    /**
     * @param int $limit
     * @return array<int, object>
     */
    public function getPostsNeedingContentKeywords(int $limit = 500): array {
        $clampedLimit = min(self::MAX_LIMIT, max(1, absint($limit)));

        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getPostsNeedingContentKeywords.sql");
        $query = $this->functions->str_replace('{limit-results}', (string) $clampedLimit, $query);

        $result = $this->dbCore->queryAndGetResults($query, array(
            'result_type' => OBJECT,
            'log_errors' => false,
        ));

        $lastError = isset($result['last_error']) && is_string($result['last_error']) ? $result['last_error'] : '';
        if ($lastError !== '') {
            if (stripos($lastError, 'unknown column') !== false) {
                $this->logger->warn("content_keywords column not yet available (DB migration pending): " . $lastError);
            } else if (!$this->dbCore->errorClassifier()->classifyAndHandleInfrastructureError($lastError)) {
                $this->logger->errorMessage("Error fetching posts for content keywords: " . $lastError);
            }
            return array();
        }

        $rawRows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();
        $rows = array();
        foreach ($rawRows as $row) {
            if (is_object($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /** @param array<int, string> $idToKeywords @return void */
    public function bulkUpdateContentKeywords(array $idToKeywords): void {
        if (empty($idToKeywords)) {
            return;
        }

        $table = $this->dbCore->doTableNameReplacements('{wp_abj404_permalink_cache}');

        $whenClauses = array();
        $params = array();
        $ids = array();
        foreach ($idToKeywords as $id => $keywords) {
            $intId = (int) $id;
            $whenClauses[] = 'WHEN %d THEN %s';
            $params[] = $intId;
            $params[] = $keywords;
            $ids[] = $intId;
        }

        $idPlaceholders = implode(',', array_fill(0, count($ids), '%d'));

        $sql = "UPDATE `{$table}` SET content_keywords = CASE id\n        "
            . implode("\n        ", $whenClauses)
            . "\n        END\n        WHERE id IN ({$idPlaceholders})";

        $allParams = array_merge($params, $ids);

        $result = $this->dbCore->queryAndGetResults($sql, array('query_params' => $allParams));

        $lastErrorRaw = $result['last_error'] ?? '';
        $lastError = is_string($lastErrorRaw) ? $lastErrorRaw : '';
        if ($lastError !== '' && stripos($lastError, 'unknown column') !== false) {
            $this->logger->warn("content_keywords column not yet available (DB migration pending): " . $lastError);
        }
    }
}
