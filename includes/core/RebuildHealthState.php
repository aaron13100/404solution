<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Unified rebuild health state for view-build and logs-hits rebuild pipelines.
 *
 * Single wp_options row for health gate state (failure count, exponential
 * backoff, trial tokens) and adaptive chunk sizing for hits rebuild.
 *
 * Created for task c632 (Bruno 507 rebuild loop).
 */
class ABJ_404_Solution_RebuildHealthState {

    const OPTION_NAME = 'abj404_rebuild_health';
    const TRIAL_LOCK_OPTION = 'abj404_rebuild_health_trial_lock';
    const MAX_COOLDOWN_SECONDS = 86400;
    const TRIAL_TTL_SECONDS = 300;
    const FAILURE_THRESHOLD = 3;
    const INITIAL_COOLDOWN_SECONDS = 300;
    const DISK_ERROR_COOLDOWN_SECONDS = 86400;
    const DAILY_MAINTENANCE_RECOVERY_SECONDS = 86400;
    const MAX_CHUNK_SIZE = 100000;
    const MIN_CHUNK_SIZE = 100;
    const CHUNK_GROWTH_FACTOR = 1.5;

    /** @var ABJ_404_Solution_Clock */
    private $clock;
    /** @var ABJ_404_Solution_Logging|null */
    private $logger;
    /** @var string|null Exact row value acquired by this instance. */
    private $trialLockValue;

    /**
     * @param ABJ_404_Solution_Clock $clock
     * @param ABJ_404_Solution_Logging|null $logger
     */
    public function __construct(ABJ_404_Solution_Clock $clock, $logger = null) {
        $this->clock = $clock;
        $this->logger = $logger;
    }

    /** @return bool */
    public function mayStartExpensiveRebuild(): bool {
        $state = $this->readState();
        if ($state === null) {
            $this->log('warn', 'Rebuild health state is corrupt; refusing expensive rebuild.');
            return false;
        }
        $gate = $state['gate'];
        $trial = $state['trial'];
        $now = $this->clock->now();
        if ($this->dailyRecoveryIsActive($trial, $now)) {
            return true;
        }
        $rawNext = $gate['next_allowed_at'] ?? 0;
        $nextAllowed = is_numeric($rawNext) ? intval($rawNext) : 0;
        if ($this->trialIsActive($trial, $now)) {
            return !$this->gateHasOpenFailureWindow($gate);
        }
        return $nextAllowed <= $now;
    }

    /** @return bool */
    public function beginExpensiveRebuildAttempt(): bool {
        if (!$this->mayStartExpensiveRebuild()) {
            return false;
        }
        $state = $this->readState();
        if ($state === null) {
            return false;
        }
        if ($this->dailyRecoveryIsActive($state['trial'], $this->clock->now())) {
            return true;
        }
        $gate = $state['gate'];
        if (!$this->gateHasOpenFailureWindow($gate)) {
            return true;
        }
        return $this->acquireTrialToken() !== null;
    }

    /** @return bool */
    public function beginDailyMaintenanceRebuildAttempt(): bool {
        if ($this->beginExpensiveRebuildAttempt()) {
            return true;
        }
        $state = $this->readState();
        if ($state === null) {
            return false;
        }
        $now = $this->clock->now();
        $rawLastDaily = $state['gate']['last_daily_maintenance_attempt_ts'] ?? 0;
        $lastDaily = is_numeric($rawLastDaily) ? intval($rawLastDaily) : 0;
        if ($lastDaily > 0 && ($now - $lastDaily) < self::DAILY_MAINTENANCE_RECOVERY_SECONDS) {
            return false;
        }
        if ($this->acquireTrialToken() === null) {
            return false;
        }
        $this->mutateState(function (array $state) use ($now): array {
            $state['gate']['last_daily_maintenance_attempt_ts'] = $now;
            $state['trial']['daily_recovery_until'] = $now + self::TRIAL_TTL_SECONDS;
            return $state;
        });
        return true;
    }

    /** Give up the trial lock row.
     *
     * Goes through the same exclusive-row primitive the claim uses rather than
     * delete_option(), so there is one write path to this row instead of two
     * that have to be kept consistent with each other.
     *
     * @return void
     */
    private function releaseTrialLock(): void {
        if ($this->trialLockValue === null) {
            return;
        }
        (new ABJ_404_Solution_ExclusiveOptionRow())->releaseIfValueIs(array(
            'optionName' => self::TRIAL_LOCK_OPTION,
            'value' => $this->trialLockValue,
        ));
        $this->trialLockValue = null;
    }

