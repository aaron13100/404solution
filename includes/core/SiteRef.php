<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Typed value object for a WordPress site (multisite blog) as passed to
 * the `wp_initialize_site` action and returned by `get_site()` /
 * `get_sites()` when the `fields` argument is left at its default.
 *
 * Boundary normalizer (task: type-pressure at module boundaries).
 *
 * Multisite WP exposes a site via the `WP_Site` class, whose properties
 * (`blog_id`, `site_id`, `domain`, `path`, `registered`, `last_updated`,
 * `public`, `archived`, `mature`, `spam`, `deleted`) are all declared
 * `string` in the WP stubs because they come straight from the
 * `wp_blogs` table. Consumers must therefore cast every access:
 *
 *   $blogId = (int)$site->blog_id;
 *   $domain = property_exists($site, 'domain') ? (string)$site->domain : '';
 *
 * In the 404 Solution codebase only one consumer takes a WP_Site object
 * directly (the `wp_initialize_site` hook handler, which receives the
 * full object). The rest of the plugin uses `get_sites(['fields' => 'ids'])`
 * and operates on raw IDs. That single consumer is currently:
 *
 *   $blogId = (int)$site->blog_id;
 *   switch_to_blog($blogId);
 *
 * which silently treats a malformed `$site` (`null`, an array, a missing
 * `blog_id`) as `switch_to_blog(0)`. `switch_to_blog(0)` is a no-op in
 * some WP versions and a fatal in others. The VO catches the malformed
 * input at the boundary so the consumer either gets a valid VO or a
 * clean `null` to early-return on.
 *
 * Schema (after normalization):
 *
 *   - blogId      : int >= 1   (zero or negative rejected at boundary)
 *   - networkId   : int >= 0   (site_id; 0 if absent)
 *   - domain      : string
 *   - path        : string
 *   - registered  : string     (raw datetime; consumers parse if needed)
 *   - lastUpdated : string
 *   - public      : bool       ('0'/'1' string normalized)
 *   - archived    : bool
 *   - mature      : bool
 *   - spam        : bool
 *   - deleted     : bool
 *
 * Construction accepts a `WP_Site` object, any object that mirrors the
 * documented property surface, an associative array (some WP filters
 * pass the row shape as ARRAY_A), or `null` / non-object input.
 * `fromWpSite` returns `null` when `blog_id` cannot be coerced to a
 * positive integer: without it the VO cannot serve its primary purpose
 * (driving `switch_to_blog`).
 */
final class ABJ_404_Solution_SiteRef {

    /** @var int */
    private $blogId;

    /** @var int */
    private $networkId;

    /** @var string */
    private $domain;

    /** @var string */
    private $path;

    /** @var string */
    private $registered;

    /** @var string */
    private $lastUpdated;

    /** @var bool */
    private $public;

    /** @var bool */
    private $archived;

    /** @var bool */
    private $mature;

    /** @var bool */
    private $spam;

    /** @var bool */
    private $deleted;

    private function __construct(
        int $blogId,
        int $networkId,
        string $domain,
        string $path,
        string $registered,
        string $lastUpdated,
        bool $public,
        bool $archived,
        bool $mature,
        bool $spam,
        bool $deleted
    ) {
        $this->blogId = $blogId;
        $this->networkId = $networkId;
        $this->domain = $domain;
        $this->path = $path;
        $this->registered = $registered;
        $this->lastUpdated = $lastUpdated;
        $this->public = $public;
        $this->archived = $archived;
        $this->mature = $mature;
        $this->spam = $spam;
        $this->deleted = $deleted;
    }

    /**
     * Normalize a `WP_Site` / `get_site()` return (or compatible payload)
     * into a typed VO. Returns null when the input cannot yield a usable
     * `blog_id` (non-positive or missing), because a SiteRef without a
     * `blog_id` cannot drive `switch_to_blog()` and every consumer would
     * have to re-check anyway.
     *
     * @param mixed $raw
     */
    public static function fromWpSite($raw): ?self {
        if ($raw === null || is_bool($raw)) {
            return null;
        }
        if (is_object($raw)) {
            $blogId = self::coerceObjectInt($raw, 'blog_id');
            if ($blogId <= 0) {
                return null;
            }
            return new self(
                $blogId,
                self::coerceObjectInt($raw, 'site_id'),
                self::coerceObjectString($raw, 'domain'),
                self::coerceObjectString($raw, 'path'),
                self::coerceObjectString($raw, 'registered'),
                self::coerceObjectString($raw, 'last_updated'),
                self::coerceObjectBool($raw, 'public', true),
                self::coerceObjectBool($raw, 'archived', false),
                self::coerceObjectBool($raw, 'mature', false),
                self::coerceObjectBool($raw, 'spam', false),
                self::coerceObjectBool($raw, 'deleted', false)
            );
        }
        if (is_array($raw)) {
            $blogId = self::coerceArrayInt($raw, 'blog_id');
            if ($blogId <= 0) {
                return null;
            }
            return new self(
                $blogId,
                self::coerceArrayInt($raw, 'site_id'),
                self::coerceArrayString($raw, 'domain'),
                self::coerceArrayString($raw, 'path'),
                self::coerceArrayString($raw, 'registered'),
                self::coerceArrayString($raw, 'last_updated'),
                self::coerceArrayBool($raw, 'public', true),
                self::coerceArrayBool($raw, 'archived', false),
                self::coerceArrayBool($raw, 'mature', false),
                self::coerceArrayBool($raw, 'spam', false),
                self::coerceArrayBool($raw, 'deleted', false)
            );
        }
        return null;
    }

