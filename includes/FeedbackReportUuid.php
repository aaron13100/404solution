<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generates random report identifiers for queued feedback envelopes.
 */
class ABJ_404_Solution_FeedbackReportUuid {

    public static function generate(): string {
        try {
            $data = random_bytes(16);
        // allow-silent-catch: random_bytes only throws when CSPRNG unavailable; fallback to wp_generate_password / mt_rand still produces a valid transient key
        } catch (\Throwable $e) {
            $data = '';
            if (function_exists('wp_generate_password')) {
                $data = (string)wp_generate_password(16, true, true);
                $data = substr($data . str_repeat("\0", 16), 0, 16);
            }
            if ($data === '' || strlen($data) < 16) {
                $data = str_pad((string)mt_rand(), 16, "\0");
                $data = substr($data, 0, 16);
            }
        }
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
