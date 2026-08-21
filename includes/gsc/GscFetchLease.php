<?php

// allow-no-test-found: exercised by GscFetchPipelineTest renewable lock lifecycle scenarios

if (!defined('ABSPATH')) {
    exit;
}

/** Immutable ownership state for one GSC fetch lease. */
final class ABJ_404_Solution_GscFetchLease {

    /** @var string */
    private $value;

    /** @var int */
    private $renewedAt;

    /** @param array{value: string, renewedAt: int} $state */
    private function __construct(array $state) {
        if ($state['value'] === '' || $state['renewedAt'] < 0) {
            throw new InvalidArgumentException('Invalid GSC fetch lease state.');
        }
        $this->value = $state['value'];
        $this->renewedAt = $state['renewedAt'];
    }

    /** @param array{value: string, renewedAt: int} $state */
    public static function fromState(array $state): self {
        return new self($state);
    }

    public function value(): string { return $this->value; }

    public function renewedAt(): int { return $this->renewedAt; }

    public function renewed(string $replacementValue, int $renewedAt): self {
        return new self(array(
            'value' => $replacementValue,
            'renewedAt' => $renewedAt,
        ));
    }
}
