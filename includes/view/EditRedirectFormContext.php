<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parameter object for the add/edit redirect form renderer,
 * {@see ABJ_404_Solution_View_RedirectForms::echoEditRedirect()}.
 *
 * Replaces a 9-positional-parameter method signature (criterion 220
 * Interface Size). Plain data carrier read directly by the consumer.
 */
// allow-no-test-found: pure parameter-object DTO (typed public fields, constructor-only assignment, no behavior); fields consumed by View_RedirectForms::echoEditRedirect(), exercised in ViewFormRenderingTest and EditRedirectNativeFormShapeTest.
class ABJ_404_Solution_EditRedirectFormContext {

    /** @var string */
    public string $destination;

    /** @var string */
    public string $codeSelected;

    /** @var string */
    public string $label;

    /** @var string|null */
    public ?string $sourcePage;

    /** @var string|null */
    public ?string $filter;

    /** @var string|null */
    public ?string $orderby;

    /** @var string|null */
    public ?string $order;

    /** @var string */
    public string $startDate;

    /** @var string */
    public string $endDate;

    /**
     * @param string $destination
     * @param string $codeSelected
     * @param string $label
     * @param string|null $sourcePage
     * @param string|null $filter
     * @param string|null $orderby
     * @param string|null $order
     * @param string $startDate
     * @param string $endDate
     */
    public function __construct(string $destination, string $codeSelected, string $label,
            ?string $sourcePage = null, ?string $filter = null, ?string $orderby = null,
            ?string $order = null, string $startDate = '', string $endDate = '') {
        $this->destination = $destination;
        $this->codeSelected = $codeSelected;
        $this->label = $label;
        $this->sourcePage = $sourcePage;
        $this->filter = $filter;
        $this->orderby = $orderby;
        $this->order = $order;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
    }
}
