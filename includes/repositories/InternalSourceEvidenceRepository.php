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

    /**
     * Hard upper bound on aggregate rows returned per queryAggregateRows()
     * call. Referrer cardinality for a captured URL is visitor-supplied and
     * unbounded (bots and open redirects can drive arbitrarily many distinct
     * Referer values at one captured URL); this caps the SQL-level result
     * set so the query itself cannot pull unbounded rows into memory before
     * the PHP-side $maxSources slice in getEvidenceForCapturedUrls() runs.
     * Matches ABJ_404_Solution_ContentKeywordsRepository::MAX_LIMIT.
     */
    const MAX_AGGREGATE_ROWS = 5000;

    /**
     * Hard upper bound on how many caller-supplied captured URLs this
     * repository will accept per call. $capturedUrls is meant to be "URLs
     * visible on the current admin table page" (well under 100 in practice,
     * matching RestApiRequestParser's per_page cap of 100), but the method
     * is public and does not otherwise validate cardinality; every accepted
     * URL drives a SQL IN() placeholder and bound parameter in
     * queryAggregateRows(), so an unbounded caller-supplied array would
     * still cost memory and query-planning work before MAX_AGGREGATE_ROWS'
     * LIMIT ever applies. Extra accepted URLs beyond this bound are dropped,
     * not errored, consistent with this repository degrading gracefully
     * rather than failing the admin table render.
     */
    const MAX_CAPTURED_URLS = 200;

    /** @var ABJ_404_Solution_DatabaseQueryInterface */
    private $db;

    /** @var ABJ_404_Solution_Functions */
    private $functions;

    /**
     * Same-site referrer host, in lowercase. Resolved once at construction
     * time rather than lazily memoized on first use: home_url() does not
     * change mid-request, so there is no benefit to deferring the read, and
     * resolving it eagerly keeps this a plain immutable value instead of a
     * query-shaped accessor with a mutation side effect (CQS violation --
     * see homeHost() removal, c308).
     *
     * @var string
     */
    private $homeHost;

    /**
     * @param ABJ_404_Solution_DatabaseQueryInterface $db
     * @param ABJ_404_Solution_Functions|null $functions UTF-8 sanitizer source.
     *     Defaults to the `functions` service so every call site (including
     *     tests that omit this argument) still gets real UTF-8 sanitization
     *     before values reach $wpdb->prepare() -- see queryAggregateRows()
     *     and resolvePostIdFromPermalinkCache(), which both take
     *     visitor-supplied captured 404 URLs. prepare() escapes quote and
     *     percent characters but does not validate or repair encoding, so
     *     sanitization must still happen before values become bound params.
     */
    public function __construct(ABJ_404_Solution_DatabaseQueryInterface $db, $functions = null) {
        $this->db = $db;
        $this->functions = $functions !== null ? $functions : abj_service('functions');
        $this->homeHost = $this->resolveHomeHost();
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
            // Same per-URL length bound the capture path itself enforces
            // (UserRequest::getPath(), SettingsRedirectPolicy, etc.). This
            // repository is a separate trust boundary from whatever called
            // it with $capturedUrls, so it must not assume the caller
            // already enforced the invariant: MAX_CAPTURED_URLS above only
            // bounds the count, not the size of each accepted string, and
            // every accepted URL is later bound into a SQL IN() clause.
            if (defined('ABJ404_MAX_URL_LENGTH') && strlen($url) > ABJ404_MAX_URL_LENGTH) {
                continue;
            }
            $set[$url] = true;
            if (count($set) >= self::MAX_CAPTURED_URLS) {
                break;
            }
        }
        return $set;
    }

    /**
     * @param array<int, string> $visibleUrls
     * @return array<int, array<string, mixed>>
     */
    private function queryAggregateRows(array $visibleUrls): array {
        $cleanUrls = array();
        foreach ($visibleUrls as $url) {
            // Captured 404 URLs are visitor-supplied (bots routinely deliver
            // garbage bytes through the request path); sanitize invalid
            // UTF-8 before it reaches $wpdb->prepare(), which does not
            // validate encoding on its own.
            $cleanUrls[] = $this->functions->sanitizeInvalidUTF8($url);
        }
        if (empty($cleanUrls)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($cleanUrls), '%s'));
        $query = "SELECT requested_url, referrer, COUNT(*) AS hit_count, MAX(timestamp) AS last_seen"
            . " FROM {wp_abj404_logsv2}"
            . " WHERE requested_url IN (" . $placeholders . ")"
            . " AND referrer IS NOT NULL AND referrer != ''"
            . " GROUP BY requested_url, referrer"
            . " ORDER BY requested_url ASC, hit_count DESC, last_seen DESC"
            . " LIMIT " . self::MAX_AGGREGATE_ROWS;

        $result = $this->db->queryAndGetResults($query, array('query_params' => $cleanUrls));
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
        // Call-scoped memo, not instance state: resolveSource() does a
        // url_to_postid()/permalink-cache lookup per unique source path, and
        // the same source path commonly repeats across rows within one
        // top-level call. Keeping the memo local (rather than an instance
        // property mutated by a "read" method) avoids a command-query
        // separation violation and stale-across-calls results if this
        // repository instance is ever reused for a second page render (see
        // homeHost() eager-resolve fix above for the same class of issue).
        $resolvedSources = array();
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
                $grouped[$capturedUrl][$sourcePath] = $this->resolveSource($sourcePath, $resolvedSources);
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
            if ($host === '' || $host !== $this->homeHost) {
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

    private function resolveHomeHost(): string {
        $home = function_exists('home_url') ? (string)home_url('/') : '';
        $host = parse_url($home, PHP_URL_HOST);
        return is_string($host) ? strtolower($host) : '';
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
     * Resolves raw post identity data for a same-site referrer path only:
     * post_id and post_title. Authorization (current_user_can('edit_post'))
     * and the edit_url presentation link are NOT this repository's concern
     * -- a data-access repository must not make authorization decisions or
     * build admin-link HTML (CLAUDE.md "Strict layer separation"). The
     * caller that renders source-evidence rows
     * (ABJ_404_Solution_CapturedSourceEvidenceRenderer::editLinkHtml()) owns
     * that decision, using the post_id returned here (c308).
     *
     * @param array<string, array<string, mixed>> $resolvedSources Call-scoped
     *     memo, keyed and updated by reference so repeat source paths within
     *     the same top-level call skip the post-id lookup. See
     *     groupRowsByCapturedUrl() for why this is a parameter rather than
     *     instance state.
     * @return array<string, mixed>
     */
    private function resolveSource(string $sourcePath, array &$resolvedSources): array {
        if (isset($resolvedSources[$sourcePath])) {
            return $resolvedSources[$sourcePath];
        }

        $postId = $this->resolvePostId($sourcePath);
        $title = $postId > 0 && function_exists('get_the_title') ? (string)get_the_title($postId) : '';

        $resolvedSources[$sourcePath] = array(
            'referrer_url' => $sourcePath,
            'post_id' => $postId,
            'post_title' => $title,
            'hit_count' => 0,
            'last_seen' => 0,
        );
        return $resolvedSources[$sourcePath];
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
        $cleanVariants = array();
        foreach ($variants as $variant) {
            if ($variant === '') {
                continue;
            }
            // $sourcePath is derived from the HTTP Referer header (see
            // normalizeSameSiteReferrer()), which is visitor-supplied and can
            // carry invalid UTF-8 byte sequences; sanitize before
            // $wpdb->prepare().
            $cleanVariants[] = $this->functions->sanitizeInvalidUTF8($variant);
        }
        if (empty($cleanVariants)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($cleanVariants), '%s'));
        // allow-unbounded-select: literal "LIMIT 1" lands in a separate concatenated string than SELECT/FROM (audit blind spot #1); $cleanVariants is also a fixed <=4-element set, not visitor-controlled cardinality.
        $query = "SELECT id FROM {wp_abj404_permalink_cache} WHERE url IN (" . $placeholders . ") LIMIT 1";
        $result = $this->db->queryAndGetResults($query, array('query_params' => $cleanVariants));
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        $row = is_array($rows[0] ?? null) ? $rows[0] : array();
        return $this->intField($row, 'id');
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
