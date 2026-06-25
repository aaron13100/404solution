<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/FeedbackTransportLog.php';
require_once __DIR__ . '/FeedbackHttpClient.php';
require_once __DIR__ . '/FeedbackEmailFallback.php';
require_once __DIR__ . '/FeedbackPayloadBuilder.php';
require_once __DIR__ . '/FeedbackPayloadSchemaGuard.php';
require_once __DIR__ . '/ReportPayloadJsonSchemaValidator.php';
require_once __DIR__ . '/../diagnostics/CrashBeaconReporter.php';

/**
 * Orchestrates feedback report sends. Owns the queue/cron lifecycle and the
 * post-send diagnostics state that callers inspect to surface specific
 * transport failures to the user. Delegates the actual work:
 *
 *   - FeedbackPayloadBuilder      - assemble the payload from site state
 *   - FeedbackPayloadSchemaGuard  - normalize, validate, redact PII
 *   - FeedbackHttpClient          - POST to the developer endpoint
 *   - FeedbackEmailFallback       - wp_mail() last-resort path
 *
 *   queue($payload, $type): interactive paths (deactivate AJAX). Stores
 *     the payload in a transient and schedules a single-shot cron event so
 *     the user's click is never blocked on the network.
 *
 *   sendNow($payload, $type): already-async paths (nightly cron).
 *     Synchronously POSTs the payload, falls back to wp_mail() on non-2xx
 *     or WP_Error.
 *
 *   handleQueuedSend($uuid): cron handler. Loads transient, calls
 *     sendNow(), deletes transient regardless of outcome.
 *
 *   buildPayload($type, $extra): re-exports FeedbackPayloadBuilder::build()
 *     so existing callers and tests keep working through the historical
 *     entry point.
 */
class ABJ_404_Solution_FeedbackTransport {

    const TRANSIENT_PREFIX = 'abj404_pending_report_';
    const TRANSIENT_TTL = 86400; // 24 hours
    const CRON_HOOK = 'abj404_send_queued_report';

    /**
     * Records whether the most recent sendNow() call fell back to wp_mail()
     * after the HTTP POST failed. Read-only for callers that need to surface
     * "we sent via email instead of HTTP" in their own response (e.g. the
     * support-request AJAX handler returning {fallback_used: true}). Reset
     * at the top of every sendNow() call so concurrent reads don't see a
     * stale value from a previous unrelated send.
     *
     * @var bool
     */
    private static $lastSendUsedFallback = false;

    /**
     * Diagnostic details from the most recent sendNow() call. Populated
     * unconditionally so callers (e.g. the support-request AJAX handler)
     * can surface the actual failure code and reason to the user instead
     * of a generic "could not send" message.
     *
     * Shape:
     *   http_status:      int|null  HTTP status code from the developer
     *                                endpoint when the wp_remote_post()
     *                                call completed, or null when the
     *                                request never reached HTTP.
     *   http_reason:      string    Short slug (json_encode_failed,
     *                                gzencode_failed, wp_error,
     *                                http_<code>) usable for log greps.
     *   http_detail:      string    Free-form context (WP_Error message,
     *                                etc). May be empty.
     *   email_attempted:  bool      true when HTTP failed and the email
     *                                fallback ran.
     *   email_ok:         bool|null Result of the email fallback when it
     *                                ran; null when not attempted.
     *
     * @var array{http_status: int|null, http_reason: string, http_detail: string, email_attempted: bool, email_ok: bool|null}
     */
    private static $lastSendDiagnostics = array(
        'http_status'     => null,
        'http_reason'     => '',
        'http_detail'     => '',
        'email_attempted' => false,
        'email_ok'        => null,
    );

