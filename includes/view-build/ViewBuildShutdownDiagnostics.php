<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shutdown-time post-mortem diagnostics for interrupted staged builds.
 *
 * Registers one shutdown callback per PHP process and, when the process dies
 * with a stage still open, emits a warning that names the open stage, the last
 * completed stage, and the last PHP fatal context available from error_get_last.
 *
 * @property ABJ_404_Solution_Logging $logger
 */
class ABJ_404_Solution_ViewBuildShutdownDiagnostics extends ABJ_404_Solution_ViewBuildCollaborator {

    /** @var bool Process-local guard so shutdown diagnostics register once. */
    private static $viewBuildShutdownLoggerRegistered = false;

    /** @var ABJ_404_Solution_ViewBuildStageRuntimeState */
    private $runtimeState;
    /** @var callable|null */
    private $shutdownRegistrar;
    /** @var callable|null */
    private $lastErrorProvider;

    /**
     * @param object $host Explicit context or test double exposing the same methods.
     * @phpstan-param ABJ_404_Solution_ViewBuildCollaborationContext $host
     * @param ABJ_404_Solution_ViewBuildStageRuntimeState $runtimeState
     * @param callable|null $shutdownRegistrar Test seam for register_shutdown_function.
     * @param callable|null $lastErrorProvider Test seam for error_get_last.
     */
    public function __construct(
        $host,
        ABJ_404_Solution_ViewBuildStageRuntimeState $runtimeState,
        $shutdownRegistrar = null,
        $lastErrorProvider = null
    ) {
        parent::__construct($host);
        $this->runtimeState = $runtimeState;
        $this->shutdownRegistrar = is_callable($shutdownRegistrar) ? $shutdownRegistrar : null;
        $this->lastErrorProvider = is_callable($lastErrorProvider) ? $lastErrorProvider : null;
    }

    /** @return void */
    public static function resetViewBuildShutdownLoggerRegistration(): void {
        self::$viewBuildShutdownLoggerRegistered = false;
    }

    /** @return void */
    public function clearViewBuildOpenStageForShutdown(): void {
        $this->runtimeState->clearOpenStageForShutdown();
    }

    /** @return void */
    public function registerViewBuildShutdownDiagnostics(): void {
        if (self::$viewBuildShutdownLoggerRegistered) {
            return;
        }
        if ($this->shutdownRegistrar === null && !function_exists('register_shutdown_function')) {
            return;
        }
        self::$viewBuildShutdownLoggerRegistered = true;
        $callback = function (): void {
            $this->logViewBuildShutdownDiagnostics();
        };
        if ($this->shutdownRegistrar !== null) {
            call_user_func($this->shutdownRegistrar, $callback);
            return;
        }
        register_shutdown_function($callback);
    }

    /** @return void */
    public function logViewBuildShutdownDiagnostics(): void {
        if (!$this->runtimeState->stageOpenForShutdown()) {
            return;
        }
        $stageNumber = $this->runtimeState->shutdownStageNumber() > 0
            ? $this->runtimeState->shutdownStageNumber()
            : $this->host->stageServices()->progressOptions()->readProgressOption('last_started_stage', 0);
        if ($stageNumber <= 0) {
            return;
        }
        $lastCompleted = $this->host->stageServices()->progressOptions()->readProgressOption('last_completed_stage', 0);
        if ($lastCompleted >= $stageNumber) {
            return;
        }

        $this->host->dataBoundary()->logger()->warn(sprintf(
            '[staged] shutdown while build stage %d/11 %s was still open; '
            . 'last_completed_stage=%d; fatal_context=%s',
            $stageNumber,
            $this->runtimeState->shutdownStageKey(),
            $lastCompleted,
            substr($this->lastErrorText(), 0, 240)
        ));
    }

    /** @return string */
    private function lastErrorText(): string {
        $errorText = 'none';
        $lastError = null;
        if ($this->lastErrorProvider !== null) {
            $lastError = call_user_func($this->lastErrorProvider);
        } else if (function_exists('error_get_last')) {
            $lastError = error_get_last();
        }
        if (!is_array($lastError)) {
            return $errorText;
        }

        $message = isset($lastError['message']) && is_scalar($lastError['message'])
            ? (string)$lastError['message'] : '';
        $file = isset($lastError['file']) && is_scalar($lastError['file'])
            ? (string)$lastError['file'] : '';
        $line = isset($lastError['line']) && is_scalar($lastError['line'])
            ? (string)$lastError['line'] : '';
        $errorText = trim($message . ($file !== '' ? ' in ' . $file : '') . ($line !== '' ? ':' . $line : ''));
        return $errorText !== '' ? $errorText : 'error_get_last returned an empty error';
    }
}
