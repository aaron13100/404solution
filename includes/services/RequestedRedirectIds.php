<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Which redirects one Edit Redirect request named, as one of three states.
 *
 * The states are mutually exclusive and were previously carried as five
 * independent array keys -- a nullable single id, a list, a source string, a
 * `truncated` flag and a count -- which made incoherent combinations
 * expressible: a single id AND a populated list, a bulk answer labelled with a
 * single-id source, a refusal that still carried ids to edit. Nothing built
 * those combinations, but nothing stopped the next edit from doing so either,
 * and a consumer branching on the wrong key would act on the wrong redirects.
 *
 * Three named constructors and a private one, so the only reachable shapes are:
 *
 *   - SINGLE:  exactly one id, from the GET or POST `id` parameter.
 *   - BULK:    one or more ids from `idnum`, within the plugin's ceiling.
 *   - REFUSED: the request named more than the ceiling, so it names NO ids at
 *              all. Deliberately not "the first N of them": editing a subset
 *              of what the admin selected, with no way to see which was
 *              dropped, is worse than refusing (see
 *              ABJ_404_Solution_RedirectEditRequest::MAX_SELECTED_IDS).
 *
 * `requestedCount` survives on every state, including REFUSED, because the
 * refusal has to name the real number back to the admin.
 */
final class ABJ_404_Solution_RequestedRedirectIds {

    /** Exactly one redirect, named through the GET or POST `id` parameter. */
    const KIND_SINGLE = 'single';

    /** A set named through `idnum`, within the plugin's ceiling. */
    const KIND_BULK = 'bulk';

    /** More than the ceiling, so no ids at all. */
    const KIND_REFUSED_TOO_MANY = 'refused_too_many';

    /**
     * @var string Which of the three states this is.
     *
     * Explicit rather than inferred from the id count. A bulk selection of one
     * (`idnum[]=5`) and a single-id request both hold one id, and they render
     * differently -- deducing the state from count() would route the former
     * into the single-record form.
     */
    private $kind;

    /** @var string One of ABJ_404_Solution_RedirectEditRequest's SOURCE_* values. */
    private $source;

    /** @var array<int, int> The ids to act on. Always empty when refused. */
    private $ids;

    /** @var int How many the request named, before any ceiling was applied. */
    private $requestedCount;

    /**
     * @param array<int, int> $ids
     */
    private function __construct(string $kind, string $source, array $ids, int $requestedCount) {
        $this->kind = $kind;
        $this->source = $source;
        $this->ids = $ids;
        $this->requestedCount = $requestedCount;
    }

    /** One redirect, named through the GET or POST `id` parameter. */
    public static function single(string $source, int $id): self {
        return new self(self::KIND_SINGLE, $source, array($id), 1);
    }

    /**
     * A bulk selection within the ceiling.
     *
     * @param array<int, int> $ids Non-empty; a set that sanitizes to nothing is
     *   "no usable id", which callers represent as null rather than as this.
     * @param int $requestedCount How many the request NAMED, which can exceed
     *   count($ids) when some entries sanitized away to nothing.
     */
    public static function bulk(string $source, array $ids, int $requestedCount): self {
        return new self(self::KIND_BULK, $source, array_values($ids), $requestedCount);
    }

    /** A selection larger than the plugin will carry. Names no ids on purpose. */
    public static function refusedAsTooMany(string $source, int $requestedCount): self {
        return new self(self::KIND_REFUSED_TOO_MANY, $source, array(), $requestedCount);
    }

    /** Which request parameter the answer came from. */
    public function source(): string {
        return $this->source;
    }

    /** Whether this names exactly one redirect through the `id` parameter. */
    public function isSingle(): bool {
        return $this->kind === self::KIND_SINGLE;
    }

    /** The single id, or null when this is not a single-id request. */
    public function singleId(): ?int {
        return $this->isSingle() ? $this->ids[0] : null;
    }

    /**
     * Every id to act on. Empty when the request was refused, which is why
     * callers must check wasRefusedAsTooMany() first: an empty list here means
     * "nothing to edit", and the reason matters to the admin.
     *
     * @return array<int, int>
     */
    public function ids(): array {
        return $this->ids;
    }

    /** How many redirects the request named, before the ceiling was applied. */
    public function requestedCount(): int {
        return $this->requestedCount;
    }

    /** Whether the request named more redirects than one screen may carry. */
    public function wasRefusedAsTooMany(): bool {
        return $this->kind === self::KIND_REFUSED_TOO_MANY;
    }
}
