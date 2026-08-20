<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decides, for the 404 currently being answered, what URL WordPress's own
 * canonicalization would have sent it to -- or that there is no such URL.
 *
 * Pure policy: it reads the request and the query WordPress already ran, and
 * returns either a canonical URL or a skip reason. It writes nothing, logs
 * nothing, and emits no response. The result carries the diagnostic context
 * to {@see ABJ_404_Solution_CanonicalPaginationRedirect}, which logs or acts
 * on the answer.
 *
 * THE CLASS OF URL
 *
 * `WP::handle_404()` declares a 404 when a pagination query var asks for a
 * page of content that does not exist -- even though the underlying resource
 * was found and is sitting in `$wp_query->post`. The clearest case is
 * `/?page=1` against a static front page: WordPress resolves the front page,
 * sees `page` set, finds no `<!--nextpage-->` marker in the content, and sets
 * `$content_found = false`. Core's remedy runs later, in `redirect_canonical()`
 * (wp-includes/canonical.php, the `if ( is_404() )` branch): recover the post
 * id from `$wp_query->queried_object` / `$wp_query->post`, redirect to
 * `get_permalink()`, and drop `page` from the query. This class is that same
 * decision, reimplemented rather than delegated -- calling
 * `redirect_canonical()` would also drag in `redirect_guess_404_permalink()`,
 * which guesses, and guessing is the behavior being removed here.
 *
 * WHICH QUERY VARS
 *
 * `page`, `paged`, `cpage`. WordPress exposes no API that enumerates "the
 * query vars redirect_canonical strips", so this list is literal, and it is
 * taken from core's own `remove_query_arg()` calls inside
 * `redirect_canonical()` (canonical.php: `page` in the is_404 branch and again
 * in the post-paging branch, `paged` and `cpage` in the paging-and-feeds
 * branch). `WP::$public_query_vars` (class-wp.php) is NOT a usable source: it
 * lists the ~48 query vars WordPress *recognizes* (`s`, `cat`, `author`, `m`,
 * `name`, ...), almost none of which core canonicalizes away, so deriving from
 * it would strip query vars that carry real meaning. `$wp_rewrite` exposes
 * `pagination_base` / `comments_pagination_base`, which are PATH segment
 * names, not query var names.
 *
 * WHAT IT WILL AND WILL NOT ANSWER
 *
 * A canonical URL when the stripped URL resolves; null when it does not.
 * "Resolves" is not guessed here: it is the answer WordPress already computed
 * for this very request. If `$wp_query` holds no post, the path is genuinely
 * broken and merely happens to carry `?page=`, and this class says so.
 *
 * The destination is additionally required to be the canonical form of the
 * SAME resource: the resolved permalink's path must match the requested path
 * (allowing for a trailing pretty-pagination segment). That makes "invent a
 * destination" structurally impossible. It is not a theoretical guard -- on a
 * blog posts page `$wp_query->post` is the first post IN the loop, not the
 * page itself, so without it `/blog/?page=2` would resolve to
 * `/blog/first-post/`.
 */
class ABJ_404_Solution_CanonicalPaginationUrlResolver {

    /**
     * Reserved pagination query vars `redirect_canonical()` removes. See the
     * class docblock for why this list is literal and where it comes from.
     *
     * @var array<int, string>
     */
    const RESERVED_PAGINATION_QUERY_VARS = array('page', 'paged', 'cpage');

    const STATUS_MATCHED = 'matched';
    const STATUS_SKIPPED = 'skipped';

