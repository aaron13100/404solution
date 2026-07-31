<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Is the JavaScript that executed in the browser the JavaScript this install
 * shipped? (Bruno timeout cause matrix, cause A8 "wrong, duplicated, or stale
 * client JS", coverage req. 6, gap GF / Codex gap #8.)
 *
 * The admin table has now been rewritten three times server-side without the
 * user-visible symptom changing, which makes the client bundle one of the few
 * remaining invariants. Asking the browser for a version string it was told by
 * the same page would prove nothing: a page rendered by fresh PHP always
 * reports the fresh value even when the browser is executing a bundle out of
 * an edge or disk cache. So the browser instead hashes the source text of the
 * modules as they are actually executing (Function.prototype.toString), and
 * this class independently hashes the same text out of the shipped .js files.
 * Equal hashes prove the executing bytes are the shipped bytes. Unequal hashes
 * prove they are not, whether the cause is a stale cache, a second copy of the
 * plugin's JS on the page, or an optimizer rewriting the bundle in flight.
 *
 * The first version of this probe covered ONE function in ONE telemetry file,
 * which left the request driver, transport, storage, delivery, canary, and
 * support modules unproven -- exactly the modules a stalled table request
 * lives in. The manifest below covers all of them, and the verdict is now
 * per-module: a mismatch NAMES the drifted file instead of only announcing
 * that the client is not what we shipped.
 *
 * Nothing is kept in sync by hand: editing a module changes both sides at
 * once, because both sides derive their value from the same file. Two module
 * shapes are supported, matching how the modules are actually written:
 *
 *   - `markers`: the module body is a single function expression delimited by
 *     the marker comments (every IIFE-shaped module). The browser hashes
 *     String(moduleFn); this class hashes the text between the markers.
 *   - `functions`: the module is flat top-level function declarations. The
 *     browser hashes their sources joined by newlines, in file order; this
 *     class extracts the same named functions from the file and joins them
 *     the same way.
 */
final class ABJ_404_Solution_ClientBuildFingerprint {

    /** Markers delimiting a whole-module function expression. Comments, so they never reach toString(). */
    const START_MARKER = '/* abj404-client-module:start */';
    const END_MARKER = '/* abj404-client-module:end */';

    /**
     * Every diagnostic client module, keyed by the short name the browser
     * registers it under (view_updater_client_build_registry.js). `functions`
     * is null for marker-delimited modules and an ordered list of top-level
     * function names for flat ones.
     *
     * Paths are relative to includes/. ClientBuildFingerprintTest pins this
     * table against the modules' own registration calls, so the two lists
     * cannot drift apart silently.
     */
    const MODULES = array(
        'tab_identity' => array('file' => 'ajax/view_updater_client_tab_identity.js', 'functions' => null),
        'tab_presence' => array('file' => 'ajax/view_updater_client_tab_presence.js', 'functions' => null),
        'page_ajax_activity' => array('file' => 'ajax/view_updater_page_ajax_activity.js', 'functions' => null),
        'attempt_buffer' => array('file' => 'ajax/view_updater_client_attempt_buffer.js', 'functions' => null),
        'telemetry_store' => array('file' => 'ajax/view_updater_client_telemetry_store.js', 'functions' => null),
        'canary_cooldown' => array('file' => 'ajax/view_updater_canary_cooldown.js', 'functions' => null),
        'main_thread_observations' => array(
            'file' => 'ajax/view_updater_client_main_thread_observations.js', 'functions' => null),
        'telemetry_env' => array('file' => 'ajax/view_updater_client_telemetry_env.js', 'functions' => null),
        'resource_timing' => array(
            'file' => 'ajax/view_updater_client_resource_timing.js', 'functions' => null),
        'transport_telemetry' => array('file' => 'ajax/view_updater_transport_telemetry.js', 'functions' => null),
        'telemetry_delivery' => array('file' => 'ajax/view_updater_transport_telemetry_delivery.js', 'functions' => null),
        'canary_measurements' => array(
            'file' => 'ajax/view_updater_canary_measurements.js', 'functions' => null),
        'concurrent_control_evidence' => array(
            'file' => 'ajax/view_updater_concurrent_control_evidence.js', 'functions' => null),
        'canary_ladder' => array('file' => 'ajax/view_updater_canary_ladder.js', 'functions' => null),
        'support_request' => array('file' => 'ajax/SupportRequest.js', 'functions' => null),
        'pagination_request' => array(
            'file' => 'ajax/view_updater_pagination_request.js',
            'functions' => array('abj404BuildPaginationRequest'),
        ),
        'pagination_transport' => array(
            'file' => 'ajax/view_updater_pagination_transport.js',
            'functions' => array(
                'abj404PaginationResponseHasStructuredError',
                'abj404PaginationFailureIsTransient',
                'abj404PaginationTelemetry',
                'abj404PaginationTelemetryDelivery',
                'abj404PaginationServerOperationThresholdMs',
                'abj404ConcurrentControlRelay',
                'abj404PaginationAttemptUrl',
                'abj404PaginationAttemptData',
                'abj404RequestPaginationPart',
                'abj404PaginationOutcome',
            ),
        ),
        'stage_diagnostics' => array(
            'file' => 'ajax/view_updater_stage_diagnostics.js',
            'functions' => array('abj404AjaxStageDiagnostics'),
        ),
    );

    /** Hard bound on the reported per-module wire string, before parsing. */
    const MAX_REPORTED_MODULES_BYTES = 1024;

    /** @var array<string, string>|null Memoized per request; the files cannot change mid-request. */
    private static $expectedModuleHashes = null;

    /**
     * FNV-1a, 32 bit, lowercase hex, byte for byte identical to the client's
     * abj404ClientBuildRegistry.fnv1a32(). Written as shift-and-add with an
     * explicit 32-bit mask after every step for exactly one reason: the
     * JavaScript side truncates to 32 bits on every shift, so a PHP `*
     * 16777619` on a 64-bit build would silently diverge and turn every
     * comparison into a false mismatch.
     *
     * PHP strings are already bytes, so this walks them directly; the client
     * encodes its UTF-16 string to UTF-8 first so both sides hash the same
     * sequence. That is not theoretical: a module source containing one em
     * dash used to hash differently on the two sides and would have reported
     * every healthy browser as running a stale build.
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
     * The shipped source text for one module, normalized exactly the way the
     * browser normalizes its own (carriage returns stripped, surrounding
     * whitespace trimmed), or '' when the file or its markers/functions
     * cannot be read.
     */
    public static function expectedModuleSource(string $name): string {
        $module = self::MODULES[$name] ?? null;
        if (!is_array($module)) {
            return '';
        }
        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, (string)$module['file']);
        $contents = @file_get_contents($path);
        if (!is_string($contents) || $contents === '') {
            return '';
        }
        $contents = str_replace("\r", '', $contents);
        $functions = $module['functions'] ?? null;
        return is_array($functions)
            ? self::joinedFunctionSources($contents, $functions)
            : self::markedRegion($contents);
    }

    /** The text between the module markers, or '' when they are absent. */
    private static function markedRegion(string $contents): string {
        $start = strpos($contents, self::START_MARKER);
        $end = strrpos($contents, self::END_MARKER);
        if ($start === false || $end === false || $end <= $start) {
            return '';
        }
        $start += strlen(self::START_MARKER);
        return trim(substr($contents, $start, $end - $start));
    }

    /**
     * Top-level function declarations joined by newlines, in the given order,
     * matching what the browser produces from the same function references.
     * Any function that cannot be located makes the whole module unreadable
     * ('') rather than silently hashing a subset: a partial hash would read
     * as a mismatch and send the investigation after a phantom.
     *
     * @param array<int, string> $functionNames
     */
    private static function joinedFunctionSources(string $contents, array $functionNames): string {
        $sources = array();
        foreach ($functionNames as $name) {
            $source = self::topLevelFunctionSource($contents, (string)$name);
            if ($source === '') {
                return '';
            }
            $sources[] = $source;
        }
        return implode("\n", $sources);
    }

    /**
     * One top-level function declaration's exact source text: from the
     * `function` keyword at column zero through the matching closing brace,
     * which the project's JS style always puts at column zero too. That is
     * precisely the text Function.prototype.toString() returns for the same
     * function, so the two sides agree without either parsing JavaScript.
     */
    private static function topLevelFunctionSource(string $contents, string $name): string {
        if (preg_match('/^function\s+' . preg_quote($name, '/') . '\s*\(/m', $contents, $matches,
                PREG_OFFSET_CAPTURE) !== 1) {
            return '';
        }
        $start = (int)$matches[0][1];
        $end = strpos($contents, "\n}\n", $start);
        if ($end === false) {
            // The last declaration in a file with no trailing blank line.
            $end = substr($contents, -2) === "\n}" ? strlen($contents) - 2 : false;
        }
        if ($end === false) {
            return '';
        }
        return substr($contents, $start, ($end + 2) - $start);
    }

    /**
     * Shipped hash per module, '' for any module that could not be read.
     *
     * @return array<string, string>
     */
    public static function expectedModuleHashes(): array {
        if (self::$expectedModuleHashes === null) {
            $hashes = array();
            foreach (array_keys(self::MODULES) as $name) {
                $source = self::expectedModuleSource((string)$name);
                $hashes[(string)$name] = $source === '' ? '' : self::hashOf($source);
            }
            self::$expectedModuleHashes = $hashes;
        }
        return self::$expectedModuleHashes;
    }

    /**
     * The combined hash the browser would report for a given set of module
     * names: FNV-1a over the sorted `name:hash` pairs, exactly as
     * abj404ClientBuildRegistry.digest() computes it. Computed for the
     * reported SUBSET rather than for every module, because an admin screen
     * that loads the support client but not the canary ladder is a smaller
     * module set, not a stale one.
     *
     * @param array<int, string> $names
     */
    public static function expectedCombinedHash(array $names): string {
        $expected = self::expectedModuleHashes();
        $names = array_values(array_unique(array_filter($names, static function ($name) use ($expected) {
            return isset($expected[$name]) && $expected[$name] !== '';
        })));
        if ($names === array()) {
            return '';
        }
        sort($names);
        $parts = array();
        foreach ($names as $name) {
            $parts[] = $name . ':' . $expected[$name];
        }
        return self::hashOf(implode('|', $parts));
    }

    /**
     * The combined hash a page that loaded EVERY shipped diagnostic module
     * would report. Also what compare() falls back to when a client sends a
     * combined hash without the per-module breakdown (an older client, or one
     * whose registry failed to load).
     */
    public static function expectedHash(): string {
        return self::expectedCombinedHash(array_keys(self::MODULES));
    }

    /** Test seam: drop the memoized hashes so rewritten module files are re-read. */
    public static function resetMemoizedHash(): void {
        self::$expectedModuleHashes = null;
    }

    /**
     * Parse the client's compact `name:hash,name:hash` module string into a
     * map, discarding anything that is not a known module name paired with a
     * well-formed hash. Untrusted browser text: bounded before parsing and
     * never echoed back.
     *
     * @return array<string, string>
     */
    public static function parseReportedModules(string $raw): array {
        $modules = array();
        foreach (explode(',', substr($raw, 0, self::MAX_REPORTED_MODULES_BYTES)) as $pair) {
            $parts = explode(':', trim($pair), 2);
            if (count($parts) !== 2) {
                continue;
            }
            $name = $parts[0];
            if (isset(self::MODULES[$name]) && preg_match('/^[0-9a-f]{8}$/', $parts[1]) === 1) {
                $modules[$name] = $parts[1];
            }
        }
        return $modules;
    }

    /**
     * Compare what the browser reported against what this install shipped.
     *
     * The verdict is deliberately three-valued. "unknown" (either side could
     * not produce a hash) must never be reported as a match, because the whole
     * point of this probe is that a missing answer is itself evidence.
     *
     * `mismatched_modules` is the actionable half: when the combined hashes
     * disagree it names every module whose own hash disagrees, so a support
     * payload says which file drifted rather than only that something did.
     * `unreported_modules` names modules this install ships that the page did
     * not register at all -- a module that failed to load looks identical to
     * a healthy smaller page unless it is stated.
     *
     * @return array{reported: string, expected: string, verdict: string,
     *   reported_module_count: int, mismatched_modules: array<int, string>,
     *   unreported_modules: array<int, string>}
     */
    public static function compare(string $reportedHash, string $reportedModules = ''): array {
        $modules = self::parseReportedModules($reportedModules);
        $expected = self::expectedModuleHashes();
        $expectedCombined = $modules === array()
            ? self::expectedCombinedHash(array_keys($expected))
            : self::expectedCombinedHash(array_keys($modules));
        $reported = preg_match('/^[0-9a-f]{8}$/', $reportedHash) === 1 ? $reportedHash : '';

        $mismatched = array();
        foreach ($modules as $name => $hash) {
            if (($expected[$name] ?? '') !== '' && !hash_equals($expected[$name], $hash)) {
                $mismatched[] = $name;
            }
        }

        // A drifted module is a mismatch even when the combined hash agrees.
        // The client derives its combined value from these same per-module
        // hashes, so the two can only disagree if something rewrote one of
        // them in transit -- and "the summary says fine while a component
        // says otherwise" is the one reading that must never be reported as
        // healthy.
        if ($expectedCombined === '' || $reported === '') {
            $verdict = 'unknown';
        } elseif (hash_equals($expectedCombined, $reported) && $mismatched === array()) {
            $verdict = 'match';
        } else {
            $verdict = 'mismatch';
        }
        $unreported = $modules === array()
            ? array()
            : array_values(array_diff(array_keys($expected), array_keys($modules)));

        return array(
            'reported' => $reported,
            'expected' => $expectedCombined,
            'verdict' => $verdict,
            'reported_module_count' => count($modules),
            'mismatched_modules' => $mismatched,
            'unreported_modules' => $unreported,
        );
    }
}
