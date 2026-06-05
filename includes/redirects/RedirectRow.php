<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Typed value object for a row returned by `getRedirectsByIDs()` (and other
 * `SELECT ... FROM {prefix}_abj404_redirects` queries that project the same
 * columns).
 *
 * Boundary normalizer (task: type-pressure at module boundaries).
 *
 * The redirect row is produced by the DAO (`DataAccessTrait_Stats::getRedirectsByIDs`
 * and DAO siblings) and consumed by the admin form renderer
 * (`ViewTrait_Redirects::echoEditRedirectFields` etc.) and the runtime
 * pipeline (`FrontendRequestPipeline::dispatchRedirect`). Without this VO
 * each consumer re-implemented its own shape probing:
 *
 *   - `is_scalar($redirect['id'] ?? '') ? (string)... : ''`
 *   - `is_string($redirect['url'] ?? '') ? (string)... : ''`
 *   - `isset($redirect['start_ts']) && is_numeric(...) ? (int)... : 0`
 *   - `is_scalar($redirect['type']) ? (int)... : 0`
 *
 * Drift between the DAO's projection and a consumer's expected shape is the
 * same class of defect that produced the URL-cache-key bug chain. The VO
 * pulls every shape probe into one place. Producers pass raw rows through
 * `fromRaw()`; consumers read fields via accessors that already enforce the
 * contract.
 *
 * Schema (after normalization):
 *
 *   - id          : int >= 0
 *   - url         : string
 *   - type        : int      (one of ABJ404_TYPE_*; 0 if absent / unparseable)
 *   - status      : int      (one of ABJ404_STATUS_*; 0 if absent / unparseable)
 *   - finalDest   : string   ('0' if absent)
 *   - code        : string   (HTTP code; '' if absent)
 *   - engine      : string   (engine name or '' if absent)
 *   - startTs     : int >= 0 (0 = no schedule)
 *   - endTs       : int >= 0 (0 = no schedule)
 *
 * Construct via `fromRaw()` on the consumer side; the DAO continues to
 * return raw arrays for backwards compatibility (typed accessors layer on
 * top, no producer rewrite required).
 */
final class ABJ_404_Solution_RedirectRow {

    /** @var int */
    private $id;

    /** @var string */
    private $url;

    /** @var int */
    private $type;

    /** @var int */
    private $status;

    /** @var string */
    private $finalDest;

    /** @var string */
    private $code;

    /** @var string */
    private $engine;

    /** @var int */
    private $startTs;

    /** @var int */
    private $endTs;

    private function __construct(
        int $id,
        string $url,
        int $type,
        int $status,
        string $finalDest,
        string $code,
        string $engine,
        int $startTs,
        int $endTs
    ) {
        $this->id = $id;
        $this->url = $url;
        $this->type = $type;
        $this->status = $status;
        $this->finalDest = $finalDest;
        $this->code = $code;
        $this->engine = $engine;
        $this->startTs = $startTs;
        $this->endTs = $endTs;
    }

    /**
     * Normalize a raw DB row into a typed VO, or null when the payload is
     * unrecoverably malformed (not an array, or missing both id and url so
     * the row cannot be acted on).
     *
     * Callers MUST NOT shape-probe the raw payload themselves.
     *
     * @param mixed $raw
     */
    public static function fromRaw($raw): ?self {
        if (!is_array($raw)) {
            return null;
        }
        $hasId  = array_key_exists('id', $raw);
        $hasUrl = array_key_exists('url', $raw);
        if (!$hasId && !$hasUrl) {
            return null;
        }

        $id        = self::coerceNonNegativeInt($raw, 'id');
        $url       = self::coerceString($raw, 'url');
        $type      = self::coerceInt($raw, 'type');
        $status    = self::coerceInt($raw, 'status');
        $finalDest = self::coerceFinalDest($raw);
        $code      = self::coerceString($raw, 'code');
        $engine    = trim(self::coerceString($raw, 'engine'));
        $startTs   = self::coerceNonNegativeInt($raw, 'start_ts');
        $endTs     = self::coerceNonNegativeInt($raw, 'end_ts');

        return new self($id, $url, $type, $status, $finalDest, $code, $engine, $startTs, $endTs);
    }

    /**
     * @param array<int, array<string, mixed>> $rows Raw rows from the DAO.
     * @return array<int, self> Normalized rows; malformed rows are skipped.
     */
    public static function fromRawList(array $rows): array {
        $result = array();
        foreach ($rows as $row) {
            $vo = self::fromRaw($row);
            if ($vo !== null) {
                $result[] = $vo;
            }
        }
        return $result;
    }

    public function getId(): int {
        return $this->id;
    }

    public function getUrl(): string {
        return $this->url;
    }

    public function getType(): int {
        return $this->type;
    }

    public function getStatus(): int {
        return $this->status;
    }

    public function isRegex(): bool {
        return defined('ABJ404_STATUS_REGEX') && $this->status === (int)ABJ404_STATUS_REGEX;
    }

    public function isExternal(): bool {
        return defined('ABJ404_TYPE_EXTERNAL') && $this->type === (int)ABJ404_TYPE_EXTERNAL;
    }

    public function isFourOhFourDisplayed(): bool {
        return defined('ABJ404_TYPE_404_DISPLAYED') && $this->type === (int)ABJ404_TYPE_404_DISPLAYED;
    }

    /** Stored verbatim. May be a numeric post ID or a URL (external redirect). */
    public function getFinalDest(): string {
        return $this->finalDest;
    }

    public function hasFinalDest(): bool {
        $t = trim($this->finalDest);
        return $t !== '' && $t !== '0';
    }

    public function getCode(): string {
        return $this->code;
    }

    public function getEngine(): string {
        return $this->engine;
    }

    public function getStartTs(): int {
        return $this->startTs;
    }

    public function getEndTs(): int {
        return $this->endTs;
    }

    /**
     * @param array<mixed, mixed> $raw
     */
    private static function coerceString(array $raw, string $key): string {
        if (!isset($raw[$key])) {
            return '';
        }
        $v = $raw[$key];
        if (is_string($v)) {
            return $v;
        }
        if (is_scalar($v)) {
            return (string)$v;
        }
        return '';
    }

    /**
     * @param array<mixed, mixed> $raw
     */
    private static function coerceInt(array $raw, string $key): int {
        if (!isset($raw[$key])) {
            return 0;
        }
        $v = $raw[$key];
        if (is_int($v)) {
            return $v;
        }
        if (is_float($v)) {
            return (int)$v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (int)$v;
        }
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        return 0;
    }

    /**
     * @param array<mixed, mixed> $raw
     */
    private static function coerceNonNegativeInt(array $raw, string $key): int {
        $i = self::coerceInt($raw, $key);
        return $i < 0 ? 0 : $i;
    }

    /**
     * `final_dest` is stored as a string column but historically populated
     * with both integer post-IDs and external URLs. Old rows can have an
     * absent/null/0 value.
     *
     * @param array<mixed, mixed> $raw
     */
    private static function coerceFinalDest(array $raw): string {
        if (!isset($raw['final_dest'])) {
            return '0';
        }
        $v = $raw['final_dest'];
        if (is_string($v)) {
            return $v;
        }
        if (is_scalar($v)) {
            return (string)$v;
        }
        return '0';
    }
}
