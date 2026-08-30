<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Engine Profile Resolver
 *
 * Allows admins to create URL-pattern-based "engine profiles" that control
 * which matching engines run for a given 404 URL. First matching profile wins.
 * If no profile matches, all engines are used (default behavior preserved).
 *
 * Profiles are stored in wp_abj404_engine_profiles and cached per-request
 * via a static property to avoid repeated DB queries on the same page.
 */
class ABJ_404_Solution_EngineProfileResolver {

    /** @var self|null */
    private static $instance = null;
    /**
     * Test seam: install or clear the cached singleton instance without
     * private-field reflection. Pass null to reset between tests; pass a
     * configured instance (or double) to install it. Mirrors the setInstance()
     * contract on DataAccess / PluginLogic (M105 singleton-reset seam).
     *
     * @param self|null $instance
     * @return void
     */
    public static function setInstance($instance) {
        self::$instance = $instance;
    }


    /**
     * Per-blog memo of the resolved profile rows. Scoped to the blog id active
     * at cache time (not just the request) because wp_abj404_engine_profiles is
     * a genuinely per-blog table (the repository derives it from $wpdb->prefix,
     * which switch_to_blog() changes): a multisite background batch that
     * switch_to_blog()s mid-request (DatabaseUpgradeMultiSite::processMultisiteBatch(),
     * NGramCacheRebuildScheduler, network-wide deactivation/uninstall) would
     * otherwise permanently pin the memoized profile list to whichever blog
     * happened to trigger the first resolution, silently applying blog A's
     * engine-filtering profile to blog B's 404 matching once the blog context
     * changes. Cleared by saveProfile()/deleteProfile()/clearCache().
     *
     * @var array<int, object>|null Cached profile rows for current request
     */
    private $cachedProfiles = null;

    /** @var int|null Blog id $cachedProfiles was resolved for. */
    private $cachedProfilesBlogId = null;

    /** @var ABJ_404_Solution_DataAccess|null Lazy DAO accessor for centralized query handling. */
    /** @var ABJ_404_Solution_EngineProfileRepository|null */
    private $repository = null;

    /** @return self */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Lazy repository accessor, deferring resolution until first use so unit
     * tests that exercise pure-logic methods (URL matching, JSON decoding)
     * never boot the database layer.
     */
    private function repository(): ABJ_404_Solution_EngineProfileRepository {
        if ($this->repository === null) {
            $this->repository = new ABJ_404_Solution_EngineProfileRepository();
        }
        return $this->repository;
    }

    /**
     * Resolve which engines to run for a given 404 URL.
     *
     * Queries active profiles ordered by priority ASC. First profile whose
     * url_pattern matches the requested URL wins. The matched profile's
     * enabled_engines list (JSON array of full class names) is used to filter
     * the $allEngines array.
     *
     * If no profile matches, $allEngines is returned unchanged.
     *
     * The result is passed through `apply_filters('abj404_resolved_engines', ...)`.
     *
     * @param string $requestedURL The 404 URL being processed (path only or full URL).
     * @param array<int, mixed> $allEngines The full list of matching engine instances.
     * @return array<int, mixed> Filtered (or unchanged) engine list.
     */
    public function resolve(string $requestedURL, array $allEngines): array {
        if (empty($allEngines)) {
            return $allEngines;
        }

        $profiles = $this->getActiveProfiles();

        if (empty($profiles)) {
            return $allEngines;
        }

        foreach ($profiles as $profile) {
            if ($this->urlMatchesProfile($requestedURL, $profile)) {
                $filtered = $this->filterEnginesByProfile($allEngines, $profile);
                /** @var array<int, mixed> $filtered */
                $filtered = apply_filters('abj404_resolved_engines', $filtered, $requestedURL, $profile);
                return $filtered;
            }
        }

        // No match — return all engines unchanged.
        return $allEngines;
    }

    /**
     * Determine whether a specific engine class is enabled for a requested URL.
     *
     * This mirrors profile matching + fail-open behavior used by resolve():
     * - no matching profile -> enabled
     * - empty/malformed enabled_engines -> enabled
     * - matching profile with explicit enabled_engines -> enabled only if listed
     *
     * @param string $requestedURL
     * @param string $engineClassName Fully-qualified class name or short suffix.
     * @return bool
     */
    public function isEngineEnabledForUrl(string $requestedURL, string $engineClassName): bool {
        $profiles = $this->getActiveProfiles();

        if (empty($profiles)) {
            return true;
        }

        foreach ($profiles as $profile) {
            if (!$this->urlMatchesProfile($requestedURL, $profile)) {
                continue;
            }

            $enabledLower = $this->decodeEnabledEnginesLower($profile);
            if ($enabledLower === null || empty($enabledLower)) {
                // Fail-open to preserve historical behavior for broken/empty config.
                return true;
            }

            return $this->classMatchesEnabledList($engineClassName, $enabledLower);
        }

        // No profile matched this URL.
        return true;
    }

    /**
     * Load all active profiles ordered by priority ASC (lowest priority number = first).
     *
     * Uses a per-request cache to avoid repeated DB queries.
     *
     * @return array<int, object>
     */
    private function getActiveProfiles(): array {
        $currentBlogId = function_exists('get_current_blog_id') ? (int)get_current_blog_id() : 0;
        if ($this->cachedProfiles !== null && $this->cachedProfilesBlogId === $currentBlogId) {
            return $this->cachedProfiles;
        }

        $this->cachedProfiles = $this->repository()->readActiveProfiles();
        $this->cachedProfilesBlogId = $currentBlogId;
        return $this->cachedProfiles;
    }

