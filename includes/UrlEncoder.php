<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Percent-encodes URLs for storage, legacy matching, and cache-key normalization.
 *
 * Responsibilities:
 *  - selectivelyURLEncode: selective percent-encoding of non-Latin1 characters
 *  - encodeUrlForLegacyMatch: rawurlencode then restore URL delimiters
 *  - urlencodeEmojis: percent-encode only emoji characters within a URL
 *  - normalizeURLForCacheKey: canonical key used for cache/transient lookups
 *
 * Extracted from ABJ_404_Solution_Functions per design-audit-2026-06-02
 * M201 (Functions.php grab-bag split, parent task i802). This is a
 * percent-encoding concern, not a generic string utility.
 *
 * Depends on ABJ_404_Solution_MbStringAdapter for ord() (sibling task
 * i825) and ABJ_404_Solution_RegexHelper for regexReplace() (sibling
 * task i826). The constructor accepts the focused adapters directly, or
 * a legacy ABJ_404_Solution_Functions instance for backward compatibility
 * with existing test fixtures - it will extract both adapters from it.
 */
class ABJ_404_Solution_UrlEncoder {

    /** @var ABJ_404_Solution_MbStringAdapter */
    private $mbAdapter;

    /** @var ABJ_404_Solution_RegexHelper */
    private $regexHelper;

    /**
     * @param ABJ_404_Solution_MbStringAdapter|ABJ_404_Solution_Functions $adapter
     * @param ABJ_404_Solution_RegexHelper|null $regexHelper
     */
    public function __construct($adapter, $regexHelper = null) {
        if ($adapter instanceof ABJ_404_Solution_MbStringAdapter) {
            $this->mbAdapter = $adapter;
            if (!($regexHelper instanceof ABJ_404_Solution_RegexHelper)) {
                throw new InvalidArgumentException(
                    'ABJ_404_Solution_UrlEncoder requires a RegexHelper when constructed with an MbStringAdapter; got '
                    . (is_object($regexHelper) ? get_class($regexHelper) : gettype($regexHelper))
                );
            }
            $this->regexHelper = $regexHelper;
        } else if ($adapter instanceof ABJ_404_Solution_Functions) {
            $this->mbAdapter   = $adapter->getMbStringAdapter();
            $this->regexHelper = $adapter->getRegexHelper();
        } else {
            throw new InvalidArgumentException(
                'ABJ_404_Solution_UrlEncoder requires an MbStringAdapter or Functions instance; got '
                . (is_object($adapter) ? get_class($adapter) : gettype($adapter))
            );
        }
    }

    /**
     * This function selectively urlencodes a string. Characters outside of the latin1
     * range (0-255) are urlencoded, while characters inside the range are kept as is.
     * @param string|array<int|string, mixed> $input The string to be selectively urlencoded.
     * @return string|array<int|string, mixed> The urlencoded string or array of strings.
     */
    public function selectivelyURLEncode($input) {
        // Handle array input
        if (is_array($input)) {
            /** @var callable(mixed): mixed $callback */
            $callback = [$this, 'selectivelyURLEncode'];
            return array_map($callback, $input);
        }

        if (!is_string($input)) {
            $input = strval($input);
        }

        // Define replacements for unsafe characters
        $replacements = [
            '<' => '%3C',
            '>' => '%3E',
            '"' => '%22',
            "'" => '%27',
            '`' => '%60',
            '{' => '%7B',
            '}' => '%7D',
            '(' => '%28',
            ')' => '%29',
        ];

        // Perform replacements
        $input = strtr($input, $replacements);

        $encodedString = '';
        // Iterate through each character in the string
        for ($i = 0; $i < strlen($input); $i++) {
            $char = $input[$i];
            $ord = $this->mbAdapter->ord($char);

            // If the character is outside of latin1 range or is not representable
            if ($ord > 255) {
                // Convert to hexadecimal representation
                $encodedString .= urlencode($char);
            } else {
                // Keep the original character if it's in the latin1 range
                $encodedString .= $char;
            }
        }

        return $encodedString;
    }

    /**
     * Encode a URL for legacy matching while preserving URL delimiters.
     *
     * @param string|null $url
     * @return string
     */
    public function encodeUrlForLegacyMatch($url) {
        if ($url === null || $url === '') {
            return '';
        }

        if (!is_string($url)) {
            $url = strval($url);
        }

        $encoded = rawurlencode($url);
        $encoded = str_replace(
            array('%2F', '%3F', '%26', '%3D', '%23', '%3A', '%40'),
            array('/', '?', '&', '=', '#', ':', '@'),
            $encoded
        );

        return $encoded;
    }

    /**
     * Normalize a URL for use as a cache/transient key.
     *
     * This function ensures consistent URL normalization across the codebase:
     * - Strips query strings (removes everything after '?')
     * - Applies esc_url for security and consistency
     *
     * IMPORTANT: All code that computes cache keys or transient keys from URLs
     * should use this function to ensure keys match across different code paths.
     *
     * Used by: SpellChecker, ShortCode, Ajax_SuggestionPolling, PluginLogic
     *
     * @param string $url The URL to normalize
     * @return string The normalized URL (query string stripped, esc_url applied)
     */
    public function normalizeURLForCacheKey($url) {
        $url = abj_service('sanitizer')->normalizeUrlString($url);
        // Strip query string (everything after '?')
        $normalized = $this->regexHelper->regexReplace('\?.*', '', $url) ?? $url;
        // Apply esc_url for security and consistency
        return esc_url($normalized);
    }

    /** Only URL encode emojis from a string.
     * @param string $url
     * @return string
     */
    public function urlencodeEmojis($url) {
        // Get all emojis in the string.
        $matches = [];
        $emojiPattern = '/[\x{1F000}-\x{1F6FF}\x{1F900}-\x{1F9FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F1E6}-\x{1F1FF}]/u';
        $emojis = preg_match_all($emojiPattern, $url, $matches);

        // If there are any emojis in the string, urlencode them.
        if ($emojis > 0) {
            foreach ($matches[0] as $emoji) {
                $url = str_replace($emoji, urlencode($emoji), $url);
            }
        }

        // Return the urlencoded string.
        return $url;
    }
}
