<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Matching engine that delegates to SpellChecker::getPermalinkUsingSpelling().
 *
 * Uses Levenshtein distance / N-gram filtering to find the closest matching
 * published page. Expensive, so shouldRun() filters out URL shapes that are
 * unlikely to be useful typo corrections.
 */
class ABJ_404_Solution_SpellingMatchingEngine implements ABJ_404_Solution_MatchingEngine {

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
        return __('spell check', '404-solution');
    }

    /** @param ABJ_404_Solution_MatchRequest $request */
    public function shouldRun(ABJ_404_Solution_MatchRequest $request): bool {
        $urlSlugOnly = $request->getUrlSlugOnly();

        if ($urlSlugOnly === '') {
            return false;
        }

        $segments = array_values(array_filter(explode('/', $urlSlugOnly)));
        if (count($segments) === 0) {
            return false;
        }

        $lastSegment = (string)end($segments);

        if (strlen($lastSegment) > 80) {
            return false;
        }
        if (substr_count($lastSegment, '-') >= 6) {
            return false;
        }
        if (preg_match('/\d{4,}/', $lastSegment)) {
            return false;
        }
        if (!preg_match('/[a-zA-Z]/', $lastSegment)) {
            return false;
        }

        return true;
    }

    /** @param ABJ_404_Solution_MatchRequest $request */
    public function match(ABJ_404_Solution_MatchRequest $request): ?ABJ_404_Solution_MatchResult {
        $permalink = $this->spellChecker->getPermalinkUsingSpelling(
            $request->getUrlSlugOnly(),
            $request->getRequestedURL(),
            $request->getOptions()
        );

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
