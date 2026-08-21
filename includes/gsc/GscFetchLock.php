<?php

// allow-no-test-found: exercised by GscFetchPipelineTest contention and migration scenarios

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/GscConfig.php';
require_once __DIR__ . '/GscFetchLease.php';
require_once __DIR__ . '/GscFetchLockStore.php';

/** Coordinates one renewable GSC fetch lease across requests and upgrades. */
final class ABJ_404_Solution_GscFetchLock {

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_GscFetchLease|null */
    private $lease;

    /** @var ABJ_404_Solution_GscFetchLockStore */
    private $store;

    /** @var int|null */
    private $atomicReadyAt;

    /** @param ABJ_404_Solution_Logging $logger */
    public function __construct($logger) {
        $this->logger = $logger;
        $this->store = new ABJ_404_Solution_GscFetchLockStore();
    }

    /** Initialize and, when absent, persist the atomic-lock migration deadline. */
    public function initializeAtomicLockMigrationState(): void {
        if ($this->atomicReadyAt === null) {
            $this->atomicReadyAt = $this->initializeAtomicReadyAt();
        }
    }

    public function claim(): bool {
        $this->initializeAtomicLockMigrationState();
        $now = abj_clock()->now();
        $readyAt = $this->atomicReadyAt === null ? PHP_INT_MAX : $this->atomicReadyAt;
        if ($now < $readyAt
            || $this->store->legacyOwner() !== false
        ) {
            return false;
        }
        $value = ABJ_404_Solution_ExclusiveOptionRow::uniqueClaimValue((string)$now);
        if ($this->store->claim(array('value' => $value))) {
            $this->lease = $this->atomicLease($value, $now);
            return true;
        }
        $heldSince = $this->store->owner();
        if (!$this->hasAgedOut($heldSince, $now)) {
            return false;
        }
        $this->store->release(array('value' => $heldSince));
        $value = ABJ_404_Solution_ExclusiveOptionRow::uniqueClaimValue((string)$now);
        if (!$this->store->claim(array('value' => $value))) {
            return false;
        }
        $this->lease = $this->atomicLease($value, $now);
        return true;
    }

    public function release(): void {
        if ($this->lease === null) {
            return;
        }
        $this->store->release(array('value' => $this->lease->value()));
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
        if ($this->store->legacyOwner() !== false) {
            return true;
        }
        $heldSince = $this->store->owner();
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
        if (!$this->store->renew(array(
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
        $rawReadyAt = $this->store->atomicReadyAt();
        if ($rawReadyAt === false || !is_numeric($rawReadyAt)) {
            $readyAt = abj_clock()->now() + ABJ_404_Solution_GscConfig::ATOMIC_LOCK_MIGRATION_DELAY;
            if (!$this->store->persistAtomicReadyAt($readyAt)) {
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
}
