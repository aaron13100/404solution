<?php

if (!defined('ABSPATH')) {
    exit;
}

/** WordPress option persistence for the canonical-redirect hook census. */
final class ABJ_404_Solution_CanonicalHookCensusStore {

    const OPTION_NAME = 'abj404_canonical_hook_census';

    /**
     * @return array<string, mixed>
     */
    public function read(): array {
        try {
            if (!function_exists('get_option')) {
                return array();
            }
            $raw = get_option(self::OPTION_NAME, '');
            if ($raw === '') {
                return array();
            }
            if (!is_string($raw)) {
                abj404_logPhpFallback('canonical-hook-census',
                    'canonical hook census option has a malformed non-string value. Recovery: delete the '
                    . self::OPTION_NAME . ' option and retry from a front-end 404.');
                return array();
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                abj404_logPhpFallback('canonical-hook-census',
                    'canonical hook census option contains malformed JSON: ' . json_last_error_msg()
                    . '. Recovery: delete the ' . self::OPTION_NAME
                    . ' option and retry from a front-end 404.');
                return array();
            }
            $record = array();
            foreach ($decoded as $key => $value) {
                if (!is_string($key)) {
                    abj404_logPhpFallback('canonical-hook-census',
                        'canonical hook census option contains a non-string field name. Recovery: delete the '
                        . self::OPTION_NAME . ' option and retry from a front-end 404.');
                    return array();
                }
                $record[$key] = $value;
            }
            return $record;
        } catch (Throwable $e) {
            abj404_logPhpFallback('canonical-hook-census',
                'canonical hook census read failed (code ' . $e->getCode() . '): ' . $e->getMessage());
            return array();
        }
    }

    /** @param array<string, mixed> $record */
    public function write(array $record): void {
        if (!function_exists('update_option')) {
            return;
        }
        $encoded = json_encode($record);
        if (!is_string($encoded)) {
            abj404_logPhpFallback('canonical-hook-census',
                'canonical hook census could not be encoded: ' . json_last_error_msg());
            return;
        }
        if (!update_option(self::OPTION_NAME, $encoded, false)) {
            abj404_logPhpFallback('canonical-hook-census',
                'canonical hook census option write was not persisted. Recovery: inspect the WordPress '
                . 'options table and object-cache error logs, then retry from a front-end 404.');
        }
    }

    /** Drop cached values that can hide a concurrent refresh. */
    public function refreshReads(): void {
        if (!function_exists('wp_cache_delete')) {
            return;
        }
        wp_cache_delete(self::OPTION_NAME, 'options');
        wp_cache_delete('notoptions', 'options');
    }
}
