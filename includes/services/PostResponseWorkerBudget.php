<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hard wall-clock deadline for instrumented AJAX work after a response detaches.
 *
 * LiteSpeed and FastCGI finish-request functions release the client connection,
 * but PHP continues running WordPress and raw PHP shutdown callbacks in the same
 * worker. On hosts with pcntl, SIGALRM is the only in-process mechanism that can
 * interrupt foreign code which neither cooperates with a deadline nor returns.
 */
final class ABJ_404_Solution_PostResponseWorkerBudget {

    public const BUDGET_SECONDS = 2;

    /** @var bool */
    private static $armed = false;

    /** @var string */
    private static $requestId = '';

    /**
     * Report whether this process exposes every primitive needed to arm the
     * OS-backed post-response deadline.
     */
    public static function isSupported(): bool {
        return defined('SIGALRM')
            && function_exists('pcntl_async_signals')
            && function_exists('pcntl_signal')
            && function_exists('pcntl_alarm');
    }

    /**
     * Arm the deadline once a response has successfully detached.
     *
     * @return bool True when an OS-backed wall-clock deadline was installed.
     */
    public static function arm(string $requestId): bool {
        if (self::$armed || $requestId === '') {
            return self::$armed;
        }
        if (!self::isSupported()) {
            self::record($requestId, 'post_response_worker_budget_unavailable', array(
                'budget_seconds' => self::BUDGET_SECONDS,
                'reason' => 'pcntl_alarm_unavailable',
            ));
            return false;
        }

        try {
            pcntl_async_signals(true);
            if (!pcntl_signal(SIGALRM, array(__CLASS__, 'expire'))) {
                self::record($requestId, 'post_response_worker_budget_unavailable', array(
                    'budget_seconds' => self::BUDGET_SECONDS,
                    'reason' => 'pcntl_signal_registration_failed',
                ));
                return false;
            }

            // Do not extend a deadline another component already installed.
            $priorAlarmSeconds = pcntl_alarm(0);
            $budgetSeconds = $priorAlarmSeconds > 0
                ? min(self::BUDGET_SECONDS, $priorAlarmSeconds)
                : self::BUDGET_SECONDS;
            self::$requestId = $requestId;
            self::$armed = true;
            // Registered at the detach boundary, after WordPress and all
            // request-time raw shutdown callbacks. A normal request reaches
            // this last and cancels the process-global alarm before LSAPI
            // reuses the worker; a stuck earlier callback never reaches it.
            register_shutdown_function(array(__CLASS__, 'complete'));
            pcntl_alarm($budgetSeconds);
            self::record($requestId, 'post_response_worker_budget_armed', array(
                'budget_seconds' => $budgetSeconds,
                'configured_budget_seconds' => self::BUDGET_SECONDS,
                'prior_alarm_seconds' => $priorAlarmSeconds,
                'mechanism' => 'pcntl_sigalrm',
            ));
            return true;
        } catch (\Throwable $e) {
            self::$armed = false;
            self::$requestId = '';
            self::reportFailure('Could not arm post-response worker budget', $e);
            self::record($requestId, 'post_response_worker_budget_unavailable', array(
                'budget_seconds' => self::BUDGET_SECONDS,
                'reason' => 'pcntl_exception',
                'exception_class' => get_class($e),
                'exception_code' => $e->getCode(),
                'exception_message' => $e->getMessage(),
            ));
            return false;
        }
    }

    /**
     * SIGALRM handler. Exiting here stops every remaining WordPress, plugin,
     * extension, destructor, and raw PHP shutdown callback in this worker.
     */
    public static function expire(int $signal): void {
        $message = '[404 Solution] Post-response worker budget exhausted; terminating detached request'
            . ' request_id=' . self::$requestId
            . ' budget_seconds=' . self::BUDGET_SECONDS
            . ' signal=' . $signal;
        // Same terminal-handler sink as every other last-resort path: this
        // runs inside a signal handler that is about to end the process, so
        // plugin logging may already be torn down.
        abj404_logPhpFallback('fatal-handler-fallback', $message);
        exit(0);
    }

    /** Cancel the process-global alarm after every earlier callback returned. */
    public static function complete(): void {
        if (!self::$armed) {
            return;
        }
        $remainingSeconds = function_exists('pcntl_alarm') ? pcntl_alarm(0) : 0;
        $requestId = self::$requestId;
        self::$armed = false;
        self::$requestId = '';
        self::record($requestId, 'post_response_worker_budget_completed', array(
            'remaining_seconds' => $remainingSeconds,
            'mechanism' => 'pcntl_sigalrm',
        ));
    }

    /** @param array<string,mixed> $fields */
    private static function record(string $requestId, string $event, array $fields): void {
        if (class_exists('ABJ_404_Solution_AjaxCheckpointLogger', false)) {
            // The completion sentinel runs at the very end of PHP shutdown;
            // use the lightweight writer so it does not invoke full-envelope
            // samplers whose dependencies may already have torn down.
            ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent($requestId, $event, $fields);
        }
    }

    private static function reportFailure(string $context, \Throwable $error): void {
        abj404_logPhpFallback('fatal-handler-fallback',
            '[404 Solution] ' . $context . ': ' . get_class($error)
            . ' code=' . $error->getCode() . ' message=' . $error->getMessage());
    }
}
