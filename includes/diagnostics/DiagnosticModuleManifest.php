<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Which bytes on disk make up the diagnostic request path, and is the opcode
 * cache serving those same bytes? (Bruno timeout cause matrix, gap GF /
 * Codex gap #8, cause D "stale or mixed code".)
 *
 * `request_start` used to fingerprint exactly two files: this request's
 * handler and ABJ_404_Solution_RequestEnvironmentFingerprint itself. That
 * proved nothing about the modules a stalled table request actually spends
 * its time in -- the request driver, the checkpoint recorder, the journal,
 * the response emitter, the canary ladder, the support collector. A mixed
 * OPcache generation (one module recompiled after a deploy, its neighbours
 * still running the previous release's opcodes) is invisible to a two-file
 * probe, and mixed generations are exactly what produces a symptom that
 * survives three rewrites of the code the symptom appears in.
 *
 * So the manifest covers the whole path, and it is DERIVED rather than
 * listed where it can be: every file in includes/diagnostics/ is in scope by
 * construction, so a diagnostic module added next month is covered without
 * anyone remembering to add it here. Only the collaborators that live
 * elsewhere (dispatch, response emission, the endpoints themselves, the DB
 * query hook, asset delivery) are named explicitly, and
 * DiagnosticModuleManifestTest pins that list against the files those
 * endpoints are actually implemented in.
 *
 * The captured record is deliberately compact: one combined hash, per-file
 * short hashes so a drifted file can be NAMED when a payload is compared
 * against a reference build, counts for the healthy majority, and names only
 * for the anomalies (unreadable, uncached, or an OPcache timestamp that does
 * not match the file on disk). The support excerpt is the scarce resource
 * (gap G1); a full per-file fingerprint block for thirty-odd modules would
 * cost more evidence than it produces.
 */
final class ABJ_404_Solution_DiagnosticModuleManifest {

    /** Bumped when the record's shape changes, so an old payload stays readable. */
    const SCHEMA_VERSION = 1;

    /** Characters of each file's md5 kept per module. Enough to name a drifted file. */
    const SHORT_HASH_CHARS = 8;

    /**
     * Modules outside includes/diagnostics/ that a table AJAX request, its
     * telemetry, the canary ladder, or the support collector executes.
     *
     * Paths are relative to includes/. Anything under diagnostics/ is picked
     * up automatically and must NOT be listed here.
     */
    const EXTERNAL_MODULES = array(
        // The plugin entry point owns the earliest compiled build marker.
        '../404-solution.php',
        // Request path: dispatch -> auth -> rate limit -> handler -> stages.
        'ajax/AjaxAdminEndpointRegistrar.php',
        'ajax/AjaxSecurityGate.php',
        'ajax/Ajax_Php.php',
        'ajax/AjaxAdminEndpointSupport.php',
        'ajax/Ajax_GetPaginationLinks.php',
        'ajax/Ajax_RefreshHealthBar.php',
        // AjaxStageDiagnostics.php is deliberately absent: it now lives under
        // diagnostics/ and is therefore picked up automatically.
        // The report-only beacon branch of the table endpoint.
        'ajax/AjaxClientReportBeaconResponder.php',
        // Response emission and connection detach.
        'ajax/AjaxResponseEmitter.php',
        // Canary ladder and support collection endpoints.
        'ajax/Ajax_CanaryLadder.php',
        'ajax/Ajax_SupportRequest.php',
        'ajax/Ajax_SupportRequestPreview.php',
        // Request parsing is part of the canary interpretation boundary.
        'services/RequestInputNormalizer.php',
        // Local template I/O boundary used by the table renderers.
        'core/FileSystemService.php',
        // Pre-query sort-readiness schema and option/cache authority.
        'view-build/RedirectsDenormSchemaReadiness.php',
        // Foreground status-count cache and cron scheduling authorities.
        'stats/StatusCountsRepository.php',
        'stats/RedirectHitCountHistogramRepository.php',
        'stats/RedirectRowCountRepository.php',
        'view-build/StatusCountsRefreshCoordinator.php',
        'services/CronScheduler.php',
        // The per-query attribution hook, which lives with the DB layer.
        'database/DatabaseQueryDiagnostics.php',
        // Query preflight spans these DB collaborators before the first SQL
        // probe. Mixed opcodes in any one would erase or mislabel the gap.
        'database/DatabaseQueryExecutor.php',
        'database/DatabaseQueryRecoveryPolicy.php',
        'database/DatabaseRepairPolicy.php',
        'database/DatabaseTableRepairer.php',
        'database/DatabaseSqlErrorReporter.php',
        'database/DatabaseConnectionManager.php',
        'database/DatabaseQueryTimeoutManager.php',
        'database/DatabaseRuntimeState.php',
        // Delivery of the browser-side modules ClientBuildFingerprint hashes.
        'admin/AdminAssetEnqueuer.php',
    );

    /**
     * Absolute paths of every module in scope, keyed by the short name the
     * record reports. Recomputed per call rather than memoized in a static:
     * an FPM worker serves many requests, and a manifest cached across a
     * deploy would report the pre-deploy file set as if it were current --
     * which is the exact blind spot this class exists to remove.
     *
     * @return array<string, string>
     */
    public static function modulePaths(): array {
        $paths = self::baseModulePathsForRoot(dirname(dirname(__DIR__)));
        // Real extension point, not a test hatch: a site running this plugin
        // from an unusual layout (a symlinked mu-plugin tree, a build that
        // relocates part of the diagnostic path) can tell the manifest where
        // its modules actually live, and an integrator adding a diagnostic
        // module of their own can have it fingerprinted alongside ours. A
        // filter that returns anything but a non-empty array is ignored, so a
        // careless callback degrades to the shipped list rather than to an
        // empty manifest that would read as "no code is deployed".
        if (!function_exists('apply_filters')) {
            return $paths;
        }
        $filtered = apply_filters('abj404_diagnostic_module_paths', $paths);
        if (!is_array($filtered) || $filtered === array()) {
            return $paths;
        }
        // Re-typed key by key rather than passed through: the filtered value
        // is whatever a third party returned, so an array key (always int or
        // string) and a value of any type are normalized here rather than
        // trusted downstream. A non-scalar path is dropped, not stringified.
        $result = array();
        foreach ($filtered as $name => $path) {
            if (is_scalar($path)) {
                $result[(string)$name] = (string)$path;
            }
        }
        return $result === array() ? $paths : $result;
    }

    /**
     * Build the unfiltered shipped manifest for a project root supplied as
     * data. Release tooling uses this instead of executing PHP from that root.
     *
     * @return array<string, string>
     */
    private static function baseModulePathsForRoot(string $projectRoot): array {
        $includes = rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR;
        $paths = array();
        foreach (glob($includes . 'diagnostics' . DIRECTORY_SEPARATOR . '*.php') ?: array() as $path) {
            $paths[self::shortName($path)] = $path;
        }
        foreach (self::EXTERNAL_MODULES as $relative) {
            $path = $includes . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $paths[self::shortName($path)] = $path;
        }
        ksort($paths);
        return $paths;
    }

    /** The record's key for one module: its basename without the .php suffix. */
    public static function shortName(string $path): string {
        return preg_replace('/\.php$/', '', basename($path)) ?? basename($path);
    }

    /**
     * Content-addressed build ID embedded into the early boot modules.
     *
     * Marker literals are normalized before hashing so the ID can be embedded
     * into the files it covers without becoming a self-referential hash. The
     * release-consistency test recomputes this value and fails whenever a
     * covered module changes without refreshing the compiled marker.
     */
    public static function releaseBuildId(): string {
        return self::buildIdForPaths(self::modulePaths());
    }

    /**
     * Compute the shipped manifest for another source root without loading or
     * executing any PHP from that caller-supplied directory.
     */
    public static function releaseBuildIdForRoot(string $projectRoot): string {
        return self::buildIdForPaths(self::baseModulePathsForRoot($projectRoot));
    }

    /** @param array<string, string> $paths */
    private static function buildIdForPaths(array $paths): string {
        $parts = array();
        foreach ($paths as $name => $path) {
            $parts[] = $name . ':' . self::canonicalSourceHash($path);
        }
        return sha1(implode('|', $parts));
    }

    /**
     * Hash every module, reconcile each against the opcode cache, and return
     * the compact manifest for request_start.
     *
     * @param ABJ_404_Solution_OpcacheGenerationProbe $opcache The request's single
     *   opcode-cache read. When it has no per-script data every module is
     *   reported as 'unknown' rather than silently as "not cached".
     * @return array<string, mixed>
     */
    public static function capture(ABJ_404_Solution_OpcacheGenerationProbe $opcache): array {
        $files = array();
        $unreadable = array();
        $uncached = array();
        $stale = array();
        $cached = 0;
        $unknown = 0;
        $parts = array(defined('ABJ404_VERSION') ? (string)ABJ404_VERSION : 'unknown');
        $releaseParts = array();

        foreach (self::modulePaths() as $name => $path) {
            $source = @file_get_contents($path);
            $hash = is_string($source) ? md5($source) : false;
            $mtime = @is_file($path) ? @filemtime($path) : false;
            $releaseParts[] = $name . ':' . (
                is_string($source) ? self::canonicalSourceHashOf($source) : 'missing'
            );
            if (!is_string($hash)) {
                $unreadable[] = $name;
                $parts[] = $name . ':missing';
                continue;
            }
            $files[$name] = substr($hash, 0, self::SHORT_HASH_CHARS);
            $parts[] = $name . ':' . $hash . ':' . (is_int($mtime) ? $mtime : '');

            // A null 'cached' is "no per-script data", which is a different
            // finding from "this module is not cached"; and a false
            // 'matches_file' is direct proof that the executing opcodes and
            // the file on disk are from different generations.
            $state = $opcache->stateFor($path, is_int($mtime) ? $mtime : null);
            if ($state['cached'] === null) {
                $unknown++;
                continue;
            }
            if ($state['cached'] === false) {
                $uncached[] = $name;
                continue;
            }
            $cached++;
            if ($state['matches_file'] === false) {
                $stale[] = $name;
            }
        }

        $releaseBuildId = sha1(implode('|', $releaseParts));
        $precomputedBuildId = defined('ABJ404_DIAGNOSTIC_BUILD_ID')
            ? (string)ABJ404_DIAGNOSTIC_BUILD_ID : '';
        return array(
            'schema' => self::SCHEMA_VERSION,
            'hash' => sha1(implode('|', $parts)),
            'diagnostic_build_id' => $releaseBuildId,
            'precomputed_build_id' => $precomputedBuildId,
            'precomputed_build_matches_files' => $precomputedBuildId !== ''
                ? hash_equals($releaseBuildId, $precomputedBuildId) : null,
            'module_count' => count($files) + count($unreadable),
            'unreadable' => $unreadable,
            'opcache' => array(
                'cached' => $cached,
                'unknown' => $unknown,
                'uncached' => $uncached,
                'stale' => $stale,
            ),
            'files' => $files,
        );
    }

    private static function canonicalSourceHash(string $path): string {
        $source = @file_get_contents($path);
        if (!is_string($source)) {
            return 'missing';
        }
        return self::canonicalSourceHashOf($source);
    }

    private static function canonicalSourceHashOf(string $source): string {
        $canonical = preg_replace(
            array(
                "/(ABJ404_DIAGNOSTIC_BUILD_ID'\\s*,\\s*')[0-9a-f]{40}(')/",
                "/(const\\s+DIAGNOSTIC_BUILD_ID\\s*=\\s*')[0-9a-f]{40}(')/",
            ),
            '$1<diagnostic-build-id>$2',
            $source
        );
        return sha1(is_string($canonical) ? $canonical : $source);
    }
}