    /** @return string|null */
    public function acquireTrialToken(): ?string {
        $now = $this->clock->now();
        // Read the row from the table rather than through get_option(), whose
        // per-request cache answers a racing request with its own write, and
        // clear an expired holder conditionally on the exact value read so a
        // fresh holder is never displaced. The claim underneath is a single
        // atomic INSERT: several requests that all saw the same expired trial
        // lock still produce exactly one token.
        $lockRow = new ABJ_404_Solution_ExclusiveOptionRow();
        $existing = $lockRow->valueOf(self::TRIAL_LOCK_OPTION);
        $existingExpiryPart = explode(':', $existing, 2)[0];
        $existingExpires = is_numeric($existingExpiryPart) ? intval($existingExpiryPart) : 0;
        if ($existingExpires > 0 && $existingExpires <= $now) {
            $lockRow->releaseIfValueIs(array('optionName' => self::TRIAL_LOCK_OPTION, 'value' => $existing));
        }
        $claimValue = ABJ_404_Solution_ExclusiveOptionRow::uniqueClaimValue(
            (string)($now + self::TRIAL_TTL_SECONDS)
        );
        $added = $lockRow->claim(array('optionName' => self::TRIAL_LOCK_OPTION, 'value' => $claimValue));
        if (!$added) { return null; }
        $this->trialLockValue = $claimValue;
        try {
            $token = bin2hex(random_bytes(8));
        } catch (\Throwable $t) {
            // allow-silent-catch: random_bytes unavailable on some hosts; fallback token is sufficient for trial lock dedup.
            $token = (string)mt_rand() . '_' . (string)$now;
        }
        $this->mutateState(function (array $state) use ($token, $now): array { $state['trial'] = array('token' => $token, 'started_at' => $now, 'ttl' => self::TRIAL_TTL_SECONDS); return $state; });
        return $token;
    }

    /**
     * @param string $msg
     * @param string $class
     * @return void
     */
    public function recordFailure(string $msg, string $class = 'unknown'): void {
        $now = $this->clock->now();
        $this->mutateState(function (array $state) use ($msg, $class, $now): array {
            $gate = $state['gate'];
            $fc = is_numeric($gate['failure_count'] ?? 0) ? intval($gate['failure_count']) : 0;
            $gate['failure_count'] = $fc + 1;
            $gate['last_failure_ts'] = $now;
            $gate['last_failure_msg'] = substr($msg, 0, 500);
            $gate['last_failure_class'] = $class;
            $cd = is_numeric($gate['cooldown_seconds'] ?? self::INITIAL_COOLDOWN_SECONDS) ? intval($gate['cooldown_seconds']) : self::INITIAL_COOLDOWN_SECONDS;
            if ($class === 'disk') {
                $gate['cooldown_seconds'] = self::DISK_ERROR_COOLDOWN_SECONDS;
                $gate['next_allowed_at'] = $now + self::DISK_ERROR_COOLDOWN_SECONDS;
            } elseif (($fc + 1) >= self::FAILURE_THRESHOLD) {
                $gate['next_allowed_at'] = $now + $cd;
                $gate['cooldown_seconds'] = min(self::MAX_COOLDOWN_SECONDS, $cd * 2);
            }
            $state['gate'] = $gate;
            $state['trial'] = array('token' => '', 'started_at' => 0, 'ttl' => self::TRIAL_TTL_SECONDS, 'daily_recovery_until' => 0);
            return $state;
        });
        $this->releaseTrialLock();
        $this->log('warn', sprintf('Rebuild health: failure (class=%s). Message: %s', $class, substr($msg, 0, 200)));
    }

    /** @return void */
    public function recordSuccess(): void {
        $now = $this->clock->now();
        $this->mutateState(function (array $state) use ($now): array {
            $state['gate'] = array('failure_count' => 0, 'next_allowed_at' => 0, 'last_failure_ts' => 0, 'last_failure_msg' => '', 'last_failure_class' => '', 'cooldown_seconds' => self::INITIAL_COOLDOWN_SECONDS, 'last_success_ts' => $now);
            $state['trial'] = array('token' => '', 'started_at' => 0, 'ttl' => self::TRIAL_TTL_SECONDS, 'daily_recovery_until' => 0);
            return $state;
        });
        $this->releaseTrialLock();
    }

