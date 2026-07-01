<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Centralized PII redaction layer for all outgoing logs and reports.
 *
 * Every string that leaves the plugin (debug file, error_log, HTTP report,
 * email fallback, admin-screen excerpts) passes through redact() before
 * reaching its destination. The class owns the regex catalog and masking
 * helpers; callers never build their own PII patterns.
 *
 * Configurable via $options passed to redact():
 *   'redact_ips' => bool  (default true, controls IP address hashing)
 */
class ABJ_404_Solution_PiiRedactor {

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /**
     * @param ABJ_404_Solution_Functions $functions
     */
    public function __construct($functions) {
        $this->f = $functions;
    }

    /**
     * Redact PII from a text string.
     *
     * @param string $text The text to redact
     * @param array<string, mixed> $options Optional configuration overrides
     * @return string Redacted text
     */
    public function redact($text, $options = array()) {
        $redactIps = !isset($options['redact_ips']) || $options['redact_ips'];

        $text = $this->stripUrlQueryStrings($text);
        $text = $this->stripPathQueryStrings($text);
        $text = $this->redactAuthorizationHeaders($text);
        $text = $this->redactCookieValues($text);
        $text = $this->redactSensitiveFormFields($text);
        $text = $this->redactEmails($text);

        if ($redactIps) {
            $text = $this->redactIpv4($text);
            $text = $this->redactIpv6($text);
        }

        $text = $this->redactUsernames($text);
        $text = $this->redactDisplayNames($text);
        $text = $this->redactAbsolutePaths($text);
        $text = $this->redactDatabaseIdentifiers($text);
        $text = $this->redactLongTokens($text);
        $text = $this->redactNonces($text);

        return $text;
    }

    // =========================================================================
    // URL / query-string stripping
    // =========================================================================

    /**
     * @param string $text @return string
     *
     * The query-string match excludes closing quote/bracket characters in
     * addition to whitespace: a URL quoted in log text (e.g. `Captured 404
     * for "/page?utm_source=x" creating a record.`) was matching greedily
     * past the query string's end and swallowing the closing quote too,
     * corrupting the rest of the sentence.
     */
    private function stripUrlQueryStrings(string $text): string {
        return preg_replace('/(https?:\/\/[^\s?]+)\?[^\s"\'\)\]\}]*/', '$1', $text) ?? $text;
    }

    /** @param string $text @return string */
    private function stripPathQueryStrings(string $text): string {
        return preg_replace('/(?<![A-Za-z0-9:@])(\/[^\s?#]*)\?[^\s"\'\)\]\}]*/', '$1', $text) ?? $text;
    }

    // =========================================================================
    // Authorization headers
    // =========================================================================

