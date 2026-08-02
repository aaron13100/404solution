<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Detects plugin-admin fatal requests, stores the fatal state, and renders the fallback page.
 */
class ABJ_404_Solution_AdminFatalErrorResponder {

    /**
     * Prevent duplicate shutdown fallback output when multiple handlers run.
     *
     * @var bool
     */
    private static $adminFatalPageRendered = false;

    /** @var int Output-buffer level inherited when this responder took control. */
    private $entryOutputBufferLevel;

    public function __construct() {
        $this->entryOutputBufferLevel = ABJ_404_Solution_OutputBufferDrain::currentLevel();
    }

    /**
     * Detect whether the current request is the plugin admin page.
     *
     * @return bool
     */
    public function isPluginAdminPageRequest(): bool {
        if ($this->isCliRequest()) {
            return false;
        }

        $page = $this->getRequestValue('page');
        if ($page === '') {
            return false;
        }

        $pluginPage = defined('ABJ404_PP') ? (string)ABJ404_PP : 'abj404_solution';
        return $page === $pluginPage;
    }

    /**
     * Persist the last admin fatal so we can show a notice on the next request.
     *
     * @param array<string,mixed> $lasterror
     * @return void
     */
    public function stashAdminFatal(array $lasterror): void {
        $payload = array(
            'message' => array_key_exists('message', $lasterror) ? (is_string($lasterror['message']) ? $lasterror['message'] : '') : '',
            'file' => array_key_exists('file', $lasterror) ? (is_string($lasterror['file']) ? $lasterror['file'] : '') : '',
            'line' => array_key_exists('line', $lasterror) ? (is_int($lasterror['line']) ? $lasterror['line'] : 0) : 0,
            'type' => array_key_exists('type', $lasterror) ? (is_int($lasterror['type']) ? $lasterror['type'] : 0) : 0,
            'time' => $this->currentTime(),
            'page' => $this->getRequestValue('page'),
            'subpage' => $this->getRequestValue('subpage'),
        );

        $ttl = defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600;
        if (function_exists('set_transient')) {
            // allow-cache-empty: admin fatal stashes must persist even when PHP omits message/file details from error_get_last().
            set_transient('abj404_admin_fatal', $payload, $ttl);
            return;
        }

        if (function_exists('update_option')) {
            update_option('abj404_admin_fatal_fallback', $payload);
        }
    }

    /**
     * Render a small HTML fallback so fatal admin errors do not become a blank page.
     *
     * @param array<string,mixed> $lasterror
     * @return void
     */
    public function renderAdminFatalFallback(array $lasterror): void {
        if (self::$adminFatalPageRendered) {
            return;
        }
        self::$adminFatalPageRendered = true;

        $canShowDetails = $this->canShowDetails();
        $this->clearOutputBuffersIfAllowed();
        $this->sendFatalHeaders();
        $settingsUrl = $this->settingsUrl();

        $message = array_key_exists('message', $lasterror) ? (is_string($lasterror['message']) ? $lasterror['message'] : 'Fatal error') : 'Fatal error';
        $file = array_key_exists('file', $lasterror) ? (is_string($lasterror['file']) ? $lasterror['file'] : '(unknown file)') : '(unknown file)';
        $line = array_key_exists('line', $lasterror) ? (is_int($lasterror['line']) ? $lasterror['line'] : 0) : 0;

        $templatePath = dirname(__DIR__) . '/html/adminFatalErrorFallback.html';
        $templateContent = file_exists($templatePath) ? file_get_contents($templatePath) : false;
        if (!is_string($templateContent)) {
            echo '404 Solution: fatal error fallback template unavailable.';
            return;
        }

        echo str_replace(
            array('{settings_url}', '{details}'),
            array(
                htmlspecialchars($settingsUrl, ENT_QUOTES, 'UTF-8'),
                $canShowDetails ? $this->renderDetails($message, $file, $line) : '',
            ),
            $templateContent
        );
    }