    /**
     * The canonical destination for the request being answered.
     *
     * @param string $requestedURL requested path with sorted query string,
     *                             used only in returned diagnostic context.
     * @return array{status: 'matched', url: string}|array{status: 'skipped', reason: string|null}
     */
    function resolve(string $requestedURL): array {
        if (!$this->requestIsSafeToCanonicalize()) {
            return $this->skipped();
        }

        if (!$this->hasReservedPaginationQueryVar()) {
            return $this->skipped();
        }

        $postId = $this->resolvedPostId();
        if ($postId <= 0) {
            // WordPress found no resource for this request, so there is
            // nothing to canonicalize to: a genuinely broken path that merely
            // happens to carry a pagination query var.
            return $this->skipped();
        }

        $permalink = $this->permalinkFor($postId);
        if ($permalink === null) {
            return $this->skipped('Canonical pagination redirect skipped: post ' . $postId .
                ' resolved for "' . $requestedURL . '" has no usable permalink.');
        }

        $userRequest = ABJ_404_Solution_UserRequest::getInstance();
        if ($userRequest === null) {
            return $this->skipped();
        }
        $requestPath = (string)$userRequest->getPath();
        $requestQuery = (string)$userRequest->getQueryString();

        if (!$this->pathsDescribeTheSameResource(array(
            'requestPath' => $requestPath,
            'permalinkPath' => $this->pathOf($permalink),
        ))) {
            // The post WordPress resolved is not the resource that was asked
            // for. Redirecting there would invent a destination, which is the
            // bug this class exists to remove.
            return $this->skipped('Canonical pagination redirect skipped: resolved permalink "' .
                $permalink . '" is a different resource than the requested path "' . $requestPath . '".');
        }

        $remainingQuery = $this->queryWithoutPaginationVars($requestQuery);
        $canonicalUrl = $permalink . ($remainingQuery === '' ? '' : '?' . $remainingQuery);

        if ($this->pathAndQuery($canonicalUrl) === $this->joinPathAndQuery(array(
            'path' => $requestPath,
            'query' => $requestQuery,
        ))) {
            // Nothing to move to. Emitting this would ask the visitor to fetch
            // the URL that just 404'd.
            return $this->skipped('Canonical pagination redirect skipped: the canonical form of "' .
                $requestedURL . '" is the request itself.');
        }

        return $this->matched($canonicalUrl);
    }

    /**
     * @param string $url
     * @return array{status: 'matched', url: string}
     */
    private function matched(string $url): array {
        return array('status' => self::STATUS_MATCHED, 'url' => $url);
    }

    /**
     * @param string|null $reason
     * @return array{status: 'skipped', reason: string|null}
     */
    private function skipped(?string $reason = null): array {
        return array('status' => self::STATUS_SKIPPED, 'reason' => $reason);
    }

