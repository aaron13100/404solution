<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Records what happens to the PHP process AFTER an AJAX response is logically
 * complete.
 *
 * This is a different job from journaling a request's stages, and it is the one
 * that answers the question the timeout investigation actually turns on. Beta.1
 * proved the gap: its teardown recorder no-op'd once finish() had run, so a slow
 * sibling shutdown hook, or a lingering client abort that stalled the worker
 * after the response was handed off, produced zero evidence. Report 193 then
 * showed the shape that gap was hiding -- four workers alive 121-198 seconds
 * after their handlers had returned in 1.3-4.7s.
 *
 * So a recorder writes unconditionally, from every sentinel, and states
 * already_finished and elapsed_since_response_emitted_ms rather than declining
 * to write. Two mechanisms are watched because either can be skipped: callbacks
 * armed with register_shutdown_function() run BELOW WordPress and survive a
 * WordPress shutdown that never fires, while callbacks on the WordPress
 * 'shutdown' action can bracket the other plugins' hooks that a PHP-level
 * sentinel cannot see. ABJ_404_Solution_ShutdownTeardownBracket splits the time
 * between them.
 *
 * ABJ_404_Solution_AjaxRequestTrace owns the request's own lifecycle and hands
 * over a snapshot of it per call; this class owns the post-response record, the
 * bracket, and the once-per-rotation environment inventory. It never calls back
 * into the trace, so the trace can change how it journals stages without this
 * changing at all -- which is the point, because the post-response window is
 * where the remaining fixes are going.
 */
final class ABJ_404_Solution_AjaxTeardownRecorder {

    /** Teardown sentinel armed with register_shutdown_function(): runs BELOW WordPress. */
    const MECHANISM_SHUTDOWN_FUNCTION = 'php_shutdown_function';
    /** Teardown sentinel armed as a WordPress 'shutdown' action callback. */
    const MECHANISM_WP_ACTION = 'wp_shutdown_action';
    /** Sentinel registered when the AJAX handler was entered. */
    const ARMED_HANDLER_ENTRY = 'handler_entry';
    /** Sentinel registered when the response was emitted, in finish(). */
    const ARMED_RESPONSE_TIME = 'response_time';

    const SHUTDOWN_INVENTORY_MARKER = 'abj404_ajax_shutdown_inventory.marker';

    /**
     * Guards the one-time-per-rotation $wp_filter['shutdown'] inventory.
     * Keyed by trace directory (not a single scalar) so unrelated trace
     * directories -- distinct sites, or distinct tests in the same worker
     * process -- never share a dedup decision.
     * @var array<string, string>
     */
    private static $inventoryCapturedForRotation = array();

    /** @var ABJ_404_Solution_Clock */
    private $clock;
    /** @var string */
    private $directory;
    /** @var ABJ_404_Solution_AjaxTraceJournal */
    private $journal;
    /** @var ABJ_404_Solution_ShutdownTeardownBracket Splits shutdown time into WordPress-action vs below-WordPress. */
    private $bracket;
    /**
     * True once this recorder has been retired by the test harness. Checked by
     * record() so a sentinel PHP will still invoke at process exit --
     * register_shutdown_function() cannot be unregistered -- writes nothing.
     * Never set in production.
     * @var bool
     */
    private $disarmed = false;

    public function __construct(ABJ_404_Solution_Clock $clock, string $directory,
            ABJ_404_Solution_AjaxTraceJournal $journal) {
        $this->clock = $clock;
        $this->directory = $directory;
        $this->journal = $journal;
        $this->bracket = new ABJ_404_Solution_ShutdownTeardownBracket();
    }

    /** Open the WordPress-shutdown-action bracket. */
    public function noteWpActionStart(float $now): void {
        $this->bracket->noteWpActionStart($now);
    }

    /** Close the WordPress-shutdown-action bracket. */
    public function noteWpActionEnd(float $now): void {
        $this->bracket->noteWpActionEnd($now);
    }

    /**
     * Retire this recorder: every sentinel that still fires writes nothing.
     * See ABJ_404_Solution_AjaxRequestTrace::disarmTeardownSentinelsForTests().
     */
    public function disarm(): void {
        $this->disarmed = true;
    }

