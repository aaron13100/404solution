<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Matching engine that searches post content (body text) for URL keywords.
 *
 * Last resort before Levenshtein spelling — catches cases where URL keywords
 * appear in the post body but not the title or slug. Queries published posts
 * via DataAccess::getPublishedPagesAndPostsIDs() with a LIKE-based WHERE clause
 * against post_content, then scores candidates by title keyword overlap to
 * avoid matching random keyword appearances in body text.
 */
class ABJ_404_Solution_ContentMatchingEngine implements ABJ_404_Solution_MatchingEngine {

    /** Minimum number of keywords required to run (single keyword too ambiguous). */
    const MIN_KEYWORDS = 2;

    /** Maximum keywords to use from the slug (caps query complexity). */
    const MAX_KEYWORDS = 5;

    /** Minimum word length to keep after splitting (filters articles, prepositions). */
    const MIN_WORD_LENGTH = 3;

    /** Maximum rows to fetch from the DB query. */
    const QUERY_LIMIT = 100;

    /** Maximum Levenshtein distance to accept as a fuzzy keyword match. */
    const MAX_FUZZY_DISTANCE = 2;

    /** Weight assigned to a fuzzy (non-exact) keyword match (0.0–1.0). */
    const FUZZY_MATCH_WEIGHT = 0.75;

    /** @var array<int, string> Common English stop words filtered from URL slugs and post content. */
    public static $stopWords = [
        'the', 'and', 'for', 'with', 'this', 'that', 'from', 'your', 'have',
        'will', 'been', 'they', 'their', 'what', 'when', 'where', 'which',
        'there', 'about', 'would', 'could', 'should', 'into', 'than',
        'then', 'them', 'these', 'those', 'does', 'done', 'also', 'just',
        'more', 'most', 'much', 'very', 'some', 'only', 'over', 'such',
        'each', 'were', 'here', 'after', 'before', 'between', 'under',
        'through', 'being', 'other', 'like', 'not', 'but', 'are', 'was',
        'all', 'any', 'can', 'had', 'her', 'his', 'how', 'its', 'may',
        'our', 'own', 'who', 'you',
    ];

    /** @var ABJ_404_Solution_DataAccess */
    private $dao;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_DataAccess $dao
     * @param ABJ_404_Solution_Functions $f
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct(
        ABJ_404_Solution_DataAccess $dao,
        ABJ_404_Solution_Functions $f,
        ABJ_404_Solution_Logging $logger
    ) {
        $this->dao = $dao;
        $this->f = $f;
        $this->logger = $logger;
    }

    /** @return string */
    public function getName(): string {
        return __('content keywords', '404-solution');
    }

    /** @param ABJ_404_Solution_MatchRequest $request */
    public function shouldRun(ABJ_404_Solution_MatchRequest $request): bool {
        $slug = $request->getUrlSlugOnly();

        if ($slug === '') {
            return false;
        }

        // Skip date/tracking patterns (4+ consecutive digits)
        if (preg_match('/\d{4,}/', $slug)) {
            return false;
        }

        $keywords = $this->extractKeywords($slug);

        return count($keywords) >= self::MIN_KEYWORDS;
    }

    /** @param ABJ_404_Solution_MatchRequest $request */
    public function match(ABJ_404_Solution_MatchRequest $request): ?ABJ_404_Solution_MatchResult {
        $keywords = $this->extractKeywords($request->getUrlSlugOnly());

        if (count($keywords) < self::MIN_KEYWORDS) {
            return null;
        }

        $extraWhere = $this->buildWhereClause($keywords);
        $rows = $this->dao->getPublishedPagesAndPostsIDs('', '', '0,' . self::QUERY_LIMIT, '', $extraWhere);

        if (empty($rows)) {
            $this->logger->debugMessage("Content engine: no candidates for keywords [" .
                implode(', ', $keywords) . "]");
            return null;
        }

        $bestScore = 0.0;
        $bestRow = null;

        foreach ($rows as $row) {
            $title = isset($row->post_title) && is_string($row->post_title) ? $row->post_title : '';
            if ($title === '') {
                continue;
            }

            $score = $this->scoreText($keywords, $title);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestRow = $row;
            }
        }

        if ($bestRow === null) {
            return null;
        }

        $minScore = $request->getMinScore('auto_score_content');

        if ($bestScore < $minScore) {
            $this->logger->debugMessage("Content engine: best score " . $bestScore .
                " below threshold " . $minScore);
            return null;
        }

        $id = isset($bestRow->id) && is_scalar($bestRow->id) ? (string)$bestRow->id : '';
        $title = isset($bestRow->post_title) && is_string($bestRow->post_title) ? $bestRow->post_title : '';
        $link = isset($bestRow->url) && is_string($bestRow->url) ? $bestRow->url : '';
        $type = (string)ABJ404_TYPE_POST;

