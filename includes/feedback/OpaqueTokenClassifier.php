<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decides whether a long run of token characters is generated key material or
 * ordinary human-authored text.
 *
 * ABJ_404_Solution_PiiRedactor owns the other half of this: it holds the
 * pattern that FINDS a long token in free-form text, and it decides what a
 * token that turns out to be a secret is replaced with. This class answers
 * only the question in between, and it is a policy question rather than a
 * pattern one, which is why it lives apart. The two halves also fail
 * differently. A pattern that is too broad looks at text it had no business
 * reading; this decision, when it goes wrong, either hashes away the URL that
 * a 404 log line exists to record or writes a live credential to disk in the
 * clear. Keeping it here puts the calibration that separates those two
 * outcomes in one auditable place instead of inline in a callback.
 *
 * This is the third of PiiRedactor's collaborators that is not shape
 * recognition, alongside ABJ_404_Solution_RequestCredentialRedactor (masks the
 * values that request text labels by name) and
 * ABJ_404_Solution_SensitiveValueMask (decides what a masked value looks
 * like). This one covers the values that nothing labels, where the token's own
 * character makeup is the only evidence there is.
 *
 * Pure decision logic: no patterns applied to free text, no I/O, no state.
 */
final class ABJ_404_Solution_OpaqueTokenClassifier {

    /**
     * The length at which a run of token characters becomes worth redacting at
     * all. Real credential formats (JWT segments, Stripe and GitHub keys, SHA
     * digests) clear this comfortably; ordinary identifiers in log text do not.
     *
     * PiiRedactor builds its candidate pattern from this same constant, so the
     * bar cannot drift between the pass that finds tokens and the policy that
     * judges them. Were the two allowed to disagree, a candidate the pattern
     * accepted but this class considered too short would be reported as benign
     * and written out in the clear.
     */
    const MIN_SECRET_LENGTH = 40;

    /**
     * The longest run of consonants a readable word segment may contain before
     * the token is treated as machine-generated. English tops out at four
     * ("wordpress" and the "html"/"css" style abbreviations that appear in real
     * slugs both reach exactly four); a random lowercase run of 40+ characters
     * essentially always exceeds it.
     */
    const MAX_CONSONANT_RUN = 4;

    /**
     * The largest share of a readable token's alphanumerics that may be digits.
     * Slugs carry a year or a list count; hex digests (~62% digits) and
     * base36/numeric identifiers (~28%) sit above this line.
     */
    const MAX_DIGIT_RATIO = 0.25;

    /**
     * @param string $token any string; no precondition is assumed beyond that.
     * @return bool true when the token should be treated as generated key
     *   material and redacted, false when it is short enough to be uninteresting
     *   or recognizable as text a human wrote.
     *
     * The polarity is deliberate. Every route to false is an affirmative
     * finding -- too short to matter, a known plugin identifier, or a token
     * that reads as words -- so anything this class does not positively
     * recognize is redacted. A string that is neither recognizably benign nor
     * a well-formed token (one carrying spaces or punctuation, which
     * PiiRedactor's pattern would never hand over) therefore fails toward
     * redaction rather than away from it.
     */
    public static function isOpaqueSecret(string $token): bool {
        if (strlen($token) < self::MIN_SECRET_LENGTH) {
            return false;
        }

        return !self::looksLikeOwnIdentifier($token) && !self::looksLikeReadableSlug($token);
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

    /**
     * @param string $token a token that has already met the 40-char length bar
     * @return bool true when the token reads as words joined by separators --
     *   a URL slug -- rather than as generated key material.
     *
     * Length alone is not evidence of a secret. The threshold above counts
     * hyphens and underscores toward its 40 characters, so every ordinary
     * hyphenated post slug that long was hashed away:
     * '/2024/06/how-to-configure-your-wordpress-permalinks-correctly/' became
     * '/2024/06/token-bfe96037/', destroying the one thing a 404 log line
     * exists to record. Slugs of this length are not exotic; they are what
     * every SEO-oriented WordPress site produces by default.
     *
     * Splitting the token on its separators and re-applying the length test
     * would be the obvious narrowing and it is not safe: base64url key
     * material (a JWT signature, for instance) contains '-' and '_' from the
     * same alphabet as everything else, so its pieces would fall under any
     * length bar and leak in the clear. The discriminator has to be the
     * character makeup of the token, not its geometry. Three tests, each of
     * which a real credential format fails:
     *
     *   1. Lowercase letters, digits, and single interior separators only.
     *      One uppercase character is the most reliable evidence a token came
     *      from a generator, and it is what excludes every base64, base64url,
     *      and mixed-case vendor key. A token with no separator at all is a
     *      single opaque run and is likewise excluded, which covers hex
     *      digests and base36 identifiers.
     *   2. At most MAX_DIGIT_RATIO of the alphanumerics are digits. Hex
     *      digests, numeric account identifiers, and Slack-style tokens are
     *      digit-dense; a slug carries a year or a list count at most.
     *   3. No consonant run longer than MAX_CONSONANT_RUN. Words alternate
     *      vowels and consonants; random lowercase strings do not. Digits are
     *      transparent to this scan rather than breaking a run, so a token
     *      that laces digits through random letters ('a3f5b2k9m...') cannot
     *      use them to hide its consonant runs, while a product slug like
     *      'model-number-x1000-included' still reads as words.
     *
     * Measured over 40,000 random tokens per alphabet at lengths 40 to 64,
     * these three tests exempted 0.000% of base64url, base36, hex, and
     * hex-with-separator tokens, and 0 of 11 real-world credential formats
     * (JWT header and signature, Stripe, GitHub, Google, Mailgun, Slack, SHA
     * digest, UUID runs, WooCommerce session values).
     *
     * The one shape that survives all three by construction is a wordlist
     * passphrase ('correct-horse-battery-staple-...'), which no character
     * test can separate from a slug because it is literally words. That case
     * is covered a layer earlier: a passphrase reaches this text as the value
     * of a labelled field, and ABJ_404_Solution_RequestCredentialRedactor
     * masks it by name before redact() ever runs this pass.
     */
    private static function looksLikeReadableSlug(string $token): bool {
        if (!preg_match('/^[a-z0-9]+(?:[_-][a-z0-9]+)+$/', $token)) {
            return false;
        }

        $digitCount = preg_match_all('/[0-9]/', $token);
        $letterCount = preg_match_all('/[a-z]/', $token);

        // An all-digit token ('1234-5678-9012-...') is an identifier, not
        // words, so requiring a letter both rejects it and makes the ratio's
        // denominator below provably non-zero.
        if ($letterCount < 1) {
            return false;
        }

        if (($digitCount / ($digitCount + $letterCount)) > self::MAX_DIGIT_RATIO) {
            return false;
        }

        return self::longestConsonantRun($token) <= self::MAX_CONSONANT_RUN;
    }

    /**
     * @param string $token a lowercase alphanumeric-and-separator token
     * @return int the longest run of consecutive consonants, where 'y' counts
     *   as a vowel (it carries one in 'correctly' and 'your'), separators end a
     *   run, and digits are skipped over without ending one.
     */
    private static function longestConsonantRun(string $token): int {
        $longest = 0;
        $current = 0;

        for ($i = 0, $length = strlen($token); $i < $length; $i++) {
            $character = $token[$i];

            if ($character >= '0' && $character <= '9') {
                continue;
            }

            if ($character >= 'a' && $character <= 'z' && strpos('aeiouy', $character) === false) {
                $current++;
                if ($current > $longest) {
                    $longest = $current;
                }
            } else {
                $current = 0;
            }
        }

        return $longest;
    }
}
