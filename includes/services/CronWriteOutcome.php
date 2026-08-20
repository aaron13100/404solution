<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decides what a WordPress cron write's reported result actually means, and
 * says so.
 *
 * The write-side twin of {@see ABJ_404_Solution_ScheduledEventInspector}, and
 * it exists for the same reason: WordPress's cron primitives report failure for
 * outcomes that are not failures. Three of them have reached production:
 *
 *   - `duplicate_event`, which wp_schedule_single_event() returns only after
 *     finding an event it considers equivalent to the requested one, i.e. only
 *     when the hook IS scheduled (report 284, signature b2c064ec15425cf2);
 *   - `could_not_set` from a write that changed nothing because a concurrent
 *     request stored the identical event first (report 276, signature
 *     87a00a2680c1bc07);
 *   - the same `could_not_set` from wp_unschedule_event() for an event another
 *     request had already removed.
 *
 * Believing any of them costs the plugin author an emailed ERROR report about
 * work that was already done, so every refusal is settled here before anything
 * is logged: benign ones leave a debug breadcrumb and report success, and only
 * a genuinely unmet request produces the diagnostic line.
 *
 * This class owns no cron writes and no scheduling policy; it is given what a
 * primitive returned and answers for it.
 */
class ABJ_404_Solution_CronWriteOutcome {

    /**
     * WordPress's code for "I did not write this because I already hold an
     * equivalent event" (wp_schedule_single_event(), wp-includes/cron.php).
     */
    const WP_ERROR_DUPLICATE_EVENT = 'duplicate_event';

    /** Shared reason text for a write another request had already performed. */
    const SATISFIED_BY_CONCURRENT_WRITE =
        'the cron store already holds the requested state (a concurrent request wrote it first)';

    /** @var ABJ_404_Solution_Logging|null */
    private $logger;

    /** @var ABJ_404_Solution_ScheduledEventInspector Read side of the cron store. */
    private $inspector;

    /** @var string */
    private $lastFailureDetail = '';

    /**
     * @param ABJ_404_Solution_Logging|null $logger
     * @param ABJ_404_Solution_ScheduledEventInspector $inspector
     */
    public function __construct($logger, ABJ_404_Solution_ScheduledEventInspector $inspector) {
        $this->logger = $logger;
        $this->inspector = $inspector;
    }

    /** @return string */
    public function lastFailureDetail(): string {
        return $this->lastFailureDetail;
    }

    /**
     * Whether a cron primitive's return value is WordPress claiming failure.
     * Whether that claim is true is what the resolve* methods below decide.
     *
     * @param mixed $result
     */
    public function reportsFailure($result): bool {
        return $result === false || $this->isWpError($result);
    }

    /**
     * Settle a refused wp_schedule_single_event() write: true when the caller's
     * request holds regardless, false (reported) when it genuinely does not.
     *
     * WordPress answers this itself whenever it can. `duplicate_event` is not a
     * failure report at all -- core returns it only after scanning its own cron
     * store and finding an equivalent event, so the hook is scheduled and core
     * has already done the check. Re-deriving that verdict from a second read
     * is what produced report 284: the plugin's copy of the duplicate window
     * disagreed with the one core had just applied.
     *
     * Builds older than WordPress 5.7 ignore the $wp_error argument and return
     * a bare false with no code to read, so the store inspection stays as the
     * fallback for them (and for the no-op option write of report 276).
     *
     * @param array{writeResult: mixed, hook: string, args: array<int, mixed>, timestamp: int, now: int} $request
     */
    public function resolveSingleWrite(array $request): bool {
        $writeResult = $request['writeResult'];
        $hook = $request['hook'];
        $args = $request['args'];
        $timestamp = $request['timestamp'];
        $now = $request['now'];

        if ($this->wpErrorCode($writeResult) === self::WP_ERROR_DUPLICATE_EVENT) {
            return $this->reportAlreadySatisfied(array(
                'type' => 'single',
                'hook' => $hook,
                'timestamp' => $timestamp,
                'reason' => 'WordPress refused it as a duplicate, which means its cron store already holds an '
                    . 'equivalent event for the hook',
            ));
        }
        $inspectionFailure = '';
        try {
            $this->inspector->refreshCronStoreReads();
            $stored = $this->inspector->requestedEventIsStored(array(
                'hook' => $hook,
                'args' => $args,
                'timestamp' => $timestamp,
                'recurrence' => null,
                'now' => $now,
            ));
        } catch (Throwable $e) {
            $stored = false;
            $inspectionFailure = $this->inspectionFailureDetail($e);
        }
        if ($stored) {
            return $this->reportAlreadySatisfied(array(
                'type' => 'single',
                'hook' => $hook,
                'timestamp' => $timestamp,
                'reason' => self::SATISFIED_BY_CONCURRENT_WRITE,
            ));
        }
        $this->reportScheduleFailure(array(
            'type' => 'single',
            'hook' => $hook,
            'recurrence' => null,
            'timestamp' => $timestamp,
            'args' => $args,
            'errorCode' => $this->wpErrorCode($writeResult) ?: 'cron_write_returned_false',
            'detail' => $this->writeFailureDetail($writeResult, 'wp_schedule_single_event') . $inspectionFailure,
            'now' => $now,
        ));
        return false;
    }