    public function getBlogId(): int {
        return $this->blogId;
    }

    public function getNetworkId(): int {
        return $this->networkId;
    }

    public function getDomain(): string {
        return $this->domain;
    }

    public function getPath(): string {
        return $this->path;
    }

    public function getRegistered(): string {
        return $this->registered;
    }

    public function getLastUpdated(): string {
        return $this->lastUpdated;
    }

    public function isPublic(): bool {
        return $this->public;
    }

    public function isArchived(): bool {
        return $this->archived;
    }

    public function isMature(): bool {
        return $this->mature;
    }

    public function isSpam(): bool {
        return $this->spam;
    }

    public function isDeleted(): bool {
        return $this->deleted;
    }

    /**
     * True iff this site can be safely activated against (not archived,
     * not deleted, not spam). Matches the gate that the plugin's
     * lifecycle hooks should respect before calling `switch_to_blog(...)`
     * followed by `activateSingleSite()`.
     */
    public function isActivatable(): bool {
        return !$this->archived && !$this->deleted && !$this->spam;
    }

    /**
     * @param object $obj
     */
    private static function coerceObjectString($obj, string $key): string {
        if (!property_exists($obj, $key)) {
            return '';
        }
        $v = $obj->{$key};
        if (is_string($v)) {
            return $v;
        }
        if (is_scalar($v)) {
            return (string)$v;
        }
        return '';
    }

    /**
     * @param object $obj
     */
    private static function coerceObjectInt($obj, string $key): int {
        if (!property_exists($obj, $key)) {
            return 0;
        }
        return self::scalarToInt($obj->{$key});
    }

    /**
     * @param object $obj
     */
    private static function coerceObjectBool($obj, string $key, bool $default): bool {
        if (!property_exists($obj, $key)) {
            return $default;
        }
        return self::scalarToBool($obj->{$key}, $default);
    }

    /**
     * @param array<mixed, mixed> $arr
     */
    private static function coerceArrayString(array $arr, string $key): string {
        if (!isset($arr[$key])) {
            return '';
        }
        $v = $arr[$key];
        if (is_string($v)) {
            return $v;
        }
        if (is_scalar($v)) {
            return (string)$v;
        }
        return '';
    }

    /**
     * @param array<mixed, mixed> $arr
     */
    private static function coerceArrayInt(array $arr, string $key): int {
        if (!isset($arr[$key])) {
            return 0;
        }
        return self::scalarToInt($arr[$key]);
    }

    /**
     * @param array<mixed, mixed> $arr
     */
    private static function coerceArrayBool(array $arr, string $key, bool $default): bool {
        if (!isset($arr[$key])) {
            return $default;
        }
        return self::scalarToBool($arr[$key], $default);
    }

    /**
     * @param mixed $v
     */
    private static function scalarToInt($v): int {
        if (is_int($v)) {
            return $v < 0 ? 0 : $v;
        }
        if (is_float($v)) {
            $i = (int)$v;
            return $i < 0 ? 0 : $i;
        }
        if (is_string($v) && is_numeric($v)) {
            $i = (int)$v;
            return $i < 0 ? 0 : $i;
        }
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        return 0;
    }

    /**
     * WP stores boolean flags from `wp_blogs` as '0' / '1' strings. Coerce
     * other truthy/falsy scalar shapes too (some test fixtures use real
     * booleans / ints). Non-scalar / unknown shape returns the default
     * rather than silently treating malformed input as one of true/false.
     *
     * @param mixed $v
     */
    private static function scalarToBool($v, bool $default): bool {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return ((int)$v) === 1;
        }
        if (is_string($v)) {
            if ($v === '1') {
                return true;
            }
            if ($v === '0' || $v === '') {
                return false;
            }
            return $default;
        }
        return $default;
    }
}
