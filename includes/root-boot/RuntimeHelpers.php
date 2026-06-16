<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Runtime helpers for root-file callbacks (logging, clock, admin-policy gate).
 *
 * The plugin entry point owns early boot, shutdown, and cron callbacks where the
 * service container may not be available yet. These helpers keep those callbacks
 * on the same logging/clock/authorization abstractions the rest of the plugin
 * uses, while degrading safely when the container or its classes are missing.
 */
// allow-no-test-found: boot-time global helper functions (logging/clock/admin-policy gate) required directly by 404-solution.php before the service container exists; no isolated unit-file seam. abj404_logRuntimeWarning is exercised in CatchExceptionCoverageAuditTest and OpcacheStaleAfterUpgradeTest; the clock helpers back the Clock-injection tests.

if (!function_exists('abj404_record_missing_file')) {
/**
 * Append a missing/corrupt plugin file path to the boot-state missing-files
 * list. Centralizes the typed write to $GLOBALS['abj404_missing_files'] so
 * callers (the autoloader, the boot sequence) don't each have to re-assert the
 * global's array shape.
 *
 * @param string $path
 * @return void
 */
function abj404_record_missing_file(string $path): void {
	if (!isset($GLOBALS['abj404_missing_files']) || !is_array($GLOBALS['abj404_missing_files'])) {
		$GLOBALS['abj404_missing_files'] = array();
	}
	$GLOBALS['abj404_missing_files'][] = $path;
}
}

if (!function_exists('abj404_logRuntimeWarning')) {
/**
 * Route root-file runtime warnings through the plugin logger when available.
 *
 * The root plugin file owns early boot, shutdown, and cron callbacks where the
 * service container may not be available yet. This helper keeps normal runtime
 * failures in the plugin log while preserving a last-resort PHP error log
 * breadcrumb if logger resolution itself fails.
 *
 * @param string $context
 * @param \Throwable|null $throwable
 * @return void
 */
function abj404_logRuntimeWarning(string $context, ?\Throwable $throwable = null): void {
    $line = $context;
    if ($throwable !== null) {
        $line .= ' (code ' . (string)$throwable->getCode() . ') at ' .
            $throwable->getFile() . ':' . (string)$throwable->getLine() .
            ': ' . $throwable->getMessage();
    }

    $loggerFailure = null;
    try {
        $logger = null;
        if (function_exists('abj_service_optional')) {
            $logger = abj_service_optional('logging');
        }
        if (!is_object($logger) && class_exists('ABJ_404_Solution_Logging', false)) {
            $logger = ABJ_404_Solution_Logging::getInstance();
        }
        if (is_object($logger) && method_exists($logger, 'warn')) {
            $logger->warn($line);
            return;
        }
        if (is_object($logger) && method_exists($logger, 'errorMessage')) {
            $logger->errorMessage($line, $throwable instanceof Exception ? $throwable : null);
            return;
        }
    } catch (\Throwable $loggingError) {
        $loggerFailure = $loggingError;
    }

    $fallback = $line;
    if ($loggerFailure !== null) {
        $fallback .= ' | logger failure (code ' . (string)$loggerFailure->getCode() . ') at ' .
            $loggerFailure->getFile() . ':' . (string)$loggerFailure->getLine() .
            ': ' . $loggerFailure->getMessage();
    }
    abj404_logPhpFallback('early-boot', $fallback);
}
}

if (!function_exists('abj404_resolve_clock')) {
/**
 * Resolve the project clock from the root plugin bootstrap.
 *
 * The normal service helper is not available until Loader.php pulls in the
 * bootstrap files, but root-file callbacks also run before or after that
 * boundary. This keeps those callbacks on the same clock abstraction without
 * making early boot depend on the fully initialized container.
 *
 * @return object|null
 */
function abj404_resolve_clock() {
	static $fallbackClock = null;

		try {
			if (function_exists('abj_clock')) {
				return abj_clock();
			}
		if (is_object($fallbackClock)) {
			return $fallbackClock;
		}
		if (class_exists('ABJ_404_Solution_SystemClock')) {
			$fallbackClock = new ABJ_404_Solution_SystemClock();
			return $fallbackClock;
		}
	} catch (\Throwable $e) {
		abj404_logRuntimeWarning('Clock resolution failed in root bootstrap', $e);
	}

	return null;
}
}

if (!function_exists('abj404_now')) {
/**
 * Current epoch seconds for root-file callbacks.
 *
 * @return int
 */
function abj404_now(): int {
	$clock = abj404_resolve_clock();
	if (is_object($clock) && method_exists($clock, 'now')) {
		try {
			return (int)$clock->now();
		} catch (\Throwable $e) {
			abj404_logRuntimeWarning('Clock now() failed in root bootstrap', $e);
		}
	}

	static $reportedUnavailable = false;
	if (!$reportedUnavailable) {
		abj404_logRuntimeWarning('Clock unavailable in root bootstrap; using zero epoch fallback');
		$reportedUnavailable = true;
	}
	return 0;
}
}

if (!function_exists('abj404_now_float')) {
/**
 * Current epoch seconds with sub-second precision for root-file callbacks.
 *
 * @return float
 */
function abj404_now_float(): float {
	$clock = abj404_resolve_clock();
	if (is_object($clock) && method_exists($clock, 'nowFloat')) {
		try {
			return (float)$clock->nowFloat();
		} catch (\Throwable $e) {
			abj404_logRuntimeWarning('Clock nowFloat() failed in root bootstrap', $e);
		}
	}

	return (float)abj404_now();
}
}

if (!function_exists('abj404_current_user_is_plugin_admin')) {
/**
 * Root-file policy gate for admin callbacks declared before normal classes run.
 *
 * Degraded boot screens still use direct WordPress capabilities because the
 * policy class can be among the missing files. Runtime admin callbacks should
 * call this helper so delegated plugin admins follow the same authorization
 * decision everywhere.
 *
 * @return bool
 */
function abj404_current_user_is_plugin_admin(): bool {
    try {
        if (class_exists('ABJ_404_Solution_PluginAdminAccessPolicy')) {
            return ABJ_404_Solution_PluginAdminAccessPolicy::currentUserCanAccessPluginAdmin();
        }
        abj404_logRuntimeWarning(
            'plugin admin access policy resolution failed because PluginAdminAccessPolicy is unavailable.'
        );
    } catch (\Throwable $e) {
        abj404_logRuntimeWarning('plugin admin access policy resolution failed', $e);
    }

    return false;
}
}
