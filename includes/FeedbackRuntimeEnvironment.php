<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Collects runtime-environment fields for feedback payloads.
 */
class ABJ_404_Solution_FeedbackRuntimeEnvironment {

    /**
     * @return array<string, int>
     */
    public function resourceLimits(): array {
        return array(
            'php_memory' => function_exists('ini_get') ? self::iniSizeToBytesForPayload((string)ini_get('memory_limit')) : 0,
            'wp_memory'  => defined('WP_MEMORY_LIMIT') ? self::iniSizeToBytesForPayload((string)WP_MEMORY_LIMIT) : 0,
            'php_max_execution_seconds' => function_exists('ini_get') ? max(0, (int)ini_get('max_execution_time')) : 0,
            'php_post_max_size' => function_exists('ini_get') ? self::iniSizeToBytesForPayload((string)ini_get('post_max_size')) : 0,
            'php_upload_max_size' => function_exists('ini_get') ? self::iniSizeToBytesForPayload((string)ini_get('upload_max_filesize')) : 0,
        );
    }

    public function wpMemoryLimitBytes(): int {
        if (!defined('WP_MEMORY_LIMIT')) {
            return 0;
        }
        return self::iniSizeToBytesForPayload((string)WP_MEMORY_LIMIT);
    }

    /**
     * Convert PHP shorthand byte notation to a non-negative byte count for
     * the feedback schema. Malformed values and "no limit" normalize to 0.
     */
    public static function iniSizeToBytesForPayload(string $value): int {
        $str = trim($value);
        if ($str === '') {
            return 0;
        }

        if (preg_match('/^([+-]?\d+)\s*([gmk]?)$/i', $str, $matches) !== 1) {
            return 0;
        }

        $num = (int)$matches[1];
        if ($num <= 0) {
            return 0;
        }

        $unit = strtolower((string)$matches[2]);
        switch ($unit) {
            case 'g':
                return $num * 1024 * 1024 * 1024;
            case 'm':
                return $num * 1024 * 1024;
            case 'k':
                return $num * 1024;
            default:
                return $num;
        }
    }

    /**
     * @return array<string, bool>
     */
    public function loadedExtensionsMap(): array {
        if (!function_exists('get_loaded_extensions')) {
            return array();
        }
        $names = get_loaded_extensions();
        $out = array();
        foreach ($names as $name) {
            if ($name !== '') {
                $out[strtolower($name)] = true;
            }
        }
        return $out;
    }

    /**
     * Detect a development host so reports can be flagged as non-production.
     */
    public function isDevelopmentEnvironment(): bool {
        $home = function_exists('home_url') ? (string)home_url() : '';
        $host = '';
        if (function_exists('wp_parse_url')) {
            $parsed = wp_parse_url($home, PHP_URL_HOST);
            $host = is_string($parsed) ? $parsed : '';
        } else {
            $parsed = parse_url($home, PHP_URL_HOST);
            $host = is_string($parsed) ? $parsed : '';
        }
        if ($host === '') {
            return false;
        }
        if ($host === 'localhost') {
            return true;
        }
        if (preg_match('/\.(test|local|dev|localhost)$/i', $host) === 1) {
            return true;
        }
        if (defined('WP_DEBUG') && WP_DEBUG && strpos((string)$home, 'localhost') !== false) {
            return true;
        }
        return false;
    }

    /**
     * Strip site-identifying noise from the SERVER_SOFTWARE banner.
     */
    public function sanitizeServerSoftware(string $raw): string {
        if ($raw === '') {
            return '';
        }
        $marker = stripos($raw, ' Server at ');
        $clean = $marker === false ? $raw : substr($raw, 0, $marker);
        $clean = trim($clean);
        if (strlen($clean) > 100) {
            $clean = substr($clean, 0, 100);
        }
        return $clean;
    }
}
