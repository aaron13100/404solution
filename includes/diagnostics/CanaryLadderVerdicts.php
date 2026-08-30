<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The canary ladder's three closing verdicts, joined from the durable journals.
 *
 * Split out of ABJ_404_Solution_AjaxCanaryStepRunner, which emits sized
 * responses and times them. This class emits nothing and measures nothing: it
 * reads what both sides already recorded and decides what those records mean
 * together. Those are different jobs, and they belong to different layers --
 * every collaborator here (ResponseBodyDeliveryEvidence, DetachAbEvidence,
 * EncodedTableResponseSize) already lives in diagnostics/, so joining them
 * from the ajax/ probe emitter had the read-side layer depending on the
 * response-emission layer for no reason.
 *
 * The ladder interpretation matrix is computed from the BROWSER's observations
 * (it is the only side that saw every step) and journaled here. The other two
 * are computed HERE, from the durable journal, because each has two halves
 * that never meet on the client.
 *
 * The detach A/B verdict: the server chose each real table request's detach
 * mode, the browser reported whether that request completed, and until this
 * call site existed nothing joined them -- ABJ_404_Solution_DetachAbVerdict::
 * fromAttempts() was a decision rule with no production caller, so the verdict
 * a beta session exists to produce depended on a human joining two record
 * kinds by hand.
 *
 * The A/B verdict is written through the checkpoint channel rather than only
 * into the stage trace: its source evidence lives in that same journal, so
 * verdict and evidence travel together into the support payload and the
 * developer log archive, and a defect in the trace class cannot erase the
 * conclusion drawn about it.
 *
 * The body-delivery join: the server journaled how many bytes each response
 * encoded, the browser journaled how many bytes its Resource Timing says
 * arrived, and until this call site existed nothing compared them. That
 * comparison is the one line that names support report 2026-08-27 (3072 bytes
 * emitted for the `stream` canary, 6089 delivered, complete, unparseable): a
 * body rewritten in transit. It rides its own checkpoint record for the same
 * reason the A/B verdict does, and it is ALSO folded into the matrix, where it
 * lets `streamingBufferCausal` require that the streaming step actually
 * streamed. See ABJ_404_Solution_ResponseBodyDeliveryEvidence.
 *
 * The three verdicts stay separate records computed from disjoint inputs.
 * Merging them would let an ambiguous quadrant in one leak into the others'
 * conclusions, which is the same reason the pure rules are separate classes.
 *
 * The parsed-observations type is spelled as the union it actually is, not as
 * a flat shape carrying every key: `observations` exists only on the available
 * side and `unavailable` only on the other, so a flat shape would both invite
 * reads of a key that is not there and discard the narrowing that proves the
 * unavailable payload is all scalars.
 *
 * @phpstan-type ParsedObservations array{status: 'available', observations: array<mixed>}|array{status: 'unavailable', unavailable: array{code: string, message: string, payloadBytes: int, maxBytes: int}}
 */
final class ABJ_404_Solution_CanaryLadderVerdicts {

    /** The browser's observations arrived and were interpretable. */
    const INTERPRETATION_AVAILABLE = 'available';

    /** They did not, and `interpretationUnavailable` says why. */
    const INTERPRETATION_UNAVAILABLE = 'unavailable';

    /**
     * Compute all three verdicts, journal each, and return the closing step's
     * response payload.
     *
     * Called from inside the caller's already-open trace stage, so
     * addStageMetadata() lands on `canary_interpret` rather than opening a
     * stage of its own.
     *
     * Keyed rather than positional: `request_id` and `session_id` are both
     * strings, so positionally they could be transposed into a perfectly typed
     * call that scoped the journal reads to a request id and stamped the
     * records with a session id. PHP 7.4 is the floor here, so named arguments
     * are unavailable and a positional value object would carry the same
     * hazard into its constructor.
     *
     * @param array{request_id: string, session_id: string, parsed: ParsedObservations, real_request_failed: bool} $inputs
     * @return array<string, mixed>
     */
    public static function assemble(array $inputs): array {
        $requestId = $inputs['request_id'];
        $sessionId = $inputs['session_id'];
        $parsed = $inputs['parsed'];

        // Resolved HERE, not inside interpretResults(): the rule stays pure and
        // the journal read stays in the request that has a session to scope it
        // to. Read before the matrix so a failure to join degrades to unknown
        // facts rather than to no matrix.
        $bodyDelivery = ABJ_404_Solution_ResponseBodyDeliveryEvidence::forSession($sessionId);
        ABJ_404_Solution_AjaxCheckpointLogger::record(
            $requestId,
            ABJ_404_Solution_ResponseBodyDeliveryEvidence::EVIDENCE_EVENT,
            $bodyDelivery);

        $interpretation = null;
        $stageMetadata = array();
        if ($parsed['status'] === self::INTERPRETATION_AVAILABLE) {
            $interpretation = ABJ_404_Solution_CanaryLadderInterpretation::interpret(
                $parsed['observations'], $inputs['real_request_failed'], $bodyDelivery);
            foreach ($interpretation as $key => $value) {
                if (is_scalar($value)) {
                    $stageMetadata[$key] = $value;
                }
            }
        } else {
            $stageMetadata = $parsed['unavailable'];
        }
        ABJ_404_Solution_AjaxStageDiagnostics::addStageMetadata($stageMetadata);

        $detachAb = ABJ_404_Solution_DetachAbEvidence::verdictForSession($sessionId);
        ABJ_404_Solution_AjaxCheckpointLogger::record(
            $requestId, ABJ_404_Solution_DetachAbEvidence::VERDICT_EVENT, $detachAb);

        return array_merge(
            self::interpretationFields($parsed, $interpretation),
            array(
                'detachAb' => $detachAb,
                // Returned even when the browser's observations were rejected:
                // the emitted/delivered join is derived entirely from the
                // server's own journal, so an unreadable observations payload
                // is no reason to withhold it.
                'bodyDelivery' => $bodyDelivery,
            )
        );
    }

    /**
     * The interpretation half of the payload, as one discriminated shape.
     *
     * `interpretationStatus` decides which of the two payload fields carries
     * meaning; both keys are always present, so a consumer never has to
     * distinguish "absent" from "null".
     *
     * Built in one place on purpose. The payload used to carry three
     * separately-computed fields for a single fact -- a nullable
     * `interpretation`, a nullable `interpretationUnavailable`, and a
     * `received` boolean -- so the shape could express combinations that mean
     * nothing (received with neither an interpretation nor a reason, or both
     * halves populated at once), and each consumer had to decide for itself
     * which field to believe. A discriminator computed once cannot contradict
     * itself; three fields derived independently eventually will.
     *
     * @param ParsedObservations $parsed
     * @param array<string, mixed>|null $interpretation
     * @return array{interpretationStatus: string, interpretation: array<string, mixed>|null, interpretationUnavailable: array<string, mixed>|null}
     */
    private static function interpretationFields(array $parsed, ?array $interpretation): array {
        if ($parsed['status'] === self::INTERPRETATION_AVAILABLE) {
            return array(
                'interpretationStatus' => self::INTERPRETATION_AVAILABLE,
                'interpretation' => $interpretation,
                'interpretationUnavailable' => null,
            );
        }
        return array(
            'interpretationStatus' => self::INTERPRETATION_UNAVAILABLE,
            'interpretation' => null,
            'interpretationUnavailable' => $parsed['unavailable'],
        );
    }
}
