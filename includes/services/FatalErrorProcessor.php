<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../diagnostics/CrashBeaconStore.php';

/**
 * Orchestrates shutdown-time fatal-error handling.
 */
class ABJ_404_Solution_FatalErrorProcessor {

    /** @var ABJ_404_Solution_ErrorTypeClassifier */
    private $classifier;

    /** @var ABJ_404_Solution_ErrorDiagnosticsReporter */
    private $diagnostics;

    /** @var ABJ_404_Solution_AdminFatalErrorResponder */
    private $adminResponder;

    /** @var ABJ_404_Solution_AjaxFatalErrorResponder */
    private $ajaxResponder;

    /**
     * @param ABJ_404_Solution_ErrorTypeClassifier|null $classifier
     * @param ABJ_404_Solution_ErrorDiagnosticsReporter|null $diagnostics
     * @param ABJ_404_Solution_AdminFatalErrorResponder|null $adminResponder
     * @param ABJ_404_Solution_AjaxFatalErrorResponder|null $ajaxResponder
     */
    public function __construct($classifier = null, $diagnostics = null, $adminResponder = null, $ajaxResponder = null) {
        $this->classifier = $classifier !== null ? $classifier : new ABJ_404_Solution_ErrorTypeClassifier();
        $this->diagnostics = $diagnostics !== null ? $diagnostics : new ABJ_404_Solution_ErrorDiagnosticsReporter();
        $this->adminResponder = $adminResponder !== null ? $adminResponder : new ABJ_404_Solution_AdminFatalErrorResponder();
        $this->ajaxResponder = $ajaxResponder !== null ? $ajaxResponder : new ABJ_404_Solution_AjaxFatalErrorResponder($this->diagnostics);
    }

    /**
     * @param array<string,mixed>|null $lasterror
     * @return bool
     */
    public function process($lasterror): bool {
        if (!$this->isProcessableFatal($lasterror) || !is_array($lasterror)) {
            return false;
        }

        $lasterror = $this->truncateLargeMessage($lasterror);
        $ctx = $this->currentAjaxContext();

        // Crash beacon: for a plugin-scope fatal (including OOM), release the
        // memory reserve on ALL requests (previously admin-only, which is why a
        // front-end OOM had no headroom) and write a tiny breadcrumb file FIRST,
        // before any heavier handling below, so the post-mortem survives even if
        // a later step in this handler fatals again. Self-contained and best
        // effort; never throws.
        if ($this->isPluginScopeFatal($lasterror)) {
            ABJ_404_Solution_ErrorHandler::releaseReservedMemory();
            $this->captureCrashBeacon($lasterror);
        }

        $isPluginAdminPage = $this->adminResponder->isPluginAdminPageRequest();
        if ($isPluginAdminPage) {
            ABJ_404_Solution_ErrorHandler::releaseReservedMemory();
            $this->adminResponder->stashAdminFatal($lasterror);
        }

        if ($this->ajaxResponder->isAjaxContext($ctx)) {
            return $this->ajaxResponder->process($lasterror, is_array($ctx) ? $ctx : array());
        }

        $this->logDefaultFatal($lasterror, $isPluginAdminPage);

        if ($isPluginAdminPage) {
            $this->adminResponder->renderAdminFatalFallback($lasterror);
        }

        return false;
    }

