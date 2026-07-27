<?php


if (!defined('ABSPATH')) {
    exit;
}

// WordPress keeps the installed plugin's autoloader alive while replacing the
// plugin directory. Its cached classmap therefore cannot know about helper
// classes first introduced by the incoming release. PiiRedactor is reachable
// during the updater's uploaded-attachment cleanup, so load its same-directory
// collaborators by stable path before declaring the host class. This keeps the
// in-flight old-classmap/new-code request from fatalling; the ordinary
// classmap entries remain available for callers that use a helper directly.
require_once __DIR__ . '/RequestCredentialRedactor.php';
require_once __DIR__ . '/SensitiveValueMask.php';
require_once __DIR__ . '/OpaqueTokenClassifier.php';

/**
 * Centralized PII redaction layer for all outgoing logs and reports.
 *
 * Every string that leaves the plugin (debug file, error_log, HTTP report,
 * email fallback, admin-screen excerpts) passes through redact() before
 * reaching its destination. This class owns the patterns that must RECOGNIZE
 * an unlabeled shape in free-form text -- an address, an IP, a path, a
 * database identifier, an opaque token -- and the order they run in. Callers
 * never build their own PII patterns.
 *
 * Two collaborators own the parts that are not shape recognition:
 * ABJ_404_Solution_RequestCredentialRedactor masks the values that request
 * text labels by name (headers, cookies, form fields, nonces), and
 * ABJ_404_Solution_SensitiveValueMask decides what a masked value looks like.
 *
 * Configurable via $options passed to redact():
 *   'redact_ips' => bool  (default true, controls IP address hashing)
 */
class ABJ_404_Solution_PiiRedactor {

    /**
     * Final filename extensions that are NOT delegated top-level domains, so a
     * token ending in one cannot be a deliverable email address no matter how
     * email-shaped it looks. Checked against the IANA root zone
     * (data.iana.org/TLD/tlds-alpha-by-domain.txt, version 2026062302).
     *
     * Membership here is the one thing standing between a real address and the
     * log file, so the list may only ever grow with extensions that are absent
     * from the root zone. Several obvious candidates are deliberately missing
     * because they ARE real gTLDs: .zip, .mov, .app, .dev, .page, .link, .map,
     * and .md. Entries must be lowercase and letters-only; the email pattern
     * only matches a letters-only final label, so anything else is unreachable
     * (a '@2x.woff2' URL never matches the pattern in the first place).
     *
     * @var array<int, string>
     */
    const NON_TLD_FILE_EXTENSIONS = array(
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'bmp', 'ico', 'svg', 'tif', 'tiff', 'heic',
        'css', 'js', 'mjs', 'scss', 'less', 'json', 'xml', 'txt', 'html', 'htm', 'php',
        'woff', 'ttf', 'otf', 'eot',
        'pdf', 'csv', 'webm', 'ogg', 'wav',
    );

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
        $text = ABJ_404_Solution_RequestCredentialRedactor::redact($text);
        $text = $this->redactEmails($text);

        if ($redactIps) {
            $text = $this->redactIpv4($text);
            $text = $this->redactIpv6($text);
        }

        $text = $this->redactUsernames($text);
        $text = $this->redactDatabaseAccountNames($text);
        $text = $this->redactDisplayNames($text);
        $text = $this->redactAbsolutePaths($text);
        $text = $this->redactDatabaseIdentifiers($text);
        $text = $this->redactLongTokens($text);

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
    // Email addresses
    // =========================================================================