    /** @return bool */
    private function canShowDetails(): bool {
        try {
            return ABJ_404_Solution_PluginAdminAccessPolicy::currentUserCanAccessPluginAdmin();
        } catch (Throwable $e) {
            abj404_logPhpFallback(
                'fatal-handler-fallback',
                'admin fatal capability detection failed (code ' . $e->getCode() . '): ' . $e->getMessage()
            );
        }

        return false;
    }

    /** @return void */
    private function clearOutputBuffersIfAllowed(): void {
        $shouldManageOb = function_exists('apply_filters')
            ? apply_filters('abj404_should_manage_output_buffer', true, array('source' => 'renderAdminFatalFallback'))
            : true;
        if (!$shouldManageOb) {
            return;
        }

        // Bounded and ownership-scoped: discard only output created after this
        // responder took control. WordPress, PHP, and other-plugin buffers
        // below the captured entry level are inherited and must survive.
        ABJ_404_Solution_OutputBufferDrain::drainTo($this->entryOutputBufferLevel, static function () {
            @ob_end_clean();
        });
    }

    /** @return void */
    private function sendFatalHeaders(): void {
        if (headers_sent()) {
            return;
        }

        if (function_exists('status_header')) {
            status_header(500);
        } elseif (function_exists('http_response_code')) {
            http_response_code(500);
        }
        header('Content-Type: text/html; charset=UTF-8');
    }

    /** @return string */
    private function settingsUrl(): string {
        $settingsUrl = '?page=' . (defined('ABJ404_PP') ? ABJ404_PP : 'abj404_solution') . '&subpage=abj404_options';
        if (function_exists('admin_url')) {
            return admin_url('options-general.php' . $settingsUrl);
        }

        return $settingsUrl;
    }

    /**
     * Best-effort scalar read from request arrays without depending on WP helpers.
     *
     * @param string $key
     * @return string
     */
    private function getRequestValue(string $key): string {
        $raw = null;
        if (array_key_exists($key, $_GET)) {
            $raw = $_GET[$key];
        } elseif (array_key_exists($key, $_POST)) {
            $raw = $_POST[$key];
        } elseif (array_key_exists($key, $_REQUEST)) {
            $raw = $_REQUEST[$key];
        }

        if (!is_scalar($raw)) {
            return '';
        }

        return trim((string)$raw);
    }

    /**
     * @return string
     */
    private function renderDetails(string $message, string $file, int $line): string {
        $templatePath = dirname(__DIR__) . '/html/adminFatalErrorDetails.html';
        $templateContent = file_exists($templatePath) ? file_get_contents($templatePath) : false;
        if (!is_string($templateContent)) {
            return '';
        }

        return str_replace(
            array('{error_details}'),
            array(htmlspecialchars($message . "\n" . $file . ':' . (string)$line, ENT_QUOTES, 'UTF-8')),
            $templateContent
        );
    }

    /** @return int */
    private function currentTime(): int {
        if (class_exists('ABJ_404_Solution_ServiceContainer')) {
            $clock = ABJ_404_Solution_ServiceContainer::safeGet('clock');
            if (is_object($clock) && method_exists($clock, 'now')) {
                try {
                    return (int)$clock->now();
                } catch (Throwable $e) {
                    abj404_logPhpFallback(
                        'fatal-handler-fallback',
                        'admin fatal clock lookup failed (code ' . $e->getCode() . '): ' . $e->getMessage()
                    );
                }
            }
        }

        return abj_clock()->now();
    }

    /** @return bool */
    private function isCliRequest(): bool {
        if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
            return false;
        }

        // Real extension point: unusual hosts and test harnesses may need to
        // exercise wp-admin request handling while PHP reports a CLI-like SAPI.
        $allowAdminFatalDetection = function_exists('apply_filters')
            ? apply_filters('abj404_error_handler_allow_admin_fatal_detection_in_cli', false)
            : false;
        return !$allowAdminFatalDetection;
    }
}
