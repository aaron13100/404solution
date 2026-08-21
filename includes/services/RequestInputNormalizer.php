<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalizes request values that arrive from WordPress superglobals.
 */
class ABJ_404_Solution_RequestInputNormalizer {

    /**
     * Safely unslash request data when wp_unslash exists and is callable.
     * Some test environments report wp_unslash as existing but throw when called.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function safeWpUnslash($value) {
        if (!function_exists('wp_unslash')) {
            return $value;
        }

        try {
            return wp_unslash($value);
        } catch (Throwable $e) { // allow-silent-catch: wp_unslash() failure; pass-through preserves the original value which is always usable
            return $value;
        }
    }

    /**
     * Normalize request input to a scalar string to avoid warnings when arrays/objects are passed.
     *
     * @param mixed $value
     * @return string
     */
    public static function normalizeScalar($value): string {
        $value = self::safeWpUnslash($value);
        if (!is_scalar($value)) {
            return '';
        }
        return (string)$value;
    }

    /**
     * One single-line request field, unslashed and then sanitized.
     *
     * This pair (and readTextarea() below) exists so a handler can never get
     * the order wrong. wp_magic_quotes() backslash-escapes every superglobal
     * at boot and sanitize_text_field() does not undo that, so a call site
     * that sanitizes a raw superglobal stores O\'Brien instead of O'Brien --
     * which is exactly how support report 282 and uninstall reports 50 / 57
     * came to hold literal backslashes the reporters never typed.
     *
     * Non-scalar values (an array posted where a string was expected) yield
     * the default rather than a PHP array-to-string warning.
     *
     * @param array<string|int, mixed> $source One of the request superglobals.
     * @param array{name: string, default?: string} $options Named read options.
     * @return string
     */
    public static function readText(array $source, array $options): string {
        return self::readSanitized($source, array(
            'name' => $options['name'],
            'default' => $options['default'] ?? '',
            'sanitizer' => 'sanitize_text_field',
        ));
    }

    /**
     * One multi-line request field, unslashed and then sanitized.
     * Same contract as readText(), but preserves newlines.
     *
     * @param array<string|int, mixed> $source One of the request superglobals.
     * @param array{name: string, default?: string} $options Named read options.
     * @return string
     */
    public static function readTextarea(array $source, array $options): string {
        return self::readSanitized($source, array(
            'name' => $options['name'],
            'default' => $options['default'] ?? '',
            'sanitizer' => 'sanitize_textarea_field',
        ));
    }

    /**
     * Shared body of readText()/readTextarea(): unslash first, sanitize
     * second, never the other way around.
     *
     * @param array<string|int, mixed> $source
     * @param array{name: string, default: string, sanitizer: string} $options
     * @return string
     */
    private static function readSanitized(array $source, array $options): string {
        $name = $options['name'];
        $default = $options['default'];
        $sanitizer = $options['sanitizer'];
        if (!array_key_exists($name, $source) || !is_scalar($source[$name])) {
            return $default;
        }

        $unslashed = self::normalizeScalar($source[$name]);
        if (!function_exists($sanitizer)) {
            // Only reachable where WordPress core itself is unavailable
            // (sanitize_text_field exists since 2.9, sanitize_textarea_field
            // since 4.7). Unslashing still happened, so returning the value
            // degrades rather than silently dropping the user's input.
            return $unslashed;
        }
        $clean = $sanitizer($unslashed);
        return is_string($clean) ? $clean : $default;
    }

    /**
     * Decode a size-bounded JSON array without truncating valid input into an
     * invalid document or silently converting parse failure to an empty array.
     *
     * @param array{raw: string, max_bytes: int, unavailable_label: string} $options
     * @return array{status: 'available', observations: array<mixed>}|array{
     *   status: 'unavailable',
     *   unavailable: array{code: string, message: string, payloadBytes: int, maxBytes: int}
     * }
     */
    public static function decodeBoundedJsonArray(array $options): array {
        $raw = $options['raw'];
        $maxBytes = max(1, $options['max_bytes']);
        $label = $options['unavailable_label'];
        $payloadBytes = strlen($raw);

        if ($payloadBytes === 0) {
            $code = 'payload_missing';
            $message = $label . ' payload is missing.';
        } elseif ($payloadBytes > $maxBytes) {
            $code = 'payload_truncated';
            $message = $label . ' payload truncated at ' . $maxBytes . ' bytes.';
        } else {
            $decoded = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $code = 'invalid_json';
                $message = $label . ' JSON is invalid (' . json_last_error_msg() . ').';
            } elseif (!is_array($decoded)) {
                $code = 'invalid_shape';
                $message = $label . ' JSON must decode to an array.';
            } else {
                return array('status' => 'available', 'observations' => $decoded);
            }
        }

