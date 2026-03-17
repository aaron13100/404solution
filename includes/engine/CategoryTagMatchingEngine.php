<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Matching engine that finds categories/tags by keyword overlap with URL slugs,
 * and resolves hierarchical paths (e.g., /recipes/pasta-guide → category "recipes" + post title match).
 *
 * Phase 1: Hierarchical path resolution — parent URL segments identify a category,
 *          child segment finds posts within it via title keyword matching.
 * Phase 2: Category/tag name keyword matching — match URL keywords against
 *          category/tag names (similar to TitleMatchingEngine for post titles).
 */
class ABJ_404_Solution_CategoryTagMatchingEngine implements ABJ_404_Solution_MatchingEngine {

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

    /** @var array<int, string> Common English stop words filtered from URL slugs. */
    private static $stopWords = [
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
        return __('category/tag keywords', '404-solution');
    }

    /** @param ABJ_404_Solution_MatchRequest $request */
    public function shouldRun(ABJ_404_Solution_MatchRequest $request): bool {
        $slug = $request->getUrlSlugOnly();

        if ($slug === '') {
            return false;
        }

        $options = $request->getOptions();
        $autoCats = isset($options['auto_cats']) ? $options['auto_cats'] : '0';
        $autoTags = isset($options['auto_tags']) ? $options['auto_tags'] : '0';

        if ($autoCats !== '1' && $autoTags !== '1') {
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
        $options = $request->getOptions();
        $autoCats = isset($options['auto_cats']) && is_string($options['auto_cats']) ? $options['auto_cats'] : '0';
        $autoTags = isset($options['auto_tags']) && is_string($options['auto_tags']) ? $options['auto_tags'] : '0';
        $minScore = $request->getMinScore('auto_score_category_tag');

        // Phase 1: Hierarchical path match
        $phase1Result = $this->matchHierarchical($request, $autoCats, $minScore);
        if ($phase1Result !== null) {
            return $phase1Result;
        }

        // Phase 2: Category/tag name keyword match
        return $this->matchTermKeywords($request, $autoCats, $autoTags, $minScore);
    }

    /**
     * Phase 1: Hierarchical path resolution.
     *
     * For URLs with 2+ path segments, tries each non-last segment as a category slug.
     * If a category matches, searches for posts within that category whose titles
     * match the last segment's keywords.
     *
     * @param ABJ_404_Solution_MatchRequest $request
     * @param string $autoCats
     * @param float $minScore
     * @return ABJ_404_Solution_MatchResult|null
     */
    private function matchHierarchical(
        ABJ_404_Solution_MatchRequest $request,
        string $autoCats,
        float $minScore
    ): ?ABJ_404_Solution_MatchResult {
        if ($autoCats !== '1') {
            return null;
        }

        $slug = $request->getUrlSlugOnly();
        $segments = array_values(array_filter(explode('/', $slug), function ($s) {
            return $s !== '';
        }));

        if (count($segments) < 2) {
            return null;
        }

        $lastSegment = $segments[count($segments) - 1];
        $keywords = $this->extractKeywords($lastSegment);

        if (count($keywords) < self::MIN_KEYWORDS) {
            return null;
        }

        // Try each non-last segment as a category slug
        for ($i = 0; $i < count($segments) - 1; $i++) {
            $categorySlug = $this->f->strtolower($segments[$i]);
            $categories = $this->dao->getPublishedCategories(null, $categorySlug, 1);

            if (empty($categories)) {
                continue;
            }

            $category = $categories[0];
            $termId = isset($category->term_id) ? (int)$category->term_id : 0;

            if ($termId <= 0) {
                continue;
            }

            $extraWhere = $this->buildCategoryPostWhereClause($termId, $keywords);
            $rows = $this->dao->getPublishedPagesAndPostsIDs('', '', '0,' . self::QUERY_LIMIT, '', $extraWhere);

            if (empty($rows)) {
                continue;
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

            if ($bestRow !== null && $bestScore >= $minScore) {
                $id = isset($bestRow->id) && is_scalar($bestRow->id) ? (string)$bestRow->id : '';
                $title = isset($bestRow->post_title) && is_string($bestRow->post_title) ? $bestRow->post_title : '';
                $link = isset($bestRow->url) && is_string($bestRow->url) ? $bestRow->url : '';

                $this->logger->debugMessage("Category/tag engine Phase 1: matched post " .
                    $id . " via category " . $categorySlug . " (score " . $bestScore . ")");

                return new ABJ_404_Solution_MatchResult(
                    $id, (string)ABJ404_TYPE_POST, $link, $title, $bestScore, $this->getName()
                );
            }
        }

        return null;
    }

    /**
     * Phase 2: Category/tag name keyword matching.
     *
     * Extracts keywords from the full slug and scores them against all published
     * category and tag names. Returns the best match above the score threshold.
     *
     * @param ABJ_404_Solution_MatchRequest $request
     * @param string $autoCats
     * @param string $autoTags
     * @param float $minScore
     * @return ABJ_404_Solution_MatchResult|null
     */
    private function matchTermKeywords(
        ABJ_404_Solution_MatchRequest $request,
        string $autoCats,
        string $autoTags,
        float $minScore
    ): ?ABJ_404_Solution_MatchResult {
        $keywords = $this->extractKeywords($request->getUrlSlugOnly());

        if (count($keywords) < self::MIN_KEYWORDS) {
            return null;
        }

        $bestScore = 0.0;
        $bestTerm = null;
        /** @var string|null $bestType */
        $bestType = null;

        if ($autoCats === '1') {
            $categories = $this->dao->getPublishedCategories();
            foreach ($categories as $cat) {
                $name = isset($cat->name) && is_string($cat->name) ? $cat->name : '';
                if ($name === '') {
                    continue;
                }
                $score = $this->scoreText($keywords, $name);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestTerm = $cat;
                    $bestType = (string)ABJ404_TYPE_CAT;
                }
            }
        }

        if ($autoTags === '1') {
            $tags = $this->dao->getPublishedTags();
            foreach ($tags as $tag) {
                $name = isset($tag->name) && is_string($tag->name) ? $tag->name : '';
                if ($name === '') {
                    continue;
                }
                $score = $this->scoreText($keywords, $name);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestTerm = $tag;
                    $bestType = (string)ABJ404_TYPE_TAG;
                }
            }
        }

        if ($bestTerm === null || $bestType === null || $bestScore < $minScore) {
            $this->logger->debugMessage("Category/tag engine Phase 2: no match above threshold " .
                $minScore . " (best score: " . $bestScore . ")");
            return null;
        }

        $termId = isset($bestTerm->term_id) && is_scalar($bestTerm->term_id) ? (string)$bestTerm->term_id : '';
        $termName = isset($bestTerm->name) && is_string($bestTerm->name) ? $bestTerm->name : '';
        $termUrl = isset($bestTerm->url) && is_string($bestTerm->url) ? $bestTerm->url : '';

        $this->logger->debugMessage("Category/tag engine Phase 2: matched term " .
            $termId . " '" . $termName . "' (score " . $bestScore . ")");

        return new ABJ_404_Solution_MatchResult(
            $termId, $bestType, $termUrl, $termName, $bestScore, $this->getName()
        );
    }

