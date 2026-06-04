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

    public function clear(): void {
        self::clearStatic();
    }

    public static function clearStatic(): void {
        self::$regexRedirectsCache = null;
        self::$regexCacheDisabled = false;
    }

    /** @return array<int, array<string, mixed>>|null */
    public static function getRegexRedirectsCache() {
        return self::$regexRedirectsCache;
    }

    /** @param array<int, array<string, mixed>>|null $cache */
    public static function setRegexRedirectsCache($cache): void {
        self::$regexRedirectsCache = $cache;
    }

    public static function isRegexCacheDisabled(): bool {
        return self::$regexCacheDisabled;
    }

    public static function setRegexCacheDisabled(bool $disabled): void {
        self::$regexCacheDisabled = $disabled;
    }
}
