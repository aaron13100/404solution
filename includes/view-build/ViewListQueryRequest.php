<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Immutable parameter carrier for the redirects-for-view query family.
 *
 * Exists to make the call sites self-describing and to prevent same-type
 * positional swaps (two bools, two ints) in the legacy 6-positional
 * signature of getRedirectsForViewQuery.
 */
final class ABJ_404_Solution_ViewListQueryRequest {

    /** @var string */
    public $sub;

    /** @var array<string, mixed> */
    public $tableOptions;

    /** @var bool */
    public $queryAllRowsAtOnce;

    /** @var int */
    public $limitStart;

    /** @var int */
    public $limitEnd;

    /** @var bool */
    public $selectCountOnly;

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param bool $queryAllRowsAtOnce
     * @param int $limitStart
     * @param int $limitEnd
     * @param bool $selectCountOnly
     */
    private function __construct(
        string $sub,
        array $tableOptions,
        bool $queryAllRowsAtOnce,
        int $limitStart,
        int $limitEnd,
        bool $selectCountOnly
    ) {
        $this->sub = $sub;
        $this->tableOptions = $tableOptions;
        $this->queryAllRowsAtOnce = $queryAllRowsAtOnce;
        $this->limitStart = $limitStart;
        $this->limitEnd = $limitEnd;
        $this->selectCountOnly = $selectCountOnly;
    }

    /**
     * Paginated data read for a list-table page.
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param int $limitStart
     * @param int $limitEnd
     */
    public static function forPage(string $sub, array $tableOptions, int $limitStart, int $limitEnd): self {
        return new self($sub, $tableOptions, false, $limitStart, $limitEnd, false);
    }

    /**
     * Count-only read (no data rows; SELECT COUNT(*)).
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     */
    public static function forCount(string $sub, array $tableOptions): self {
        return new self($sub, $tableOptions, false, 0, 0, true);
    }

    /**
     * Read all rows at once (export / no pagination).
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     */
    public static function forAllRows(string $sub, array $tableOptions): self {
        return new self($sub, $tableOptions, true, 0, 0, false);
    }
}