    /**
     * Extract meaningful keywords from a URL slug.
     *
     * @param string $slug
     * @return array<int, string>
     */
    private function extractKeywords(string $slug): array {
        $decoded = rawurldecode($slug);
        $normalized = preg_replace('/[-_~.\/]+/', ' ', $decoded);
        if (!is_string($normalized)) {
            return [];
        }

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
     * Score a text string by keyword overlap ratio with fuzzy matching.
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
     * @param string $keyword
     * @param array<int, string> $textWords
     * @return float 0.0 or FUZZY_MATCH_WEIGHT
     */
    private function bestFuzzyMatch(string $keyword, array $textWords): float {
        $kwLen = $this->f->strlen($keyword);

        foreach ($textWords as $tw) {
            $twLen = $this->f->strlen($tw);

            if (abs($kwLen - $twLen) > self::MAX_FUZZY_DISTANCE) {
                continue;
            }

            if (levenshtein($keyword, $tw) <= self::MAX_FUZZY_DISTANCE) {
                return self::FUZZY_MATCH_WEIGHT;
            }
        }

        return 0.0;
    }

    /**
     * Build a SQL WHERE clause that filters posts in a specific category
     * and matches keywords in post titles.
     *
     * @param int $termId
     * @param array<int, string> $keywords
     * @return string
     */
    private function buildCategoryPostWhereClause(int $termId, array $keywords): string {
        global $wpdb;
        $prefix = isset($wpdb->prefix) ? $wpdb->prefix : 'wp_';

        $trTable = $prefix . 'term_relationships';
        $ttTable = $prefix . 'term_taxonomy';

        $clause = ' and wp_posts.ID IN ('
            . 'SELECT wtr.object_id FROM ' . $trTable . ' wtr '
            . 'INNER JOIN ' . $ttTable . ' wtt ON wtt.term_taxonomy_id = wtr.term_taxonomy_id '
            . 'WHERE wtt.term_id = ' . $termId
            . ')';

        $titleConditions = $this->buildTitleWhereClause($keywords);

        return $clause . $titleConditions;
    }

    /**
     * Build a SQL WHERE clause that matches any keyword in the post title.
     *
     * @param array<int, string> $keywords
     * @return string
     */
    private function buildTitleWhereClause(array $keywords): string {
        $conditions = [];
        foreach ($keywords as $kw) {
            $escaped = esc_sql($this->f->strtolower($kw));
            $conditions[] = "lower(wp_posts.post_title) LIKE '%" . $escaped . "%'";
        }

        return ' and (' . implode(' OR ', $conditions) . ')';
    }
}