    /**
     * Queue a payload for asynchronous send. Used by interactive paths
     * (deactivate AJAX). Returns immediately; the actual send happens in a
     * single-shot cron event.
     *
     * Schedules the cron event and then kicks WP-Cron via spawn_cron() so the
     * send happens on the next request cycle instead of waiting for a natural
     * cron tick. On low-traffic sites a natural tick can be hours away, which
     * is long enough for the deactivate flow to forget about the report.
     *
     * @param array<string, mixed> $payload
     * @param string $type
     * @return void
     */
    public static function queue(array $payload, string $type): void {
        $payload = ABJ_404_Solution_FeedbackPayloadSchemaGuard::normalize($payload);
        $contract = ABJ_404_Solution_FeedbackPayloadSchemaGuard::validate($payload, $type);
        if (empty($contract['valid'])) {
            return;
        }
        ABJ_404_Solution_FeedbackPayloadSchemaGuard::logContractWarnings($payload, $type);
        $uuid = ABJ_404_Solution_FeedbackPayloadBuilder::generateUuid();
        $envelope = array(
            'payload' => $payload,
            'type' => $type,
        );
        // allow-cache-empty: feedback envelope is generated locally and may contain an intentionally empty payload.
        set_transient(self::TRANSIENT_PREFIX . $uuid, $envelope, self::TRANSIENT_TTL);
        abj_cron_scheduler()->scheduleSingle(self::CRON_HOOK, 0, array($uuid));

        // Trigger spawn_cron so the listener runs on the next request rather
        // than waiting for the next page load on a logged-in admin. spawn_cron
        // is a no-op when DISABLE_WP_CRON is true or a cron is already running.
        if (function_exists('spawn_cron')) {
            spawn_cron();
        }
    }

    /**
     * Synchronously POST and fall back to wp_mail() on failure. Used by paths
     * already in cron context (nightly maintenance).
     *
     * @param array<string, mixed> $payload
     * @param string $type
     * @return bool true if any transport (HTTP or email) succeeded.
     */
    public static function sendNow(array $payload, string $type): bool {
        self::$lastSendUsedFallback = false;
        self::$lastSendDiagnostics = array(
            'http_status'     => null,
            'http_reason'     => '',
            'http_detail'     => '',
            'email_attempted' => false,
            'email_ok'        => null,
        );

        $payload = ABJ_404_Solution_FeedbackPayloadSchemaGuard::normalize($payload);
        $contract = ABJ_404_Solution_FeedbackPayloadSchemaGuard::validate($payload, $type);
        if (empty($contract['valid'])) {
            self::$lastSendDiagnostics = array(
                'http_status'     => null,
                'http_reason'     => ABJ_404_Solution_ReportPayloadJsonSchemaValidator::REASON_VALIDATION_FAILED,
                'http_detail'     => isset($contract['detail']) && is_scalar($contract['detail']) ? (string)$contract['detail'] : '',
                'email_attempted' => false,
                'email_ok'        => null,
            );
            return false;
        }
        ABJ_404_Solution_FeedbackPayloadSchemaGuard::logContractWarnings($payload, $type);
        $payload = ABJ_404_Solution_FeedbackPayloadSchemaGuard::redact($payload);
        $started = abj_clock()->nowFloat();
        $result = ABJ_404_Solution_FeedbackHttpClient::send($payload);
        $elapsedMs = (int) round((abj_clock()->nowFloat() - $started) * 1000);

        $statusStr = isset($result['status']) && is_scalar($result['status']) ? (string)$result['status'] : '';
        $reasonStr = isset($result['reason']) && is_scalar($result['reason']) ? (string)$result['reason'] : '';
        $detailStr = isset($result['detail']) && is_scalar($result['detail']) ? (string)$result['detail'] : '';

        self::$lastSendDiagnostics = array(
            'http_status'     => $statusStr !== '' ? (int)$statusStr : null,
            'http_reason'     => $reasonStr,
            'http_detail'     => $detailStr,
            'email_attempted' => false,
            'email_ok'        => null,
        );

        if (!empty($result['ok'])) {
            ABJ_404_Solution_FeedbackTransportLog::log('info', sprintf(
                'abj404_transport: type=%s http_status=%s fallback_used=false ms_elapsed=%d',
                $type,
                $statusStr !== '' ? $statusStr : 'ok',
                $elapsedMs
            ));
            return true;
        }

        $statusLabel = $statusStr !== '' ? $statusStr : ($reasonStr !== '' ? $reasonStr : 'unknown');
        ABJ_404_Solution_FeedbackTransportLog::log('warn', sprintf(
            'abj404_transport: type=%s http_status=%s fallback_used=true ms_elapsed=%d detail=%s',
            $type,
            $statusLabel,
            $elapsedMs,
            $detailStr
        ));

        self::$lastSendUsedFallback = true;
        self::$lastSendDiagnostics['email_attempted'] = true;
        $emailOk = ABJ_404_Solution_FeedbackEmailFallback::send($payload, $type);
        self::$lastSendDiagnostics['email_ok'] = $emailOk;
        return $emailOk;
    }