    /**
     * @param mixed $lasterror
     * @return bool
     */
    private function isProcessableFatal($lasterror): bool {
        if ($lasterror == null || !is_array($lasterror) || !array_key_exists('type', $lasterror) ||
            !array_key_exists('file', $lasterror)) {
            return false;
        }

        $errorType = $lasterror['type'];
        return $this->classifier->isFatalType(is_int($errorType) ? $errorType : (is_scalar($errorType) ? (int)$errorType : 0));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function currentAjaxContext() {
        if (!isset($GLOBALS['abj404_ajax_context']) || !is_array($GLOBALS['abj404_ajax_context'])) {
            return null;
        }

        $context = array();
        foreach ($GLOBALS['abj404_ajax_context'] as $key => $value) {
            if (is_string($key)) {
                $context[$key] = $value;
            }
        }
        return $context;
    }

    /**
     * @param array<string,mixed> $lasterror
     * @return void
     */
    private function logDefaultFatal(array $lasterror, bool $isPluginAdminPage): void {
        try {
            $errno = $lasterror['type'];
            $isPluginScopeFatal = $this->isPluginScopeFatal($lasterror);

            if (!$isPluginScopeFatal && !$isPluginAdminPage) {
                return;
            }

            $extraInfo = "(none)";
            $ctxDebugInfo = abj_service('request_context')->debug_info;
            if ($ctxDebugInfo !== '') {
                $extraInfo = stripcslashes(wp_kses_post((string)json_encode($ctxDebugInfo)));
            }
            $contextPrefix = $isPluginScopeFatal
                ? 'ABJ404-SOLUTION Fatal error handler: '
                : 'ABJ404-SOLUTION Fatal error handler (plugin admin page, foreign scope): ';

            $errmsg = $contextPrefix .
                stripcslashes(wp_kses_post((string)json_encode($lasterror))) .
                ", \nAdditional info: " . $extraInfo . ", mbstring: " .
                (extension_loaded('mbstring') ? 'true' : 'false');

            $abj404logging = abj_service('logging');
            if ($abj404logging != null) {
                switch ($errno) {
                    case E_NOTICE:
                        $serverName = array_key_exists('SERVER_NAME', $_SERVER) ? $_SERVER['SERVER_NAME'] : (array_key_exists('HTTP_HOST', $_SERVER) ? $_SERVER['HTTP_HOST'] : '(not found)');
                        $whitelist = isset($GLOBALS['abj404_whitelist']) && is_array($GLOBALS['abj404_whitelist'])
                            ? $GLOBALS['abj404_whitelist'] : array();
                        if (in_array($serverName, $whitelist, true)) {
                            $abj404logging->debugMessage($errmsg);
                        }
                        break;

                    default:
                        $abj404logging->errorMessage($errmsg);
                        break;
                }
            } else {
                echo $errmsg;
            }
        } catch (Throwable $ex) {
            abj404_logPhpFallback(
                'fatal-handler-fallback',
                'error handler itself failed (code ' . $ex->getCode() . '): ' . $ex->getMessage()
            );
        }
    }

    /**
     * @param array<string,mixed> $lasterror
     * @return array<string,mixed>
     */
    private function truncateLargeMessage(array $lasterror): array {
        if (isset($lasterror['message']) && is_string($lasterror['message'])
            && strlen($lasterror['message']) > 8192) {
            $lasterror['message'] = substr($lasterror['message'], 0, 8192)
                . '... (truncated; original length ' . strlen($lasterror['message']) . ' bytes)';
        }
        return $lasterror;
    }

    /** @return string */
    private function pluginFolder(): string {
        $slashPos = strpos(ABJ404_NAME, '/');
        $pluginFolder = substr(ABJ404_NAME, 0, ($slashPos !== false ? $slashPos : strlen(ABJ404_NAME)));
        return is_string($pluginFolder) ? $pluginFolder : (string)ABJ404_NAME;
    }

    /**
     * Whether the fatal occurred in one of this plugin's files. Pure string
     * containment on the existing plugin-folder marker, so it is safe to call
     * during an OOM shutdown.
     *
     * @param array<string,mixed> $lasterror
     * @return bool
     */
    private function isPluginScopeFatal(array $lasterror): bool {
        $errfile = isset($lasterror['file']) && is_string($lasterror['file']) ? $lasterror['file'] : '';
        if ($errfile === '') {
            return false;
        }
        return strpos($errfile, $this->pluginFolder()) !== false;
    }

    /**
     * Write a crash beacon for a plugin-scope fatal using ONLY the path
     * precomputed at healthy boot (ABJ_404_Solution_ErrorHandler::precomputeCrashBeaconPath)
     * plus primitives. No wp_upload_dir()/options/container/clock calls here:
     * this runs in the fatal handler where memory may be exhausted, so it must
     * not re-enter the failure class it is reporting. Best effort; never throws.
     *
     * @param array<string,mixed> $lasterror
     * @return void
     */
    private function captureCrashBeacon(array $lasterror): void {
        try {
            // Guarantee headroom for the post-OOM beacon write. Raising
            // memory_limit inside the shutdown handler is honored by PHP even
            // after a memory-exhaustion fatal, so the tiny json_encode/fwrite
            // below cannot itself fail for want of memory (the exact case the
            // beacon exists to capture). ONLY ever raise (current limit + a
            // small fixed margin); never lower, and leave an unlimited (-1) or
            // unparseable limit untouched -- so this can never shrink a healthy
            // request's budget. Bounded (not unlimited) so a constrained or
            // shared host is never pushed into an OS-level OOM kill. Complements
            // the released memory reserve.
            $currentLimitBytes = $this->currentMemoryLimitBytes();
            if ($currentLimitBytes > 0) {
                @ini_set('memory_limit', (string) ($currentLimitBytes + (8 * 1024 * 1024)));
            }

            $path = isset($GLOBALS['abj404_crash_beacon_path']) && is_string($GLOBALS['abj404_crash_beacon_path'])
                ? $GLOBALS['abj404_crash_beacon_path'] : '';
            if ($path === '') {
                return;
            }
            $version = defined('ABJ404_VERSION') ? (string)ABJ404_VERSION : '';
            $pluginRoot = defined('ABJ404_PATH') ? (string)ABJ404_PATH : '';
            // Clock service for the informational capture timestamp. Safe during
            // shutdown: the clock has no settings/logging/uploads dependencies
            // (the re-entry classes this capture avoids) and is near-certainly
            // already resolved this request; the surrounding try/catch makes the
            // whole capture best-effort if it is somehow unavailable.
            $now = abj_clock()->now();
            $beacon = ABJ_404_Solution_CrashBeacon::fromLastError($lasterror, $version, $pluginRoot, $now);
            $store = new ABJ_404_Solution_CrashBeaconStore($path);
            $store->recordIfAbsent($beacon);
        } catch (\Throwable $e) {
            abj404_logPhpFallback('crash-beacon-capture', 'capture failed: ' . $e->getMessage());
        }
    }

    /**
     * Current PHP memory_limit in bytes: -1 for unlimited, 0 when unset or
     * unparseable, otherwise the positive byte count. Minimal and
     * dependency-free (no WordPress helpers) so it is safe to call from inside
     * the fatal handler after a memory-exhaustion fatal.
     *
     * @return int
     */
    private function currentMemoryLimitBytes(): int {
        $raw = trim((string) @ini_get('memory_limit'));
        if ($raw === '') {
            return 0;
        }
        if ($raw === '-1') {
            return -1;
        }
        $value = (int) $raw;
        switch (strtoupper(substr($raw, -1))) {
            case 'G': $value *= 1024 * 1024 * 1024; break;
            case 'M': $value *= 1024 * 1024; break;
            case 'K': $value *= 1024; break;
        }
        return $value > 0 ? $value : 0;
    }
}