    /**
     * @param string $text @return string
     *
     * The pattern must be email-SHAPED, not merely '\S+@\S+'. The old form
     * matched from the first non-space character to the last, so the most
     * common '@' in a WordPress URL -- a retina asset such as
     * '/wp-content/uploads/logo@2x.png' -- was rewritten to '/wp***@2***-hash'
     * in the debug log and the crash report, destroying the very URL a 404
     * plugin exists to diagnose.
     *
     * The grammar is WordPress's own: the local-part class and the per-label
     * domain class are the two character classes is_email() applies, and the
     * requirement that the domain carry at least two labels is is_email()'s
     * `2 > count( $subs )` rule. Anything WordPress would refuse to call an
     * email address is therefore not treated as one here either. Only '/' is
     * withheld from is_email()'s local-part class, because it is a path
     * separator in the text this class processes; '@' is added so that a
     * multi-'@' token is masked whole rather than leaving its head in the
     * clear.
     *
     * Being email-shaped is necessary but not sufficient: '2x.png' is a
     * perfectly good domain shape. The final label therefore also has to be a
     * plausible TLD, which is what NON_TLD_FILE_EXTENSIONS decides. Narrowing
     * must never cost a redaction that used to happen, so the domain still
     * accepts a bare IPv4 literal ('root@192.168.0.5') and a trailing sentence
     * period is still allowed to follow the address.
     */
    private function redactEmails(string $text): string {
        $local = '[A-Za-z0-9!#$%&\'*+=?^_`{|}~.@-]+';
        $fqdn = '(?:[A-Za-z0-9-]+\.)+[A-Za-z]{2,63}';
        $ipv4Literal = '(?:\d{1,3}\.){3}\d{1,3}';

        return preg_replace_callback(
            '/' . $local . '@(?:' . $fqdn . '|' . $ipv4Literal . ')(?![A-Za-z0-9-])/',
            function ($matches) {
                if (self::endsWithNonTldFileExtension($matches[0])) {
                    return $matches[0];
                }
                return ABJ_404_Solution_SensitiveValueMask::maskEmail($matches[0]);
            },
            $text
        ) ?? $text;
    }

    /**
     * @param string $token an email-shaped token
     * @return bool true when the token's final label is a file extension that
     *   no registry has delegated, which makes the token a filename rather than
     *   an address.
     */
    private static function endsWithNonTldFileExtension(string $token): bool {
        $lastDot = strrpos($token, '.');
        if ($lastDot === false) {
            return false;
        }

        $extension = strtolower(substr($token, $lastDot + 1));

        return in_array($extension, self::NON_TLD_FILE_EXTENSIONS, true);
    }

    // =========================================================================
    // IP addresses
    // =========================================================================

    /**
     * @param string $text @return string
     *
     * Two constraints keep this from matching ordinary dotted runs that are
     * not network data. Each octet is range-checked (0-255), so a decimal
     * sequence like "1024.768.900.640" is not an address; and the match may
     * not be adjacent to another dotted-number segment, so "1.2.3.4.5" is
     * left whole instead of having its fourth segment hashed. The trailing
     * guard deliberately still allows a plain sentence period, so
     * "blocked 203.0.113.42." is redacted normally.
     *
     * The octet accepts leading zeros ("010.000.000.001") because some
     * proxies and legacy access logs zero-pad. Narrowing the range must
     * never cost a redaction that used to happen: over-redaction is
     * fail-safe here, under-redaction leaks an address.
     */
    private function redactIpv4(string $text): string {
        $octet = '(?:25[0-5]|2[0-4]\d|[01]?\d?\d)';

        return preg_replace_callback(
            '/(?<![\w.])(?:' . $octet . '\.){3}' . $octet . '(?!\w)(?!\.\d)/',
            function ($matches) {
                return $this->f->md5lastOctet($matches[0]);
            },
            $text
        ) ?? $text;
    }