    /**
     * Write one teardown record. Never disarmed by finish() and never throws --
     * a teardown recorder that could itself fatal would defeat its own purpose.
     *
     * @param string $mechanism  One of the MECHANISM_* constants: which of the
     *                           two shutdown mechanisms invoked this sentinel.
     * @param string $armedAt    One of the ARMED_* constants: where the callback
     *                           was registered, which is what fixes its position
     *                           in PHP's registration-ordered shutdown queue.
     * @param array{envelope: array<string, mixed>, request_started_at: float, response_emitted_at: float|null, already_finished: bool, current_stage: string} $lifecycle
     *   The trace's state at the moment the sentinel fired, passed in rather
     *   than read back, so this class holds no reference to the trace.
     * @return bool whether a record was written. False means the caller must
     *   NOT treat the request as torn down: the recorder was retired, or the
     *   write itself failed, and in both cases the original code left the
     *   trace active so a later sentinel could still try.
     */
    public function record(string $event, string $mechanism, string $armedAt, array $lifecycle): bool {
        if ($this->disarmed) {
            // Retired by the test harness: this recorder's "request" (one
            // PHPUnit test) already ended and its journal directory was deleted
            // with that test, so a flush here could only report a vanished path.
            return false;
        }
        try {
            $lastError = error_get_last();
            $now = $this->clock->nowFloat();
            $responseEmittedAt = $lifecycle['response_emitted_at'] ?? null;
            $record = array_merge(array(
                'event' => $event,
                'elapsed_ms' => max(0, (int)round(($now - $lifecycle['request_started_at']) * 1000)),
                'elapsed_since_response_emitted_ms' => $responseEmittedAt !== null
                    ? max(0, (int)round(($now - $responseEmittedAt) * 1000))
                    : null,
                'already_finished' => $lifecycle['already_finished'],
                'current_stage' => $lifecycle['current_stage'],
                'peak_memory_bytes' => memory_get_peak_usage(true),
                'connection_aborted' => function_exists('connection_aborted') ? connection_aborted() : 0,
                // connection_status() carries the TIMEOUT bit that
                // connection_aborted() cannot express, and session_status()
                // turning ACTIVE between request_start and teardown is the
                // evidence for a session write at shutdown (cause class G).
                'connection_status' => function_exists('connection_status') ? connection_status() : null,
                'session_status' => function_exists('session_status') ? session_status() : null,
                'php_error_type' => is_array($lastError) ? (int)$lastError['type'] : 0,
                'php_error_message' => is_array($lastError) ? substr((string)$lastError['message'], 0, 500) : '',
                'php_error_file' => is_array($lastError) ? (string)$lastError['file'] : '',
                'php_error_line' => is_array($lastError) ? (int)$lastError['line'] : 0,
            ), $this->bracket->attribution(
                $mechanism, $armedAt, $mechanism === self::MECHANISM_SHUTDOWN_FUNCTION, $now));
            $this->journal->append(array_merge($lifecycle['envelope'], $record));
            $this->journal->promote();
            $this->recordEnvironmentInventoryOncePerRotation($lifecycle['envelope']);
            return true;
        } catch (Throwable $e) {
            abj404_logPhpFallback('ajax-trace',
                'AJAX teardown recorder failed (' . $event . '): ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Capture the shutdown-time environment
     * (ABJ_404_Solution_ShutdownEnvironmentInventory) once per journal
     * generation rather than on every request: the roster and extension list
     * are static within a deploy, so per-request capture would only bloat the
     * journal. A marker file records the generation last inventoried, and a
     * same-process static short-circuits repeat requests inside one worker.
     *
     * Called from every teardown sentinel, not just the WordPress-action one:
     * the case this inventory exists to explain (shutdown work that is not a
     * WordPress shutdown-action callback) includes the case where the WordPress
     * shutdown action never runs at all, and an inventory only that action can
     * write would be missing exactly then.
     *
     * @param array<string, mixed> $envelope
     */
    private function recordEnvironmentInventoryOncePerRotation(array $envelope): void {
        $generationKey = $this->journalGenerationKey();
        if ((self::$inventoryCapturedForRotation[$this->directory] ?? null) === $generationKey) {
            return;
        }
        $markerPath = $this->directory . self::SHUTDOWN_INVENTORY_MARKER;
        $existingMarker = @file_get_contents($markerPath);
        if ($existingMarker === $generationKey) {
            self::$inventoryCapturedForRotation[$this->directory] = $generationKey;
            return;
        }
        self::$inventoryCapturedForRotation[$this->directory] = $generationKey;
        $this->journal->append(array_merge($envelope, array(
            'event' => 'shutdown_hook_inventory',
            'rotation_key' => $generationKey,
        ), ABJ_404_Solution_ShutdownEnvironmentInventory::capture()));
        @file_put_contents($markerPath, $generationKey, LOCK_EX);
        // Promote here rather than relying on a later sentinel: the sentinel
        // that writes this may be the last one to run, and an unpromoted spool
        // waits 300 seconds for another request to recover it.
        $this->journal->promote();
    }

    /**
     * Identify which journal the marker is describing.
     *
     * The rotated file's mtime alone is not enough. Before the first rotation
     * it is the constant "never-rotated", and the marker outlives the records:
     * any site whose journal is deleted or truncated before it ever rotates --
     * a host wiping uploads/temp, a log cleanup, a support-bundle reset --
     * keeps a marker that matches forever, so no further inventory is ever
     * written. Every support bundle collected afterwards then arrives without a
     * shutdown_hook_inventory, which is the one record that names WHICH
     * plugin's shutdown callback held the worker.
     *
     * Adding the live journal's inode makes a replaced journal a new
     * generation, because a deleted-and-recreated file is a different file.
     * Called after this request's own append, so the live journal exists and
     * the key is stable for the rest of the generation.
     */
    private function journalGenerationKey(): string {
        $rotationMtime = @filemtime($this->directory . ABJ_404_Solution_AjaxTraceJournal::ROTATED_FILE);
        $liveInode = @fileinode($this->directory . ABJ_404_Solution_AjaxTraceJournal::JOURNAL_FILE);
        return (is_int($rotationMtime) ? (string)$rotationMtime : 'never-rotated')
            . '|' . (is_int($liveInode) && $liveInode > 0 ? (string)$liveInode : 'absent');
    }

    /**
     * Test seam: forget which generation this process already inventoried.
     *
     * Production never needs it -- a worker serves one journal generation and
     * the static is exactly the point -- but a PHPUnit worker replays many
     * requests against many temp directories in one process, so without this a
     * test cannot observe the generation change it just created.
     *
     * @return void
     */
    public static function resetInventoryMemoForTests(): void {
        self::$inventoryCapturedForRotation = array();
    }
}
