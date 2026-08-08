<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/CrashBeacon.php';
require_once __DIR__ . '/CrashBeaconStore.php';

/**
 * Drains a pending crash beacon on a HEALTHY request and reports it as a
 * post-mortem `error` feedback report. Runs in the existing async maintenance
 * telemetry path (alongside the error-log and heartbeat dispatch), gated by the
 * same send_error_logs opt-in, so the GDPR transport-disable control governs it.
 *
 * Because this runs with memory plentiful, it does the work the capture path
 * could not: full canonical PII normalization of the signature, cooldown
 * bookkeeping, and transport. Collaborators are injected as callables so the
 * behavior is unit-testable without mocking the static FeedbackTransport facade.
 *
 * Wire contract: the crash marker rides the EXISTING, already-versioned
 * error_signature field as a machine-stable, versioned prefix
 * "[abj404-crash-beacon:v1 plugin=X] ...". The marker comes FIRST so a
 * server-side signature length cap cannot truncate it away. No new wire field
 * and no server change is required; the report is an ordinary `error` report.
 */
class ABJ_404_Solution_CrashBeaconReporter {

    /** Transient key prefix for the per-signature cooldown. */
    const COOLDOWN_TRANSIENT_PREFIX = 'abj404_crash_beacon_sent_';

    /** One report per crash signature per 24h. */
    const COOLDOWN_SECONDS = 86400;

    /** Only discard a corrupt/partial file once it is older than this, so a file
     *  caught mid-write by a concurrent crashing request is not deleted before
     *  its writer finishes. */
    const CORRUPT_DISCARD_AGE_SECONDS = 300;

    /** @var ABJ_404_Solution_CrashBeaconStore */
    private $store;
    /** @var callable fn(string $message): string */
    private $normalizer;
    /** @var callable fn(string $type, array $extra): array */
    private $payloadBuilder;
    /** @var callable fn(array $payload, string $type): bool */
    private $sender;
    /** @var callable fn(): int epoch seconds */
    private $clock;

    /**
     * @param ABJ_404_Solution_CrashBeaconStore $store
     * @param callable|null $normalizer     fn(string $message): string. Default: canonical normalizeErrorSignature.
     * @param callable|null $payloadBuilder fn(string $type, array $extra): array. Default: FeedbackTransport::buildPayload.
     * @param callable|null $sender         fn(array $payload, string $type): bool. Default: FeedbackTransport::sendNow.
     * @param callable|null $clock          fn(): int epoch seconds. Default: abj_clock()->now().
     */
    public function __construct(
        ABJ_404_Solution_CrashBeaconStore $store,
        $normalizer = null,
        $payloadBuilder = null,
        $sender = null,
        $clock = null
    ) {
        $this->store = $store;
        $this->normalizer = is_callable($normalizer) ? $normalizer : function (string $message): string {
            return (new ABJ_404_Solution_FeedbackEnvironmentExtras_DebugLogSignatures())->normalizeErrorSignature($message);
        };
        $this->payloadBuilder = is_callable($payloadBuilder) ? $payloadBuilder : function (string $type, array $extra): array {
            return ABJ_404_Solution_FeedbackTransport::buildPayload($type, $extra);
        };
        $this->sender = is_callable($sender) ? $sender : function (array $payload, string $type): bool {
            return ABJ_404_Solution_FeedbackTransport::sendNow($payload, $type);
        };
        $this->clock = is_callable($clock) ? $clock : function (): int {
            return abj_clock()->now();
        };
    }

    /**
     * Read any pending beacon and, if its signature is outside the 24h cooldown,
     * report it as an `error` feedback report. On a successful send the cooldown
     * transient is set BEFORE the file is cleared, so a failed unlink cannot
     * cause a resend. Wrapped in a Throwable guard: a transport failure on the
     * healthy path must never escalate (and must never feed back into the crash
     * capture path).
     *
     * @return bool true iff a report was sent.
     */
    public function drainAndReport(): bool {
        try {
            $result = $this->store->read();
            $status = isset($result['status']) ? (string)$result['status'] : 'absent';
            $beacon = isset($result['beacon']) && $result['beacon'] instanceof ABJ_404_Solution_CrashBeacon
                ? $result['beacon'] : null;

            if ($status === 'absent' || $status === 'future') {
                // Nothing pending, or a newer-format file we must leave for a
                // compatible version to drain (forward-compatibility).
                return false;
            }

            if ($beacon === null) {
                // corrupt / oversized / unreadable: discard only once it is old
                // enough that any in-flight write has certainly completed.
                $modifiedAt = $this->store->modifiedAt();
                if ($modifiedAt !== null && ($this->now() - $modifiedAt) > self::CORRUPT_DISCARD_AGE_SECONDS) {
                    $this->store->clear();
                }
                return false;
            }

            $cooldownKey = self::COOLDOWN_TRANSIENT_PREFIX . md5($beacon->signatureKey());
            if (get_transient($cooldownKey) !== false) {
                // Already reported this signature recently. Clear the file so a
                // different future crash can be captured (first-crash-wins frees up).
                $this->store->clear();
                return false;
            }

            $signature = $this->buildSignature($beacon);
            $payload = call_user_func($this->payloadBuilder, 'error', array(
                'error_signature' => $signature,
                'previously_sent_line' => 0,
                'debug_log_evidence' => array(
                    'schema_version' => 1,
                    'source' => 'crash_beacon',
                    'error_excerpt' => $signature,
                    'error_excerpt_in_debug_log' => false,
                    'error_line_number' => -1,
                    'total_evidence_bytes' => strlen($signature),
                ),
            ));

            $sent = (bool) call_user_func($this->sender, $payload, 'error');
            if ($sent) {
                set_transient($cooldownKey, 1, self::COOLDOWN_SECONDS);
                $this->store->clear();
            }
            return $sent;
        } catch (\Throwable $e) {
            // The drain is a best-effort recovery path and must never fatal --
            // including for want of a runtime helper that a degraded or isolated
            // bootstrap has not loaded yet (every other abj404_logRuntimeWarning
            // caller guards the same way). Fall back to the inert PHP-error-log
            // sink, then to nothing.
            if (function_exists('abj404_logRuntimeWarning')) {
                abj404_logRuntimeWarning('Crash beacon drain failed', $e);
            } elseif (function_exists('abj404_logPhpFallback')) {
                abj404_logPhpFallback('crash-beacon', 'drain failed: ' . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Machine-stable, versioned signature. The marker is placed first; the
     * description is fully normalized (paths -> basename, digits -> N, hex ->
     * 0xN) before it goes on the wire.
     *
     * @param ABJ_404_Solution_CrashBeacon $beacon
     * @return string
     */
    private function buildSignature(ABJ_404_Solution_CrashBeacon $beacon): string {
        $marker = '[abj404-crash-beacon:v1 plugin=' . $beacon->pluginVersion() . '] ';
        $description = 'type=' . $beacon->errorType() . ' '
            . $beacon->relativeFile() . ':' . $beacon->line() . ' ' . $beacon->message();
        $normalized = (string) call_user_func($this->normalizer, $description);
        return $marker . $normalized;
    }

    /** @return int epoch seconds */
    private function now(): int {
        return (int) call_user_func($this->clock);
    }
}
