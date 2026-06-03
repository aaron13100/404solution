<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Extracts N-grams (bigrams + trigrams) from a URL string.
 *
 * Pure computation. No I/O, no DB, no transients. Both production callers
 * (the live 404 candidate-selection pipeline via NGramFilter, and the
 * background term-cache rebuild loops in DatabaseUpgradeNGram) call into
 * this for the same purpose: take a URL, produce a deduplicated set of
 * character N-grams suitable for Dice-coefficient similarity. The only
 * collaborator needed is the polymorphic mbstring adapter (Functions),
 * because URLs may contain UTF-8 (i18n permalink slugs).
 */
class ABJ_404_Solution_NGramExtractor {

    /** Cap on URL length before extraction; above this the URL is truncated.
     * Most real URLs are < 200 chars; 500 prevents memory blowup on 2083-char
     * legacy URLs (4000+ N-grams per URL) without losing matching value. */
    const MAX_URL_LENGTH = 500;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_Logging|null $logging
     */
    public function __construct($functions = null, $logging = null) {
        $this->f = $functions !== null ? $functions : abj_service('functions');
        $this->logger = $logging !== null ? $logging : abj_service('logging');
    }

    /**
     * Extract N-grams from a URL string.
     *
     * Generates both bigrams (n=2) and trigrams (n=3) for optimal accuracy.
     * Research shows using both provides better typo detection than either alone.
     *
     * @param string $url
     * @param array<int, int> $ngramSizes default [2, 3]
     * @return array{bi: array<int, string>, tri: array<int, string>}
     */
    public function extractNGrams($url, $ngramSizes = [2, 3]) {
        if (empty($url)) {
            return ['bi' => [], 'tri' => []];
        }

        $url = $this->f->strtolower($url);

        $originalLength = $this->f->strlen($url);
        if ($originalLength > self::MAX_URL_LENGTH) {
            $this->logger->infoMessage("WARNING: URL too long for N-gram extraction: {$originalLength} chars, truncating to " . self::MAX_URL_LENGTH . ". URL: " . $this->f->substr($url, 0, 100) . "...");
            $url = $this->f->substr($url, 0, self::MAX_URL_LENGTH);
        }

        $result = [];
        $length = $this->f->strlen($url);

        foreach ($ngramSizes as $n) {
            $ngrams = [];
            for ($i = 0; $i <= $length - $n; $i++) {
                $ngram = $this->f->substr($url, $i, $n);
                $ngrams[$ngram] = true;
            }
            $key = ($n == 2) ? 'bi' : 'tri';
            // Convert keys to strings to prevent PHP from converting numeric strings to integers
            $result[$key] = array_map('strval', array_keys($ngrams));
        }

        /** @var array{bi: array<int, string>, tri: array<int, string>} $result */
        return $result;
    }
}
