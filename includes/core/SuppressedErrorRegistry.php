<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Diagnostic registry for Throwables suppressed by non-throwing call wrappers.
 *
 * Some helpers in this plugin (notably ServiceContainer::safeGet() and the
 * abj_service() helper) intentionally swallow exceptions and return null so
 * callers can rely on a uniform non-throwing contract. Swallowing alone would
 * lose the exception chain (class, code, file, line, message), which is what
 * design-audit criterion M401 "Silent catch blocks" flags.
 *
 * This registry preserves the full Throwable instance and emits a
 * fully-contextualised error_log() line so:
 *   1. Production sysadmins see the exception class, code, file:line, and
 *      message in their PHP error log, not just the message.
 *   2. Diagnostic surfaces (admin notices, integration tests, the recovery
 *      pass that follows a null-return) can call getLast() to recover the
 *      raw Throwable for richer reporting.
 *
 * Callers MUST call clear() on the success path so a stale prior failure
 * does not appear to belong to the most recent call.
 */
class ABJ_404_Solution_SuppressedErrorRegistry {

    /**
     * Most recent suppressed Throwable, or null if the last recorded
     * call succeeded.
     *
     * @var \Throwable|null
     */
    private static $last = null;

    /**
     * Capture a suppressed Throwable and emit a contextualised log line.
     *
     * @param string     $context Short call-site identifier
     *                            (e.g. 'ServiceContainer::safeGet(foo)').
     * @param \Throwable $e       The suppressed exception.
     * @return void
     */
    public static function record($context, \Throwable $e) {
        self::$last = $e;
        error_log(sprintf(
            '404 Solution: %s suppressed %s (code %s) at %s:%d: %s',
            $context,
            get_class($e),
            (string) $e->getCode(),
            $e->getFile(),
            $e->getLine(),
            $e->getMessage()
        ));
    }

    /**
     * Most recent suppressed Throwable, or null if the last recorded
     * resolution succeeded.
     *
     * @return \Throwable|null
     */
    public static function getLast() {
        return self::$last;
    }

    /**
     * Reset the registry. Wrappers call this on the success path so a stale
     * prior failure is not attributed to the current call.
     *
     * @return void
     */
    public static function clear() {
        self::$last = null;
    }
}
