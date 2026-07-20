<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Masks the credential-bearing values that a request carries, in text that
 * names them: Authorization and API-key headers, cookie headers and
 * $_COOKIE assignments, sensitive form / JSON body fields, and WordPress
 * nonces.
 *
 * Every pattern here is anchored on a literal field name, so the value to
 * mask is never in doubt and the replacement is a constant. That is the whole
 * difference from ABJ_404_Solution_PiiRedactor, which has to RECOGNIZE
 * unlabeled shapes (an address, an IP, a path) in free-form text and has
 * repeatedly over-matched ordinary diagnostics while doing it. Keeping the
 * two apart keeps the risky half small and lets a caller that only has a
 * request dump to scrub run this without the shape-recognition passes.
 *
 * Ordering note: run this BEFORE the shape-recognition passes. A masked
 * value is inert to them ('[REDACTED]' and 'nonce-a1b2c3d4' match nothing
 * downstream), whereas a header value left in the clear can be misread as
 * an address or a token.
 */
final class ABJ_404_Solution_RequestCredentialRedactor {

    /**
     * Field names whose value is a credential wherever it appears -- as a
     * query/form pair, as a PHP superglobal assignment, or as a JSON member.
     */
    const SENSITIVE_FIELD_NAMES = 'password|passwd|pwd|secret|credit_card|card_number|cvv|ssn'
        . '|api_key|private_key|access_token|refresh_token';

    /**
     * @param string $text
     * @return string the text with every labeled credential value replaced
     */
    public static function redact(string $text): string {
        $text = self::redactAuthorizationHeaders($text);
        $text = self::redactCookieValues($text);
        $text = self::redactSensitiveFormFields($text);

        return self::redactNonces($text);
    }

    /** @param string $text @return string */
    private static function redactAuthorizationHeaders(string $text): string {
        $text = preg_replace(
            '/\b(Authorization:\s*)(Bearer|Basic|Digest|Token)\s+\S+/i',
            '$1$2 [REDACTED]',
            $text
        ) ?? $text;

        $text = preg_replace(
            '/\b(X-API-Key|X-Auth-Token|X-Access-Token):\s*\S+/i',
            '$1: [REDACTED]',
            $text
        ) ?? $text;

        return $text;
    }

    /** @param string $text @return string */
    private static function redactCookieValues(string $text): string {
        $text = preg_replace(
            '/\b(Cookie|Set-Cookie):\s*\S[^\r\n]*/i',
            '$1: [REDACTED]',
            $text
        ) ?? $text;

        $text = preg_replace(
            '/(\$_COOKIE\s*\[\s*[\'"][^\'"]*[\'"]\s*\])\s*=\s*[\'"][^\'"]*[\'"]/i',
            '$1 = \'[REDACTED]\'',
            $text
        ) ?? $text;

        return $text;
    }

    /** @param string $text @return string */
    private static function redactSensitiveFormFields(string $text): string {
        $sensitiveKeys = self::SENSITIVE_FIELD_NAMES;

        $text = preg_replace(
            '/\b(' . $sensitiveKeys . ')\s*=\s*(?:([\'"])[^\'"]*\2|\S+)/i',
            '$1=[REDACTED]',
            $text
        ) ?? $text;

        $text = preg_replace(
            '/(\$_(?:POST|GET|REQUEST)\s*\[\s*[\'"](?:' . $sensitiveKeys . ')[\'"]\s*\])\s*(?:=\s*[\'"][^\'"]*[\'"])?/i',
            '$1=[REDACTED]',
            $text
        ) ?? $text;

        $text = preg_replace(
            '/"(' . $sensitiveKeys . ')"\s*:\s*"[^"]*"/i',
            '"$1":"[REDACTED]"',
            $text
        ) ?? $text;

        return $text;
    }

    /**
     * @param string $text @return string
     *
     * A nonce is hashed rather than blanked so that the same nonce can be
     * recognized across log lines (it is the key evidence in a "nonce
     * mismatch" report) without the value itself being reusable.
     */
    private static function redactNonces(string $text): string {
        return preg_replace_callback(
            '/_wpnonce=([A-Za-z0-9]+)/',
            function ($matches) {
                return '_wpnonce=nonce-' . substr(md5($matches[1]), 0, 8);
            },
            $text
        ) ?? $text;
    }
}
