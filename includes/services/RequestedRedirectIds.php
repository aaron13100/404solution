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
     * Keyed, because `kind` and `source` are both strings: positionally they
     * transpose into a type-correct call that labels a bulk request as a
     * single one, which is the exact confusion this type exists to end. PHP 7.4
     * is the floor here, so named arguments are not available.
     *
     * @param array{kind: string, source: string, ids: array<int, int>, requested_count: int} $state
     */
    private function __construct(array $state) {
        $this->kind = $state['kind'];
        $this->source = $state['source'];
        $this->ids = $state['ids'];
        $this->requestedCount = $state['requested_count'];
    }

    /** One redirect, named through the GET or POST `id` parameter. */
    public static function single(string $source, int $id): self {
        return new self(array('kind' => self::KIND_SINGLE, 'source' => $source,
            'ids' => array($id), 'requested_count' => 1));
    }

    /**
     * A bulk selection within the ceiling, or null when it names no ids.
     *
     * @param array<int, int> $ids A set that sanitizes to nothing yields null
     *   rather than an empty bulk request.
     * @param int $requestedCount How many the request NAMED, which can exceed
     *   count($ids) when some entries sanitized away to nothing.
     */
    public static function bulk(string $source, array $ids, int $requestedCount): ?self {
        // An empty set is not a bulk request with nothing in it; it is "the
        // request named no usable id", which callers already represent as null.
        // Deciding that here rather than at the call site is what keeps a
        // KIND_BULK holding zero ids unrepresentable instead of merely
        // undocumented.
        if ($ids === array()) {
            return null;
        }
        return new self(array('kind' => self::KIND_BULK, 'source' => $source,
            'ids' => array_values($ids), 'requested_count' => $requestedCount));
    }

    /** A selection larger than the plugin will carry. Names no ids on purpose. */
    public static function refusedAsTooMany(string $source, int $requestedCount): self {
        return new self(array('kind' => self::KIND_REFUSED_TOO_MANY, 'source' => $source,
            'ids' => array(), 'requested_count' => $requestedCount));
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
