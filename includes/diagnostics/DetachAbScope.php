<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One immutable detach A/B workload scope: the session, request part, and
 * payload shape whose attempts are counted together.
 *
 * These three arrived at every collaborator as adjacent positional strings.
 * resolve(), nextAttemptIndex(), assignmentSeed(), transientKey() and the
 * normalized-scope builder all took (sessionId, part, payloadKey) in that
 * order, so transposing the last two stayed type-correct and silently moved a
 * request onto a different counter and a different assignment: the experiment
 * would still report a well-formed slot, for the wrong workload, and nothing
 * downstream could tell. Normalizing once here and passing one value gives the
 * mistake nowhere left to happen.
 *
 * Normalization is done at construction rather than at each use. The old code
 * normalized the part and payload key in resolve(), then handed the RAW triple
 * to two collaborators that normalized them again; the results agreed only
 * because both normalizers happen to be idempotent, which is a property nobody
 * had written down and nothing was checking.
 */
final class ABJ_404_Solution_DetachAbScope {

    /** The table endpoint's finite request-part catalog. */
    const PARTS = array('all', 'table', 'counts', 'pagination');

    /** The part a request falls back to when it names none, or names one we do not serve. */
    const DEFAULT_PART = 'all';

    /** Prefix identifying this counter's storage generation. */
    const TRANSIENT_PREFIX = 'abj404_ab_detach_v2_';

    /** @var string The browser-supplied opaque session id, unhashed. */
    private $sessionId;

    /** @var string One of self::PARTS. */
    private $part;

    /** @var string A 40-character lowercase hex payload fingerprint. */
    private $payloadKey;

    /**
     * Keyed, not positional. Three adjacent strings here would put the exact
     * transposition this type exists to end back inside the type itself: both
     * named constructors below would still compile with two of them swapped,
     * and the value they produced would be a well-formed scope for the wrong
     * workload. The same mistake was made and caught once already in
     * ABJ_404_Solution_RequestedRedirectIds' own private constructor.
     *
     * @param array{sessionId: string, part: string, payloadKey: string} $fields
     */
    private function __construct(array $fields) {
        $this->sessionId = $fields['sessionId'];
        $this->part = $fields['part'];
        $this->payloadKey = $fields['payloadKey'];
    }

    /**
     * Build a scope from the AJAX request context.
     *
     * The context already carries these three under distinct keys, and the
     * caller used to unpack them into three locals purely to pass them
     * positionally. Reading them here keeps the keys attached the whole way.
     *
     * @param mixed $context The request context, normally $GLOBALS['abj404_ajax_context'].
     */
    public static function fromAjaxContext($context): self {
        $fields = is_array($context) ? $context : array();
        return new self(array(
            'sessionId' => self::scalarField($fields, 'session_id', ''),
            'part' => self::normalizePart(
                self::scalarField($fields, 'part', self::DEFAULT_PART)),
            'payloadKey' => self::normalizePayloadKey(
                self::scalarField($fields, 'detach_ab_payload_key', '')),
        ));
    }

    /**
     * Build a scope for one session, optionally narrowed to a part and payload.
     *
     * The narrowing arrives keyed rather than as two more positional strings,
     * so the one remaining bare argument is the only one of its type and cannot
     * be swapped with anything.
     *
     * @param array{part?: string, payload_key?: string} $options
     */
    public static function forSession(string $sessionId, array $options = array()): self {
        return new self(array(
            'sessionId' => $sessionId,
            'part' => self::normalizePart(
                isset($options['part']) ? (string)$options['part'] : self::DEFAULT_PART),
            'payloadKey' => self::normalizePayloadKey(
                isset($options['payload_key']) ? (string)$options['payload_key'] : ''),
        ));
    }

    /** The raw session id, needed only to derive keys; never journalled. */
    public function sessionId(): string {
        return $this->sessionId;
    }

    /** The hashed session id that journal records join on. */
    public function sessionKey(): string {
        return self::sessionKeyFor($this->sessionId);
    }

    /**
     * Derive the join key for a browser session without journaling its raw value.
     *
     * The checkpoint journal is site-wide while counters are per session, so
     * records need something to join on that is stable per tab and distinct
     * between tabs. Empty stays empty because "no session" is evidence, not a
     * shared real session.
     *
     * This is a CORRELATION key, not a secret and not an authenticator: nothing
     * anywhere grants access on the strength of it, and it is derived rather
     * than stored only to keep the raw per-tab id out of support payloads. It
     * is deliberately not treated as a privacy guarantee. The id it hashes
     * comes from abj404GenerateRequestId() (16 chars of Math.random, adequate
     * against enumeration) but falls back, on a page that loaded the identity
     * module without the shared generator, to a purely time-derived value --
     * Date.now() plus a counter plus performance.now() -- which is low enough
     * entropy to be searched offline no matter which hash wraps it. Choosing a
     * stronger digest here would not change that; adding entropy at the mint
     * would, and that is a client-format change rather than a hashing one.
     *
     * The formula lives here, on the type that defines what a scope IS, so the
     * scope does not have to reach back into the policy class that consumes it.
     * ABJ_404_Solution_DetachAbExperiment::sessionKey() is the plugin-wide name
     * the diagnostics classes already call and forwards to this.
     */
    public static function sessionKeyFor(string $sessionId): string {
        return $sessionId === '' ? '' : md5($sessionId);
    }

    public function part(): string {
        return $this->part;
    }

    public function payloadKey(): string {
        return $this->payloadKey;
    }

    /**
     * Whether this scope can carry a counter at all.
     *
     * "No session" is evidence rather than an error: a request that arrives
     * without one is not an anonymous member of a shared sequence, it is a
     * request the experiment cannot scope, and it is answered inert.
     */
    public function isSessionless(): bool {
        return $this->sessionId === '';
    }

    /** The canonical scope string that both the counter key and the seed derive from. */
    public function normalizedScope(): string {
        return implode('|', array($this->sessionKey(), $this->part, $this->payloadKey));
    }

    /** The transient holding this scope's attempt counter. */
    public function transientKey(): string {
        return self::TRANSIENT_PREFIX . md5($this->normalizedScope());
    }

    /** The stable seed deciding which mode runs first in this scope. */
    public function assignmentSeed(): string {
        return md5($this->normalizedScope());
    }

    /**
     * Read one field as a string, accepting only values that have a string form.
     *
     * @param array<string, mixed> $fields
     */
    private static function scalarField(array $fields, string $name, string $fallback): string {
        if (!isset($fields[$name]) || !is_scalar($fields[$name])) {
            return $fallback;
        }
        return (string)$fields[$name];
    }

    /** Constrain a supplied part to the catalog the table endpoint serves. */
    private static function normalizePart(string $part): string {
        return in_array($part, self::PARTS, true) ? $part : self::DEFAULT_PART;
    }

    /** Normalize a supplied payload fingerprint without journaling raw input. */
    private static function normalizePayloadKey(string $payloadKey): string {
        return preg_match('/^[a-f0-9]{40}$/', $payloadKey) === 1
            ? $payloadKey
            : sha1($payloadKey === '' ? 'legacy-payload' : $payloadKey);
    }
}
