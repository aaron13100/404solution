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
 * Construct via {@see self::fromArray()} at new call sites and read via the
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
     * Legacy positional factory. Prefer {@see self::fromArray()} at new call
     * sites so same-type fields are spelled out before construction.
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

    /**
     * Build a redirect-create request from named fields.
     *
     * @param array<string, mixed> $fields
     * @return self
     */
    public static function fromArray(array $fields): self {
        return new self(
            (string)self::requiredStringOrInt($fields, 'fromURL'),
            self::requiredStringOrInt($fields, 'status'),
            self::requiredStringOrInt($fields, 'type'),
            (string)self::requiredStringOrInt($fields, 'finalDest'),
            self::requiredStringOrInt($fields, 'code'),
            array_key_exists('disabled', $fields) ? self::requiredInt($fields, 'disabled') : 0,
            array_key_exists('engine', $fields) && $fields['engine'] !== null
                ? self::requiredString($fields, 'engine')
                : null,
            array_key_exists('score', $fields) && $fields['score'] !== null
                ? self::requiredFloat($fields, 'score')
                : null
        );
    }

    /**
     * @param array<string, mixed> $fields
     * @return int|string
     */
    private static function requiredStringOrInt(array $fields, string $name) {
        if (!array_key_exists($name, $fields)) {
            throw new InvalidArgumentException('RedirectSpec is missing required field: ' . $name);
        }
        if (!is_int($fields[$name]) && !is_string($fields[$name])) {
            throw new InvalidArgumentException('RedirectSpec field must be string or int: ' . $name);
        }
        return $fields[$name];
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function requiredString(array $fields, string $name): string {
        if (!array_key_exists($name, $fields)) {
            throw new InvalidArgumentException('RedirectSpec is missing required field: ' . $name);
        }
        if (!is_string($fields[$name])) {
            throw new InvalidArgumentException('RedirectSpec field must be string: ' . $name);
        }
        return $fields[$name];
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function requiredInt(array $fields, string $name): int {
        if (!array_key_exists($name, $fields)) {
            throw new InvalidArgumentException('RedirectSpec is missing required field: ' . $name);
        }
        if (!is_int($fields[$name]) && !(is_string($fields[$name]) && is_numeric($fields[$name]))) {
            throw new InvalidArgumentException('RedirectSpec field must be int: ' . $name);
        }
        return (int)$fields[$name];
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function requiredFloat(array $fields, string $name): float {
        if (!array_key_exists($name, $fields)) {
            throw new InvalidArgumentException('RedirectSpec is missing required field: ' . $name);
        }
        if (!is_int($fields[$name]) && !is_float($fields[$name]) && !(is_string($fields[$name]) && is_numeric($fields[$name]))) {
            throw new InvalidArgumentException('RedirectSpec field must be numeric: ' . $name);
        }
        return (float)$fields[$name];
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
