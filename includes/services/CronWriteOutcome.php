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
     * @param bool|WP_Error|null $result What wp_schedule_single_event() returned.
     * @param array<int, mixed> $args
     * @param int $now The clock $timestamp was measured against.
     */
    public function resolveSingleWrite($result, string $hook, array $args, int $timestamp, int $now): bool {
        if ($this->wpErrorCode($result) === self::WP_ERROR_DUPLICATE_EVENT) {
            return $this->reportAlreadySatisfied(
                'single',
                $hook,
                $timestamp,
                'WordPress refused it as a duplicate, which means its cron store already holds an '
                    . 'equivalent event for the hook'
            );
        }
        if ($this->inspector->requestedEventIsStored($hook, $args, $timestamp, null, $now)) {
            return $this->reportAlreadySatisfied('single', $hook, $timestamp, self::SATISFIED_BY_CONCURRENT_WRITE);
        }
        $this->reportScheduleFailure('single', $hook, null, $timestamp, $args, $this->wpErrorMessage($result), $now);
        return false;
    }

    /**
     * Settle a refused wp_schedule_event() write. wp_schedule_event() has no
     * duplicate check of its own, so only the store can answer here.
     *
     * @param bool|WP_Error|null $result
     * @param array<int, mixed> $args
     * @param int $now The clock $timestamp was measured against.
     */
    public function resolveRecurringWrite(
        $result,
        string $hook,
        string $recurrence,
        array $args,
        int $timestamp,
        int $now
    ): bool {
        if ($this->inspector->requestedEventIsStored($hook, $args, $timestamp, $recurrence, $now)) {
            return $this->reportAlreadySatisfied('recurring', $hook, $timestamp, self::SATISFIED_BY_CONCURRENT_WRITE);
        }
        $this->reportScheduleFailure(
            'recurring',
            $hook,
            $recurrence,
            $timestamp,
            $args,
            $this->wpErrorMessage($result),
            $now
        );
        return false;
    }

    /**
     * Settle a refused wp_unschedule_event() write. Removal is reported at
     * warning level rather than error: the plugin keeps working with a stale
     * event scheduled, so this is not something to mail anyone about.
     *
     * @param bool|WP_Error|null $result
     * @param array<int, mixed> $args
     */
    public function resolveRemoval($result, string $hook, array $args, int $timestamp): bool {
        if ($this->inspector->requestedEventIsAbsent($hook, $args, $timestamp)) {
            return $this->reportAlreadySatisfied(
                'removal of',
                $hook,
                $timestamp,
                'the event is already gone (a concurrent request removed it first)'
            );
        }
        $errorMessage = $this->wpErrorMessage($result);
        $this->lastFailureDetail = $errorMessage !== '' ? $errorMessage : 'wp_unschedule_event returned false';
        $this->warn('Failed to unschedule cron hook ' . $hook . ' at timestamp ' . $timestamp
            . '. Detail: ' . $this->lastFailureDetail);
        return false;
    }

    /**
     * Report a removal that a WordPress build reported no status for and that
     * the cron store shows still scheduled afterwards.
     */
    public function reportRemovalNotVerified(string $hook, int $timestamp): bool {
        $this->lastFailureDetail = 'event remained scheduled after wp_unschedule_event returned no status';
        $this->warn('Failed to verify cron hook removal for ' . $hook . ' at timestamp ' . $timestamp . '.');
        return false;
    }

    /**
     * Report a cron primitive that this WordPress build does not provide, which
     * is the one failure mode no amount of re-reading the store can excuse.
     *
     * @param string $verb What the caller was trying to do ('unschedule', 'clear').
     */
    public function reportUnavailablePrimitive(string $verb, string $hook, string $primitive): void {
        $this->lastFailureDetail = $primitive . ' unavailable';
        $this->warn('Cannot ' . $verb . ' cron hook ' . $hook . ': ' . $primitive . ' unavailable.');
    }

    /**
     * Report a scheduling request that was genuinely not met, with everything
     * needed to tell a hosting problem from a plugin one: what was asked for,
     * when, against which clock, whether WP-Cron is even enabled on this site,
     * and whether the database said anything.
     *
     * @param string $type 'single' or 'recurring'.
     * @param array<int, mixed> $args
     * @param int $now The clock $timestamp was measured against.
     * @return void
     */
    public function reportScheduleFailure(
        string $type,
        string $hook,
        ?string $recurrence,
        int $timestamp,
        array $args,
        string $detail,
        int $now
    ): void {
        $this->lastFailureDetail = $detail;
        global $wpdb;
        $dbError = isset($wpdb) && isset($wpdb->last_error) && is_string($wpdb->last_error) && $wpdb->last_error !== ''
            ? $wpdb->last_error
            : 'none';
        $argsJson = json_encode($args);
        $argsText = is_string($argsJson) ? $argsJson : 'unencodable';
        $this->error(sprintf(
            'Failed to schedule %s cron hook %s. Recurrence: %s, timestamp: %d, current: %d, args: %s, '
                . 'WP-Cron disabled: %s, DB error: %s, detail: %s',
            $type,
            $hook,
            $recurrence ?? 'single',
            $timestamp,
            $now,
            $argsText,
            (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) ? 'yes' : 'no',
            $dbError,
            $detail
        ));
    }

    /**
     * Record that a write WordPress reported as failed had in fact already been
     * satisfied, and report success. Debug level on purpose: nothing is wrong,
     * nothing needs doing, and the line exists only so the race stays traceable
     * in a debug log.
     *
     * @param string $type 'single', 'recurring' or 'removal of'.
     * @param string $reason Why the request holds anyway, so a debug log says
     *   which of the several benign refusals this was.
     */
    private function reportAlreadySatisfied(string $type, string $hook, int $timestamp, string $reason): bool {
        $this->lastFailureDetail = '';
        $this->debug(sprintf(
            'WordPress reported the %s cron write for %s at timestamp %d as failed, but %s. '
                . 'Treating as scheduled.',
            $type,
            $hook,
            $timestamp,
            $reason
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
            return is_string($code) ? $code : '';
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
