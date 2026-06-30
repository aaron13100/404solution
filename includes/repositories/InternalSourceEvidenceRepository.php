<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads observed same-site referrer evidence for the visible captured 404 rows.
 *
 * The repository is intentionally read-side only: it does not scan content, crawl
 * pages, or add write-path cost to 404 logging. Callers pass the captured URLs
 * already visible on the current table page, and this class bounds the logsv2
 * aggregate to those URLs.
 */
class ABJ_404_Solution_InternalSourceEvidenceRepository {

    /** @var ABJ_404_Solution_DatabaseQueryInterface */
    private $db;

    /** @var ABJ_404_Solution_Functions|null */
    private $functions;

    /** @var array<string, array<string, mixed>> */
    private $resolutionMemo = array();

    /** @var string|null */
    private $homeHost = null;

    /**
     * @param ABJ_404_Solution_DatabaseQueryInterface $db
     * @param ABJ_404_Solution_Functions|null $functions UTF-8 sanitizer source.
     */
    public function __construct(ABJ_404_Solution_DatabaseQueryInterface $db, $functions = null) {
        $this->db = $db;
        $this->functions = $functions;
    }

    /**
     * Return source evidence keyed by captured requested URL.
     *
     * @param array<int, string> $capturedUrls Visible captured URLs only.
     * @param int $maxSources Maximum source rows to display per captured URL.
     * @return array<string, array{source_count:int,displayed_source_count:int,sources:array<int,array<string,mixed>>}>
     */
    public function getEvidenceForCapturedUrls(array $capturedUrls, int $maxSources = 5): array {
        $visibleUrls = $this->visibleUrlSet($capturedUrls);
        if (empty($visibleUrls)) {
            return array();
        }

        $aggregateRows = $this->queryAggregateRows(array_keys($visibleUrls));
        $grouped = $this->groupRowsByCapturedUrl($aggregateRows, $visibleUrls);

        $evidence = array();
        foreach ($grouped as $capturedUrl => $sourcesByPath) {
            uasort($sourcesByPath, function (array $a, array $b): int {
                $hitsCompare = $this->intField($b, 'hit_count') <=> $this->intField($a, 'hit_count');
                if ($hitsCompare !== 0) {
                    return $hitsCompare;
                }
                return $this->intField($b, 'last_seen') <=> $this->intField($a, 'last_seen');
            });

            $sourceCount = count($sourcesByPath);
            if ($sourceCount === 0) {
                continue;
            }

            $displayed = array_slice(array_values($sourcesByPath), 0, max(1, $maxSources));
            $evidence[$capturedUrl] = array(
                'source_count' => $sourceCount,
                'displayed_source_count' => count($displayed),
                'sources' => $displayed,
            );
        }

        return $evidence;
    }

    /**
     * @param array<int, string> $capturedUrls
     * @return array<string, bool>
     */
    private function visibleUrlSet(array $capturedUrls): array {
        $set = array();
        foreach ($capturedUrls as $url) {
            if (!is_string($url) || $url === '') {
                continue;
            }
            $set[$url] = true;
        }
        return $set;
    }

    /**
     * @param array<int, string> $visibleUrls
     * @return array<int, array<string, mixed>>
     */
    private function queryAggregateRows(array $visibleUrls): array {
        $quoted = array();
        foreach ($visibleUrls as $url) {
            $quoted[] = "'" . esc_sql($this->sanitizeSqlString($url)) . "'";
        }

        $query = "SELECT requested_url, referrer, COUNT(*) AS hit_count, MAX(timestamp) AS last_seen"
            . " FROM {wp_abj404_logsv2}"
            . " WHERE requested_url IN (" . implode(',', $quoted) . ")"
            . " AND referrer IS NOT NULL AND referrer != ''"
            . " GROUP BY requested_url, referrer"
            . " ORDER BY requested_url ASC, hit_count DESC, last_seen DESC";

        $result = $this->db->queryAndGetResults($query);
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        $typedRows = array();
        foreach ($rows as $row) {
            if (is_array($row)) {
                $typedRows[] = $this->stringKeyedRow($row);
            }
        }
        return $typedRows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, bool> $visibleUrls
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function groupRowsByCapturedUrl(array $rows, array $visibleUrls): array {
        $grouped = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $capturedUrl = $this->stringField($row, 'requested_url');
            if ($capturedUrl === '' || !isset($visibleUrls[$capturedUrl])) {
                continue;
            }

            $sourcePath = $this->normalizeSameSiteReferrer($this->stringField($row, 'referrer'));
            if ($sourcePath === null) {
                continue;
            }

            if (!isset($grouped[$capturedUrl][$sourcePath])) {
                $grouped[$capturedUrl][$sourcePath] = $this->resolveSource($sourcePath);
            }

            $currentSource = $grouped[$capturedUrl][$sourcePath];
            $currentSource['hit_count'] = $this->intField($currentSource, 'hit_count')
                + max(0, $this->intField($row, 'hit_count'));
            $currentSource['last_seen'] = max(
                $this->intField($currentSource, 'last_seen'),
                $this->intField($row, 'last_seen')
            );
            $grouped[$capturedUrl][$sourcePath] = $currentSource;
        }
        return $grouped;
    }

