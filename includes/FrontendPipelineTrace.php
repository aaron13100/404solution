<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ordered record of (step, outcome, detail) tuples produced by one frontend
 * request pipeline execution. The trace is attached to redirect-hit log rows
 * and used by the debug interstitial to explain why a request 404'd or matched.
 *
 * Mutable; created per request. Helpers receive the same instance and append
 * to it as they make decisions.
 */
class ABJ_404_Solution_FrontendPipelineTrace {

    /** @var list<array{step: string, outcome: string, detail: string}> */
    private $steps = array();

    function add(string $step, string $outcome, string $detail = ''): void {
        $this->steps[] = array('step' => $step, 'outcome' => $outcome, 'detail' => $detail);
    }

    /** @return list<array{step: string, outcome: string, detail: string}> */
    function getSteps(): array {
        return $this->steps;
    }

    function reset(): void {
        $this->steps = array();
    }
}
