<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Immutable record of a match that was found and then rejected for scoring
 * under the auto-redirect threshold.
 *
 * A near miss is the answer to "why was this URL captured instead of
 * redirected?". The matching engines already compute it on every 404 with
 * automatic redirects on; without somewhere to put it the number was discarded
 * on the losing branch and the captured row stored score = NULL.
 *
 * Score and engine travel together because they are only meaningful together:
 * "48" means nothing without "spell check" to say what produced it, and they
 * are persisted as a pair on the redirect row (the `score` and `engine`
 * columns), exactly as they are for an automatic redirect.
 *
 * The matched destination (id/type) is deliberately NOT carried. A captured
 * row's type/final_dest mean "no destination, the 404 page was shown"; writing
 * a suggested destination into them would silently promote a captured URL into
 * a live redirect nobody approved.
 *
 * // allow-no-test-found: exercised by CapturedRedirectNearMissScoreTest
 */
final class ABJ_404_Solution_NearMissMatch {

    /** @var float */
    private $score;

    /** @var string */
    private $engineName;

    /**
     * @param float $score
     * @param string $engineName
     */
    private function __construct($score, $engineName) {
        $this->score = (float)$score;
        $this->engineName = (string)$engineName;
    }

    /**
     * @param float $score Match confidence, on the same 0-100 scale as the
     *                     score stored for an automatic redirect.
     * @param string $engineName Name of the matching engine that scored it.
     * @return self
     */
    public static function create(float $score, string $engineName): self {
        return new self($score, $engineName);
    }

    /** @return float */
    public function getScore(): float {
        return $this->score;
    }

    /** @return string */
    public function getEngineName(): string {
        return $this->engineName;
    }
}