    private function normalizeSameSiteReferrer(string $referrer): ?string {
        $referrer = trim($referrer);
        if ($referrer === '') {
            return null;
        }

        if (strpos($referrer, '/') === 0 && strpos($referrer, '//') !== 0) {
            $path = parse_url($referrer, PHP_URL_PATH);
        } else {
            $parts = parse_url($referrer);
            if (!is_array($parts)) {
                return null;
            }
            $host = isset($parts['host']) && is_string($parts['host']) ? strtolower($parts['host']) : '';
            if ($host === '' || $host !== $this->homeHost()) {
                return null;
            }
            $path = isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : '';
        }

        $path = is_string($path) ? '/' . ltrim($path, '/') : '';
        if ($path === '' || $path === '/') {
            return null;
        }
        if ($this->isExcludedPath($path)) {
            return null;
        }

        return $path;
    }

    private function homeHost(): string {
        if ($this->homeHost !== null) {
            return $this->homeHost;
        }
        $home = function_exists('home_url') ? (string)home_url('/') : '';
        $host = parse_url($home, PHP_URL_HOST);
        $this->homeHost = is_string($host) ? strtolower($host) : '';
        return $this->homeHost;
    }

    private function isExcludedPath(string $path): bool {
        $lower = strtolower($path);
        foreach (array('/wp-admin', '/wp-login.php', '/wp-json', '/wp-content', '/wp-includes') as $prefix) {
            if ($lower === $prefix || strpos($lower, $prefix . '/') === 0) {
                return true;
            }
        }

        return preg_match('/\.(css|js|map|json|xml|jpg|jpeg|png|gif|webp|svg|ico|pdf|zip|woff|woff2|ttf|eot)$/i', $lower) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSource(string $sourcePath): array {
        if (isset($this->resolutionMemo[$sourcePath])) {
            return $this->resolutionMemo[$sourcePath];
        }

        $postId = $this->resolvePostId($sourcePath);
        $title = '';
        $editUrl = '';
        if ($postId > 0) {
            $title = function_exists('get_the_title') ? (string)get_the_title($postId) : '';
            if (function_exists('current_user_can') && current_user_can('edit_post', $postId) && function_exists('get_edit_post_link')) {
                $editUrl = (string)get_edit_post_link($postId);
            }
        }

        $this->resolutionMemo[$sourcePath] = array(
            'referrer_url' => $sourcePath,
            'post_id' => $postId,
            'post_title' => $title,
            'edit_url' => $editUrl,
            'hit_count' => 0,
            'last_seen' => 0,
        );
        return $this->resolutionMemo[$sourcePath];
    }

    private function resolvePostId(string $sourcePath): int {
        if (function_exists('url_to_postid') && function_exists('home_url')) {
            $postId = (int)url_to_postid(home_url($sourcePath));
            if ($postId > 0) {
                return $postId;
            }
        }

        return $this->resolvePostIdFromPermalinkCache($sourcePath);
    }

    private function resolvePostIdFromPermalinkCache(string $sourcePath): int {
        $trimmed = trim($sourcePath, '/');
        $variants = array_values(array_unique(array($sourcePath, '/' . $trimmed, $trimmed, '/' . $trimmed . '/')));
        $quoted = array();
        foreach ($variants as $variant) {
            if ($variant === '') {
                continue;
            }
            $quoted[] = "'" . esc_sql($this->sanitizeSqlString($variant)) . "'";
        }
        if (empty($quoted)) {
            return 0;
        }

        $query = sprintf(
            "SELECT id FROM {wp_abj404_permalink_cache} WHERE url IN (%s) LIMIT 1",
            implode(',', $quoted)
        );
        $result = $this->db->queryAndGetResults($query);
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        $row = is_array($rows[0] ?? null) ? $rows[0] : array();
        return $this->intField($row, 'id');
    }

    private function sanitizeSqlString(string $value): string {
        if ($this->functions !== null && method_exists($this->functions, 'sanitizeInvalidUTF8')) {
            return (string)$this->functions->sanitizeInvalidUTF8($value);
        }
        return $value;
    }

    /** @param array<string, mixed> $row */
    private function stringField(array $row, string $key): string {
        return isset($row[$key]) && is_scalar($row[$key]) ? (string)$row[$key] : '';
    }

    /** @param array<string, mixed> $row */
    private function intField(array $row, string $key): int {
        return isset($row[$key]) && is_numeric($row[$key]) ? (int)$row[$key] : 0;
    }

    /**
     * @param array<array-key, mixed> $row
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
