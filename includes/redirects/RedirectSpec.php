<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Immutable value object describing one redirect to be created via
 * {@see ABJ_404_Solution_RedirectsRepositoryInterface::setupRedirect()}.
 *
 * Replaces an 8-position parameter list (queue task c738; audit source
 * design-audit-2026-05-29.md, criterion 220 Interface Size). The previous
 * positional signature mixed two URL strings ($fromURL and $final_dest) and
 * five numeric/string fields with overlapping semantics, making call sites
 * trivially transposable at the wrong end of the list.
 *
 * Construct via {@see self::create()} (named-arg friendly) and read via the
 * typed getters. Instances are immutable; there are no setters.
 *
 * Field semantics match the legacy positional parameters exactly:
 *   - fromURL: source URL the redirect matches (string).
 *   - status:  ABJ404_STATUS_* discriminator (int|string, numeric expected).
 *   - type:    ABJ404_TYPE_*  discriminator (int|string, numeric expected).
 *   - finalDest: destination URL or numeric destination id (string).
 *   - code:    HTTP status code (int|string, numeric expected).
 *   - disabled: 0/1 flag (int, default 0).
 *   - engine:  optional engine name producing this redirect (string|null).
 *   - score:   optional match score (float|null).
 */
final class ABJ_404_Solution_RedirectSpec {

    /** @var string */
    private $fromURL;
    /** @var int|string */
    private $status;
    /** @var int|string */
    private $type;
    /** @var string */
    private $finalDest;
    /** @var int|string */
    private $code;
    /** @var int */
    private $disabled;
    /** @var string|null */
    private $engine;
    /** @var float|null */
    private $score;

    /**
     * @param string $fromURL
     * @param int|string $status
     * @param int|string $type
     * @param string $finalDest
     * @param int|string $code
     * @param int $disabled
     * @param string|null $engine
     * @param float|null $score
     */
    private function __construct(
        $fromURL,
        $status,
        $type,
        $finalDest,
        $code,
        $disabled,
        $engine,
        $score
    ) {
        $this->fromURL = (string)$fromURL;
        $this->status = $status;
        $this->type = $type;
        $this->finalDest = (string)$finalDest;
        $this->code = $code;
        $this->disabled = (int)$disabled;
        $this->engine = ($engine === null) ? null : (string)$engine;
        $this->score = ($score === null) ? null : (float)$score;
    }

    /**
     * Named-arg friendly factory. Prefer this at call sites.
     *
     * @param string $fromURL
     * @param int|string $status
     * @param int|string $type
     * @param string $finalDest
     * @param int|string $code
     * @param int $disabled
     * @param string|null $engine
     * @param float|null $score
     * @return self
     */
    public static function create(
        $fromURL,
        $status,
        $type,
        $finalDest,
        $code,
        $disabled = 0,
        $engine = null,
        $score = null
    ): self {
        return new self($fromURL, $status, $type, $finalDest, $code, $disabled, $engine, $score);
    }

    /** @return string */
    public function getFromURL(): string { return $this->fromURL; }

    /** @return int|string */
    public function getStatus() { return $this->status; }

    /** @return int|string */
    public function getType() { return $this->type; }

    /** @return string */
    public function getFinalDest(): string { return $this->finalDest; }

    /** @return int|string */
    public function getCode() { return $this->code; }

    /** @return int */
    public function getDisabled(): int { return $this->disabled; }

    /** @return string|null */
    public function getEngine() { return $this->engine; }

    /** @return float|null */
    public function getScore() { return $this->score; }

    /**
     * Return a new spec with the fromURL replaced. Used by callers that need
     * to normalize the URL after construction (e.g. relative-path conversion).
     *
     * @param string $fromURL
     * @return self
     */
    public function withFromURL(string $fromURL): self {
        return new self(
            $fromURL,
            $this->status,
            $this->type,
            $this->finalDest,
            $this->code,
            $this->disabled,
            $this->engine,
            $this->score
        );
    }
}
