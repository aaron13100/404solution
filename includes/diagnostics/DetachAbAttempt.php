<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One detach A/B attempt, parsed once at the journal boundary (Bruno timeout
 * cause matrix, gap G9 / c434).
 *
 * The experiment's vocabulary and its parse used to live in three places at
 * once. ABJ_404_Solution_AjaxRequestLedger wrote the raw strings 'on', 'off',
 * 'inert' and 'default'; ABJ_404_Solution_DetachAbEvidence read a journal
 * record and narrowed those strings plus an ordinal; and then
 * ABJ_404_Solution_DetachAbVerdict narrowed the SAME values a second time,
 * from mixed, after they had already been parsed -- twice more, in two
 * separate private methods. Nothing joined the three definitions, so the
 * producer's vocabulary and the consumer's could drift apart silently: a
 * fifth mode, or a rename, would simply stop pairing and the verdict would
 * read 'inconclusive' with no evidence that anything had gone wrong.
 *
 * This type is the single named definition. A record becomes an attempt
 * exactly once, here; everything downstream reads typed accessors and never
 * re-inspects mixed input. Whether an attempt can occupy a PAIR slot is a
 * separate, derived question (pairSlot()), because the pair coordinates
 * depend on optional workload fields that a legacy record may not carry --
 * that is a domain property of the record, not a second validation of it.
 *
 * Immutable: the browser's outcome arrives later, on a different request, so
 * withOutcome() returns a new attempt rather than mutating one that another
 * caller may already hold.
 */
final class ABJ_404_Solution_DetachAbAttempt {

    /** The response detached the connection before finishing its work. */
    const MODE_ON = 'on';

    /** The response deliberately did not detach: the experiment's control. */
    const MODE_OFF = 'off';

    /**
     * The session's bounded run is over and this request took the ordinary
     * best-available path. Positive evidence, never a measurement.
     */
    const MODE_DEFAULT = 'default';

    /**
     * The experiment did not run at all for this request (an ordinary release
     * build, or a client that sends no session id). Positive evidence, never a
     * measurement.
     */
    const MODE_INERT = 'inert';

    /** @var string */
    private $requestId;

    /** @var self::MODE_ON|self::MODE_OFF */
    private $mode;

    /** @var string */
    private $part;

    /** @var string */
    private $payloadKey;

    /** @var int Server-assigned attempt ordinal, or -1 when absent. */
    private $ordinal;

    /** @var int */
    private $pairOrdinal;

    /** @var int */
    private $pairPosition;

    /** @var bool|null null means the browser has not reported on this attempt. */
    private $completed;

    /**
     * One keyed bag rather than eight positional parameters.
     *
     * Four of the fields are strings and three are ints, so a positional
     * signature lets requestId and mode swap, or part and payloadKey, or any
     * two of the three ordinals, with every type check still passing and the
     * damage appearing far away: a transposed part/payloadKey silently changes
     * every pair key, and a transposed ordinal/pairPosition moves attempts into
     * slots the server never assigned. PHP 7.4 is the floor for shipped code,
     * so named arguments are not available; PHPStan checks this shape instead.
     *
     * @param array{
     *   requestId: string,
     *   mode: self::MODE_ON|self::MODE_OFF,
     *   part: string,
     *   payloadKey: string,
     *   ordinal: int,
     *   pairOrdinal: int,
     *   pairPosition: int,
     *   completed: bool|null
     * } $fields
     */
    private function __construct(array $fields) {
        $this->requestId = $fields['requestId'];
        $this->mode = $fields['mode'];
        $this->part = $fields['part'];
        $this->payloadKey = $fields['payloadKey'];
        $this->ordinal = $fields['ordinal'];
        $this->pairOrdinal = $fields['pairOrdinal'];
        $this->pairPosition = $fields['pairPosition'];
        $this->completed = $fields['completed'];
    }

    /**
     * This attempt's fields, for the two call sites that build a new one from
     * them. Private, so the keyed shape never becomes part of the public
     * surface: callers read the named accessors.
     *
     * @return array{requestId: string, mode: self::MODE_ON|self::MODE_OFF, part: string,
     *   payloadKey: string, ordinal: int, pairOrdinal: int, pairPosition: int,
     *   completed: bool|null}
     */
    private function fields(): array {
        return array(
            'requestId' => $this->requestId,
            'mode' => $this->mode,
            'part' => $this->part,
            'payloadKey' => $this->payloadKey,
            'ordinal' => $this->ordinal,
            'pairOrdinal' => $this->pairOrdinal,
            'pairPosition' => $this->pairPosition,
            'completed' => $this->completed,
        );
    }