    /** @return void */
    public function reset(): void {
        if (function_exists('update_option')) { update_option(self::OPTION_NAME, $this->defaultState(), false); }
        $this->releaseTrialLock();
    }

    /** @return array{failure_count: int, last_failure_msg: string, last_failure_class: string, cooldown_seconds: int, next_allowed_at: int}|null */
    public function getNoticePayload(): ?array {
        $state = $this->readState();
        if ($state === null) { return array('failure_count' => 0, 'last_failure_msg' => 'Health state corrupt.', 'last_failure_class' => 'unknown', 'cooldown_seconds' => 0, 'next_allowed_at' => 0); }
        $gate = $state['gate'];
        $rawNext = $gate['next_allowed_at'] ?? 0;
        $nextAllowed = is_numeric($rawNext) ? intval($rawNext) : 0;
        if ($nextAllowed <= $this->clock->now()) { return null; }
        $rawFc = $gate['failure_count'] ?? 0;
        $rawMsg = $gate['last_failure_msg'] ?? '';
        $rawCls = $gate['last_failure_class'] ?? '';
        $rawCd = $gate['cooldown_seconds'] ?? 0;
        return array('failure_count' => is_numeric($rawFc) ? intval($rawFc) : 0, 'last_failure_msg' => is_string($rawMsg) ? $rawMsg : '', 'last_failure_class' => is_string($rawCls) ? $rawCls : '', 'cooldown_seconds' => is_numeric($rawCd) ? intval($rawCd) : 0, 'next_allowed_at' => $nextAllowed);
    }

    /** @param string $errorMessage @return string */
    public function classifyError(string $errorMessage): string {
        $lower = strtolower($errorMessage);
        foreach (array('507', 'incorrect key file', 'is full', 'table full', 'no space left', 'disk quota exceeded') as $p) { if (strpos($lower, $p) !== false) { return 'disk'; } }
        foreach (array('max_statement_time exceeded', 'query execution was interrupted') as $p) { if (strpos($lower, $p) !== false) { return 'timeout'; } }
        if (strpos($lower, 'illegal mix of collations') !== false) { return 'collation'; }
        return 'unknown';
    }

    /** @param int $idRange @return int */
    public function getHitsChunkSize(int $idRange): int {
        $state = $this->readState();
        $current = $state !== null ? ($state['hits_chunk_size']['current'] ?? null) : null;
        if ($current !== null && is_numeric($current)) { return max(self::MIN_CHUNK_SIZE, min(self::MAX_CHUNK_SIZE, intval($current))); }
        $estimated = $idRange <= 0 ? self::MAX_CHUNK_SIZE : max(self::MIN_CHUNK_SIZE, min(self::MAX_CHUNK_SIZE, intval($idRange / 10)));
        $this->mutateState(function (array $state) use ($estimated): array { $state['hits_chunk_size']['current'] = $estimated; return $state; });
        return $estimated;
    }

    /** @param int $size @return void */
    public function recordHitsChunkSuccess(int $size): void {
        $this->mutateState(function (array $state) use ($size): array { $state['hits_chunk_size']['last_successful'] = $size; if ($state['hits_chunk_size']['current'] === null) { $state['hits_chunk_size']['current'] = $size; } return $state; });
    }

    /** @return void */
    public function recordHitsChunkFailure(): void {
        $this->mutateState(function (array $state): array { $c = $state['hits_chunk_size']['current'] ?? self::MAX_CHUNK_SIZE; $state['hits_chunk_size']['current'] = intval(max(self::MIN_CHUNK_SIZE, intval(is_numeric($c) ? intval($c) : self::MAX_CHUNK_SIZE) / 2)); return $state; });
    }

