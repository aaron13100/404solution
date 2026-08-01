<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One boot lifecycle waypoint, stamped with the build generation that is
 * actually executing (Bruno timeout cause matrix, gaps G3 and GF).
 *
 * Split out of ABJ_404_Solution_AjaxCheckpointLogger, whose subject is a
 * different one: how a single checkpoint reaches disk. This class answers
 * "how far into boot are we, and whose opcodes are running" -- it composes
 * ABJ_404_Solution_OpcacheGenerationProbe, both compiled build markers and
 * ABJ_404_Solution_RequestEnvironmentFingerprint into a build-generation
 * verdict, and only then uses the journal as a sink. Nothing a reader of the
 * journal writer needs to understand lives here, and nothing here needs the
 * writer's internals.
 *
 * The dependency runs one way only (recorder -> journal writer); the writer
 * has no knowledge of boot waypoints.
 *
 * Called by the plugin bootstrap (404-solution.php: the file's own first
 * executable line, then `plugins_loaded`, `init` and `admin_init` at
 * PHP_INT_MIN) and by ABJ_404_Solution_AjaxAdminEndpointRegistrar on
 * admin-ajax dispatch.
 */
final class ABJ_404_Solution_BootWaypointRecorder {

    /**
     * The boundary a caller that names none is measured at: the checkpoint
     * logger module itself, because its compiled marker is what every other
     * build id on the record is compared against.
     *
     * Stated as an explicit name/path pair rather than taken from __FILE__,
     * so moving this recorder between files can never silently re-point what
     * "the default boundary" means.
     */
    const DEFAULT_MODULE = 'AjaxCheckpointLogger';

    /**
     * Record one boot lifecycle waypoint (Bruno timeout cause matrix, gap
     * G3): our own plugin file's first executable line, `plugins_loaded`,
     * `init`, `admin_init`, or admin-ajax action dispatch. Every record
     * carries the delta from REQUEST_TIME_FLOAT (via
     * ABJ_404_Solution_RequestEnvironmentFingerprint::bootDelta(), the same
     * formula request_start uses), so the gaps between consecutive
     * waypoints localize a slow boot to a phase instead of a single total,
     * and a request that dies before trace construction still has its boot
     * cost attributable from whichever waypoints it reached.
     *
     * Scope is gated by ABJ_404_Solution_AjaxRequestLedger::bootWaypointRequestId()
     * to our own table-AJAX and canary-ladder requests: never for ordinary
     * front-end page views, where the write cost would land on the hot 404
     * path. Never throws.
     *
     * @param array{module?: string, path?: string, build_id?: string} $boundary
     */
    public static function record(string $event, array $boundary = array()): void {
        try {
            $requestId = ABJ_404_Solution_AjaxRequestLedger::bootWaypointRequestId();
            if ($requestId === '') {
                return;
            }
            $loggerBuildId = ABJ_404_Solution_AjaxCheckpointLogger::DIAGNOSTIC_BUILD_ID;
            $now = self::nowFloat();
            $module = is_string($boundary['module'] ?? null) && $boundary['module'] !== ''
                ? $boundary['module'] : self::DEFAULT_MODULE;
            $modulePath = is_string($boundary['path'] ?? null) && $boundary['path'] !== ''
                ? $boundary['path'] : __DIR__ . '/AjaxCheckpointLogger.php';
            $boundaryBuildId = $boundary['build_id'] ?? null;
            $moduleBuildId = self::validBuildId($boundaryBuildId)
                ? (string)$boundaryBuildId : $loggerBuildId;
            $rootBuildId = defined('ABJ404_DIAGNOSTIC_BUILD_ID')
                && self::validBuildId(ABJ404_DIAGNOSTIC_BUILD_ID)
                    ? (string)ABJ404_DIAGNOSTIC_BUILD_ID : null;
            $boundaryOpcache = ABJ_404_Solution_OpcacheGenerationProbe::boundarySnapshot($modulePath);
            $boundaryOpcache['module'] = $module;
            $boundaryOpcache['compiled_build_id'] = $moduleBuildId;
            $boundaryOpcache['matches_checkpoint_logger'] = hash_equals(
                $loggerBuildId,
                $moduleBuildId
            );
            // A waypoint with no clock is still worth recording: WHICH boot
            // phase was reached is the measurement, and the delta is the
            // refinement. Sending a stand-in number into bootDelta() would
            // publish a boot duration that no clock produced.
            $fields = $now === null
                ? array('request_time_float' => null, 'boot_delta_ms' => null)
                : ABJ_404_Solution_RequestEnvironmentFingerprint::bootDelta($now);
            $fields['diagnostic_build_id'] = $moduleBuildId;
            $fields['checkpoint_logger_build_id'] = $loggerBuildId;
            $fields['root_boot_build_id'] = $rootBuildId;
            $fields['build_generation_consistent'] =
                $boundaryOpcache['matches_checkpoint_logger']
                && ($rootBuildId === null || hash_equals($loggerBuildId, $rootBuildId));
            $fields['boundary_opcache'] = $boundaryOpcache;
            ABJ_404_Solution_AjaxCheckpointLogger::record($requestId, $event, $fields);
        } catch (Throwable $e) {
            // Same inert sink the journal writer reports through: the plugin's
            // own logger reads settings to find its log file, which is exactly
            // the recursion a boot-time diagnostic must not start.
            abj404_logPhpFallback(
                'ajax-checkpoint',
                'AJAX boot waypoint record failed (' . $event . '): ' . $e->getMessage()
            );
        }
    }

    /** @param mixed $value */
    private static function validBuildId($value): bool {
        return is_string($value) && preg_match('/^[0-9a-f]{40}$/', $value) === 1;
    }

    private static function nowFloat(): ?float {
        if (function_exists('abj_clock')) {
            return abj_clock()->nowFloat();
        }
        if (class_exists('ABJ_404_Solution_SystemClock')) {
            return (new ABJ_404_Solution_SystemClock())->nowFloat();
        }
        return null;
    }

}
