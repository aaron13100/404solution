<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Server-side regex auto-promotion helpers.
 *
 * The JS in includes/ajax/redirect_to_ajax.js already auto-ticks the
 * "Treat as regex" checkbox when an admin types a URL that looks like a
 * regex pattern. This class is the server-side counterpart: it lets the
 * admin-save, import, and runtime-matching paths reach the same conclusion
 * when the JS detection didn't run (paste-and-submit with JS disabled, CSV
 * import, existing MANUAL row written by an older version, etc.).
 *
 * The character set used here (`* [ ] | ^ \ { }`) is intentionally narrower
 * than the JS detector: every character is reserved/unsafe per RFC 3986, so
 * browsers URL-encode them. Their RAW presence in a stored from_url is
 * near-certain regex intent. False positives would require a user to
 * paste an already URL-encoded query string verbatim, which is not a real
 * workflow this plugin needs to support.
 *
 * Explicitly NOT included: `. ? + ( ) & = #`. These appear in real URLs
 * constantly (file extensions, query strings, fragments) and a sniff that
 * promoted them would catch many real MANUAL redirects by mistake.
 */
class ABJ_404_Solution_RegexAutoPromote {

    /**
     * Decide whether a raw from_url contains regex metachars unambiguous
     * enough to auto-promote status=MANUAL to status=REGEX.
     *
     * @param string $url The raw from_url as stored / as posted.
     * @return bool True when the URL contains at least one unambiguous regex
     *              metacharacter from the safe set described in the class
     *              docblock.
     */
    public static function looksLikeUnambiguousRegex($url) {
        if (!is_string($url) || $url === '') {
            return false;
        }

        // Each char in the bracket below is RFC 3986-reserved or non-URL-safe.
        // A browser submitting these via the address bar would URL-encode
        // them (`%5B`, `%7C`, etc.), so a raw presence is strong regex
        // intent. Backslash is included because a plain URL never contains
        // one. `\d`, `\w`, `\s` are PCRE shorthand classes only.
        return preg_match('/[\*\[\]\|\^\\\\\{\}]/', $url) === 1;
    }

    /**
     * Apply minimal glob-to-regex normalization on the auto-promote path
     * only. This handles the "user typed `/sales/*` and meant `/sales/.*`"
     * case: shells use bare `*` for "zero or more of anything", but PCRE's
     * `*` is a quantifier that needs something in front of it. Without this
     * fix, `/sales/*` compiles as the literal string `/sales/` followed by
     * the regex error "nothing to repeat". Every 404 hit logs a PHP warning
     * and the redirect never fires.
     *
     * Rules (deliberately narrow). The user can always uncheck the
     * "Treat as regex" box if we guess wrong, and the admin notice points
     * out the rewrite so it is visible:
     *
     *   1. Pattern contains `*` AND nowhere contains `.*`: replace every
     *      bare `*` with `.*`. Covers `/sales/*` and `*-old.html` both.
     *   2. Pattern does not contain `*`: no change.
     *   3. Pattern already contains `.*` somewhere: leave the bare `*`s
     *      alone. The user (or upstream) clearly knows the difference.
     *
     * Explicitly NOT applied:
     *   - Auto-escaping `.` (would break legitimate `.*` users).
     *   - Auto-anchoring with `^` / `$` (some users want substring match).
     *   - Quoting other metachars. We trust the pattern shape from here on
     *     and the import-time validator (validateRegexPattern) catches the
     *     leftover compile failures.
     *
     * This MUST be called only on the auto-promote path. When the user
     * explicitly checks "Treat as regex" on a weird pattern like `(foo)*`,
     * they meant exactly what they wrote and we don't second-guess them.
     *
     * @param string $url Raw from_url to consider for normalization.
     * @return array{url: string, changed: bool}
     */
    public static function applyGlobFixup($url) {
        if (!is_string($url)) {
            return array('url' => '', 'changed' => false);
        }
        if ($url === '') {
            return array('url' => '', 'changed' => false);
        }
        if (strpos($url, '*') === false) {
            return array('url' => $url, 'changed' => false);
        }
        if (strpos($url, '.*') !== false) {
            return array('url' => $url, 'changed' => false);
        }
        // Replace each bare `*` with `.*`. preg_replace with a negative
        // lookbehind for `.` would also work, but a straight str_replace
        // suffices since we already proved no `.*` exists anywhere.
        $rewritten = str_replace('*', '.*', $url);
        return array('url' => $rewritten, 'changed' => $rewritten !== $url);
    }

