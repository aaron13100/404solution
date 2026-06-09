<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parameter carrier for ViewSnapshotWarmupOrchestrator's stage dispatch.
 *
 * Replaces the four-positional parameter set
 * ($host, $sub, $tableOptions, $stageOptions) that was previously threaded
 * through dispatchWarmupStage / runRowsWarmupStage / runCountWarmupStage.
 * Two of the four parameters were adjacent same-type arrays
 * (array<string, mixed> $tableOptions then array<string, mixed> $stageOptions),
 * making a positional swap undetectable at the call site. Bundling them in
 * a named-field carrier makes the swap impossible: callers reference
 * $ctx->tableOptions and $ctx->stageOptions by name.
 *
 * Immutable by convention: construct once at the entry to
 * warmViewTableSnapshotStage and pass to the private stage methods.
 */
final class ABJ_404_Solution_ViewSnapshotWarmupContext {

    /** @var ABJ_404_Solution_ViewSnapshotCacheHostInterface */
    public $host;

    /** @var string */
    public $sub;

    /** @var array<string, mixed> */
    public $tableOptions;

    /** @var array<string, mixed> Same shape as $tableOptions plus warmup-only knobs (timeout, throw-on-error). */
    public $stageOptions;

    /**
     * @param ABJ_404_Solution_ViewSnapshotCacheHostInterface $host
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param array<string, mixed> $stageOptions
     */
    private function __construct(
        ABJ_404_Solution_ViewSnapshotCacheHostInterface $host,
        string $sub,
        array $tableOptions,
        array $stageOptions
    ) {
        $this->host = $host;
        $this->sub = $sub;
        $this->tableOptions = $tableOptions;
        $this->stageOptions = $stageOptions;
    }

    /**
     * @param ABJ_404_Solution_ViewSnapshotCacheHostInterface $host
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param array<string, mixed> $stageOptions
     * @return self
     */
    public static function create(
        ABJ_404_Solution_ViewSnapshotCacheHostInterface $host,
        string $sub,
        array $tableOptions,
        array $stageOptions
    ): self {
        return new self($host, $sub, $tableOptions, $stageOptions);
    }
}