    /**
     * The two early-outs `redirect_canonical()` itself takes before doing any
     * work, for the same reasons.
     *
     * A redirect answers a request with "go fetch this other URL", which a
     * browser does with GET and no body: issuing one for a POST/PUT/DELETE
     * silently discards what the visitor submitted. Core refuses on anything
     * but GET/HEAD, and so does this.
     *
     * Preview requests are excluded because a preview URL is a working URL for
     * unpublished content; `get_permalink()` on a draft does not describe the
     * resource the previewer asked for.
     *
     * @return bool
     */
    private function requestIsSafeToCanonicalize(): bool {
        $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])
            ? strtoupper($_SERVER['REQUEST_METHOD']) : '';
        if ($method !== 'GET' && $method !== 'HEAD') {
            return false;
        }

        if (function_exists('is_preview') && is_preview()) {
            return false;
        }

        return true;
    }

    /**
     * Core gates on truthiness (`if ( get_query_var( 'page' ) )`), so "0" and
     * "" are not this class. Reading the query VARS rather than the query
     * string is deliberate: it also catches the pretty-permalink form
     * (`/a-post/5/`), where the page number never appears in a query string.
     *
     * @return bool
     */
    private function hasReservedPaginationQueryVar(): bool {
        if (!function_exists('get_query_var')) {
            return false;
        }
        foreach (self::RESERVED_PAGINATION_QUERY_VARS as $queryVar) {
            if (get_query_var($queryVar)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The post WordPress resolved for this request, surviving `set_404()`.
     *
     * `WP_Query::set_404()` resets only the is_* booleans (via
     * `init_query_flags()`), so `queried_object` and `post` still hold what the
     * main query found. Preference order matches core canonical.php:
     * `queried_object` when it is a post, then the loop post.
     *
     * @return int post id, or 0 when WordPress resolved nothing.
     */
    private function resolvedPostId(): int {
        $wpQuery = isset($GLOBALS['wp_query']) && is_object($GLOBALS['wp_query']) ? $GLOBALS['wp_query'] : null;
        if ($wpQuery === null) {
            return 0;
        }

        $queriedObjectId = $this->postIdOf(isset($wpQuery->queried_object) ? $wpQuery->queried_object : null);
        if ($queriedObjectId > 0) {
            return $queriedObjectId;
        }

        return $this->postIdOf(isset($wpQuery->post) ? $wpQuery->post : null);
    }

    /**
     * Read a post id off a query slot, insisting the slot really holds a post.
     *
     * Core writes `$wp_query->queried_object instanceof WP_Post`, and the type
     * check is load-bearing: the same slot holds a WP_Term on an archive and a
     * WP_User on an author page, both of which have an `ID`. Taking one of
     * those ids and asking `get_permalink()` about it means resolving a term
     * id against the posts table, which is how a redirect ends up pointing at
     * an unrelated page.
     *
     * @param mixed $candidate
     * @return int post id, or 0 when the slot is not a post.
     */
    private function postIdOf($candidate): int {
        if (!is_object($candidate) || !isset($candidate->ID) || (int)$candidate->ID <= 0) {
            return 0;
        }

        if ($candidate instanceof WP_Post) {
            return (int)$candidate->ID;
        }

        // Not the core class (a decorated post from a page builder, or an
        // early-boot object): accept it only if it is post-SHAPED. `post_type`
        // is the discriminator that matters, because the very next thing this
        // id is used for is get_permalink(), which reads the posts table. A
        // WP_Term and a WP_User both carry an `ID` and neither carries
        // `post_type`.
        if (isset($candidate->post_type) && (string)$candidate->post_type !== '') {
            return (int)$candidate->ID;
        }

        return 0;
    }

    /**
     * @param int $postId
     * @return string|null permalink, or null when it cannot be built.
     */
    private function permalinkFor(int $postId): ?string {
        if (!function_exists('get_permalink')) {
            return null;
        }
        $permalink = get_permalink($postId);
        if (!is_string($permalink) || trim($permalink) === '') {
            return null;
        }
        return $permalink;
    }

    /**
     * Whether the requested path and the resolved permalink's path describe
     * the same resource. They match outright, or the request is the permalink
     * plus a trailing pretty-pagination segment (`/a-post/5/`). Trailing
     * slashes are not significant for this comparison.
     *
     * @param array{requestPath: string, permalinkPath: string} $paths
     * @return bool
     */
    private function pathsDescribeTheSameResource(array $paths): bool {
        $requestPath = $paths['requestPath'];
        $permalinkPath = $paths['permalinkPath'];
        $request = $this->normalizePathForComparison($requestPath);
        $permalink = $this->normalizePathForComparison($permalinkPath);

        if ($request === $permalink) {
            return true;
        }

        // Strip one trailing numeric segment: the pretty-permalink page form.
        $withoutPageSegment = preg_replace('#/[0-9]+$#', '', $request);
        return is_string($withoutPageSegment)
            && $withoutPageSegment !== $request
            && $this->normalizePathForComparison($withoutPageSegment) === $permalink;
    }

    /**
     * @param string $path
     * @return string path with any trailing slash removed; '' stays '/'.
     */
    private function normalizePathForComparison(string $path): string {
        $trimmed = rtrim($path, '/');
        return $trimmed === '' ? '/' : $trimmed;
    }

    /**
     * @param string $url
     * @return string the path component, or '/' when there is none.
     */
    private function pathOf(string $url): string {
        $path = parse_url($url, PHP_URL_PATH);
        return (is_string($path) && $path !== '') ? $path : '/';
    }

    /**
     * @param string $url
     * @return string comparison key: path plus query, no scheme or host.
     */
    private function pathAndQuery(string $url): string {
        $query = parse_url($url, PHP_URL_QUERY);
        return $this->joinPathAndQuery(array(
            'path' => $this->pathOf($url),
            'query' => is_string($query) ? $query : '',
        ));
    }

    /**
     * @param array{path: string, query: string} $parts
     * @return string
     */
    private function joinPathAndQuery(array $parts): string {
        $path = $parts['path'];
        $query = $parts['query'];
        $normalizedPath = $this->normalizePathForComparison($path);
        return $query === '' ? $normalizedPath : $normalizedPath . '?' . $query;
    }

    /**
     * The request's query string with the reserved pagination vars removed.
     * Everything else the visitor sent (campaign parameters, filters) belongs
     * to them and survives, exactly as core's `remove_query_arg()` calls leave
     * the rest of the query alone.
     *
     * The surviving pairs are carried across byte-for-byte rather than run
     * through `parse_str()` + `http_build_query()`. That round trip is lossy
     * in two ways this path cannot afford: `parse_str()` rewrites `.` and ` `
     * inside parameter NAMES to `_` (so `?utm.id=7` would come back out as
     * `utm_id=7`), and re-encoding can change the escaping of values that were
     * already valid. A canonical redirect exists to remove one redundant
     * parameter; it must not quietly rewrite the others.
     *
     * @param string $queryString
     * @return string
     */
    private function queryWithoutPaginationVars(string $queryString): string {
        if (trim($queryString) === '') {
            return '';
        }

        $kept = array();
        foreach (explode('&', $queryString) as $pair) {
            if ($pair === '') {
                continue;
            }
            $equalsPos = strpos($pair, '=');
            $rawName = ($equalsPos === false) ? $pair : substr($pair, 0, $equalsPos);
            if (in_array(urldecode($rawName), self::RESERVED_PAGINATION_QUERY_VARS, true)) {
                continue;
            }
            $kept[] = $pair;
        }

        return implode('&', $kept);
    }
}
