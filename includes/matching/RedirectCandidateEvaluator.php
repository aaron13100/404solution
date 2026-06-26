<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decides whether an already-fetched redirect row is actionable. Three checks:
 *   1. Row well-formed (id != 0, final_dest != 0 unless type=HOME).
 *   2. Destination not dead -- a bounded on-demand check asks the
 *      redirect_dead_destination_checker whether THIS redirect's destination
 *      shows recent failed hits in the logs_hits rollup.
 *   3. RedirectConditionEvaluator passes (any per-row conditions like
 *      time-of-day, country, user-agent, referrer rules).
 *
 * Records a trace step at each step so the debug interstitial can show why
 * an existing redirect was skipped.
 */
class ABJ_404_Solution_RedirectCandidateEvaluator {

    /** @var ABJ_404_Solution_RedirectsRepository */
    private $redirectsRepository;

    /**
     * @param ABJ_404_Solution_RedirectsRepository $redirectsRepository
     */
    function __construct($redirectsRepository) {
        $this->redirectsRepository = $redirectsRepository;
    }

    /**
     * @param array<string, mixed>|null $redirect
     * @param string $labelSuffix Appended to trace step labels (e.g. ' (without comments)').
     * @param array<string, mixed> $options
     * @param ABJ_404_Solution_FrontendPipelineTrace $trace
     * @return array<string, mixed>|null The redirect row if actionable, null otherwise.
     */
    function evaluate(?array $redirect, string $labelSuffix, array $options, ABJ_404_Solution_FrontendPipelineTrace $trace): ?array {
        if ($redirect === null) {
            if ($labelSuffix === '') {
                $trace->add('Redirect lookup', 'No matching redirect');
            }
            return null;
        }

        $typeHomeInt = (int)ABJ404_TYPE_HOME;
        $redirectType = isset($redirect['type']) && is_scalar($redirect['type']) ? (int)$redirect['type'] : 0;

        if ($redirect['id'] == '0' || ($redirect['final_dest'] == '0' && $redirectType !== $typeHomeInt)) {
            if ($labelSuffix === '') {
                $trace->add('Redirect lookup', 'No matching redirect');
            }
            return null;
        }

        $trace->add('Redirect lookup' . $labelSuffix, 'Found existing redirect',
            'rule #' . (is_scalar($redirect['id']) ? (string)$redirect['id'] : '?'));

        $redirectIdForCheck = is_scalar($redirect['id']) ? (int) $redirect['id'] : 0;
        $deadIds = abj_service('redirect_dead_destination_checker')->findDeadDestinationIds(array($redirectIdForCheck));
        if (!empty($deadIds)) {
            $trace->add('Health check' . $labelSuffix, 'Destination unreachable - skipped');
            return null;
        }

        $condEvaluator = new ABJ_404_Solution_RedirectConditionEvaluator($this->redirectsRepository);
        $redirectIdForCond = is_scalar($redirect['id']) ? (int)$redirect['id'] : 0;
        if ($condEvaluator->shouldApplyRedirect($redirectIdForCond)) {
            $trace->add('Conditions' . $labelSuffix, 'All conditions met');
            return $redirect;
        }

        $condTrace = $condEvaluator->getLastEvaluationTrace();
        $condDetail = implode(', ', array_map(function ($c) {
            $label = str_replace('_', ' ', $c['type']);
            return $label . ': ' . ($c['result'] ? 'passed' : 'failed');
        }, $condTrace));
        $trace->add('Conditions' . $labelSuffix, 'Blocked by conditions', $condDetail);
        return null;
    }

    /**
     * @param array<string, mixed> $redirect
     * @return bool
     */
    function isAutoRedirect(array $redirect): bool {
        return isset($redirect['status']) && is_scalar($redirect['status']) &&
            (int)$redirect['status'] === (int)ABJ404_STATUS_AUTO;
    }
}
