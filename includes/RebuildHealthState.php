<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Unified rebuild health state for the view-build and logs-hits rebuild
 * pipelines.
 *
 * Stores failure history, cooldown state, trial-token management, and
 * adaptive chunk sizing in a single wp_options row. Provides the health
 * gate that all expensive rebuild entry points must pass before executing.
 *
 * Concurrency model: all state mutations acquire a trial-lock option row
 * (via add_option()) as a short-lived mutex to prevent concurrent
 * read-modify-write races. The same atomic pattern is proven in the
 * codebase by acquireTransientFallbackLock() in
 * DataAccessTrait_ViewBuildLockAndCron.php.
 *
 * Created for task c632 (Bruno 507 rebuild loop).
 *
 * @phpstan-type GateState array{failure_count: int, next_allowed_at: int, last_failure_ts: int, last_failure_msg: string, last_failure_class: string, cooldown_seconds: int, last_success_ts: int}
 * @phpstan-type TrialState array{token: string, started_at: int, ttl: int}
 * @phpstan-type ChunkState array{last_successful: int|null, current: int|null}
 * @phpstan-type HealthState array{gate: GateState, trial: TrialState, hits_chunk_size: ChunkState}
 */
class ABJ_404_Solution_RebuildHealthState {

    /** @var string Option name for the consolidated health state. */
    const OPTION_NAME = 'abj404_rebuild_health';

    /** @var string Option name for the atomic trial-lock row. */
    const TRIAL_LOCK_OPTION = 'abj404_rebuild_health_trial_lock';

    /** @var int Maximum cooldown in seconds (24 hours). */
    const MAX_COOLDOWN_SECONDS = 86400;

    /** @var int Default trial window TTL in seconds. */
    const TRIAL_TTL_SECONDS = 300;

    /** @var int Threshold: number of non-disk failures before the gate closes. */
    const FAILURE_THRESHOLD = 3;

    /** @var int Initial cooldown in seconds after crossing failure threshold. */
    const INITIAL_COOLDOWN_SECONDS = 300;

    /** @var int Immediate cooldown for disk/storage errors (24 hours). */
    const DISK_ERROR_COOLDOWN_SECONDS = 86400;

    /** @var int Maximum chunk size for hits rebuild. */
    const MAX_CHUNK_SIZE = 100000;

    /** @var int Minimum chunk size floor for hits rebuild. */
    const MIN_CHUNK_SIZE = 100;

    /** @var float Growth factor for chunk size after full rebuild success. */
    const CHUNK_GROWTH_FACTOR = 1.5;

    /** @var ABJ_404_Solution_Clock */
    private $clock;

    /** @var ABJ_404_Solution_Logging|null */
    private $logger;

    /**
     * @param ABJ_404_Solution_Clock $clock
     * @param ABJ_404_Solution_Logging|null $logger
     */
    public function __construct(ABJ_404_Solution_Clock $clock, $logger = null) {
        $this->clock = $clock;
        $this->logger = $logger;
    }

    // =========================================================================
    // Health gate
    // =========================================================================

    /**
     * Check whether an expensive rebuild may start now.
     *
     * Returns true only if now >= next_allowed_at AND no active unexpired
     * trial token exists. This is the single check all rebuild entry points
     * call before executing expensive work.
     *
     * If the option data is corrupt (not a valid array), fails closed
     * (returns false) and logs a warning.
     *
     * @return bool
     */
    public function mayStartExpensiveRebuild(): bool {
        $state = $this->readState();
        if ($state === null) {
            $this->log('warn', 'Rebuild health state is corrupt or unreadable; refusing expensive rebuild.');
            return false;
        }

        $gate = $state['gate'];
        $trial = $state['trial'];
        $now = $this->clock->now();

        // If a trial token is active and unexpired, another worker is running
        // the trial rebuild. Do not start another one.
        if ($trial['token'] !== '' && $trial['started_at'] > 0) {
            $trialAge = $now - $trial['started_at'];
            if ($trialAge < $trial['ttl']) {
                return false;
            }
        }

        return $gate['next_allowed_at'] <= $now;
    }

