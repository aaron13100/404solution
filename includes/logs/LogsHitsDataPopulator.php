<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Joins pre-aggregated wp_abj404_logs_hits rollup data (logsid, logshits,
 * last_used) onto a list of result rows by canonical URL.
 *
 * Real URLs arrive in many shapes (leading slash / no slash, trailing slash,
 * raw / esc_url_raw'd, with or without query/fragment) so a direct equality
 * lookup misses too often. This populator builds a small set of URL variants
 * per row, queries the hits table in chunked IN() batches, then merges
 * by canonical form.
 *
 * Extracted from LogsRepository under M201. Consumed by the LogsRepository
 * facade, which also passes itself (as LogsRepositoryInterface) so that any
 * test-time override of logsHitsTableExists / scheduleHitsTableRebuild on
 * the facade (or a DataAccess fake DAO bridge) propagates into this
 * populator's existence-check and missing-table-rebuild-scheduling paths.
 */
class ABJ_404_Solution_LogsHitsDataPopulator {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore) {
        $this->dbCore = $dbCore;
    }

    /**
     * Populate logshits / logsid / last_used on each row from the
     * wp_abj404_logs_hits rollup.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param ABJ_404_Solution_LogsRepositoryInterface $repo Source for logsHitsTableExists / scheduleHitsTableRebuild; passing the facade (rather than the raw rollup) ensures subclass overrides propagate.
     * @return array<int, array<string, mixed>>
     */
    public function populate($rows, ABJ_404_Solution_LogsRepositoryInterface $repo) {
        if (empty($rows)) {
            return $rows;
        }

        $urls = array();
        foreach ($rows as $row) {
            if ($row['url'] != null && !empty($row['url'])) {
                $variants = $this->buildHitsLookupUrlVariants($row['url']);
                foreach ($variants as $variant) {
                    $urls[] = $variant;
                }
            }
        }

        if (empty($urls)) {
            return $rows;
        }

        $urls = array_values(array_unique($urls));

        if (!$repo->logsHitsTableExists()) {
            $repo->scheduleHitsTableRebuild();
            return $rows;
        }

        $logsHitsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_logs_hits}');
        $batchSize = 200;
        $logsResults = array();
        $urlChunks = array_chunk($urls, $batchSize);

        foreach ($urlChunks as $urlChunk) {
            $placeholders = implode(',', array_fill(0, count($urlChunk), '%s'));
            $sql = "SELECT requested_url, logsid, last_used, logshits "
                 . "FROM {$logsHitsTable} "
                 . "WHERE BINARY requested_url IN ($placeholders)";

            $chunkResult = $this->dbCore->queryAndGetResults($sql, array(
                'query_params' => $urlChunk,
                'log_too_slow' => false,
            ));

            if (!empty($chunkResult['timed_out']) ||
                (isset($chunkResult['last_error']) && $chunkResult['last_error'] != '')) {
                $errRaw = $chunkResult['last_error'] ?? '';
                $err = is_string($errRaw) ? $errRaw : '';
                if ($err !== '' && strpos($err, 'logs_hits') !== false) {
                    $repo->scheduleHitsTableRebuild();
                }
                return $rows;
            }

            $chunkResults = is_array($chunkResult['rows'] ?? null) ? $chunkResult['rows'] : array();
            if (!empty($chunkResults)) {
                $logsResults = array_merge($logsResults, $chunkResults);
            }
        }

        $logsDataByUrl = array();
        foreach ($logsResults as $logRow) {
            $canonicalUrl = $this->canonicalizeUrlForHitsMatch($logRow['requested_url'] ?? '');
            if ($canonicalUrl === '') {
                continue;
            }
            if (!isset($logsDataByUrl[$canonicalUrl])) {
                $logsDataByUrl[$canonicalUrl] = array(
                    'logsid' => (int)($logRow['logsid'] ?? 0),
                    'logshits' => (int)($logRow['logshits'] ?? 0),
                    'last_used' => (int)($logRow['last_used'] ?? 0),
                );
                continue;
            }
            $existing = $logsDataByUrl[$canonicalUrl];
            $currentLogsid = (int)($logRow['logsid'] ?? 0);
            $existingLogsid = (int)$existing['logsid'];
            $logsDataByUrl[$canonicalUrl]['logsid'] = ($existingLogsid > 0 && $currentLogsid > 0)
                ? min($existingLogsid, $currentLogsid)
                : max($existingLogsid, $currentLogsid);
            $logsDataByUrl[$canonicalUrl]['logshits'] = (int)$existing['logshits'] + (int)($logRow['logshits'] ?? 0);
            $logsDataByUrl[$canonicalUrl]['last_used'] = max((int)$existing['last_used'], (int)($logRow['last_used'] ?? 0));
        }

        foreach ($rows as &$row) {
            if ($row['url'] != null && !empty($row['url'])) {
                $canonicalUrl = $this->canonicalizeUrlForHitsMatch($row['url']);
                if (isset($logsDataByUrl[$canonicalUrl])) {
                    $logData = $logsDataByUrl[$canonicalUrl];
                    $row['logsid'] = $logData['logsid'];
                    $row['logshits'] = $logData['logshits'];
                    $row['last_used'] = $logData['last_used'];
                }
            }
        }

        return $rows;
    }

    /** @param mixed $url @return string */
    private function canonicalizeUrlForHitsMatch($url): string {
        if (!is_string($url)) {
            return '';
        }
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $fragment = '';
        $fragmentPos = strpos($url, '#');
        if ($fragmentPos !== false) {
            $fragment = substr($url, $fragmentPos);
            $url = substr($url, 0, $fragmentPos);
        }
        $query = '';
        $queryPos = strpos($url, '?');
        if ($queryPos !== false) {
            $query = substr($url, $queryPos);
            $url = substr($url, 0, $queryPos);
        }
        $path = trim($url, '/');
        $normalizedPath = ($path === '') ? '/' : '/' . $path;
        return $normalizedPath . $query . $fragment;
    }

    /** @param mixed $url @return array<int, string> */
    private function buildHitsLookupUrlVariants($url) {
        $variants = array();
        if (!is_string($url)) {
            return $variants;
        }
        $raw = trim($url);
        if ($raw !== '') {
            $variants[] = $raw;
        }
        $canonical = $this->canonicalizeUrlForHitsMatch($url);
        if ($canonical !== '') {
            $variants[] = $canonical;
            $parts = $this->splitCanonicalHitsUrl($canonical);
            $pathPart = $parts['path'];
            $suffixPart = $parts['suffix'];
            $pathVariants = array($pathPart);
            $noLeadingPath = ltrim($pathPart, '/');
            if ($noLeadingPath !== '') {
                $pathVariants[] = $noLeadingPath;
            }
            if ($pathPart !== '/') {
                if (substr($pathPart, -1) === '/') {
                    $toggleTrailingPath = rtrim($pathPart, '/');
                } else {
                    $toggleTrailingPath = $pathPart . '/';
                }
                $pathVariants[] = $toggleTrailingPath;
                $toggleNoLeadingPath = ltrim($toggleTrailingPath, '/');
                if ($toggleNoLeadingPath !== '') {
                    $pathVariants[] = $toggleNoLeadingPath;
                }
            }
            foreach (array_unique($pathVariants) as $pathVariant) {
                $variants[] = $pathVariant . $suffixPart;
            }
        }
        return array_values(array_unique($variants));
    }

    /** @return array{path: string, suffix: string} */
    private function splitCanonicalHitsUrl(string $canonicalUrl): array {
        $firstQueryPos = strpos($canonicalUrl, '?');
        $firstFragmentPos = strpos($canonicalUrl, '#');
        if ($firstQueryPos === false && $firstFragmentPos === false) {
            return array('path' => $canonicalUrl, 'suffix' => '');
        }
        if ($firstQueryPos === false) {
            $splitPos = $firstFragmentPos;
        } elseif ($firstFragmentPos === false) {
            $splitPos = $firstQueryPos;
        } else {
            $splitPos = min($firstQueryPos, $firstFragmentPos);
        }
        return array(
            'path' => substr($canonicalUrl, 0, $splitPos),
            'suffix' => substr($canonicalUrl, $splitPos),
        );
    }
}
