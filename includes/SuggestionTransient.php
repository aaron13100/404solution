<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Typed value object for the `abj404_suggest_<md5(url)>` WP transient.
 *
 * Boundary normalizer (task: type-pressure at module boundaries).
 * The transient is the message bus between two producers and three
 * consumers:
 *
 *   Producers (writers):
 *     1. ABJ_404_Solution_SpellChecker::triggerAsyncSuggestionComputation
 *        (creates 'pending', started=0, with a fresh token)
 *     2. ABJ_404_Solution_SpellChecker::cacheComputedSuggestionsForShortcode
 *        (creates 'complete' directly, no token, when synchronous spell-check
 *         beat the async worker)
 *     3. ABJ_404_Solution_Ajax_SuggestionCompute::computeSuggestions
 *        (transitions 'pending' to 'pending+started' on claim, then to
 *         'complete' on finish, or to 'error' on shutdown crash)
 *
 *   Consumers (readers):
 *     1. ABJ_404_Solution_Ajax_SuggestionPolling::pollSuggestions
 *        (branches on status; checks worker-stuck / dispatch-stuck windows)
 *     2. ABJ_404_Solution_ShortCode::renderSuggestionsShortcode
 *        (renders 'complete' results directly, falls back for 'pending')
 *     3. ABJ_404_Solution_Ajax_SuggestionCompute (re-reads its own transient
 *        to check the token gate and the worker-claim state)
 *
 * Without this normalizer, each consumer reinvented its own inline
 * defensive parsing (`isset && is_scalar && (int)` chains, `is_array &&
 * isset && is_string` chains for every field). That meant any new field
 * had to be defended five places, and a malformed-payload case in one
 * consumer could not catch a sibling regression in another. The
 * normalizer pulls every shape probe into one place. All consumers
 * branch on `fromRaw()` returning null vs. a typed VO and read fields
 * via accessors that already enforce the contract.
 *
 * Schema (after normalization):
 *
 *   - status  : 'pending' | 'complete' | 'error'        (always present)
 *   - url     : string                                  ('' if absent)
 *   - token   : string                                  ('' if absent)
 *   - started : int >= 0                                (0 = no worker yet)
 *   - created : int >= 0                                (0 if absent)
 *   - completed : int >= 0                              (0 if absent)
 *   - suggestionsPacket : list, two-tuple [permalinks, rowType]
 *                                                      ([] if absent)
 *
 * Construct via fromRaw() (consumer side) or the pendingArray() /
 * completeArray() / errorArray() factories (producer side). The factory
 * methods return associative arrays ready to feed to set_transient(),
 * so producers and consumers cannot drift on field names or types.
 */
final class ABJ_404_Solution_SuggestionTransient {

    public const STATUS_PENDING  = 'pending';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_ERROR    = 'error';

    /**
     * Worker is presumed dead after this many seconds since claim
     * (started > 0). Matches the recovery window in
     * Ajax_SuggestionCompute::computeSuggestions; if changed there, change
     * here as well (the constant is the single source of truth post-VO).
     */
    public const WORKER_STUCK_SECONDS = 90;

    /**
     * Dispatch is presumed dead after this many seconds since transient
     * creation when no worker has claimed (started == 0). Mirrors the
     * dispatch-no-show window in Ajax_SuggestionPolling.
     */
    public const DISPATCH_STUCK_SECONDS = 15;

    /** @var string */
    private $status;

    /** @var string */
    private $url;

    /** @var string */
    private $token;

    /** @var int */
    private $startedAt;

    /** @var int */
    private $createdAt;

    /** @var int */
    private $completedAt;

    /** @var array<int, mixed> */
    private $suggestionsPacket;

    /**
     * @param array<int, mixed> $suggestionsPacket
     */
    private function __construct(
        string $status,
        string $url,
        string $token,
        int $startedAt,
        int $createdAt,
        int $completedAt,
        array $suggestionsPacket
    ) {
        $this->status = $status;
        $this->url = $url;
        $this->token = $token;
        $this->startedAt = $startedAt;
        $this->createdAt = $createdAt;
        $this->completedAt = $completedAt;
        $this->suggestionsPacket = $suggestionsPacket;
    }

    /**
     * Normalize a raw get_transient() return into a typed VO, or null
     * when the payload is unrecoverably malformed (not an array, missing
     * status, status not in the documented enum).
     *
     * Callers branch on null vs. VO; they MUST NOT shape-probe the raw
     * payload themselves.
     *
     * @param mixed $raw
     */
    public static function fromRaw($raw): ?self {
        if (!is_array($raw)) {
            return null;
        }
        if (!isset($raw['status']) || !is_string($raw['status'])) {
            return null;
        }
        $status = $raw['status'];
        if ($status !== self::STATUS_PENDING
            && $status !== self::STATUS_COMPLETE
            && $status !== self::STATUS_ERROR
        ) {
            return null;
        }

        $url = self::coerceString($raw, 'url');
        $token = self::coerceString($raw, 'token');
        $startedAt = self::coerceNonNegativeInt($raw, 'started');
        $createdAt = self::coerceNonNegativeInt($raw, 'created');
        $completedAt = self::coerceNonNegativeInt($raw, 'completed');
        $packet = self::coerceSuggestionsPacket($raw);

        return new self($status, $url, $token, $startedAt, $createdAt, $completedAt, $packet);
    }