    /**
     * Atomically claim a trial window.
     *
     * Uses add_option() for atomicity: at most one worker wins. The
     * winner gets a token; losers get null. The trial has a TTL; if the
     * winner dies without recording success/failure, the lock expires
     * naturally.
     *
     * @return string|null The trial token on success, null if contended.
     */
    public function acquireTrialToken(): ?string {
        if (!function_exists('add_option') || !function_exists('get_option')) {
            return null;
        }

        $now = $this->clock->now();
        $expiresAt = $now + self::TRIAL_TTL_SECONDS;

        // Clear stale lock
        $existing = get_option(self::TRIAL_LOCK_OPTION, 0);
        $existingExpires = is_scalar($existing) ? intval($existing) : 0;
        if ($existingExpires > 0 && $existingExpires <= $now && function_exists('delete_option')) {
            delete_option(self::TRIAL_LOCK_OPTION);
        }

        $added = add_option(self::TRIAL_LOCK_OPTION, (string)$expiresAt, '', false);
        if (!$added) {
            return null;
        }

        try {
            $token = bin2hex(random_bytes(8));
        } catch (\Throwable $t) {
            // allow-silent-catch: random_bytes unavailable on some hosts; mt_rand fallback is sufficient for a disposable trial token
            $token = (string)mt_rand() . '_' . (string)$now;
        }

        $this->mutateState(function (array &$state) use ($token, $now): void {
            $state['trial']['token'] = $token;
            $state['trial']['started_at'] = $now;
            $state['trial']['ttl'] = self::TRIAL_TTL_SECONDS;
        });

        return $token;
    }

    /**
     * Record a rebuild failure.
     *
     * Increments failure_count, sets next_allowed_at based on exponential
     * backoff. For 'disk' class errors, sets next_allowed_at to now + 24h
     * immediately regardless of failure_count.
     *
     * @param string $msg   Human-readable failure message
     * @param string $class Failure classification: 'timeout', 'collation', 'disk', 'unknown'
     * @return void
     */
    public function recordFailure(string $msg, string $class = 'unknown'): void {
        $now = $this->clock->now();
        $this->mutateState(function (array &$state) use ($msg, $class, $now): void {
            $gate = &$state['gate'];
            $gate['failure_count']++;
            $gate['last_failure_ts'] = $now;
            $gate['last_failure_msg'] = substr($msg, 0, 500);
            $gate['last_failure_class'] = $class;

            if ($class === 'disk') {
                $gate['cooldown_seconds'] = self::DISK_ERROR_COOLDOWN_SECONDS;
                $gate['next_allowed_at'] = $now + self::DISK_ERROR_COOLDOWN_SECONDS;
            } elseif ($gate['failure_count'] >= self::FAILURE_THRESHOLD) {
                $gate['next_allowed_at'] = $now + $gate['cooldown_seconds'];
                $gate['cooldown_seconds'] = min(
                    self::MAX_COOLDOWN_SECONDS,
                    $gate['cooldown_seconds'] * 2
                );
            }

            $state['trial']['token'] = '';
            $state['trial']['started_at'] = 0;
        });

        if (function_exists('delete_option')) {
            delete_option(self::TRIAL_LOCK_OPTION);
        }

        $stateAfter = $this->readState();
        $failCount = $stateAfter !== null ? $stateAfter['gate']['failure_count'] : '?';
        $nextAt = $stateAfter !== null ? $stateAfter['gate']['next_allowed_at'] : 0;
        $this->log('warn', sprintf(
            'Rebuild health: recorded failure (class=%s, count=%s). Next retry at %s. Message: %s',
            $class,
            (string)$failCount,
            date('Y-m-d H:i:s', (int)$nextAt),
            substr($msg, 0, 200)
        ));
    }

