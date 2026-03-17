<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Matching engine that applies common URL fixes and checks if the corrected URL
 * resolves to a real page.
 *
 * Handles structural URL issues that add too much edit distance for the Spelling
 * engine's Levenshtein matching:
 * - File extensions: /about.html → /about
 * - Trailing punctuation: /about. → /about (from copy-paste in emails)
 *
 * Runs early in the pipeline (after exact Slug, before Title/Spelling) since each
 * fix is an O(1) slug lookup — much cheaper than keyword or Levenshtein matching.
 */
class ABJ_404_Solution_UrlFixEngine implements ABJ_404_Solution_MatchingEngine {

    /**
     * Common file extensions to strip, ordered by frequency.
     * @var array<int, string>
     */
    private static $extensionsToStrip = [
        '.html',
        '.htm',
        '.php',
        '.asp',
        '.aspx',
        '.shtml',
        '.jsp',
        '.cfm',
    ];

    /**
     * Trailing punctuation characters commonly appended by email clients
     * and word processors when URLs appear at the end of sentences.
     * @var string
     */
    private static $trailingPunctuation = '.,;:!?)>';

    /** @var ABJ_404_Solution_SpellChecker */
    private $spellChecker;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_SpellChecker $spellChecker
     * @param ABJ_404_Solution_Functions $f
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct(
        ABJ_404_Solution_SpellChecker $spellChecker,
        ABJ_404_Solution_Functions $f,
        ABJ_404_Solution_Logging $logger
    ) {
        $this->spellChecker = $spellChecker;
        $this->f = $f;
        $this->logger = $logger;
    }

    /** @return string */
    public function getName(): string {
        return __('url fix', '404-solution');
    }

    /** @param ABJ_404_Solution_MatchRequest $request */
    public function shouldRun(ABJ_404_Solution_MatchRequest $request): bool {
        return $request->getUrlSlugOnly() !== '';
    }

    /** @param ABJ_404_Solution_MatchRequest $request */
    public function match(ABJ_404_Solution_MatchRequest $request): ?ABJ_404_Solution_MatchResult {
        $slug = $request->getUrlSlugOnly();
        $candidates = $this->generateFixedCandidates($slug);

        foreach ($candidates as $fixDescription => $fixedSlug) {
            $permalink = $this->spellChecker->getPermalinkUsingSlug($fixedSlug);

            if (!empty($permalink)) {
                $id = isset($permalink['id']) && is_scalar($permalink['id']) ? (string)$permalink['id'] : '';
                $type = isset($permalink['type']) && is_scalar($permalink['type']) ? (string)$permalink['type'] : '';
                $link = isset($permalink['link']) && is_string($permalink['link']) ? $permalink['link'] : '';
                $title = isset($permalink['title']) && is_string($permalink['title']) ? $permalink['title'] : '';
                $score = isset($permalink['score']) && is_scalar($permalink['score']) ? (float)$permalink['score'] : 0.0;

                $this->logger->debugMessage("URL fix engine: matched via " . $fixDescription .
                    " ('" . $slug . "' → '" . $fixedSlug . "')");

                return new ABJ_404_Solution_MatchResult($id, $type, $link, $title, $score, $this->getName());
            }
        }

        return null;
    }

    /**
     * Generate fixed URL slug candidates by applying common transformations.
     *
     * Each candidate is keyed by a human-readable description of the fix applied.
     * Only candidates that differ from the original slug are included.
     *
     * @param string $slug
     * @return array<string, string> fix description => fixed slug
     */
    private function generateFixedCandidates(string $slug): array {
        $candidates = [];

        // 1. Strip file extensions
        $slugLower = $this->f->strtolower($slug);
        foreach (self::$extensionsToStrip as $ext) {
            $extLen = strlen($ext);
            if (strlen($slugLower) > $extLen && substr($slugLower, -$extLen) === $ext) {
                $fixed = substr($slug, 0, -$extLen);
                if ($fixed !== '' && $fixed !== $slug) {
                    $candidates['strip extension ' . $ext] = $fixed;
                }
                break; // Only strip one extension
            }
        }

        // 2. Strip trailing punctuation (one character at a time, up to 3 chars)
        $stripped = $slug;
        $puncCount = 0;
        while ($stripped !== '' && $puncCount < 3 && strpos(self::$trailingPunctuation, substr($stripped, -1)) !== false) {
            $stripped = substr($stripped, 0, -1);
            $puncCount++;
        }
        if ($stripped !== '' && $stripped !== $slug) {
            $candidates['strip trailing punctuation'] = $stripped;
        }

        // 3. Combined: extension + trailing punctuation (e.g., "/about.html.")
        if (isset($candidates['strip trailing punctuation'])) {
            $strippedLower = $this->f->strtolower($stripped);
            foreach (self::$extensionsToStrip as $ext) {
                $extLen = strlen($ext);
                if (strlen($strippedLower) > $extLen && substr($strippedLower, -$extLen) === $ext) {
                    $combined = substr($stripped, 0, -$extLen);
                    if ($combined !== '' && $combined !== $slug && !in_array($combined, $candidates, true)) {
                        $candidates['strip punctuation + extension ' . $ext] = $combined;
                    }
                    break;
                }
            }
        }

        return $candidates;
    }
}