    /**
     * Every mode the ledger may assign, measurement and non-measurement alike.
     *
     * Callers that merely need to recognise a journaled mode (the resolution
     * tracer's safe-value narrowing, for instance) read this rather than
     * repeating the list, so adding a mode cannot leave one reader behind.
     *
     * @return array<int, string>
     */
    public static function everyMode(): array {
        return array(self::MODE_INERT, self::MODE_ON, self::MODE_OFF, self::MODE_DEFAULT);
    }

    /**
     * Parse one journaled mode record into an attempt, or null when the record
     * is not a measurement.
     *
     * Returning null for MODE_INERT and MODE_DEFAULT is the rule, not a
     * rejection: both are deliberately recorded as positive evidence that the
     * experiment did not run, and folding either into the tally would compare
     * the experiment against itself.
     *
     * @param array<array-key, mixed> $record One decoded journal line.
     */
    public static function fromJournalRecord(array $record): ?self {
        $mode = self::scalarField($record, 'mode');
        $requestId = self::scalarField($record, 'request_id');
        if (($mode !== self::MODE_ON && $mode !== self::MODE_OFF) || $requestId === '') {
            return null;
        }
        return new self(array(
            'requestId' => $requestId,
            'mode' => $mode,
            'part' => self::scalarField($record, 'part'),
            'payloadKey' => self::scalarField($record, 'payload_key'),
            'ordinal' => self::intField($record, 'ordinal'),
            'pairOrdinal' => self::intField($record, 'pair_ordinal'),
            'pairPosition' => self::intField($record, 'pair_position'),
            'completed' => null,
        ));
    }

    /**
     * The same attempt with the browser's verdict attached.
     *
     * @param bool|null $completed null when the browser has not reported yet:
     *   "has not said" and "said it failed" are opposite findings and only one
     *   of them belongs in a tally.
     */
    public function withOutcome(?bool $completed): self {
        return new self(array('completed' => $completed) + $this->fields());
    }

    public function requestId(): string {
        return $this->requestId;
    }

    /** @return self::MODE_ON|self::MODE_OFF Never anything else, by construction. */
    public function mode(): string {
        return $this->mode;
    }

    /** @return bool|null null when the browser has not reported on this attempt. */
    public function completed(): ?bool {
        return $this->completed;
    }

    /** Whether the browser has reported an outcome for this attempt at all. */
    public function isResolved(): bool {
        return $this->completed !== null;
    }

    /**
     * Where this attempt sits in the counterbalanced sequence, or null when it
     * carries no usable coordinates.
     *
     * Derived from the server-assigned ordinal alone. The supplemental
     * pair_ordinal / pair_position fields are journaled for human reading and
     * are deliberately NOT consulted here: a forged or corrupt pair position
     * must never be able to move an attempt into a slot the server did not
     * give it.
     *
     * @return array{key: string, position: int}|null
     */
    public function pairSlot(): ?array {
        if ($this->part === '' || $this->payloadKey === '' || $this->ordinal < 0) {
            return null;
        }
        return array(
            'key' => $this->part . '|' . $this->payloadKey . '|' . intdiv($this->ordinal, 2),
            'position' => $this->ordinal % 2,
        );
    }

    /**
     * The human-readable copy that travels on the journaled decision record.
     *
     * Field names and types are the wire format ABJ_404_Solution_DetachAbEvidence
     * has always written, so an older reader of an existing journal keeps working.
     *
     * @return array<string, mixed>
     */
    public function toJournalArray(): array {
        return array(
            'request_id' => $this->requestId,
            'mode' => $this->mode,
            'part' => $this->part,
            'payload_key' => $this->payloadKey,
            'ordinal' => $this->ordinal,
            'pair_ordinal' => $this->pairOrdinal,
            'pair_position' => $this->pairPosition,
            'ok' => $this->completed,
        );
    }

    /**
     * One record field as a string, or '' when it is absent or not scalar.
     *
     * @param array<array-key, mixed> $record
     */
    private static function scalarField(array $record, string $field): string {
        $value = $record[$field] ?? null;
        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * One record field as a non-negative int, or -1 when it is not one.
     *
     * -1 is the same "no usable coordinate" sentinel the ledger itself writes
     * for an inert attempt, so an unreadable field and an absent one are the
     * same finding downstream.
     *
     * Read through ABJ_404_Solution_ExactInteger rather than the is_numeric()
     * plus cast this used to do. That pair accepts '1.9', '0.5', 1.0 and '1e1'
     * and TRUNCATES each into a position the server never assigned: '0.5' and
     * '1.9' would occupy positions 0 and 1 of the same pair and satisfy every
     * "complete, matched, counterbalanced" condition the decision rule applies,
     * manufacturing a causal verdict out of two corrupt journal lines.
     *
     * @param array<array-key, mixed> $record
     */
    private static function intField(array $record, string $field): int {
        return ABJ_404_Solution_ExactInteger::readOr($record[$field] ?? null, 0, -1);
    }
}
