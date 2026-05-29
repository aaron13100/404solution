<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Typed value object for an "update an existing redirect row" request.
 *
 * Boundary parameter object for {@see ABJ_404_Solution_RedirectsRepository::updateRedirect()}.
 *
 * The repo method used to accept eight positional parameters:
 *
 *   updateRedirect($type, $dest, $fromURL, $idForUpdate, $redirectCode,
 *                  $statusType, $startTs = null, $endTs = null)
 *
 * Two of those (`$dest` and `$fromURL`) are arbitrary strings with no
 * type-level distinction, and five more (`$type`, `$idForUpdate`,
 * `$redirectCode`, `$startTs`, `$endTs`) are numbers with overlapping
 * semantics. Swapping any pair compiles cleanly but writes garbage to the
 * `wp_abj404_redirects` row. One such swap is silent at the DB layer
 * because both columns store strings.
 *
 * Wrapping the call shape in a VO with named fields makes the swap
 * structurally impossible: every caller has to spell out `id:`, `fromUrl:`,
 * `destination:`, etc. via the named constructor, and PHPStan can type-check
 * each field independently.
 *
 * Field semantics (mirrors the row columns):
 *
 *   - id          : int > 0     redirect row primary key (REQUIRED)
 *   - type        : int >= 0    ABJ404_TYPE_* (post / page / external / etc.)
 *   - fromUrl     : string      source URL that 404s and should redirect
 *   - destination : string      final destination URL / object id (engine-dependent)
 *   - code        : string      HTTP status code as string ("301", "302", ...)
 *   - statusType  : string      ABJ404_STATUS_* value, stored as-is
 *   - startTs     : ?int        epoch seconds; null clears the schedule lower bound
 *   - endTs       : ?int        epoch seconds; null clears the schedule upper bound
 *
 * Construct via {@see self::create()}. The constructor is private so the
 * field order is never depended on at call sites.
 */
final class ABJ_404_Solution_RedirectUpdate {

    /** @var int */
    private $id;

    /** @var int */
    private $type;

    /** @var string */
    private $fromUrl;

    /** @var string */
    private $destination;

    /** @var string */
    private $code;

    /** @var string */
    private $statusType;

    /** @var int|null */
    private $startTs;

    /** @var int|null */
    private $endTs;

    /**
     * @param int|null $startTs
     * @param int|null $endTs
     */
    private function __construct(
        int $id,
        int $type,
        string $fromUrl,
        string $destination,
        string $code,
        string $statusType,
        $startTs,
        $endTs
    ) {
        $this->id = $id;
        $this->type = $type;
        $this->fromUrl = $fromUrl;
        $this->destination = $destination;
        $this->code = $code;
        $this->statusType = $statusType;
        $this->startTs = ($startTs === null) ? null : (int)$startTs;
        $this->endTs = ($endTs === null) ? null : (int)$endTs;
    }

    /**
     * Build a redirect-update request. Callers spell out every field by
     * name; positional swap of $fromUrl/$destination is no longer possible
     * at the boundary (the call site must literally type the two parameter
     * names in order).
     *
     * @param int      $id          Primary key of the row to update.
     * @param int      $type        ABJ404_TYPE_* enum value.
     * @param string   $fromUrl     Source URL (the URL that 404'd).
     * @param string   $destination Final destination (URL or object id).
     * @param string   $code        HTTP code as string ("301" / "302").
     * @param string   $statusType  ABJ404_STATUS_* enum value.
     * @param int|null $startTs     Schedule start (epoch sec), or null.
     * @param int|null $endTs       Schedule end (epoch sec), or null.
     */
    public static function create(
        int $id,
        int $type,
        string $fromUrl,
        string $destination,
        string $code,
        string $statusType,
        $startTs = null,
        $endTs = null
    ): self {
        return new self($id, $type, $fromUrl, $destination, $code, $statusType, $startTs, $endTs);
    }

    public function getId(): int {
        return $this->id;
    }

    public function getType(): int {
        return $this->type;
    }

    public function getFromUrl(): string {
        return $this->fromUrl;
    }

    public function getDestination(): string {
        return $this->destination;
    }

    public function getCode(): string {
        return $this->code;
    }

    public function getStatusType(): string {
        return $this->statusType;
    }

    /** @return int|null */
    public function getStartTs() {
        return $this->startTs;
    }

    /** @return int|null */
    public function getEndTs() {
        return $this->endTs;
    }
}