    /**
     * Check whether a URL matches a profile's pattern.
     *
     * @param string $url
     * @param object $profile
     * @return bool
     */
    private function urlMatchesProfile(string $url, object $profile): bool {
        $pattern = isset($profile->url_pattern) ? (string)$profile->url_pattern : '';

        if ($pattern === '') {
            return false;
        }

        $isRegex = isset($profile->is_regex) && (int)$profile->is_regex === 1;

        if ($isRegex) {
            // Patterns are stored without PHP delimiters (users write ^/shop/, not #^/shop/#).
            // Auto-wrap with # delimiters unless the pattern already starts with one.
            $commonDelimiters = ['/', '#', '~', '!', '@', '|', '%'];
            if (!in_array(substr($pattern, 0, 1), $commonDelimiters, true)) {
                $pattern = '#' . $pattern . '#';
            }
            // Suppress errors to prevent site breakage from malformed patterns.
            set_error_handler(function (int $errno, string $errstr, string $errfile = '', int $errline = 0): bool { return false; }, E_WARNING);
            $matched = @preg_match($pattern, $url);
            restore_error_handler();
            return $matched === 1;
        }

        // Non-regex: fnmatch-style pattern with * as wildcard, case-insensitive.
        // fnmatch is not available on all Windows PHP builds, so fall back to
        // a manual conversion via the approach used by the rest of the codebase.
        if (function_exists('fnmatch')) {
            return fnmatch($pattern, $url, FNM_CASEFOLD | FNM_PATHNAME) ||
                   fnmatch($pattern, $url, FNM_CASEFOLD);
        }

        // Fallback: convert * wildcard to a regex.
        $regex = '/^' . str_replace(
            ['\\*', '\\?'],
            ['.*', '.'],
            preg_quote($pattern, '/')
        ) . '$/i';
        return (bool)preg_match($regex, $url);
    }

    /**
     * Filter $allEngines to only those listed in the profile's enabled_engines JSON.
     *
     * If enabled_engines is empty or malformed, all engines are returned (fail-open).
     *
     * @param array<int, mixed> $allEngines
     * @param object $profile
     * @return array<int, mixed>
     */
    private function filterEnginesByProfile(array $allEngines, object $profile): array {
        $enabledLower = $this->decodeEnabledEnginesLower($profile);
        if ($enabledLower === null || empty($enabledLower)) {
            // Empty/malformed list — no restriction (fail-open).
            return $allEngines;
        }

        $filtered = array_values(array_filter($allEngines, function ($engine) use ($enabledLower) {
            if (!is_object($engine)) {
                return false;
            }
            return $this->classMatchesEnabledList(get_class($engine), $enabledLower);
        }));

        return $filtered;
    }

    /**
     * Parse enabled_engines JSON and normalize class names to lowercase.
     *
     * @param object $profile
     * @return array<int, string>|null Null when empty/missing/malformed.
     */
    private function decodeEnabledEnginesLower(object $profile): ?array {
        $json = isset($profile->enabled_engines) ? (string)$profile->enabled_engines : '';
        if (trim($json) === '') {
            return null;
        }

        $enabledClassNames = json_decode($json, true);
        if (!is_array($enabledClassNames) || empty($enabledClassNames)) {
            return null;
        }

        $enabledLower = array_values(array_filter(array_map(function ($name) {
            return is_scalar($name) ? strtolower((string)$name) : '';
        }, $enabledClassNames), function ($name) {
            return $name !== '';
        }));

        return empty($enabledLower) ? null : $enabledLower;
    }

    /**
     * Case-insensitive class-name check with suffix matching compatibility.
     *
     * @param string $className
     * @param array<int, string> $enabledLower
     * @return bool
     */
    private function classMatchesEnabledList(string $className, array $enabledLower): bool {
        $classLower = strtolower($className);
        foreach ($enabledLower as $allowed) {
            if ($classLower === $allowed || substr($classLower, -strlen($allowed)) === $allowed) {
                return true;
            }
        }
        return false;
    }

    /**
     * Insert or update a profile row.
     *
     * @param array<string, mixed> $data
     * @return int|false Inserted/updated row ID, or false on failure.
     */
    public function saveProfile(array $data) {
        $result = $this->repository()->insertOrUpdate($data);
        $this->clearCache();
        return $result;
    }

    /**
     * Delete a profile by ID.
     *
     * @param int $id
     * @return bool
     */
    public function deleteProfile(int $id): bool {
        $deleted = $this->repository()->delete($id);
        $this->clearCache();
        return $deleted;
    }

    /**
     * Every profile, active or not, for the admin edit screen.
     *
     * The rows and the ceiling belong to
     * ABJ_404_Solution_EngineProfileRepository; this stays as the published
     * entry point because the admin AJAX handlers reach the profile subsystem
     * through this singleton, which is also the seam their tests inject at.
     * Kept deliberately thin: no SQL, no policy, just the subsystem's front door.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllProfilesForAdmin(): array {
        return $this->repository()->readAllForAdmin();
    }

    /** Whether the last getAllProfilesForAdmin() call filled the repository's ceiling. */
    public function adminProfileListWasTruncated(): bool {
        return $this->repository()->lastAdminReadWasTruncated();
    }

    /**
     * Reset the request-level cache (used in tests).
     *
     * @return void
     */
    public function clearCache(): void {
        $this->cachedProfiles = null;
        $this->cachedProfilesBlogId = null;
    }
}