    /** @param string $text @return string */
    private function redactAuthorizationHeaders(string $text): string {
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

    // =========================================================================
    // Cookie values
    // =========================================================================

    /** @param string $text @return string */
    private function redactCookieValues(string $text): string {
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

    // =========================================================================
    // Sensitive form / request-body fields
    // =========================================================================

    /** @param string $text @return string */
    private function redactSensitiveFormFields(string $text): string {
        $sensitiveKeys = 'password|passwd|pwd|secret|credit_card|card_number|cvv|ssn|api_key|private_key|access_token|refresh_token';

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

    // =========================================================================
    // Email addresses
    // =========================================================================

    /** @param string $text @return string */
    private function redactEmails(string $text): string {
        return preg_replace_callback(
            '/\S+@\S+/',
            function ($matches) {
                return $this->maskEmailAdaptive($matches[0]);
            },
            $text
        ) ?? $text;
    }

    // =========================================================================
    // IP addresses
    // =========================================================================

    /** @param string $text @return string */
    private function redactIpv4(string $text): string {
        return preg_replace_callback(
            '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
            function ($matches) {
                return $this->f->md5lastOctet($matches[0]);
            },
            $text
        ) ?? $text;
    }

    /** @param string $text @return string */
    private function redactIpv6(string $text): string {
        return preg_replace_callback(
            '/(?<![0-9A-Fa-f:])(?:(?:[0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}|(?:[0-9a-fA-F]{1,4}:){1,7}:|(?:[0-9a-fA-F]{1,4}:){1,6}:[0-9a-fA-F]{1,4}|(?:[0-9a-fA-F]{1,4}:){1,5}(?::[0-9a-fA-F]{1,4}){1,2}|(?:[0-9a-fA-F]{1,4}:){1,4}(?::[0-9a-fA-F]{1,4}){1,3}|(?:[0-9a-fA-F]{1,4}:){1,3}(?::[0-9a-fA-F]{1,4}){1,4}|(?:[0-9a-fA-F]{1,4}:){1,2}(?::[0-9a-fA-F]{1,4}){1,5}|[0-9a-fA-F]{1,4}:(?::[0-9a-fA-F]{1,4}){1,6}|:(?:(?::[0-9a-fA-F]{1,4}){1,7}|:))(?![0-9A-Fa-f:])/',
            function ($matches) {
                return $this->f->md5lastOctet($matches[0]);
            },
            $text
        ) ?? $text;
    }

    // =========================================================================
    // Usernames and display names
    // =========================================================================

    /** @param string $text @return string */
    private function redactUsernames(string $text): string {
        return preg_replace_callback(
            '/\b(current\s+)?user(name)?:\s*(\S+)/i',
            function ($matches) {
                $prefix = $matches[1] . 'user' . $matches[2] . ': ';
                return $prefix . $this->maskTextAdaptive($matches[3]);
            },
            $text
        ) ?? $text;
    }

    /** @param string $text @return string */
    private function redactDisplayNames(string $text): string {
        return preg_replace_callback(
            '/\bdisplay\s+name:\s*([^\n,]+)/i',
            function ($matches) {
                return 'display name: ' . $this->maskTextAdaptive(trim($matches[1]));
            },
            $text
        ) ?? $text;
    }

    // =========================================================================
    // Absolute file paths
    // =========================================================================

    /** @param string $text @return string */
    private function redactAbsolutePaths(string $text): string {
        $wpMarkers = 'wp-content|wp-admin|wp-includes|wp-login\\.php|wp-config\\.php|wp-cron\\.php|wp-blog-header\\.php';

        $text = preg_replace(
            '/(^|[\s\(])(\/[^\s\(]+?)\/(' . $wpMarkers . ')\b/i',
            '$1/$3',
            $text
        ) ?? $text;

        $text = preg_replace(
            '/\b[a-z]:\\\\[^\s]+\\\\(' . $wpMarkers . ')\b/i',
            '\\\\$1',
            $text
        ) ?? $text;

        return $text;
    }

    // =========================================================================
    // Database identifiers (name + prefix)
    // =========================================================================

    /**
     * @param string $text @return string
     *
     * Both lookbehinds exclude a preceding '/' (in addition to identifier
     * characters) so a DB name or table prefix that happens to also be a
     * substring of a file path is not redacted -- e.g. this plugin's own
     * dev environment sets DB_NAME to '404-solution', identical to its
     * slug, so without the '/' exclusion "/plugins/404-solution/
     * 404-solution.php" was redacted to ".../dbname.php", destroying the
     * actual filename in stack traces and log lines.
     */
    private function redactDatabaseIdentifiers(string $text): string {
        $dbname = $this->getActualDatabaseNameForRedaction();
        if ($dbname !== '' && strlen($dbname) >= 3 && $dbname !== 'dbname') {
            $text = preg_replace(
                '/(?<![A-Za-z0-9_\/-])' . preg_quote($dbname, '/') . '(?=[.`])/',
                'dbname',
                $text
            ) ?? $text;
        }

        $prefix = $this->getActualPrefixForRedaction();
        if ($prefix !== '' && strlen($prefix) >= 3 && $prefix !== 'wp_') {
            $text = preg_replace(
                '/(?<![A-Za-z0-9_\/-])' . preg_quote($prefix, '/') . '(?=[A-Za-z])/',
                'wp_',
                $text
            ) ?? $text;
        }

        return $text;
    }

    // =========================================================================
    // Tokens and nonces
    // =========================================================================

    /** @param string $text @return string */
    private function redactLongTokens(string $text): string {
        return preg_replace_callback(
            '/\b([A-Za-z0-9_-]{40,})\b/',
            function ($matches) {
                if (self::looksLikeOwnIdentifier($matches[1])) {
                    return $matches[1];
                }
                return 'token-' . substr(md5($matches[1]), 0, 8);
            },
            $text
        ) ?? $text;
    }

    /**
     * This plugin uses two long-running naming conventions that routinely
     * exceed the 40-char long-token threshold above: PascalCase classes
     * (ABJ_404_Solution_ + a descriptive suffix, e.g. fatal-error messages
     * like "Class ABJ_404_Solution_RedirectsDenormMaintenanceService not
     * found") and lowercase option/transient/filter/hook names (abj404_ +
     * a descriptive suffix, e.g. "Option
     * abj404_error_handler_allow_admin_fatal_detection_in_cli was not
     * found"). Both were being redacted into useless 'token-XXXXXXXX'
     * noise. Real secrets never coincidentally start with either exact
     * literal prefix, so exempting them does not weaken the redaction.
     *
     * @param string $token
     * @return bool
     */
    private static function looksLikeOwnIdentifier(string $token): bool {
        return strpos($token, 'ABJ_404_Solution_') === 0 || strpos($token, 'abj404_') === 0;
    }

    /** @param string $text @return string */
    private function redactNonces(string $text): string {
        return preg_replace_callback(
            '/_wpnonce=([A-Za-z0-9]+)/',
            function ($matches) {
                return '_wpnonce=nonce-' . substr(md5($matches[1]), 0, 8);
            },
            $text
        ) ?? $text;
    }

    // =========================================================================
    // Masking helpers
    // =========================================================================

    /**
     * @param string $email
     * @return string
     */
    public function maskEmailAdaptive($email) {
        if (empty($email) || strpos($email, '@') === false) {
            return $email;
        }

        $parts = explode('@', $email);
        if (count($parts) != 2) {
            return $this->maskTextAdaptive($email);
        }

        list($username, $fullDomain) = $parts;

        $domainParts = explode('.', $fullDomain);
        if (count($domainParts) > 1) {
            if (in_array(end($domainParts), array('uk', 'au', 'nz', 'za'))) {
                array_pop($domainParts);
                array_pop($domainParts);
            } else {
                array_pop($domainParts);
            }
        }
        $domain = implode('.', $domainParts);

        $usernameLen = strlen($username);
        if ($usernameLen <= 4) {
            $usernameVisible = 1;
        } elseif ($usernameLen <= 9) {
            $usernameVisible = 2;
        } else {
            $usernameVisible = 3;
        }

        $domainLen = strlen($domain);
        $domainVisible = max(1, (int)ceil($domainLen * 0.3));

        $maskedUsername = substr($username, 0, $usernameVisible) . '***';
        $maskedDomain = empty($domain) ? '' : substr($domain, 0, $domainVisible) . '***';

        if (defined('AUTH_SALT')) {
            $hash = substr(md5(AUTH_SALT . $email), 0, 4);
        } else {
            $hash = substr(md5($email), 0, 4);
        }

        if (!empty($maskedDomain)) {
            return $maskedUsername . '@' . $maskedDomain . '-' . $hash;
        }
        return $maskedUsername . '@-' . $hash;
    }

    /**
     * @param string $text
     * @return string
     */
    public function maskTextAdaptive($text) {
        if (empty($text)) {
            return $text;
        }

        $text = trim($text);
        $textLen = strlen($text);

        if ($textLen <= 4) {
            $visible = 1;
        } elseif ($textLen <= 9) {
            $visible = 2;
        } else {
            $visible = 3;
        }

        $masked = substr($text, 0, $visible) . '***';

        if (defined('AUTH_SALT')) {
            $hash = substr(md5(AUTH_SALT . $text), 0, 4);
        } else {
            $hash = substr(md5($text), 0, 4);
        }

        return $masked . '-' . $hash;
    }

    // =========================================================================
    // Database context helpers
    // =========================================================================

    /** @return string */
    private function getActualPrefixForRedaction() {
        global $wpdb;
        if (isset($wpdb) && is_object($wpdb) && isset($wpdb->prefix) && is_string($wpdb->prefix)) {
            return $wpdb->prefix;
        }
        return '';
    }

    /** @return string */
    private function getActualDatabaseNameForRedaction() {
        global $wpdb;
        if (isset($wpdb) && is_object($wpdb) && isset($wpdb->dbname) && is_string($wpdb->dbname)) {
            return $wpdb->dbname;
        }
        if (defined('DB_NAME')) {
            $name = constant('DB_NAME');
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }
        return '';
    }
}
