<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Carries the best rejected match from the matching engines to the captured-404
 * insert, within one request.
 *
 * The producer and the consumer sit five frames apart -- SpellChecker rejects a
 * candidate deep inside a Levenshtein scan, and NotFoundResponseService writes
 * the captured row after the whole engine run has already returned null -- and
 * every layer in between (MatchingEngine::match(), the orchestrator, the auto
 * redirect handler, the frontend pipeline) has no use for the value. Threading
 * it through them would put a field nobody reads into four signatures and into
 * the public engine interface; a single recorder both sides resolve from the
 * container keeps the contract at the two ends that care, and lets any future
 * engine record a near miss without an interface change.
 *
 * Two admission rules make a wrong score impossible rather than unlikely:
 *
 *   1. Records are keyed by the requested URL and read back by the same key.
 *      A lookup for a different URL returns null, so a leftover record can
 *      never be attributed to the wrong captured row -- the failure mode is
 *      "no score", which is exactly the behaviour that predates this class.
 *   2. Only a score inside the (0, 100] band is kept. The spelling score is
 *      100 - (distance / basis * 100), which goes negative for a candidate far
 *      longer than the request; a negative or absurd confidence is not a
 *      confidence, and NULL says so honestly.
 *
 * Only the single best (highest) near miss per URL is kept: that is the closest
 * the plugin came to redirecting, which is the number the admin needs.
 */
class ABJ_404_Solution_NearMissRecorder {

    /** Scores must be above this to be recorded (exclusive). */
    const MIN_RECORDABLE_SCORE = 0.0;

    /** Scores must be at or below this to be recorded (inclusive). */
    const MAX_RECORDABLE_SCORE = 100.0;

    /** @var string|null The URL the current record belongs to. */
    private $requestedURL = null;

    /** @var ABJ_404_Solution_NearMissMatch|null Best near miss for that URL. */
    private $best = null;

    /**
     * Record a match an engine found and then rejected for scoring under its
     * threshold. Out-of-band scores are dropped, so every engine can call this
     * unconditionally on its reject branch without vetting the number first.
     *
     * @param array{requestedURL: string, score: float, engineName: string} $match
     *        requestedURL is the URL being resolved, as the frontend
     *        pipeline spells it (MatchRequest::getRequestedURL(), which is the
     *        same string NotFoundResponseService::sendTo404Page() receives).
     * @return void
     */
    public function record(array $match): void {
        $requestedURL = $match['requestedURL'];
        $score = $match['score'];
        $engineName = $match['engineName'];
        if (!self::isRecordableScore($score)) {
            return;
        }

        if ($this->requestedURL !== $requestedURL) {
            $this->requestedURL = $requestedURL;
            $this->best = null;
        }

        if ($this->best === null || $score > $this->best->getScore()) {
            $this->best = ABJ_404_Solution_NearMissMatch::create($score, $engineName);
        }
    }

    /**
     * The best near miss recorded for this exact URL, or null when none was
     * recorded for it (automatic matching off, no candidate at all, or a
     * record that belongs to a different URL).
     *
     * @param string $requestedURL
     * @return ABJ_404_Solution_NearMissMatch|null
     */
    public function getBestFor(string $requestedURL): ?ABJ_404_Solution_NearMissMatch {
        if ($this->requestedURL !== $requestedURL) {
            return null;
        }
        return $this->best;
    }

    /**
     * @param float $score
     * @return bool
     */
    public static function isRecordableScore(float $score): bool {
        return $score > self::MIN_RECORDABLE_SCORE && $score <= self::MAX_RECORDABLE_SCORE;
    }
}
