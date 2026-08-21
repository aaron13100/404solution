<?php

// allow-no-test-found: exercised through GscFetchPipelineTest lock lifecycle and migration scenarios

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/GscConfig.php';

/** Persistence adapter for the GSC fetch lock and its migration marker. */
final class ABJ_404_Solution_GscFetchLockStore {

    /** @return mixed */
    public function atomicReadyAt() {
        return get_option(ABJ_404_Solution_GscConfig::ATOMIC_LOCK_READY_OPTION, false);
    }

    public function persistAtomicReadyAt(int $readyAt): bool {
        return update_option(
            ABJ_404_Solution_GscConfig::ATOMIC_LOCK_READY_OPTION,
            (string)$readyAt,
            false
        );
    }

    /** @return mixed */
    public function legacyOwner() {
        return get_transient(ABJ_404_Solution_GscConfig::LOCK_TRANSIENT_KEY);
    }

    /** @param array{value: string} $claim */
    public function claim(array $claim): bool {
        return $this->row()->claim(array(
            'optionName' => ABJ_404_Solution_GscConfig::LOCK_TRANSIENT_KEY,
            'value' => $claim['value'],
        ));
    }

    public function owner(): string {
        return $this->row()->valueOf(ABJ_404_Solution_GscConfig::LOCK_TRANSIENT_KEY);
    }

    /** @param array{value: string} $release */
    public function release(array $release): bool {
        return $this->row()->releaseIfValueIs(array(
            'optionName' => ABJ_404_Solution_GscConfig::LOCK_TRANSIENT_KEY,
            'value' => $release['value'],
        ));
    }

    /** @param array{currentValue: string, replacementValue: string} $renewal */
    public function renew(array $renewal): bool {
        return $this->row()->replaceValueIfMatches(array(
            'optionName' => ABJ_404_Solution_GscConfig::LOCK_TRANSIENT_KEY,
            'currentValue' => $renewal['currentValue'],
            'replacementValue' => $renewal['replacementValue'],
        ));
    }

    private function row(): ABJ_404_Solution_ExclusiveOptionRow {
        return new ABJ_404_Solution_ExclusiveOptionRow();
    }
}
