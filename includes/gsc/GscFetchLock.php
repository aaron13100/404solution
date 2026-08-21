<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/GscConfig.php';
require_once __DIR__ . '/GscFetchLease.php';

/** Coordinates one renewable GSC fetch lease across requests and upgrades. */
final class ABJ_404_Solution_GscFetchLock {

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_GscFetchLease|null */
    private $lease;

    /** @param ABJ_404_Solution_Logging $logger */
    public function __construct($logger) {
        $this->logger = $logger;
    }

    public function claim(): bool {
        $now = abj_clock()->now();
        if ($this->mustUseLegacyLockDuringMigration($now)) {
            return $this->claimLegacy($now);
        }
        $row = $this->row();
        $value = ABJ_404_Solution_ExclusiveOptionRow::uniqueClaimValue((string)$now);
        if ($row->claim(array('optionName' => ABJ_404_Solution_GscConfig::LOCK_TRANSIENT_KEY,
            'value' => $value))) {
            $this->lease = $this->atomicLease($value, $now);
            return true;
        }
        $heldSince = $row->valueOf(ABJ_404_Solution_GscConfig::LOCK_TRANSIENT_KEY);
        if (!$this->hasAgedOut($heldSince, $now)) {
            return false;
        }
        $row->releaseIfValueIs(array('optionName' => ABJ_404_Solution_GscConfig::LOCK_TRANSIENT_KEY,
            'value' => $heldSince));
        $value = ABJ_404_Solution_ExclusiveOptionRow::uniqueClaimValue((string)$now);
        if (!$row->claim(array('optionName' => ABJ_404_Solution_GscConfig::LOCK_TRANSIENT_KEY,
            'value' => $value))) {
            return false;
        }
        $this->lease = $this->atomicLease($value, $now);
        return true;
    }

    public function release(): void {
        if ($this->lease === null) {
            return;
        }
        if ($this->lease->mode() === ABJ_404_Solution_GscFetchLease::MODE_ATOMIC_OPTION) {
            $this->row()->releaseIfValueIs(array(
                'optionName' => ABJ_404_Solution_GscConfig::LOCK_TRANSIENT_KEY,
                'value' => $this->lease->value(),
            ));
        } elseif (get_transient(ABJ_404_Solution_GscConfig::LOCK_TRANSIENT_KEY) === $this->lease->value()) {
            delete_transient(ABJ_404_Solution_GscConfig::LOCK_TRANSIENT_KEY);
        }
        $this->lease = null;
    }

    public function isHeld(): bool {
        $now = abj_clock()->now();
        if ($this->mustUseLegacyLockDuringMigration($now)) {
            return get_transient(ABJ_404_Solution_GscConfig::LOCK_TRANSIENT_KEY) !== false;
        }
        $heldSince = $this->row()->valueOf(ABJ_404_Solution_GscConfig::LOCK_TRANSIENT_KEY);
        return $heldSince !== '' && !$this->hasAgedOut($heldSince, $now);
    }

    public function renewIfDue(): bool {
        if ($this->lease === null) {
            return true;
        }
        $now = abj_clock()->now();
        if (($now - $this->lease->renewedAt()) < intdiv(ABJ_404_Solution_GscConfig::LOCK_TTL, 3)) {
            return true;
        }
        $replacement = ABJ_404_Solution_ExclusiveOptionRow::uniqueClaimValue((string)$now);
        if ($this->lease->mode() === ABJ_404_Solution_GscFetchLease::MODE_LEGACY_TRANSIENT) {
            // allow-cache-empty: uniqueClaimValue() always returns a non-empty lease token.
            if (get_transient(ABJ_404_Solution_GscConfig::LOCK_TRANSIENT_KEY) !== $this->lease->value()
                || !set_transient(ABJ_404_Solution_GscConfig::LOCK_TRANSIENT_KEY, $replacement,
                    ABJ_404_Solution_GscConfig::LOCK_TTL)
            ) {
                $this->logger->warn('Lost the legacy GSC fetch lock while renewing it; stopping the fetch.');
                return false;
            }
            $this->lease = $this->lease->renewed($replacement, $now);
            return true;
        }
        if (!$this->row()->replaceValueIfMatches(array(
            'optionName' => ABJ_404_Solution_GscConfig::LOCK_TRANSIENT_KEY,
            'currentValue' => $this->lease->value(),
            'replacementValue' => $replacement,
        ))) {
            $this->logger->warn('Lost the GSC fetch lock while renewing it; stopping before another API request.');
            return false;
        }
        $this->lease = $this->lease->renewed($replacement, $now);
        return true;
    }

    private function mustUseLegacyLockDuringMigration(int $now): bool {
        $rawReadyAt = get_option(ABJ_404_Solution_GscConfig::ATOMIC_LOCK_READY_OPTION, false);
        if ($rawReadyAt === false || !is_numeric($rawReadyAt)) {
            $readyAt = $now + ABJ_404_Solution_GscConfig::ATOMIC_LOCK_MIGRATION_DELAY;
            if (!update_option(ABJ_404_Solution_GscConfig::ATOMIC_LOCK_READY_OPTION, (string)$readyAt, false)) {
                $this->logger->warn('Could not persist the GSC atomic-lock migration deadline; retaining the legacy lock.');
            }
            return true;
        }
        return $now < (int)$rawReadyAt;
    }

    private function claimLegacy(int $now): bool {
        if (get_transient(ABJ_404_Solution_GscConfig::LOCK_TRANSIENT_KEY) !== false) {
            return false;
        }
        $value = ABJ_404_Solution_ExclusiveOptionRow::uniqueClaimValue((string)$now);
        // allow-cache-empty: the unique legacy lease token is always non-empty.
        if (!set_transient(ABJ_404_Solution_GscConfig::LOCK_TRANSIENT_KEY, $value,
            ABJ_404_Solution_GscConfig::LOCK_TTL)) {
            $this->logger->warn('Could not persist the legacy GSC fetch lock; fetch skipped.');
            return false;
        }
        $this->lease = ABJ_404_Solution_GscFetchLease::fromState(array(
            'mode' => ABJ_404_Solution_GscFetchLease::MODE_LEGACY_TRANSIENT,
            'value' => $value,
            'renewedAt' => $now,
        ));
        return true;
    }

    private function hasAgedOut(string $value, int $now): bool {
        $timestamp = explode(':', $value, 2)[0];
        return $value === '' || !is_numeric($timestamp)
            || ($now - (int)$timestamp) > ABJ_404_Solution_GscConfig::LOCK_TTL;
    }

    private function atomicLease(string $value, int $now): ABJ_404_Solution_GscFetchLease {
        return ABJ_404_Solution_GscFetchLease::fromState(array(
            'mode' => ABJ_404_Solution_GscFetchLease::MODE_ATOMIC_OPTION,
            'value' => $value,
            'renewedAt' => $now,
        ));
    }

    private function row(): ABJ_404_Solution_ExclusiveOptionRow {
        return new ABJ_404_Solution_ExclusiveOptionRow();
    }
}