    /**
     * @param string $text @return string
     *
     * The alternation must not accept a bare '::', and the boundary guards
     * must exclude ordinary identifier characters rather than only hex digits
     * and colons. Without both, every PHP static-call frame in a stack trace
     * ('ABJ_404_Solution_ErrorHandler::processFatalError') matched as an IPv6
     * address and had its method name replaced with a hash on the way into
     * the debug log and the crash report -- destroying exactly the
     * identifiers a crash needs to be triaged. A bare '::' is the
     * unspecified address and identifies nobody, so declining to redact it
     * costs no privacy; every form that names a host is still matched,
     * including the loopback '::1'.
     */
    private function redactIpv6(string $text): string {
        $group = '[0-9a-fA-F]{1,4}';

        $address =
            '(?:' . $group . ':){7}' . $group
            . '|(?:' . $group . ':){1,7}:'
            . '|(?:' . $group . ':){1,6}:' . $group
            . '|(?:' . $group . ':){1,5}(?::' . $group . '){1,2}'
            . '|(?:' . $group . ':){1,4}(?::' . $group . '){1,3}'
            . '|(?:' . $group . ':){1,3}(?::' . $group . '){1,4}'
            . '|(?:' . $group . ':){1,2}(?::' . $group . '){1,5}'
            . '|' . $group . ':(?::' . $group . '){1,6}'
            . '|:(?::' . $group . '){1,7}';

        return preg_replace_callback(
            '/(?<![0-9A-Za-z_:])(?:' . $address . ')(?![0-9A-Za-z_:])/',
            function ($matches) {
                return $this->f->md5lastOctet($matches[0]);
            },
            $text
        ) ?? $text;
    }

    // =========================================================================
    // Usernames, database account names, and display names
    // =========================================================================

    /** @param string $text @return string */
    private function redactUsernames(string $text): string {
        return preg_replace_callback(
            '/\b(current\s+)?user(name)?:\s*(\S+)/i',
            function ($matches) {
                $prefix = $matches[1] . 'user' . $matches[2] . ': ';
                return $prefix . ABJ_404_Solution_SensitiveValueMask::maskText($matches[3]);
            },
            $text
        ) ?? $text;
    }

    /**
     * @param string $text @return string
     *
     * MySQL names an account as 'user'@'host' and quotes both halves in its
     * "Access denied for user 'wpuser'@'localhost'" error, which lands in the
     * debug log and the crash report verbatim. The old email pattern masked
     * that form only by accident (it matched any two non-space runs joined by
     * '@'), and narrowing the email pattern to WordPress's is_email() grammar
     * would silently drop the masking with it. The account name is kept masked
     * here instead, deliberately and in the layer that already masks the other
     * database identifiers. The host half stays readable: 'localhost' versus a
     * remote host is the diagnostic the error exists to carry, and this class
     * already lets the site's own hostname through elsewhere.
     *
     * Both quotes are required. Without them the pattern would re-mask an
     * address the email pass has already masked, since redact() runs this
     * after redactEmails().
     */
    private function redactDatabaseAccountNames(string $text): string {
        return preg_replace_callback(
            "/'([^'@\\s]{1,80})'@'([^'@\\s]{0,255})'/",
            function ($matches) {
                return "'" . ABJ_404_Solution_SensitiveValueMask::maskText($matches[1]) . "'@'" . $matches[2] . "'";
            },
            $text
        ) ?? $text;
    }

    /** @param string $text @return string */
    private function redactDisplayNames(string $text): string {
        return preg_replace_callback(
            '/\bdisplay\s+name:\s*([^\n,]+)/i',
            function ($matches) {
                return 'display name: ' . ABJ_404_Solution_SensitiveValueMask::maskText(trim($matches[1]));
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
    // Opaque tokens
    // =========================================================================

    /**
     * @param string $text @return string
     *
     * This pass recognizes the SHAPE -- a long run of token characters -- and
     * decides what a secret is replaced with. Whether a given candidate is
     * actually key material rather than a post slug or a plugin identifier is
     * a calibrated policy question, and it belongs to
     * ABJ_404_Solution_OpaqueTokenClassifier; the length bar is read from that
     * class so the pattern and the policy cannot disagree about it.
     */
    private function redactLongTokens(string $text): string {
        $minimumLength = ABJ_404_Solution_OpaqueTokenClassifier::MIN_SECRET_LENGTH;

        return preg_replace_callback(
            '/\b([A-Za-z0-9_-]{' . $minimumLength . ',})\b/',
            function ($matches) {
                if (!ABJ_404_Solution_OpaqueTokenClassifier::isOpaqueSecret($matches[1])) {
                    return $matches[1];
                }
                return 'token-' . substr(md5($matches[1]), 0, 8);
            },
            $text
        ) ?? $text;
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
