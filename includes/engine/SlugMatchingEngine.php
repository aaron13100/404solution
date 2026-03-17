<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Matching engine that delegates to SpellChecker::getPermalinkUsingSlug().
 *
 * Performs an exact slug lookup against published posts/pages.
 * This is a fast DB query, so shouldRun() only skips when the slug is empty.
 */
class ABJ_404_Solution_SlugMatchingEngine implements ABJ_404_Solution_MatchingEngine {

    /** @var ABJ_404_Solution_SpellChecker */
    private $spellChecker;

    /**
     * @param ABJ_404_Solution_SpellChecker $spellChecker
     */
    public function __construct(ABJ_404_Solution_SpellChecker $spellChecker) {
        $this->spellChecker = $spellChecker;
    }

    /** @return string */
    public function getName(): string {
        return __('exact slug', '404-solution');
    }

    /** @param ABJ_404_Solution_MatchRequest $request */
    public function shouldRun(ABJ_404_Solution_MatchRequest $request): bool {
        $slug = $request->getUrlSlugOnly();
        return $slug !== '';
    }

    /** @param ABJ_404_Solution_MatchRequest $request */
    public function match(ABJ_404_Solution_MatchRequest $request): ?ABJ_404_Solution_MatchResult {
        $permalink = $this->spellChecker->getPermalinkUsingSlug($request->getUrlSlugOnly());

        if (empty($permalink)) {
            return null;
        }

        $id = isset($permalink['id']) && is_scalar($permalink['id']) ? (string)$permalink['id'] : '';
        $type = isset($permalink['type']) && is_scalar($permalink['type']) ? (string)$permalink['type'] : '';
        $link = isset($permalink['link']) && is_string($permalink['link']) ? $permalink['link'] : '';
        $title = isset($permalink['title']) && is_string($permalink['title']) ? $permalink['title'] : '';
        $score = isset($permalink['score']) && is_scalar($permalink['score']) ? (float)$permalink['score'] : 0.0;

        return new ABJ_404_Solution_MatchResult($id, $type, $link, $title, $score, $this->getName());
    }
}
