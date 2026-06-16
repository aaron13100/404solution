<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parameter object for the destination-warning resolution facade,
 * {@see ABJ_404_Solution_View_RedirectsTable::resolveDestinationWarnings()}.
 *
 * Replaces a 6-positional-parameter facade signature (criterion 220
 * Interface Size). Plain data carrier read directly by the consumer.
 * $rowType is untyped to mirror the policy's existing mixed parameter.
 */
// allow-no-test-found: pure parameter-object DTO (typed public fields, constructor-only assignment, no behavior); fields consumed by View_RedirectsTable::resolveDestinationWarnings().
class ABJ_404_Solution_RedirectDestinationWarningContext {

    /** @var array<string, mixed> */
    public array $row;

    /** @var mixed */
    public $rowType;

    /** @var string */
    public string $rowFinalDest;

    /** @var string */
    public string $destForView;

    /** @var bool */
    public bool $destinationIsMissing;

    /** @var array<mixed> */
    public array $deadDestIds;

    /**
     * @param array<string, mixed> $row
     * @param mixed $rowType
     * @param string $rowFinalDest
     * @param string $destForView
     * @param bool $destinationIsMissing
     * @param array<mixed> $deadDestIds
     */
    public function __construct(array $row, $rowType, string $rowFinalDest, string $destForView,
            bool $destinationIsMissing, array $deadDestIds) {
        $this->row = $row;
        $this->rowType = $rowType;
        $this->rowFinalDest = $rowFinalDest;
        $this->destForView = $destForView;
        $this->destinationIsMissing = $destinationIsMissing;
        $this->deadDestIds = $deadDestIds;
    }
}