    /**
     * Single-tenant transient key holding the most recent auto-promote
     * event. Sites with concurrent admins editing redirects will see
     * latest-wins behavior on this notice; the underlying redirect rows
     * are still per-row so no data is mixed up, just the dismissable
     * UI banner. This is the canonical "small UX feature, big
     * complexity if multi-keyed" tradeoff: a per-user key would force
     * every test that renders the admin header to stub
     * get_current_user_id even after Brain Monkey teardown, because
     * Patchwork keeps the function registered globally.
     */
    const NOTICE_TRANSIENT_KEY = 'abj404_regex_autopromote_notice';

    /**
     * Persist an auto-promote event. The payload is small (~5 fields)
     * and one-shot per save; the next admin page render reads it back
     * and shows a notice with [Edit] and [Undo] links.
     *
     * Kept on the helper class (not PluginLogic) so the view layer can
     * read the notice without going through a typed PluginLogic method
     * call. Mockery's strict-call behavior would otherwise force every
     * existing PluginLogic mock to pre-declare an expectation on the
     * read method.
     *
     * @param int $redirectId The redirect row id that was just saved.
     * @param string $originalURL The from_url posted by the admin (pre-rewrite).
     * @param string $newURL The from_url that ended up stored.
     * @param bool $urlRewritten True when the glob fixup mutated the URL.
     * @return void
     */
    public static function saveNotice($redirectId, $originalURL, $newURL, $urlRewritten) {
        if ($redirectId <= 0 || !function_exists('set_transient')) {
            return;
        }
        $payload = array(
            'redirect_id' => (int)$redirectId,
            'original_url' => (string)$originalURL,
            'new_url' => (string)$newURL,
            'url_rewritten' => (bool)$urlRewritten,
            'created_at' => time(),
        );
        $ttl = defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600;
        // allow-cache-empty: $payload built locally from method args, never a failure-derived empty/null.
        set_transient(self::NOTICE_TRANSIENT_KEY, $payload, $ttl);
    }

    /**
     * Read the pending notice payload, or null when no notice is
     * pending. Leaves the transient in place so the Undo handler can
     * still find the original from_url after the notice has been
     * rendered. The transient self-expires via its TTL.
     *
     * @return array{redirect_id: int, original_url: string, new_url: string, url_rewritten: bool, created_at: int}|null
     */
    public static function readNotice() {
        if (!function_exists('get_transient')) {
            return null;
        }
        $data = get_transient(self::NOTICE_TRANSIENT_KEY);
        if (!is_array($data) || !isset($data['redirect_id'])) {
            return null;
        }
        $redirectId = isset($data['redirect_id']) && is_numeric($data['redirect_id']) ? (int)$data['redirect_id'] : 0;
        $createdAt = isset($data['created_at']) && is_numeric($data['created_at']) ? (int)$data['created_at'] : 0;
        return array(
            'redirect_id' => $redirectId,
            'original_url' => isset($data['original_url']) && is_string($data['original_url']) ? $data['original_url'] : '',
            'new_url' => isset($data['new_url']) && is_string($data['new_url']) ? $data['new_url'] : '',
            'url_rewritten' => !empty($data['url_rewritten']),
            'created_at' => $createdAt,
        );
    }

    /**
     * Delete the pending notice (called after a successful Undo or an
     * explicit dismissal).
     *
     * @return void
     */
    public static function clearNotice() {
        if (!function_exists('delete_transient')) {
            return;
        }
        delete_transient(self::NOTICE_TRANSIENT_KEY);
    }
}
