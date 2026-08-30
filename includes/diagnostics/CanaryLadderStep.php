<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Identity and dispatch order for the adaptive AJAX canary ladder.
 *
 * The static-asset probe is a receipt-only browser step. Every other value
 * appears in DISPATCHED in the exact order accepted by the server endpoint.
 */
final class ABJ_404_Solution_CanaryLadderStep {

    /** Browser-only probe that is legal on receipts but never dispatched. */
    const STATIC_ASSET = 'static_asset';
    const CONCURRENT_CONTROL = 'concurrent_control';
    const BASELINE_CONTROL = 'baseline_control';
    const AUTH_ONLY = 'auth_only';
    const POST_LIMITER = 'post_limiter';
    const SUMMARY = 'summary';
    const SIZE_TARGET = 'size_target';
    const SIZE_PROBE = 'size_probe';
    const INERT = 'inert';
    const COMPRESS_ON = 'compress_on';
    const COMPRESS_OFF = 'compress_off';
    const STREAM = 'stream';
    const INTERPRET = 'interpret';

    /** Every server-dispatched step. The browser-only static asset is excluded. */
    const DISPATCHED = array(
        self::CONCURRENT_CONTROL, self::BASELINE_CONTROL, self::AUTH_ONLY,
        self::POST_LIMITER, self::SUMMARY, self::SIZE_TARGET, self::SIZE_PROBE,
        self::INERT, self::COMPRESS_ON, self::COMPRESS_OFF, self::STREAM,
        self::INTERPRET,
    );

    /**
     * Convert an untrusted request value to a dispatchable step ID.
     *
     * @param mixed $raw
     */
    public static function normalize($raw): string {
        $candidate = is_scalar($raw) ? (string)$raw : '';
        return in_array($candidate, self::DISPATCHED, true) ? $candidate : '';
    }
}