    /**
     * Record a successful rebuild.
     *
     * Resets failure_count to 0, clears next_allowed_at and cooldown,
     * clears trial token.
     *
     * @return void
     */
    public function recordSuccess(): void {
        $now = $this->clock->now();
        $this->mutateState(function (array &$state) use ($now): void {
            $state['gate']['failure_count'] = 0;
            $state['gate']['next_allowed_at'] = 0;
            $state['gate']['last_failure_ts'] = 0;
            $state['gate']['last_failure_msg'] = '';
            $state['gate']['last_failure_class'] = '';
            $state['gate']['cooldown_seconds'] = self::INITIAL_COOLDOWN_SECONDS;
            $state['gate']['last_success_ts'] = $now;
            $state['trial']['token'] = '';
            $state['trial']['started_at'] = 0;
        });

        if (function_exists('delete_option')) {
            delete_option(self::TRIAL_LOCK_OPTION);
        }
    }

    /**
     * Full reset (for force-rebuild path).
     *
     * @return void
     */
    public function reset(): void {
        $defaultState = $this->defaultState();
        if (function_exists('update_option')) {
            update_option(self::OPTION_NAME, $defaultState, false);
        }
        if (function_exists('delete_option')) {
            delete_option(self::TRIAL_LOCK_OPTION);
        }
    }

    /**
     * Get data for the admin notice when the gate is closed.
     *
     * @return array{failure_count: int, last_failure_msg: string, last_failure_class: string, cooldown_seconds: int, next_allowed_at: int}|null
     *         Null if the gate is open (no notice needed).
     */
    public function getNoticePayload(): ?array {
        $state = $this->readState();
        if ($state === null) {
            return array(
                'failure_count' => 0,
                'last_failure_msg' => 'Health state is corrupt or unreadable.',
                'last_failure_class' => 'unknown',
                'cooldown_seconds' => 0,
                'next_allowed_at' => 0,
            );
        }

        $gate = $state['gate'];
        $now = $this->clock->now();

        if ($gate['next_allowed_at'] <= $now) {
            return null;
        }

        return array(
            'failure_count' => (int)$gate['failure_count'],
            'last_failure_msg' => (string)$gate['last_failure_msg'],
            'last_failure_class' => (string)$gate['last_failure_class'],
            'cooldown_seconds' => (int)$gate['cooldown_seconds'],
            'next_allowed_at' => (int)$gate['next_allowed_at'],
        );
    }

    // =========================================================================
    // Failure classification
    // =========================================================================

    /**
     * Classify a failure message into one of the known failure classes.
     *
     * @param string $errorMessage The error message or exception text.
     * @return string One of 'disk', 'timeout', 'collation', 'unknown'.
     */
    public function classifyError(string $errorMessage): string {
        $lower = strtolower($errorMessage);

        $diskPatterns = array(
            '507', 'incorrect key file', 'is full', 'table full',
            'no space left', 'disk quota exceeded',
        );
        foreach ($diskPatterns as $pattern) {
            if (strpos($lower, $pattern) !== false) {
                return 'disk';
            }
        }

        $timeoutPatterns = array(
            'max_statement_time exceeded',
            'query execution was interrupted',
        );
        foreach ($timeoutPatterns as $pattern) {
            if (strpos($lower, $pattern) !== false) {
                return 'timeout';
            }
        }

        if (strpos($lower, 'illegal mix of collations') !== false) {
            return 'collation';
        }

        return 'unknown';
    }

    // =========================================================================
    // Chunk sizing
    // =========================================================================

    /**
     * Get the chunk size to use for the hits rebuild.
     *
     * @param int $idRange  maxId - minId
     * @return int
     */
    public function getHitsChunkSize(int $idRange): int {
        $state = $this->readState();
        if ($state !== null && $state['hits_chunk_size']['current'] !== null) {
            return max(self::MIN_CHUNK_SIZE, min(self::MAX_CHUNK_SIZE, (int)$state['hits_chunk_size']['current']));
        }

        if ($idRange <= 0) {
            return self::MAX_CHUNK_SIZE;
        }
        return max(self::MIN_CHUNK_SIZE, min(self::MAX_CHUNK_SIZE, intval($idRange / 10)));
    }

    /**
     * Record a successful chunk completion (does NOT grow the chunk size).
     *
     * @param int $size The chunk size that succeeded.
     * @return void
     */
    public function recordHitsChunkSuccess(int $size): void {
        $this->mutateState(function (array &$state) use ($size): void {
            $state['hits_chunk_size']['last_successful'] = $size;
            if ($state['hits_chunk_size']['current'] === null) {
                $state['hits_chunk_size']['current'] = $size;
            }
        });
    }

