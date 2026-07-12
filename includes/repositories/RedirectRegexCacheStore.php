<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-request state store for regex redirect lookup caching.
 *
 * Redirect matching and redirect mutation both need to clear or inspect this
 * cache, so the state lives in one focused owner instead of on the broad
 * RedirectsRepository facade.
 */
class ABJ_404_Solution_RedirectRegexCacheStore {

    /** Maximum number of regex redirects to cache per request. */
    const MAX_COUNT = 50;

    /** @var array<int, array<string, mixed>>|null */
    private static $regexRedirectsCache = null;

    /** @var bool */
    private static $regexCacheDisabled = false;

    /**
     * Blog id the cache above (or the disabled flag) was populated for. This
     * is a CLASS-STATIC cache -- shared by every RedirectsBulkReader /
     * RedirectsRepository instance in the process, not just one object --
     * memoizing rows read from the per-blog {wp_prefix}_abj404_redirects
     * table. The underlying query is correctly blog-scoped ($wpdb->prefix
     * follows switch_to_blog()), but without this guard the cache sitting in
     * front of it is not: a multisite process that switches blogs mid-request
     * (a WP-CLI network-wide script looping get_sites()/switch_to_blog(), or
     * an Action Scheduler worker draining queued cross-site actions
     * sequentially) would resolve blog A's regex redirects once, cache them
     * process-wide, then silently keep serving blog A's rows to blog B (and
     * every blog after it) for the rest of the process. Every real caller of
     * getRedirectsWithRegEx() (WPCLIRedirectCommandService, RestApiReadService,
     * ViewReadService) inherits this guard automatically since they never
     * touch the cache fields directly.
     *
     * @var int|null
     */
    private static $regexRedirectsCacheBlogId = null;

    public function clear(): void {
        self::clearStatic();
    }

    public static function clearStatic(): void {
        self::$regexRedirectsCache = null;
        self::$regexCacheDisabled = false;
        self::$regexRedirectsCacheBlogId = null;
    }

    /** @return array<int, array<string, mixed>>|null */
    public static function getRegexRedirectsCache() {
        self::invalidateIfBlogChanged();
        return self::$regexRedirectsCache;
    }

    /** @param array<int, array<string, mixed>>|null $cache */
    public static function setRegexRedirectsCache($cache): void {
        self::$regexRedirectsCache = $cache;
        self::$regexRedirectsCacheBlogId = self::currentBlogId();
    }

    public static function isRegexCacheDisabled(): bool {
        self::invalidateIfBlogChanged();
        return self::$regexCacheDisabled;
    }

    public static function setRegexCacheDisabled(bool $disabled): void {
        self::$regexCacheDisabled = $disabled;
        self::$regexRedirectsCacheBlogId = self::currentBlogId();
    }

    /**
     * Drop the cache (and the disabled flag) if the active WordPress blog
     * has changed since the cache was populated. A no-op when nothing has
     * been cached yet, so an uninitialized cache never records a blog id.
     *
     * @return void
     */
    private static function invalidateIfBlogChanged(): void {
        if (self::$regexRedirectsCache === null && self::$regexCacheDisabled === false) {
            return;
        }

        if (self::$regexRedirectsCacheBlogId !== self::currentBlogId()) {
            self::$regexRedirectsCache = null;
            self::$regexCacheDisabled = false;
            self::$regexRedirectsCacheBlogId = null;
        }
    }

    /** @return int */
    private static function currentBlogId(): int {
        return function_exists('get_current_blog_id') ? (int)get_current_blog_id() : 0;
    }
}
