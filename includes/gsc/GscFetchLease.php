<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Immutable ownership state for one GSC fetch lease. */
final class ABJ_404_Solution_GscFetchLease {

    const MODE_LEGACY_TRANSIENT = 'legacy_transient';
    const MODE_ATOMIC_OPTION = 'atomic_option';

    /** @var string */
    private $mode;

    /** @var string */
    private $value;

    /** @var int */
    private $renewedAt;

    /** @param array{mode: string, value: string, renewedAt: int} $state */
    private function __construct(array $state) {
        if (!in_array($state['mode'], array(self::MODE_LEGACY_TRANSIENT, self::MODE_ATOMIC_OPTION), true)
            || $state['value'] === '' || $state['renewedAt'] < 0
        ) {
            throw new InvalidArgumentException('Invalid GSC fetch lease state.');
        }
        $this->mode = $state['mode'];
        $this->value = $state['value'];
        $this->renewedAt = $state['renewedAt'];
    }

    /** @param array{mode: string, value: string, renewedAt: int} $state */
    public static function fromState(array $state): self {
        return new self($state);
    }

    public function mode(): string { return $this->mode; }

    public function value(): string { return $this->value; }

    public function renewedAt(): int { return $this->renewedAt; }

    public function renewed(string $replacementValue, int $renewedAt): self {
        return new self(array(
            'mode' => $this->mode,
            'value' => $replacementValue,
            'renewedAt' => $renewedAt,
        ));
    }
}
