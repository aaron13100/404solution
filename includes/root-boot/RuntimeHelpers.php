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

if (!function_exists('abj404_isSelfUpdateRaceThrowable')) {
/**
 * Is this throwable the plugin's own files being replaced underneath a live
 * request, rather than a defect in the plugin's code?
 *
 * WordPress replaces the plugin directory while requests are still running on
 * the installed release, so for a few seconds a request can read new class
 * files, or read no file at all, and be unable to resolve one of OUR classes.
 * That is a hosting-lifecycle event the plugin can only degrade past and which
 * resolves itself on the very next request; per the defensive philosophy it
 * belongs at warning level, not at error level where it emails the maintainer
 * about a condition nobody needs to act on. Production report 266
 * (staging-criticalimpactcom.kinsta.cloud, 4.3.2 to 4.3.3) is one such email.
 *
 * Deliberately narrow, and safe to be narrow in exactly one direction: an
 * unresolvable ABJ_404_Solution class can no longer be a plugin BUG, because
 * ClassLoadingReachabilityTest::testProductionClassReferencesAreReachable()
 * fails the build when any production class reference is not classmapped,
 * same-file, or directly required. What is left at runtime is a missing or
 * mid-swap file, i.e. infrastructure. Everything else -- an \Exception, a
 * TypeError, a call to an undefined method, a non-plugin class -- stays at
 * error level and keeps reaching the inbox.
 *
 * @param \Throwable|null $throwable
 * @return bool
 */
function abj404_isSelfUpdateRaceThrowable($throwable): bool {
    if (!($throwable instanceof \Error)) {
        return false;
    }
    // PHP 8 quotes the name with ", PHP 7 with '. Interfaces, traits and enums
    // are in the same classmap and fail with the same sentence.
    return preg_match(
        '/^(Class|Interface|Trait|Enum) ["\']?ABJ_404_Solution[A-Za-z0-9_]*["\']? not found$/',
        $throwable->getMessage()
    ) === 1;
}
}

if (!function_exists('abj404_logCallbackFailure')) {
/**
 * Log a throwable caught by a plugin callback that must not crash its host
 * (a WordPress hook, a cron-context report send, an admin page render),
 * choosing the severity from the CAUSE rather than from the call site.
 *
 * A self-update race degrades to a warning, so it stays in the debug log for
 * support without counting as an error or triggering an error report. Anything
 * else keeps the caller's previous error-level behavior verbatim, including
 * errorMessage()'s Exception-only second parameter.
 *
 * @param object|null     $logger    ABJ_404_Solution_Logging, or anything
 *                                   exposing warn()/errorMessage().
 * @param string          $message   Already-composed log line.
 * @param \Throwable|null $throwable The caught throwable.
 * @return void
 */
function abj404_logCallbackFailure($logger, string $message, $throwable): void {
    if (abj404_isSelfUpdateRaceThrowable($throwable)
            && is_object($logger) && method_exists($logger, 'warn')) {
        $logger->warn($message . ' | plugin files were being replaced mid-request; '
            . 'this resolves on the next request');
        return;
    }
    if (is_object($logger) && method_exists($logger, 'errorMessage')) {
        $logger->errorMessage($message, $throwable instanceof \Exception ? $throwable : null);
        return;
    }
    abj404_logRuntimeWarning($message, $throwable instanceof \Throwable ? $throwable : null);
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