    /**
     * Build the array shape for a freshly-triggered pending transient
     * (before any worker has claimed). Producer side of the boundary.
     *
     * @return array{status: string, url: string, started: int, created: int, token: string}
     */
    public static function pendingArray(string $url, string $token, int $startedAt, int $createdAt): array {
        return [
            'status'  => self::STATUS_PENDING,
            'url'     => $url,
            'started' => max(0, $startedAt),
            'created' => max(0, $createdAt),
            'token'   => $token,
        ];
    }

    /**
     * Build the array shape for a completed computation. Producer side
     * of the boundary.
     *
     * @param array<int, mixed> $suggestionsPacket Two-tuple from spell-checker.
     * @return array{status: string, url: string, suggestions: array<int, mixed>, completed: int, token: string}
     */
    public static function completeArray(string $url, array $suggestionsPacket, int $completedAt, string $token): array {
        return [
            'status'      => self::STATUS_COMPLETE,
            'url'         => $url,
            'suggestions' => $suggestionsPacket,
            'completed'   => max(0, $completedAt),
            'token'       => $token,
        ];
    }

    /**
     * Build the array shape for the shutdown-handler crash marker.
     * Producer side of the boundary.
     *
     * @return array{status: string, token: string}
     */
    public static function errorArray(string $token): array {
        return [
            'status' => self::STATUS_ERROR,
            'token'  => $token,
        ];
    }

    public function getStatus(): string {
        return $this->status;
    }

    public function isPending(): bool {
        return $this->status === self::STATUS_PENDING;
    }

    public function isComplete(): bool {
        return $this->status === self::STATUS_COMPLETE;
    }

    public function isError(): bool {
        return $this->status === self::STATUS_ERROR;
    }

    public function getUrl(): string {
        return $this->url;
    }

    public function getToken(): string {
        return $this->token;
    }

    public function getStartedAt(): int {
        return $this->startedAt;
    }

    public function getCreatedAt(): int {
        return $this->createdAt;
    }

    public function getCompletedAt(): int {
        return $this->completedAt;
    }

    /**
     * True iff a worker has set started > 0 (claimed the work).
     */
    public function isClaimed(): bool {
        return $this->startedAt > 0;
    }

    /**
     * @return array<int, mixed>
     */
    public function getSuggestionsPacket(): array {
        return $this->suggestionsPacket;
    }

    /**
     * True iff a worker claimed the job but didn't finish within
     * WORKER_STUCK_SECONDS. Only meaningful for status=pending. When
     * unclaimed (started=0), always returns false.
     */
    public function isWorkerStuck(int $now): bool {
        if ($this->startedAt <= 0) {
            return false;
        }
        return ($now - $this->startedAt) > self::WORKER_STUCK_SECONDS;
    }

    /**
     * True iff no worker has claimed the job and the dispatch window
     * has expired since creation. Only meaningful for status=pending.
     */
    public function isDispatchStuck(int $now): bool {
        if ($this->startedAt > 0) {
            return false;
        }
        if ($this->createdAt <= 0) {
            return false;
        }
        return ($now - $this->createdAt) > self::DISPATCH_STUCK_SECONDS;
    }

    /**
     * @param array<mixed, mixed> $raw
     */
    private static function coerceString(array $raw, string $key): string {
        if (!isset($raw[$key])) {
            return '';
        }
        $v = $raw[$key];
        return is_string($v) ? $v : '';
    }

    /**
     * @param array<mixed, mixed> $raw
     */
    private static function coerceNonNegativeInt(array $raw, string $key): int {
        if (!isset($raw[$key])) {
            return 0;
        }
        $v = $raw[$key];
        if (is_int($v)) {
            return $v < 0 ? 0 : $v;
        }
        // PHP's serialize/unserialize is type-preserving, but some
        // object-cache plugins re-encode through JSON, which makes
        // ints come back as floats or numeric strings. Accept those.
        if (is_float($v)) {
            $i = (int)$v;
            return $i < 0 ? 0 : $i;
        }
        if (is_string($v) && is_numeric($v)) {
            $i = (int)$v;
            return $i < 0 ? 0 : $i;
        }
        return 0;
    }

    /**
     * @param array<mixed, mixed> $raw
     * @return array<int, mixed>
     */
    private static function coerceSuggestionsPacket(array $raw): array {
        if (!isset($raw['suggestions']) || !is_array($raw['suggestions'])) {
            return [];
        }
        return array_values($raw['suggestions']);
    }
}
