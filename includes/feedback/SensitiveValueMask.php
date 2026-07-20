<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Turns a sensitive value into a stable, partially-revealing placeholder.
 *
 * The output format is a contract, not an implementation detail: support
 * reads these placeholders in debug logs and crash reports, so they must
 * (a) reveal enough of the head of the value to recognize what KIND of thing
 * it was, (b) reveal proportionally less of a short value than a long one,
 * since a short one is nearly guessable from any prefix, and (c) end in a
 * salted hash so that two occurrences of the same value can be correlated
 * across lines while remaining unrecoverable. AUTH_SALT makes that hash
 * site-specific, so a value cannot be looked up from a rainbow table or
 * matched across two different sites' reports.
 *
 * Pure value shaping: no patterns, no I/O, no state. Deciding WHICH runs of
 * text are sensitive belongs to ABJ_404_Solution_PiiRedactor; this class only
 * decides what a sensitive value looks like once found. It is the adaptive
 * counterpart of ABJ_404_Solution_Functions::md5lastOctet(), which does the
 * same job for a network address.
 */
final class ABJ_404_Solution_SensitiveValueMask {

    /**
     * Trailing labels that are a registry suffix rather than the name the
     * registrant chose, so the label before them is the identifying one and
     * must be masked too: 'co.uk', 'com.au' and friends.
     *
     * @var array<int, string>
     */
    const MULTI_LABEL_SUFFIX_TLDS = array('uk', 'au', 'nz', 'za');

    /**
     * Mask an email address, keeping its shape ('ad***@exa***-e64c') so a
     * reader can still tell an address from a username in the log line.
     *
     * The public suffix is dropped rather than masked: '.com' identifies
     * nobody, and keeping it would spend visible characters on a constant.
     *
     * @param string $email
     * @return string
     */
    public static function maskEmail($email) {
        if (empty($email) || strpos($email, '@') === false) {
            return $email;
        }

        $parts = explode('@', $email);
        if (count($parts) != 2) {
            return self::maskText($email);
        }

        list($username, $fullDomain) = $parts;

        $domainParts = explode('.', $fullDomain);
        if (count($domainParts) > 1) {
            if (in_array(end($domainParts), self::MULTI_LABEL_SUFFIX_TLDS)) {
                array_pop($domainParts);
                array_pop($domainParts);
            } else {
                array_pop($domainParts);
            }
        }
        $domain = implode('.', $domainParts);

        $maskedUsername = substr($username, 0, self::visibleCharacters(strlen($username))) . '***';

        $domainVisible = max(1, (int)ceil(strlen($domain) * 0.3));
        $maskedDomain = empty($domain) ? '' : substr($domain, 0, $domainVisible) . '***';

        if (!empty($maskedDomain)) {
            return $maskedUsername . '@' . $maskedDomain . '-' . self::correlationHash($email);
        }
        return $maskedUsername . '@-' . self::correlationHash($email);
    }

    /**
     * Mask an arbitrary sensitive string (a username, a display name, a
     * database account name).
     *
     * @param string $text
     * @return string
     */
    public static function maskText($text) {
        if (empty($text)) {
            return $text;
        }

        $text = trim($text);
        $masked = substr($text, 0, self::visibleCharacters(strlen($text))) . '***';

        return $masked . '-' . self::correlationHash($text);
    }

    /**
     * How much of a value's head may stay readable. A four-character value
     * gives away almost everything from two characters, so short values
     * reveal less.
     *
     * @param int $length
     * @return int
     */
    private static function visibleCharacters(int $length): int {
        if ($length <= 4) {
            return 1;
        }
        if ($length <= 9) {
            return 2;
        }
        return 3;
    }

    /**
     * Site-specific, non-reversible tail that makes two occurrences of the
     * same value comparable. Falls back to an unsalted hash before
     * wp-config.php's salts are available (very early boot, and the plugin's
     * own test bootstrap), which keeps the value masked either way.
     *
     * @param string $value
     * @return string
     */
    private static function correlationHash(string $value): string {
        if (defined('AUTH_SALT')) {
            return substr(md5(AUTH_SALT . $value), 0, 4);
        }
        return substr(md5($value), 0, 4);
    }
}
