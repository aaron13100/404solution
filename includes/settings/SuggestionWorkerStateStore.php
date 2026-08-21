<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Atomic state transitions for one asynchronous suggestion worker.
 *
 * The AJAX handler owns request validation and HTTP termination; this store
 * owns the transient read/decide/write critical sections so those concerns
 * cannot drift apart across claim, completion, and fatal-shutdown paths.
 */
final class ABJ_404_Solution_SuggestionWorkerStateStore {

    public const OUTCOME_CLAIMED = 'claimed';
    public const OUTCOME_STALE = 'stale';
    public const OUTCOME_BUSY = 'busy';
    public const OUTCOME_WRITE_FAILED = 'write_failed';
    public const OUTCOME_STORED = 'stored';

    /** @var ABJ_404_Solution_SynchronizationUtils */
    private $synchronizer;

    /** @var ABJ_404_Solution_Clock */
    private $clock;

    public function __construct(
        ABJ_404_Solution_SynchronizationUtils $synchronizer,
        ABJ_404_Solution_Clock $clock
    ) {
        $this->synchronizer = $synchronizer;
        $this->clock = $clock;
    }

    /**
     * @param array{transientKey: string, normalizedURL: string, token: string} $request
     * @return array{status: string, startedAt: int}
     */
    public function claimWorker(array $request): array {
        $lockKey = ABJ_404_Solution_SuggestionTransient::lockKeyForNormalizedUrl($request['normalizedURL']);
        $owner = $this->synchronizer->synchronizerAcquireLockTry($lockKey);
        if ($owner === '') {
            return array('status' => self::OUTCOME_BUSY, 'startedAt' => 0);
        }

        try {
            $current = ABJ_404_Solution_SuggestionTransient::fromRaw(get_transient($request['transientKey']));
            if ($current === null || $current->isComplete() || $current->getToken() !== $request['token']) {
                return array('status' => self::OUTCOME_STALE, 'startedAt' => 0);
            }
            $now = $this->clock->now();
            if ($current->isPending() && $current->isClaimed() && !$current->isWorkerStuck($now)) {
                return array('status' => self::OUTCOME_STALE, 'startedAt' => 0);
            }

            $createdAt = $current->getCreatedAt() > 0 ? $current->getCreatedAt() : $now;
            $stored = set_transient(
                $request['transientKey'],
                ABJ_404_Solution_SuggestionTransient::pendingArray(
                    $current->getUrl(),
                    $request['token'],
                    $now,
                    $createdAt
                ),
                ABJ_404_Solution_SuggestionTransient::PENDING_TTL_SECONDS
            );
            return array(
                'status' => $stored ? self::OUTCOME_CLAIMED : self::OUTCOME_WRITE_FAILED,
                'startedAt' => $stored ? $now : 0,
            );
        } finally {
            $this->synchronizer->synchronizerReleaseLock($owner, $lockKey);
        }
    }

    /**
     * @param array{transientKey: string, normalizedURL: string, requestedURL: string, token: string, workerStartedAt: int, suggestionsPacket: array<int, mixed>} $request
     */
    public function publishCompleted(array $request): string {
        $lockKey = ABJ_404_Solution_SuggestionTransient::lockKeyForNormalizedUrl($request['normalizedURL']);
        $owner = $this->synchronizer->synchronizerAcquireLockTry($lockKey);
        if ($owner === '') {
            return self::OUTCOME_BUSY;
        }

        try {
            $current = ABJ_404_Solution_SuggestionTransient::fromRaw(get_transient($request['transientKey']));
            if ($current === null || $current->isComplete() || $current->getToken() !== $request['token']
                || $current->getStartedAt() !== $request['workerStartedAt']
            ) {
                return self::OUTCOME_STALE;
            }
            $stored = set_transient(
                $request['transientKey'],
                ABJ_404_Solution_SuggestionTransient::completeArray(
                    $request['requestedURL'],
                    $request['suggestionsPacket'],
                    $this->clock->now(),
                    $request['token']
                ),
                ABJ_404_Solution_SuggestionTransient::COMPLETE_TTL_SECONDS
            );
            return $stored ? self::OUTCOME_STORED : self::OUTCOME_WRITE_FAILED;
        } finally {
            $this->synchronizer->synchronizerReleaseLock($owner, $lockKey);
        }
    }

    /**
     * @param array{transientKey: string, normalizedURL: string, token: string, workerStartedAt: int|null} $request
     */
    public function publishCrashMarker(array $request): string {
        $lockKey = ABJ_404_Solution_SuggestionTransient::lockKeyForNormalizedUrl($request['normalizedURL']);
        $owner = $this->synchronizer->synchronizerAcquireLockTry($lockKey);
        if ($owner === '') {
            return self::OUTCOME_BUSY;
        }

        try {
            $current = ABJ_404_Solution_SuggestionTransient::fromRaw(get_transient($request['transientKey']));
            if ($current === null || $current->isComplete() || $current->getToken() !== $request['token']
                || ($request['workerStartedAt'] !== null
                    && $current->getStartedAt() !== $request['workerStartedAt'])
            ) {
                return self::OUTCOME_STALE;
            }
            $stored = set_transient(
                $request['transientKey'],
                ABJ_404_Solution_SuggestionTransient::errorArray($request['token']),
                ABJ_404_Solution_SuggestionTransient::ERROR_TTL_SECONDS
            );
            return $stored ? self::OUTCOME_STORED : self::OUTCOME_WRITE_FAILED;
        } finally {
            $this->synchronizer->synchronizerReleaseLock($owner, $lockKey);
        }
    }
}
