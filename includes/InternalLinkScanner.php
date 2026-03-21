<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Proactive Internal Link Scanner.
 *
 * Scans published post/page content for broken internal links — links whose
 * relative path matches a URL captured as a 404 in the plugin's redirect table.
 *
 * No HTTP requests are made: this is entirely database + post_content inspection.
 */
class ABJ_404_Solution_InternalLinkScanner {

    /** Transient key used to cache scan results. */
    const TRANSIENT_KEY = 'abj404_broken_links_scan';

    /** Cache TTL in seconds (6 hours). */
    const CACHE_TTL = 21600;

    /** Maximum posts to inspect in a single batch to guard large sites. */
    const BATCH_LIMIT = 1000;

    /**
     * Scan all published posts/pages for broken internal links.
     *
     * Broken = URL appears in the captured-404 list (status = ABJ404_STATUS_CAPTURED,
     * disabled = 0).  External links are ignored.
     *
     * @return array<int, array{post_id: int, post_title: string, broken_url: string, hit_count: int}>
     */
    public function scanForBrokenLinks(): array {
        // 1. Collect captured 404 URLs from the redirects table.
        $capturedUrls = $this->getCapturedUrlSet();
        if (empty($capturedUrls)) {
            return array();
        }

        // 2. Get published posts/pages in batches (guard against very large sites).
        $siteHomeUrl = function_exists('home_url') ? (string)home_url() : '';
        $results = array();
        $offset = 0;

        do {
            $posts = $this->fetchPublishedPosts($offset, self::BATCH_LIMIT);
            if (empty($posts)) {
                break;
            }

            foreach ($posts as $post) {
                if (!is_object($post)) {
                    continue;
                }
                $content  = property_exists($post, 'post_content') ? (string)$post->post_content : '';
                $postId   = property_exists($post, 'ID')           ? intval($post->ID)            : 0;
                $postTitle = property_exists($post, 'post_title')  ? (string)$post->post_title    : '';

                if ($content === '') {
                    continue;
                }

                // Extract all href values.
                preg_match_all('/href=["\']([^"\']+)["\']/', $content, $matches);
                $hrefs = $matches[1];

                foreach ($hrefs as $href) {
                    $href = trim($href);
                    if ($href === '') {
                        continue;
                    }

                    // Normalize: strip the home_url prefix to get a relative path.
                    $relative = $this->normalizeToRelative($href, $siteHomeUrl);

                    // Skip purely external links (those we could not strip to a relative path).
                    if ($relative === null) {
                        continue;
                    }

                    // Check if this relative path is a captured 404.
                    if (!isset($capturedUrls[$relative])) {
                        continue;
                    }

                    $results[] = array(
                        'post_id'    => $postId,
                        'post_title' => $postTitle,
                        'broken_url' => $relative,
                        'hit_count'  => $capturedUrls[$relative],
                    );
                }
            }

            $offset += count($posts);
        } while (count($posts) >= self::BATCH_LIMIT);

        return $results;
    }

    /**
     * Run a scan and cache results in a transient with a 6-hour TTL.
     * Called nightly by the maintenance cron.
     * @return void
     */
    public function runNightlyScan(): void {
        $results = $this->scanForBrokenLinks();
        set_transient(self::TRANSIENT_KEY, $results, self::CACHE_TTL);
    }

    /**
     * Get cached scan results.
     *
     * @return array<int, array{post_id: int, post_title: string, broken_url: string, hit_count: int}>|false
     *         Cached results array, or false if no cache exists.
     */
    public function getCachedResults() {
        $result = get_transient(self::TRANSIENT_KEY);
        return is_array($result) ? $result : false;
    }

    /**
     * Build a map of captured-404 URLs to their hit counts from the redirects table.
     *
     * @return array<string, int>  Keyed by relative URL, value = hit count estimate.
     */
    private function getCapturedUrlSet(): array {
        global $wpdb;

        $capturedStatus = defined('ABJ404_STATUS_CAPTURED') ? intval(ABJ404_STATUS_CAPTURED) : 3;

        // Use the plugin's DAO if available, otherwise fall back to a direct wpdb query.
        if (class_exists('ABJ_404_Solution_DataAccess')) {
            $dao = ABJ_404_Solution_DataAccess::getInstance();
            $redirectsTable = $dao->doTableNameReplacements('{wp_abj404_redirects}');
        } else {
            $redirectsTable = $wpdb->prefix . 'abj404_redirects';
        }

        $sql = $wpdb->prepare(
            "SELECT `url` FROM `{$redirectsTable}` WHERE `status` = %d AND `disabled` = 0",
            $capturedStatus
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return array();
        }

        $urlSet = array();
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['url'])) {
                continue;
            }
            $url = (string)$row['url'];
            if ($url !== '') {
                // Use 0 as a placeholder hit count; real hit counts would need a join.
                $urlSet[$url] = 0;
            }
        }

        return $urlSet;
    }

    /**
     * Fetch a batch of published posts and pages.
     *
     * @param int $offset
     * @param int $limit
     * @return array<int, object>
     */
    private function fetchPublishedPosts(int $offset, int $limit): array {
        if (!function_exists('get_posts')) {
            return array();
        }

        $posts = get_posts(array(
            'post_type'      => array('post', 'page'),
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'fields'         => 'all',
            // Suppress filters so we get raw post_content.
            'suppress_filters' => true,
        ));

        return $posts;
    }

    /**
     * Normalize a URL to a relative path by stripping the home_url prefix.
     *
     * Returns null when the URL is purely external (not on this site).
     *
     * @param string $href       The href value extracted from post content.
     * @param string $siteHome   The result of home_url() for this site.
     * @return string|null       Relative path (e.g. "/old-page/") or null for external links.
     */
    private function normalizeToRelative(string $href, string $siteHome): ?string {
        // Already a relative path.
        if (strpos($href, '/') === 0 && strpos($href, '//') !== 0) {
            return $href;
        }

        // Absolute URL: strip the home_url prefix if it matches this site.
        if ($siteHome !== '' && strpos($href, $siteHome) === 0) {
            $relative = substr($href, strlen($siteHome));
            return ($relative === '') ? '/' : $relative;
        }

        // Fragment-only or javascript: — not a real internal link.
        if (strpos($href, '#') === 0 || strpos($href, 'javascript:') === 0 || strpos($href, 'mailto:') === 0) {
            return null;
        }

        // Anything else is an external URL.
        return null;
    }
}