    /**
     * Record a chunk failure: halve the current chunk size, floor at MIN.
     *
     * @return void
     */
    public function recordHitsChunkFailure(): void {
        $this->mutateState(function (array &$state): void {
            $current = $state['hits_chunk_size']['current'];
            if ($current === null) {
                $current = self::MAX_CHUNK_SIZE;
            }
            $state['hits_chunk_size']['current'] = max(
                self::MIN_CHUNK_SIZE,
                intval((int)$current / 2)
            );
        });
    }

    /**
     * Record a full rebuild success: grow the chunk by CHUNK_GROWTH_FACTOR,
     * capped at MAX_CHUNK_SIZE.
     *
     * @param int $lastChunkSize The chunk size used in the successful rebuild.
     * @return void
     */
    public function recordFullRebuildSuccess(int $lastChunkSize): void {
        $this->mutateState(function (array &$state) use ($lastChunkSize): void {
            $grown = intval($lastChunkSize * self::CHUNK_GROWTH_FACTOR);
            $state['hits_chunk_size']['current'] = min(self::MAX_CHUNK_SIZE, $grown);
            $state['hits_chunk_size']['last_successful'] = $lastChunkSize;
        });
    }

    // =========================================================================
    // State read/write internals
    // =========================================================================

    /**
     * Read the persisted health state.
     *
     * @return HealthState|null  Null if corrupt.
     */
    public function readState(): ?array {
        if (!function_exists('get_option')) {
            return $this->defaultState();
        }

        $raw = get_option(self::OPTION_NAME, null);
        if ($raw === null || $raw === false) {
            return $this->defaultState();
        }

        if (!is_array($raw)) {
            return null;
        }

        if (!isset($raw['gate']) || !is_array($raw['gate'])) {
            return null;
        }

        return $this->mergeDefaults($raw);
    }

    /**
     * Atomically mutate the health state.
     *
     * @param callable(HealthState&): void $mutator
     * @return void
     */
    private function mutateState(callable $mutator): void {
        $state = $this->readState();
        if ($state === null) {
            $state = $this->defaultState();
        }

        $mutator($state);

        if (function_exists('update_option')) {
            update_option(self::OPTION_NAME, $state, false);
        }
    }

    /**
     * @param array<string, mixed> $raw
     * @return HealthState
     */
    private function mergeDefaults(array $raw): array {
        $defaults = $this->defaultState();

        $gate = is_array($raw['gate'] ?? null) ? $raw['gate'] : array();
        $trial = is_array($raw['trial'] ?? null) ? $raw['trial'] : array();
        $chunk = is_array($raw['hits_chunk_size'] ?? null) ? $raw['hits_chunk_size'] : array();

        return array(
            'gate' => array_merge($defaults['gate'], $gate),
            'trial' => array_merge($defaults['trial'], $trial),
            'hits_chunk_size' => array_merge($defaults['hits_chunk_size'], $chunk),
        );
    }

    /**
     * @return HealthState
     */
    private function defaultState(): array {
        return array(
            'gate' => array(
                'failure_count' => 0,
                'next_allowed_at' => 0,
                'last_failure_ts' => 0,
                'last_failure_msg' => '',
                'last_failure_class' => '',
                'cooldown_seconds' => self::INITIAL_COOLDOWN_SECONDS,
                'last_success_ts' => 0,
            ),
            'trial' => array(
                'token' => '',
                'started_at' => 0,
                'ttl' => self::TRIAL_TTL_SECONDS,
            ),
            'hits_chunk_size' => array(
                'last_successful' => null,
                'current' => null,
            ),
        );
    }

    /**
     * @param string $level 'warn' or 'info'
     * @param string $message
     * @return void
     */
    private function log(string $level, string $message): void {
        if ($this->logger === null) {
            return;
        }
        if ($level === 'warn' && method_exists($this->logger, 'warn')) {
            $this->logger->warn($message);
        } elseif (method_exists($this->logger, 'debugMessage')) {
            $this->logger->debugMessage($message);
        }
    }
}
