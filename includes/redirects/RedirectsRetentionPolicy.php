<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalizes retention-window settings for redirect maintenance.
 */
class ABJ_404_Solution_RedirectsRetentionPolicy {

    private const SECONDS_PER_DAY = 86400;

    /**
     * @param array<string, mixed> $options
     * @param string $optionKey
     * @return int Non-negative retention days; invalid values disable retention.
     */
    public function daysFromOptions(array $options, string $optionKey): int {
        $rawDays = $options[$optionKey] ?? 0;
        if (!is_scalar($rawDays)) {
            return 0;
        }

        return max(0, (int)$rawDays);
    }

    /**
     * @param int $days
     * @param int $now
     * @return int|null Unix cutoff timestamp, or null when retention is disabled.
     */
    public function cutoffForDays(int $days, int $now): ?int {
        if ($days <= 0) {
            return null;
        }

        return max(0, $now - ($days * self::SECONDS_PER_DAY));
    }
}
