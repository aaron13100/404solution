<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The answer to "which site comes after this one in the network?", as a value
 * with three states that cannot be confused for one another.
 *
 * Why a value object rather than a nullable int: two of the three answers are
 * "no site came back", and they mean opposite things. The network having ENDED
 * retires a rebuild; the network having failed to ANSWER must not, because a
 * walk that records itself as covering every site after draining none of them
 * leaves live sites with an empty n-gram cache and nothing left to re-arm a
 * rebuild. Every previous defect on this path was a variation on collapsing
 * those two into one null.
 *
 * @see ABJ_404_Solution_NetworkSitesRepository
 */
final class ABJ_404_Solution_NextNetworkSite {

    const STATE_FOUND = 'found';
    const STATE_END_OF_NETWORK = 'end-of-network';
    const STATE_UNREADABLE = 'unreadable';

    /** @var string One of the STATE_* constants. */
    private $state;

    /** @var int The site id; 0 unless the state is STATE_FOUND. */
    private $siteId;

    /** @var string Why the network could not be read; '' unless unreadable. */
    private $reason;

    /**
     * @param string $state
     * @param int $siteId
     * @param string $reason
     */
    private function __construct(string $state, int $siteId, string $reason) {
        $this->state = $state;
        $this->siteId = $siteId;
        $this->reason = $reason;
    }

    /**
     * @param int $siteId Must be a real site id.
     * @return self
     * @throws InvalidArgumentException When the id cannot address a site.
     */
    public static function found(int $siteId): self {
        // Rejected HERE, where the bad value is, rather than downstream in
        // switch_to_blog(): switch_to_blog(0) is a no-op on some WordPress
        // versions and a fatal on others, and either way the walk would record
        // a site as drained that was never switched into.
        if ($siteId <= 0) {
            throw new InvalidArgumentException(
                'A network site id must be positive; got ' . $siteId . '.'
            );
        }
        return new self(self::STATE_FOUND, $siteId, '');
    }

    /** @return self The walk has passed the last site in the network. */
    public static function endOfNetwork(): self {
        return new self(self::STATE_END_OF_NETWORK, 0, '');
    }

    /**
     * @param string $reason Underlying failure, for the log line that reports it.
     * @return self
     */
    public static function unreadable(string $reason): self {
        return new self(self::STATE_UNREADABLE, 0, $reason !== '' ? $reason : 'no reason reported');
    }

    /** @return bool Whether a site to drain came back. */
    public function isFound(): bool {
        return $this->state === self::STATE_FOUND;
    }

    /** @return bool Whether the walk has reached the end of the network. */
    public function isEndOfNetwork(): bool {
        return $this->state === self::STATE_END_OF_NETWORK;
    }

    /** @return bool Whether the network could not be asked at all. */
    public function isUnreadable(): bool {
        return $this->state === self::STATE_UNREADABLE;
    }

    /** @return int The site to drain, or 0 when there is none. */
    public function siteId(): int {
        return $this->siteId;
    }

    /** @return string Why the network could not be read, for the failure log. */
    public function reason(): string {
        return $this->reason;
    }
}
