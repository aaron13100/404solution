<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Is the JavaScript that executed in the browser the JavaScript this install
 * shipped? (Bruno timeout cause matrix, cause A8 "wrong, duplicated, or stale
 * client JS", coverage req. 6.)
 *
 * The admin table has now been rewritten three times server-side without the
 * user-visible symptom changing, which makes the client bundle one of the few
 * remaining invariants. Asking the browser for a version string it was told by
 * the same page would prove nothing: a page rendered by fresh PHP always
 * reports the fresh value even when the browser is executing a bundle out of
 * an edge or disk cache. So the browser instead hashes the source text of one
 * probe function as it is actually executing (Function.prototype.toString),
 * and this class independently hashes the same function's text out of the
 * shipped .js file. Equal hashes prove the executing bytes are the shipped
 * bytes. Unequal hashes prove they are not, whether the cause is a stale
 * cache, a second copy of the plugin's JS on the page, or an optimizer
 * rewriting the bundle in flight.
 *
 * Nothing is kept in sync by hand: editing the probe changes both sides at
 * once, because both sides derive their value from the same file.
 */
final class ABJ_404_Solution_ClientBuildFingerprint {

    /**
     * Source file holding the probe, relative to includes/. Resolved from this
     * class's own directory rather than through ABJ404_PATH: the probe ships
     * beside this file, so their relative position is a fact about the package
     * and cannot be invalidated by how the plugin was installed or by which
     * constants a given entry point happens to have defined.
     */
    const PROBE_FILE = 'ajax/view_updater_client_telemetry_env.js';

    /** Markers delimiting the probe. Comments, so they never reach toString(). */
    const START_MARKER = '/* abj404-client-build-probe:start */';
    const END_MARKER = '/* abj404-client-build-probe:end */';

    /** @var string|null Memoized per request; the file cannot change mid-request. */
    private static $expectedHash = null;

    /**
     * FNV-1a, 32 bit, lowercase hex, byte for byte identical to the client's
     * abj404ClientTelemetryEnv.fnv1a32(). Written as shift-and-add with an
     * explicit 32-bit mask after every step for exactly one reason: the
     * JavaScript side truncates to 32 bits on every shift, so a PHP `*
     * 16777619` on a 64-bit build would silently diverge and turn every
     * comparison into a false mismatch.
     */
    public static function hashOf(string $text): string {
        $hash = 0x811c9dc5;
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $hash ^= ord($text[$i]);
            $hash = ($hash
                + ((($hash << 1) & 0xffffffff)
                + (($hash << 4) & 0xffffffff)
                + (($hash << 7) & 0xffffffff)
                + (($hash << 8) & 0xffffffff)
                + (($hash << 24) & 0xffffffff))) & 0xffffffff;
        }
        return str_pad(dechex($hash), 8, '0', STR_PAD_LEFT);
    }

    /**
     * The probe's source text as shipped, normalized the same way the client
     * normalizes its own (carriage returns stripped, surrounding whitespace
     * trimmed), or '' when the file or markers cannot be read.
     */
    public static function expectedProbeSource(): string {
        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . self::PROBE_FILE;
        $contents = @file_get_contents($path);
        if (!is_string($contents) || $contents === '') {
            return '';
        }
        $start = strpos($contents, self::START_MARKER);
        $end = strpos($contents, self::END_MARKER);
        if ($start === false || $end === false || $end <= $start) {
            return '';
        }
        $start += strlen(self::START_MARKER);
        return trim(str_replace("\r", '', substr($contents, $start, $end - $start)));
    }

    /** Hash of the shipped probe source, or '' when it could not be read. */
    public static function expectedHash(): string {
        if (self::$expectedHash === null) {
            $source = self::expectedProbeSource();
            self::$expectedHash = $source === '' ? '' : self::hashOf($source);
        }
        return self::$expectedHash;
    }

    /** Test seam: drop the memoized hash so a rewritten probe file is re-read. */
    public static function resetMemoizedHash(): void {
        self::$expectedHash = null;
    }

    /**
     * Compare a client-reported build hash against the shipped one.
     *
     * The verdict is deliberately three-valued. "unknown" (either side could
     * not produce a hash) must never be reported as a match, because the whole
     * point of this probe is that a missing answer is itself evidence.
     *
     * @return array{reported: string, expected: string, verdict: string}
     */
    public static function compare(string $reportedHash): array {
        $expected = self::expectedHash();
        $reported = preg_match('/^[0-9a-f]{8}$/', $reportedHash) === 1 ? $reportedHash : '';
        if ($expected === '' || $reported === '') {
            $verdict = 'unknown';
        } elseif (hash_equals($expected, $reported)) {
            $verdict = 'match';
        } else {
            $verdict = 'mismatch';
        }
        return array(
            'reported' => $reported,
            'expected' => $expected,
            'verdict' => $verdict,
        );
    }
}
