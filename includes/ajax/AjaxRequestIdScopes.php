<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The three request-ID scopes one AJAX response is emitted under, resolved once
 * and thereafter reachable only by name.
 *
 * WHY THIS EXISTS. Emission needs three different request IDs, and all three are
 * strings that look alike:
 *
 *   - CHECKPOINT: the instrumented table endpoint's micro-boundary gate. '' on
 *     every other endpoint, which is what keeps the expensive per-step
 *     instrumentation off the canary ladder and out of the detach A/B
 *     experiment.
 *   - LEDGER: the immutable request ledger's ID. Stamped on the payload and
 *     echoed as X-ABJ404-Request-ID, so a request whose body never arrives is
 *     still identifiable from the proxy side.
 *   - MEASURED: the request whose durable trace is armed. Wider than
 *     CHECKPOINT on purpose -- "how many bytes did this response encode" is one
 *     fact every armed endpoint needs, not micro-boundary instrumentation.
 *
 * Passed positionally they were interchangeable: checkpointedEmitHeaders() and
 * checkpointedEncodeAndEcho() each took two adjacent `string $...RequestId`
 * parameters, so a transposition type-checked perfectly and then silently
 * misattributed the evidence -- gating brackets on the wrong scope and writing
 * the size record under the other one. Nothing downstream could detect it,
 * because both values are well-formed request IDs; the journal would simply
 * describe a different request than the one that ran. Diagnostic attribution is
 * the only thing standing behind the support reports this instrumentation
 * exists to answer, so it is worth making the mistake unspellable.
 *
 * @since 4.3.5
 */
final class ABJ_404_Solution_AjaxRequestIdScopes {

    /** @var string */
    private $checkpoint;

    /** @var string */
    private $ledger;

    /** @var string */
    private $measured;

    /**
     * @param array{checkpoint: string, ledger: string, measured: string} $ids
     *   One keyed bag rather than three positional strings, for the same reason
     *   this class exists at all.
     */
    public function __construct(array $ids) {
        $this->checkpoint = $ids['checkpoint'];
        $this->ledger = $ids['ledger'];
        $this->measured = $ids['measured'];
    }

    /**
     * Resolve all three from the shared AJAX debug context.
     *
     * Read together, in one place, so the three sources cannot be paired with
     * the wrong scope at a call site.
     */
    public static function fromGlobalContext(): self {
        return new self(array(
            'checkpoint' => ABJ_404_Solution_AjaxRequestLedger::instrumentedRequestIdFromGlobalContext(),
            'ledger' => ABJ_404_Solution_AjaxRequestLedger::requestIdFromGlobalContext(),
            'measured' => ABJ_404_Solution_AjaxRequestLedger::diagnosticRequestIdFromGlobalContext(),
        ));
    }

    /** The instrumented table endpoint's micro-boundary scope, or ''. */
    public function checkpoint(): string {
        return $this->checkpoint;
    }

    /** The immutable request ledger's ID, or ''. */
    public function ledger(): string {
        return $this->ledger;
    }

    /** The scope a durable size record is written under, or ''. */
    public function measured(): string {
        return $this->measured;
    }

    /**
     * Whether this response carries the instrumented endpoint's micro-boundary
     * checkpoints. Named because the bare `=== ''` comparison appears at six
     * branch points and reads as a null check rather than as the gate it is.
     */
    public function hasCheckpoints(): bool {
        return $this->checkpoint !== '';
    }
}
