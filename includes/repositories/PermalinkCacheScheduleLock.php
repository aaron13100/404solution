<?php

// allow-no-test-found: exercised by PermalinkCacheChainAlreadyArmedTest race and stale-owner scenarios.

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__) . '/core/ExclusiveOptionRow.php';

/**
 * Serializes the permalink-cache hook probe with its WordPress cron write.
 *
 * WordPress de-duplicates scheduled events by hook and arguments. The cache
 * chain changes its arguments on every link, so two callers that both inspect
 * an empty cron store can otherwise both add a link. This claim makes that
 * check-and-write critical section exclusive across PHP requests.
 */
final class ABJ_404_Solution_PermalinkCacheScheduleLock {

    private const OPTION_NAME = 'abj404_permalink_cache_schedule_lock';

    /** A killed request may delay, but never permanently wedge, the chain. */
    private const TTL_SECONDS = 180;

    /**
     * @return string|null Exact owner value, or null while another live caller owns it.
     * @phpstan-impure
     */
    public function acquire(): ?string {
        $lockRow = $this->lockRow();
        $now = abj_clock()->now();
        $claimValue = ABJ_404_Solution_ExclusiveOptionRow::uniqueClaimValue(
            (string)($now + self::TTL_SECONDS)
        );
        $claim = array('optionName' => self::OPTION_NAME, 'value' => $claimValue);
        if ($lockRow->claim($claim)) {
            return $claimValue;
        }

        $existing = $lockRow->valueOf(self::OPTION_NAME);
        $expiryPart = explode(':', $existing, 2)[0];
        $existingIsStale = $existing === '' || !is_numeric($expiryPart) || (int)$expiryPart <= $now;
        if (!$existingIsStale) {
            return null;
        }
        if ($existing !== '' && !$lockRow->releaseIfValueIs(array(
            'optionName' => self::OPTION_NAME,
            'value' => $existing,
        ))) {
            return null;
        }

        return $lockRow->claim($claim) ? $claimValue : null;
    }

    /** Release only the exact claim this instance acquired. */
    public function release(string $claimValue): void {
        $this->lockRow()->releaseIfValueIs(array(
            'optionName' => self::OPTION_NAME,
            'value' => $claimValue,
        ));
    }

    private function lockRow(): ABJ_404_Solution_ExclusiveOptionRow {
        return new ABJ_404_Solution_ExclusiveOptionRow();
    }
}