    /** @param int $lastChunkSize @return void */
    public function recordFullRebuildSuccess(int $lastChunkSize): void {
        $this->mutateState(function (array $state) use ($lastChunkSize): array { $state['hits_chunk_size']['current'] = min(self::MAX_CHUNK_SIZE, intval($lastChunkSize * self::CHUNK_GROWTH_FACTOR)); $state['hits_chunk_size']['last_successful'] = $lastChunkSize; return $state; });
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    public function readState(): ?array {
        if (!function_exists('get_option')) { return $this->defaultState(); }
        $raw = get_option(self::OPTION_NAME, null);
        if ($raw === null || $raw === false) { return $this->defaultState(); }
        if (!is_array($raw) || !isset($raw['gate']) || !is_array($raw['gate'])) { return null; }
        return $this->mergeDefaults($raw);
    }

    /**
     * @param callable $mutator
     * @return void
     */
    private function mutateState(callable $mutator): void {
        $state = $this->readState();
        if ($state === null) { $state = $this->defaultState(); }
        $mutated = $mutator($state);
        if (is_array($mutated)) {
            $state = $mutated;
        }
        if (function_exists('update_option')) { update_option(self::OPTION_NAME, $state, false); }
    }

    /**
     * @param array<int|string, mixed> $raw
     * @return array<string, array<string, mixed>>
     */
    private function mergeDefaults(array $raw): array {
        $d = $this->defaultState();
        $gate = is_array($raw['gate'] ?? null) ? $raw['gate'] : array();
        $trial = is_array($raw['trial'] ?? null) ? $raw['trial'] : array();
        $chunk = is_array($raw['hits_chunk_size'] ?? null) ? $raw['hits_chunk_size'] : array();
        return array('gate' => array_merge($d['gate'], $gate), 'trial' => array_merge($d['trial'], $trial), 'hits_chunk_size' => array_merge($d['hits_chunk_size'], $chunk));
    }

    /** @return array<string, array<string, mixed>> */
    private function defaultState(): array {
        return array(
            'gate' => array('failure_count' => 0, 'next_allowed_at' => 0, 'last_failure_ts' => 0, 'last_failure_msg' => '', 'last_failure_class' => '', 'cooldown_seconds' => self::INITIAL_COOLDOWN_SECONDS, 'last_success_ts' => 0),
            'trial' => array('token' => '', 'started_at' => 0, 'ttl' => self::TRIAL_TTL_SECONDS, 'daily_recovery_until' => 0),
            'hits_chunk_size' => array('last_successful' => null, 'current' => null),
        );
    }

    /**
     * @param array<string, mixed> $gate
     * @return bool
     */
    private function gateHasOpenFailureWindow(array $gate): bool {
        $rawFailureCount = $gate['failure_count'] ?? 0;
        $failureCount = is_numeric($rawFailureCount) ? intval($rawFailureCount) : 0;
        $rawNext = $gate['next_allowed_at'] ?? 0;
        $nextAllowed = is_numeric($rawNext) ? intval($rawNext) : 0;
        $rawLastFailure = $gate['last_failure_ts'] ?? 0;
        $lastFailure = is_numeric($rawLastFailure) ? intval($rawLastFailure) : 0;
        $rawLastSuccess = $gate['last_success_ts'] ?? 0;
        $lastSuccess = is_numeric($rawLastSuccess) ? intval($rawLastSuccess) : 0;
        return $failureCount > 0 || $nextAllowed > 0 || $lastFailure > $lastSuccess;
    }

    /**
     * @param array<string, mixed> $trial
     * @param int $now
     * @return bool
     */
    private function trialIsActive(array $trial, int $now): bool {
        $rawToken = $trial['token'] ?? '';
        $trialToken = is_string($rawToken) ? $rawToken : '';
        $rawStarted = $trial['started_at'] ?? 0;
        $trialStarted = is_numeric($rawStarted) ? intval($rawStarted) : 0;
        $rawTtl = $trial['ttl'] ?? 0;
        $trialTtl = is_numeric($rawTtl) ? intval($rawTtl) : 0;
        return $trialToken !== '' && $trialStarted > 0 && ($now - $trialStarted) < $trialTtl;
    }

    /**
     * @param array<string, mixed> $trial
     * @param int $now
     * @return bool
     */
    private function dailyRecoveryIsActive(array $trial, int $now): bool {
        $rawUntil = $trial['daily_recovery_until'] ?? 0;
        $until = is_numeric($rawUntil) ? intval($rawUntil) : 0;
        return $until > $now;
    }

    /**
     * @param string $level
     * @param string $message
     * @return void
     */
    private function log(string $level, string $message): void {
        if ($this->logger === null) { return; }
        if ($level === 'warn' && method_exists($this->logger, 'warn')) { $this->logger->warn($message); }
        elseif (method_exists($this->logger, 'debugMessage')) { $this->logger->debugMessage($message); }
    }
}