        return array(
            'status' => 'unavailable',
            'unavailable' => array(
                'code' => $code,
                'message' => $message,
                'payloadBytes' => $payloadBytes,
                'maxBytes' => $maxBytes,
            ),
        );
    }

    /**
     * Normalize and sanitize feedback issue selections from request data.
     *
     * @param mixed $issuesRaw
     * @return array<int, string>
     */
    public static function sanitizeFeedbackIssues($issuesRaw): array {
        $issuesRaw = self::safeWpUnslash($issuesRaw);
        if (!is_array($issuesRaw)) {
            $issuesRaw = array($issuesRaw);
        }

        $issues = array();
        foreach ($issuesRaw as $issue) {
            if (!is_scalar($issue)) {
                continue;
            }
            $clean = sanitize_text_field((string)$issue);
            if ($clean !== '') {
                $issues[] = $clean;
            }
        }
        return $issues;
    }

    /**
     * Read one request parameter, unslashed and sanitized.
     *
     * wp_magic_quotes() slash-escapes every superglobal at boot and
     * sanitize_text_field() does not undo it, so an unslashed read hands back
     * {\"v\":1,...} for JSON and O\'Brien for a search. Hence core's
     * sanitize_text_field( wp_unslash( ... ) ) order.
     *
     * Called from {@see ABJ_404_Solution_Functions::getPostOrGetSanitize()},
     * which stays the public entry point so DI-based test doubles can
     * substitute request values without touching real superglobals.
     *
     * @param string $name The key to retrieve the value for.
     * @param string|null $defaultValue The value to return if the value is not set.
     * @return string The sanitized value.
     */
    public static function getPostOrGetSanitize($name, $defaultValue = null) {
        $returnValue = isset($_GET[$name]) ? $_GET[$name] : (isset($_POST[$name]) ? $_POST[$name] : null);
        if ($returnValue === null && $name === 'action') {
            $returnValue = isset($_GET['abj404action']) ? $_GET['abj404action'] : (isset($_POST['abj404action']) ? $_POST['abj404action'] : null);
        }
        $returnValue = self::applyBulkActionFallback($name, $returnValue);
        if ($returnValue !== null) {
            $returnValue = self::safeWpUnslash($returnValue);
            if (is_array($returnValue)) {
                $returnValue = array_map('sanitize_text_field', $returnValue);
            } else {
                $returnValue = sanitize_text_field($returnValue);
            }
        }
        $finalValue = $returnValue ?? $defaultValue;
        return is_string($finalValue) ? $finalValue : (is_string($defaultValue) ? $defaultValue : '');
    }

    /**
     * Native WP_List_Table renders bulk-action <select>s at top and bottom of
     * the table using name="action" and name="action2". The 404 Solution
     * wrappers mirror this with abj404action (top) and abj404action2 (bottom).
     * When the top select is empty (default placeholder), fall back to the
     * bottom select's value so Apply submits from either utility row.
     *
     * @param string $name
     * @param mixed $current
     * @return mixed
     */
    private static function applyBulkActionFallback($name, $current) {
        if ($name !== 'abj404action') {
            return $current;
        }
        if ($current !== null && $current !== '' && $current !== '-1') {
            return $current;
        }
        $alt = isset($_GET['abj404action2']) ? $_GET['abj404action2'] : (isset($_POST['abj404action2']) ? $_POST['abj404action2'] : null);
        if ($alt === null || $alt === '' || $alt === '-1') {
            return $current;
        }
        return $alt;
    }

    /**
     * @param string $name The key to retrieve the value for.
     * @param string|null $defaultValue The value to return if the value is not set.
     * @return string|array<string>|null The normalized URL value.
     */
    public static function getPostOrGetSanitizeUrl($name, $defaultValue = null) {
        $returnValue = isset($_GET[$name]) ? $_GET[$name] : (isset($_POST[$name]) ? $_POST[$name] : null);
        if ($returnValue === null) {
            return $defaultValue;
        }

        $sanitizer = abj_service('sanitizer');
        if (is_array($returnValue)) {
            return array_map(static function($value) use ($sanitizer) {
                return $sanitizer->normalizeUrlString(
                    ABJ_404_Solution_RequestInputNormalizer::safeWpUnslash($value));
            }, $returnValue);
        }
        return $sanitizer->normalizeUrlString(
            ABJ_404_Solution_RequestInputNormalizer::safeWpUnslash($returnValue));
    }
}