        return new ABJ_404_Solution_MatchResult($id, $type, $link, $title, $bestScore, $this->getName());
    }

    /**
     * Extract meaningful keywords from a URL slug.
     *
     * 1. Replace separators (hyphens, underscores, tildes, dots, slashes, %20) with spaces
     * 2. Split on whitespace, lowercase
     * 3. Filter words shorter than MIN_WORD_LENGTH
     * 4. Filter common English stop words
     * 5. Return unique words, max MAX_KEYWORDS
     *
     * @param string $slug
     * @return array<int, string>
     */
    private function extractKeywords(string $slug): array {
        // Decode percent-encoded characters first
        $decoded = rawurldecode($slug);

        // Replace separators with spaces (including slashes like CategoryTagMatchingEngine)
        $normalized = preg_replace('/[-_~.\/]+/', ' ', $decoded);
        if (!is_string($normalized)) {
            return [];
        }

        // Split on whitespace, lowercase
        $words = preg_split('/\s+/', $this->f->strtolower(trim($normalized)));
        if (!is_array($words)) {
            return [];
        }

        $stopLookup = array_flip(self::$stopWords);
        $keywords = [];

        foreach ($words as $word) {
            if (!is_string($word)) {
                continue;
            }
            if ($this->f->strlen($word) < self::MIN_WORD_LENGTH) {
                continue;
            }
            if (isset($stopLookup[$word])) {
                continue;
            }
            if (!isset($keywords[$word])) {
                $keywords[$word] = true;
            }
        }

        return array_slice(array_keys($keywords), 0, self::MAX_KEYWORDS);
    }

    /**
     * Build a SQL WHERE clause that matches any keyword in the cached content keywords.
     *
     * Uses the pre-extracted content_keywords column from the permalink cache table
     * instead of searching the full post_content. Keywords are stored lowercase,
     * so no lower() wrapping needed.
     *
     * When content_keywords is NULL (cache not yet populated), NULL LIKE '%x%'
     * evaluates to NULL (falsy), so no rows match — graceful degradation.
     *
     * @param array<int, string> $keywords
     * @return string
     */
    private function buildWhereClause(array $keywords): string {
        $conditions = [];
        foreach ($keywords as $kw) {
            // Strip invalid UTF-8 before SQL — keywords originate from
            // rawurldecode'd URL slugs and can carry scanner-attack bytes
            // (Pattern 10 — esc_sql does not validate UTF-8).
            $cleanKw = $this->f->sanitizeInvalidUTF8($this->f->strtolower($kw));
            $escaped = esc_sql($cleanKw);
            $conditions[] = "plc.content_keywords LIKE '%" . $escaped . "%'";
        }

        return ' and (' . implode(' OR ', $conditions) . ')';
    }

    /**
     * Score a text string by keyword overlap ratio with fuzzy matching.
     *
     * Splits the text into words and checks how many URL keywords match.
     * Exact whole-word matches score 1.0. If no exact match, Levenshtein
     * distance is checked against each text word; a close match (distance
     * <= MAX_FUZZY_DISTANCE) scores FUZZY_MATCH_WEIGHT. Returns
     * (weightedMatches / totalKeywords) * 100.
     *
     * @param array<int, string> $keywords
     * @param string $text
     * @return float Score from 0.0 to 100.0
     */
    private function scoreText(array $keywords, string $text): float {
        if (empty($keywords)) {
            return 0.0;
        }

        $textLower = $this->f->strtolower($text);
        $textWords = preg_split('/[\s\-_:;,!?.()\/\[\]]+/', $textLower);
        if (!is_array($textWords)) {
            return 0.0;
        }

        $filteredTextWords = array_values(array_filter($textWords, function ($w) {
            return is_string($w) && $w !== '';
        }));
        $textWordSet = array_flip($filteredTextWords);

        $weightedMatches = 0.0;
        foreach ($keywords as $kw) {
            if (isset($textWordSet[$kw])) {
                $weightedMatches += 1.0;
            } else {
                $weightedMatches += $this->bestFuzzyMatch($kw, $filteredTextWords);
            }
        }

        return ($weightedMatches / count($keywords)) * 100.0;
    }

    /**
     * Find the best fuzzy match for a keyword among text words.
     *
     * Returns FUZZY_MATCH_WEIGHT if any text word is within MAX_FUZZY_DISTANCE,
     * 0.0 otherwise. Only compares words of similar length to avoid nonsense matches.
     *
     * @param string $keyword
     * @param array<int, string> $textWords
     * @return float 0.0 or FUZZY_MATCH_WEIGHT
     */
    private function bestFuzzyMatch(string $keyword, array $textWords): float {
        $kwLen = $this->f->strlen($keyword);

        foreach ($textWords as $tw) {
            $twLen = $this->f->strlen($tw);

            // Skip if length difference alone exceeds max distance
            if (abs($kwLen - $twLen) > self::MAX_FUZZY_DISTANCE) {
                continue;
            }

            if (levenshtein($keyword, $tw) <= self::MAX_FUZZY_DISTANCE) {
                return self::FUZZY_MATCH_WEIGHT;
            }
        }

        return 0.0;
    }
}
