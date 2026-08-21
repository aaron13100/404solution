<?php

// allow-no-test-found: exercised by GscFetchPipelineTest contention and migration scenarios

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

    /** @var int|null */
    private $atomicReadyAt;

    /** @param ABJ_404_Solution_Logging $logger */
    public function __construct($logger) {
        $this->logger = $logger;
    }

    /** Initialize persistent migration state at an explicit mutation boundary. */
    public function prepare(): void {
        if ($this->atomicReadyAt === null) {
            $this->atomicReadyAt = $this->initializeAtomicReadyAt();
        }
    }

    public function claim(): bool {
        $this->prepare();
        $now = abj_clock()->now();
        $readyAt = $this->atomicReadyAt === null ? PHP_INT_MAX : $this->atomicReadyAt;
        if ($now < $readyAt
            || get_transient(ABJ_404_Solution_GscConfig::LOCK_TRANSIENT_KEY) !== false
        ) {
            return false;
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
        $this->row()->releaseIfValueIs(array(
            'optionName' => ABJ_404_Solution_GscConfig::LOCK_TRANSIENT_KEY,
            'value' => $this->lease->value(),
        ));
        $this->lease = null;
    }

    public function isHeld(): bool {
        if ($this->atomicReadyAt === null) {
            return true;
        }
        $now = abj_clock()->now();
        if ($now < $this->atomicReadyAt) {
            return true;
        }
        if (get_transient(ABJ_404_Solution_GscConfig::LOCK_TRANSIENT_KEY) !== false) {
            return true;
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

    private function initializeAtomicReadyAt(): int {
        $rawReadyAt = get_option(ABJ_404_Solution_GscConfig::ATOMIC_LOCK_READY_OPTION, false);
        if ($rawReadyAt === false || !is_numeric($rawReadyAt)) {
            $readyAt = abj_clock()->now() + ABJ_404_Solution_GscConfig::ATOMIC_LOCK_MIGRATION_DELAY;
            if (!update_option(ABJ_404_Solution_GscConfig::ATOMIC_LOCK_READY_OPTION, (string)$readyAt, false)) {
                $this->logger->warn('Could not persist the GSC atomic-lock migration deadline; GSC fetches remain paused.');
                return PHP_INT_MAX;
            }
            return $readyAt;
        }
        return max(0, (int)$rawReadyAt);
    }

    private function hasAgedOut(string $value, int $now): bool {
        $timestamp = explode(':', $value, 2)[0];
        return $value === '' || !is_numeric($timestamp)
            || ($now - (int)$timestamp) > ABJ_404_Solution_GscConfig::LOCK_TTL;
    }

    private function atomicLease(string $value, int $now): ABJ_404_Solution_GscFetchLease {
        return ABJ_404_Solution_GscFetchLease::fromState(array(
            'value' => $value,
            'renewedAt' => $now,
        ));
    }

    private function row(): ABJ_404_Solution_ExclusiveOptionRow {
        return new ABJ_404_Solution_ExclusiveOptionRow();
    }
}