    /**
     * Settle a refused wp_schedule_event() write. wp_schedule_event() has no
     * duplicate check of its own, so only the store can answer here.
     *
     * @param array{writeResult: mixed, hook: string, recurrence: string, args: array<int, mixed>, timestamp: int, now: int} $request
     */
    public function resolveRecurringWrite(array $request): bool {
        $writeResult = $request['writeResult'];
        $hook = $request['hook'];
        $recurrence = $request['recurrence'];
        $args = $request['args'];
        $timestamp = $request['timestamp'];
        $now = $request['now'];

        $inspectionFailure = '';
        try {
            $this->inspector->refreshCronStoreReads();
            $stored = $this->inspector->requestedEventIsStored(array(
                'hook' => $hook,
                'args' => $args,
                'timestamp' => $timestamp,
                'recurrence' => $recurrence,
                'now' => $now,
            ));
        } catch (Throwable $e) {
            $stored = false;
            $inspectionFailure = $this->inspectionFailureDetail($e);
        }
        if ($stored) {
            return $this->reportAlreadySatisfied(array(
                'type' => 'recurring',
                'hook' => $hook,
                'timestamp' => $timestamp,
                'reason' => self::SATISFIED_BY_CONCURRENT_WRITE,
            ));
        }
        $this->reportScheduleFailure(array(
            'type' => 'recurring',
            'hook' => $hook,
            'recurrence' => $recurrence,
            'timestamp' => $timestamp,
            'args' => $args,
            'errorCode' => $this->wpErrorCode($writeResult) ?: 'cron_write_returned_false',
            'detail' => $this->writeFailureDetail($writeResult, 'wp_schedule_event') . $inspectionFailure,
            'now' => $now,
        ));
        return false;
    }

    /**
     * Settle a refused wp_unschedule_event() write. Removal is reported at
     * warning level rather than error: the plugin keeps working with a stale
     * event scheduled, so this is not something to mail anyone about.
     *
     * @param array{writeResult: mixed, hook: string, args: array<int, mixed>, timestamp: int} $request
     */
    public function resolveRemoval(array $request): bool {
        $writeResult = $request['writeResult'];
        $hook = $request['hook'];
        $args = $request['args'];
        $timestamp = $request['timestamp'];

        $inspectionFailure = '';
        try {
            $this->inspector->refreshCronStoreReads();
            $absent = $this->inspector->requestedEventIsAbsent(array(
                'hook' => $hook,
                'args' => $args,
                'timestamp' => $timestamp,
            ));
        } catch (Throwable $e) {
            $absent = false;
            $inspectionFailure = $this->inspectionFailureDetail($e);
        }
        if ($absent) {
            return $this->reportAlreadySatisfied(array(
                'type' => 'removal of',
                'hook' => $hook,
                'timestamp' => $timestamp,
                'reason' => 'the event is already gone (a concurrent request removed it first)',
            ));
        }
        $errorCode = $this->wpErrorCode($writeResult) ?: 'cron_removal_returned_false';
        $this->lastFailureDetail = $this->writeFailureDetail($writeResult, 'wp_unschedule_event')
            . $inspectionFailure;
        $this->warn('Failed to unschedule cron hook ' . $hook . ' at timestamp ' . $timestamp
            . '. Error code: ' . $errorCode . '. Detail: ' . $this->lastFailureDetail
            . '. Recovery: inspect filters on wp_unschedule_event and the WordPress cron option, then retry.');
        return false;
    }

    /**
     * Report a removal that a WordPress build reported no status for and that
     * the cron store shows still scheduled afterwards.
     */
    public function reportRemovalNotVerified(string $hook, int $timestamp): bool {
        $this->lastFailureDetail = 'event remained scheduled after wp_unschedule_event returned no status';
        $this->warn('Failed to verify cron hook removal for ' . $hook . ' at timestamp ' . $timestamp
            . '. Detail: ' . $this->lastFailureDetail
            . '. Recovery: inspect filters on wp_unschedule_event and the WordPress cron option, then retry.');
        return false;
    }

    /**
     * Report a cron primitive that this WordPress build does not provide, which
     * is the one failure mode no amount of re-reading the store can excuse.
     *
     * @param array{verb: string, hook: string, primitive: string} $failure
     */
    public function reportUnavailablePrimitive(array $failure): void {
        $verb = $failure['verb'];
        $hook = $failure['hook'];
        $primitive = $failure['primitive'];
        $this->lastFailureDetail = $primitive . ' unavailable';
        $this->warn('Cannot ' . $verb . ' cron hook ' . $hook . ': ' . $primitive
            . ' unavailable. Error code: cron_primitive_unavailable. Recovery: verify the WordPress core '
            . 'cron files are complete and the function is available, then retry.');
    }

