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

    /** @var array<int, object>|null Cached profile rows for current request */
    private $cachedProfiles = null;

    /** @return self */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
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
        if ($this->cachedProfiles !== null) {
            return $this->cachedProfiles;
        }

        global $wpdb;

        $table = $this->getTableName();

        if (!$this->tableExists($table)) {
            $this->cachedProfiles = [];
            return $this->cachedProfiles;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT `id`, `name`, `url_pattern`, `is_regex`, `enabled_engines`, `priority`
                 FROM `{$table}`
                 WHERE `status` = 1
                 ORDER BY `priority` ASC, `id` ASC
                 LIMIT %d",
                200
            )
        );

        $this->cachedProfiles = is_array($rows) ? $rows : [];
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
     * @return string The fully-prefixed table name.
     */
    private function getTableName(): string {
        global $wpdb;
        return strtolower($wpdb->prefix) . 'abj404_engine_profiles';
    }

    /**
     * Check if the engine profiles table exists in the current database.
     *
     * @param string $table
     * @return bool
     */
    private function tableExists(string $table): bool {
        global $wpdb;
        $result = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table)
        );
        return $result === $table;
    }

    /**
     * Insert or update a profile row.
     *
     * @param array<string, mixed> $data
     * @return int|false Inserted/updated row ID, or false on failure.
     */
    public function saveProfile(array $data) {
        global $wpdb;
        $table = $this->getTableName();

        $id = isset($data['id']) && is_numeric($data['id']) ? (int)$data['id'] : 0;

        $name            = isset($data['name'])            ? sanitize_text_field(is_string($data['name']) ? $data['name'] : '')                                 : '';
        $urlPattern      = isset($data['url_pattern'])     ? wp_unslash(is_string($data['url_pattern']) ? $data['url_pattern'] : '')                           : '';
        $isRegex         = isset($data['is_regex'])        ? (int)(bool)$data['is_regex']                                                                      : 0;
        $enabledEngines  = isset($data['enabled_engines']) ? (is_string($data['enabled_engines']) ? $data['enabled_engines'] : '[]')                           : '[]';
        $priority        = isset($data['priority'])        ? (is_numeric($data['priority']) ? (int)$data['priority'] : 0)                                      : 0;
        $status          = isset($data['status'])          ? (int)(bool)$data['status']                                                                        : 1;

        // Validate enabled_engines is valid JSON array.
        $decoded = json_decode($enabledEngines, true);
        if (!is_array($decoded)) {
            $enabledEngines = '[]';
        }

        $row = array(
            'name'            => $name,
            'url_pattern'     => $urlPattern,
            'is_regex'        => $isRegex,
            'enabled_engines' => $enabledEngines,
            'priority'        => $priority,
            'status'          => $status,
        );
        $formats = array('%s', '%s', '%d', '%s', '%d', '%d');

        if ($id > 0) {
            $result = $wpdb->update($table, $row, array('id' => $id), $formats, array('%d'));
            $this->cachedProfiles = null;
            return ($result !== false) ? $id : false;
        } else {
            $result = $wpdb->insert($table, $row, $formats);
            $this->cachedProfiles = null;
            return ($result !== false) ? (int)$wpdb->insert_id : false;
        }
    }

    /**
     * Delete a profile by ID.
     *
     * @param int $id
     * @return bool
     */
    public function deleteProfile(int $id): bool {
        global $wpdb;
        $table = $this->getTableName();
        $result = $wpdb->delete($table, array('id' => $id), array('%d'));
        $this->cachedProfiles = null;
        return $result !== false;
    }

    /**
     * Return all profiles (including inactive) for admin display.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllProfilesForAdmin(): array {
        global $wpdb;
        $table = $this->getTableName();

        if (!$this->tableExists($table)) {
            return [];
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            "SELECT `id`, `name`, `url_pattern`, `is_regex`, `enabled_engines`, `priority`, `status`
             FROM `{$table}`
             ORDER BY `priority` ASC, `id` ASC",
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Reset the request-level cache (used in tests).
     *
     * @return void
     */
    public function clearCache(): void {
        $this->cachedProfiles = null;
    }
}