    /**
     * Diagnostic context from the most recent sendNow() call. Callers
     * that surface a user-facing failure message must include the
     * http_status / http_reason here so the message is actionable.
     * "Could not send" alone is the diagnostic black-hole this method
     * exists to prevent (CLAUDE.md > Error visibility).
     *
     * @return array{http_status: int|null, http_reason: string, http_detail: string, email_attempted: bool, email_ok: bool|null}
     */
    public static function lastSendDiagnostics(): array {
        return self::$lastSendDiagnostics;
    }

    /**
     * Whether the most recent sendNow() call used the wp_mail() fallback
     * after the HTTP POST failed. Callers (e.g. the support-request AJAX
     * handler) read this immediately after sendNow() to surface the
     * transport result to the user.
     *
     * @return bool
     */
    public static function lastSendUsedFallback(): bool {
        return self::$lastSendUsedFallback;
    }

    /**
     * Cron handler for queued sends. Loads payload from transient, calls
     * sendNow(), deletes transient regardless of outcome (24h TTL still
     * cleans up if anything throws before the delete).
     *
     * @param string $uuid
     * @return void
     */
    public static function handleQueuedSend(string $uuid): void {
        $key = self::TRANSIENT_PREFIX . $uuid;
        $envelope = get_transient($key);
        if (!is_array($envelope) || !isset($envelope['payload']) || !is_array($envelope['payload'])) {
            // Transient expired before WP-Cron fired, or the cron event fired
            // twice and the second invocation found the key already cleared.
            // Log so the data loss is visible to admins; 24h TTL means this
            // path is reachable on sites where WP-Cron is broken or paused.
            ABJ_404_Solution_FeedbackTransportLog::log('warn', sprintf(
                'abj404_transport: queued send missed - transient absent or malformed (key=%s). ' .
                'Most commonly: WP-Cron did not fire within the %d second TTL.',
                $key,
                self::TRANSIENT_TTL
            ));
            delete_transient($key);
            return;
        }
        /** @var array<string, mixed> $payload */
        $payload = $envelope['payload'];
        $type = isset($envelope['type']) && is_string($envelope['type']) ? $envelope['type'] : 'unknown';

        try {
            self::sendNow($payload, $type);
        } catch (\Throwable $e) {
            // sendNow() must be defensive, but if anything escapes we still
            // log and let the transient be cleared so cron doesn't loop on it.
            ABJ_404_Solution_FeedbackTransportLog::log('warn', 'abj404_transport: sendNow threw: ' . $e->getMessage());
        }

        delete_transient($key);
    }

    /**
     * Build a full payload from current site state. Public re-export of
     * FeedbackPayloadBuilder::build() so existing callers and tests keep
     * working through this historical entry point.
     *
     * @param string $type One of 'error', 'heartbeat', 'uninstall', 'support_request'.
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function buildPayload(string $type, array $extra = array()): array {
        return ABJ_404_Solution_FeedbackPayloadBuilder::build($type, $extra);
    }

    /**
     * Drain any pending crash beacon and report it as a post-mortem `error`
     * report. A crash beacon is written by the fatal shutdown handler when a
     * fatal/OOM kills a request before it can phone home; this reports it on a
     * later healthy request. Lives here, beside the other transports, so the
     * Core crash-beacon reporter is reached within the Core layer.
     *
     * @return bool True if a crash beacon was reported.
     */
    public static function drainCrashBeacon(): bool {
        $reporter = new ABJ_404_Solution_CrashBeaconReporter(
            ABJ_404_Solution_CrashBeaconStore::forCurrentSite());
        return $reporter->drainAndReport();
    }

    /**
     * Build a redacted, schema-conforming payload (uninstall opt-out path).
     * Public re-export of FeedbackPayloadBuilder::buildMinimal().
     *
     * @param string $type One of 'error', 'heartbeat', 'uninstall', 'support_request'.
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function buildMinimalPayload(string $type, array $extra = array()): array {
        return ABJ_404_Solution_FeedbackPayloadBuilder::buildMinimal($type, $extra);
    }

}
