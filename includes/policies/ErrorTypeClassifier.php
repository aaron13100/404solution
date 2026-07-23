<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classifies PHP error types for shutdown fatal handling.
 */
class ABJ_404_Solution_ErrorTypeClassifier {

    /**
     * @param int $type PHP error type value.
     * @return bool
     */
    public function isFatalType(int $type): bool {
        $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR);
        return in_array($type, $fatalTypes, true);
    }

    /**
     * Recoverable host/infrastructure warnings that PHP reports as E_WARNING
     * but which the plugin can only degrade past, never fix (the host itself
     * forbids the operation). These must be logged BELOW error level so
     * ABJ_404_Solution_DebugLogReader::getLatestErrorLine() -- which keys on the
     * "(ERROR)" token -- does not count them, and the plugin does not phone
     * home a type='error' report about a condition only the site owner can
     * resolve on the server.
     *
     * Deliberately a small allowlist seeded by real production reports, not a
     * blanket downgrade of every E_WARNING: a genuine plugin-code warning (e.g.
     * "Invalid argument supplied for foreach()") still belongs at error level
     * so it reaches the maintainer's inbox. Each fragment traces to an incident:
     *   - "headers already sent"     report 104 (chasalford.com, 4.2.0, PHP 8.4)
     *   - "open_basedir restriction" report 149 (staging.p2p-game.com, 4.3.2)
     * Add a fragment here (not a new special case at the call site) when a
     * future report shows another host-only E_WARNING reaching the error inbox.
     *
     * @param int $type PHP error type value (as passed to the error handler).
     * @param string $message The errstr PHP passed to the error handler.
     * @return bool
     */
    public function isRecoverableHostWarning(int $type, string $message): bool {
        if ($type !== E_WARNING) {
            return false;
        }
        $hostWarningFragments = array(
            'Cannot modify header information - headers already sent',
            'open_basedir restriction in effect',
        );
        foreach ($hostWarningFragments as $fragment) {
            if (strpos($message, $fragment) !== false) {
                return true;
            }
        }
        return false;
    }
}