    /** Preserve a cron-read exception as part of the write failure evidence. */
    private function inspectionFailureDetail(Throwable $error): string {
        return '; cron store verification failed (' . get_class($error) . ', code '
            . $error->getCode() . '): ' . $error->getMessage();
    }

    /**
     * Report a scheduling request that was genuinely not met, with everything
     * needed to tell a hosting problem from a plugin one: what was asked for,
     * when, against which clock, whether WP-Cron is even enabled on this site,
     * and whether the database said anything.
     *
     * @param array{type: string, hook: string, recurrence: string|null, timestamp: int, args: array<int, mixed>, errorCode: string, detail: string, now: int} $failure
     * @return void
     */
    public function reportScheduleFailure(array $failure): void {
        $type = $failure['type'];
        $hook = $failure['hook'];
        $recurrence = $failure['recurrence'];
        $timestamp = $failure['timestamp'];
        $args = $failure['args'];
        $errorCode = $failure['errorCode'];
        $detail = trim($failure['detail']);
        $now = $failure['now'];
        if ($detail === '') {
            $detail = 'cron primitive returned false without a WP_Error message';
        }
        $this->lastFailureDetail = $detail;
        global $wpdb;
        $dbError = isset($wpdb) && isset($wpdb->last_error) && is_string($wpdb->last_error) && $wpdb->last_error !== ''
            ? $wpdb->last_error
            : 'none';
        $argsJson = json_encode($args);
        $argsText = is_string($argsJson) ? $argsJson : 'unencodable';
        $this->error(sprintf(
            'Failed to schedule %s cron hook %s. Recurrence: %s, timestamp: %d, current: %d, args: %s, '
                . 'WP-Cron disabled: %s, DB error: %s, error code: %s, detail: %s. '
                . 'Recovery: inspect the named WP-Cron and database errors, then retry the schedule.',
            $type,
            $hook,
            $recurrence ?? 'single',
            $timestamp,
            $now,
            $argsText,
            (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) ? 'yes' : 'no',
            $dbError,
            $errorCode,
            $detail
        ));
    }

    /**
     * Record that a write WordPress reported as failed had in fact already been
     * satisfied, and report success. Debug level on purpose: nothing is wrong,
     * nothing needs doing, and the line exists only so the race stays traceable
     * in a debug log.
     *
     * @param array{type: string, hook: string, timestamp: int, reason: string} $outcome
     */
    private function reportAlreadySatisfied(array $outcome): bool {
        $this->lastFailureDetail = '';
        $this->debug(sprintf(
            'WordPress reported the %s cron write for %s at timestamp %d as failed, but %s. '
                . 'Treating as scheduled.',
            $outcome['type'],
            $outcome['hook'],
            $outcome['timestamp'],
            $outcome['reason']
        ));
        return true;
    }

    /** @param mixed $value */
    private function isWpError($value): bool {
        return function_exists('is_wp_error') && is_wp_error($value);
    }

    /**
     * The WP_Error code a cron primitive refused with, or '' when it gave none
     * (a bare false, or a WordPress older than 5.7 ignoring $wp_error).
     *
     * @param mixed $value
     */
    private function wpErrorCode($value): string {
        if ($this->isWpError($value) && is_object($value) && method_exists($value, 'get_error_code')) {
            $code = $value->get_error_code();
            return is_scalar($code) ? (string)$code : '';
        }
        return '';
    }

    /** @param mixed $value */
    private function wpErrorMessage($value): string {
        if ($this->isWpError($value) && is_object($value) && method_exists($value, 'get_error_message')) {
            $message = $value->get_error_message();
            return is_string($message) ? $message : '';
        }
        return '';
    }

    /** @param mixed $writeResult */
    private function writeFailureDetail($writeResult, string $primitive): string {
        $message = $this->wpErrorMessage($writeResult);
        return $message !== '' ? $message : $primitive . ' returned false without a WP_Error message';
    }

    private function error(string $message): void {
        if ($this->logger !== null && method_exists($this->logger, 'errorMessage')) {
            $this->logger->errorMessage($message);
            return;
        }
        abj404_logPhpFallback('service-resolution-fallback', $message);
    }

    private function debug(string $message): void {
        if ($this->logger !== null && method_exists($this->logger, 'debugMessage')) {
            $this->logger->debugMessage($message);
        }
    }

    private function warn(string $message): void {
        if ($this->logger !== null && method_exists($this->logger, 'warn')) {
            $this->logger->warn($message);
            return;
        }
        abj404_logPhpFallback('service-resolution-fallback', $message);
    }
}
