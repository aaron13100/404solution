<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The byte count the canary size probes calibrate against, and where it came
 * from.
 *
 * Split out of ABJ_404_Solution_AjaxCanaryStepRunner. Every other arm of that
 * class emits a payload of a known size and times the round trip; this one
 * emits nothing and times nothing. It answers a question about the SITE --
 * how big is a real admin-table response here -- by arbitrating between two
 * independent sources and naming which one answered. That is a policy
 * decision, not a probe, and it sits beside the sources it arbitrates
 * (ABJ_404_Solution_EncodedTableResponseSize,
 * ABJ_404_Solution_MeasuredTableResponseSize) rather than among the probes
 * that consume its answer.
 *
 * Recorded first, measured second, named either way. The recorded number is
 * the exact size of the response that actually failed, so it always wins when
 * it exists -- but it exists only on a site whose durable trace was already
 * armed when the failure happened, and `debug_mode` is off by default on
 * purpose (AjaxDiagnosticRequestPolicy::DIAGNOSTIC_TRACE_ACTIONS keeps
 * `ajaxUpdatePaginationLinks` behind the 4.3.3 performance gate). The ladder
 * self-arms, which is what gives the ladder its OWN evidence, but nothing can
 * retroactively arm a request that has already run.
 *
 * So on the shipped default there is nothing to recover and the step used to
 * answer with an absence, leaving the client to calibrate the whole size axis
 * on a hardcoded default -- support report 2026-08-27 (Azure App Service,
 * plugin 4.3.4) ran all ten steps and could neither confirm nor rule out size,
 * because every probe ran at a size unrelated to this site's real response. A
 * measurement is available on every site, so the absence is now the fallback's
 * fallback.
 *
 * `realResponseRecordedSource` keeps the recorded channel's own answer whatever
 * happens, so "measured because nothing was recorded" stays distinguishable
 * from "measured because the trace named no table request" and from "recorded
 * exactly". A number is never returned without the source that produced it.
 */
final class ABJ_404_Solution_CanarySizeTarget {

    /**
     * Resolve the calibration size for one browser session and subpage.
     *
     * Keyed rather than positional: a session id and a subpage are both
     * strings, so positionally a transposed call would query the journal for a
     * subpage and the table for a session, and return a confidently wrong byte
     * count with a plausible-looking source beside it. PHP 7.4 is the floor
     * here, so named arguments are unavailable.
     *
     * @param array{subpage: string, session_id: string} $inputs
     * @param array<string, mixed> $context Mutated in place by the build's stages.
     * @return array<string, mixed>
     */
    public static function resolve(array $inputs, array &$context): array {
        $sessionId = $inputs['session_id'];
        $recorded = ABJ_404_Solution_AjaxStageDiagnostics::runStage($context, 'canary_size_target',
            static function () use ($sessionId) {
                return ABJ_404_Solution_EncodedTableResponseSize::forSession($sessionId);
            });
        if (is_int($recorded['bytes']) && $recorded['bytes'] > 0) {
            return array(
                'realResponseBytes' => $recorded['bytes'],
                'realResponseBytesSource' => $recorded['source'],
                'realResponseRequestId' => $recorded['request_id'],
                'realResponseRecordedSource' => $recorded['source'],
            );
        }
        // Deliberately NOT inside the stage above: the builder opens its own
        // stage and trace stages are flat, so nesting would only mark
        // canary_size_target `superseded`. See MeasuredTableResponseSize.
        $measured = ABJ_404_Solution_MeasuredTableResponseSize::forSubpage($inputs['subpage'], $context);
        return array(
            'realResponseBytes' => $measured['bytes'],
            'realResponseBytesSource' => $measured['source'],
            // No request id: a measurement is of this site's table, not of any
            // one request, and borrowing a request id would imply otherwise.
            'realResponseRequestId' => '',
            'realResponseRecordedSource' => $recorded['source'],
        );
    }
}
