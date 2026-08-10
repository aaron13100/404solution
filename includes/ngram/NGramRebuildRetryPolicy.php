<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../database/DatabaseInfrastructureErrorTaxonomy.php';

/**
 * When, and whether, the n-gram rebuild cron chain re-arms itself after a tick
 * that failed.
 *
 * Why this is its own module rather than a constant on the batch runner: the
 * delay was four copies of the literal 10 spread across the runner's failure
 * paths, and a constant alone would only have deduplicated the number. The
 * decision has three inputs (how many ticks in a row have failed, a jitter
 * draw, and what the failure actually was), and an orchestrator that also
 * reasons about all three inline is two concerns in one method. Keeping them
 * here also means the whole delay curve is exercisable without driving cron.
 *
 * The failure mode this exists to prevent is NOT a busy-wait. rescheduleChain()
 * enqueues a WP-Cron event, it does not sleep. It is that a database outage
 * used to produce a fresh cron event every ten seconds for the entire duration
 * of the outage, each one re-running the same failing probe, on hosts where
 * cron is already the constrained resource. Two things bound that here:
 *
 *   1. Consecutive failures cost progressively more, up to a ceiling. The
 *      curve resets the moment a tick succeeds, so one bad tick cannot slow a
 *      healthy rebuild down for the rest of its life.
 *   2. A failure that retrying cannot fix does not get retried at all. A
 *      dropped connection or a Galera node that has not finished joining is
 *      worth coming back for; a statement the server has already rejected on
 *      its own terms is not, and re-running it on a short cadence buries the
 *      one report that would have said what is broken.
 *
 * Holds no state, issues no SQL, reads no options and logs nothing: it answers
 * two questions from the values it is handed. The consecutive-failure count it
 * reads the curve from is owned by
 * {@see ABJ_404_Solution_NGramRebuildProgressState}, which is a persistence
 * record; backoff math, a random source and the database error vocabulary do
 * not belong in it.
 *
 * @see ABJ_404_Solution_NGramCacheRebuildBatchRunner
 */
class ABJ_404_Solution_NGramRebuildRetryPolicy {

    /**
     * The healthy chain's cadence, and the base the backoff curve doubles from.
     * A rebuild that is simply not finished yet runs at exactly this, with no
     * backoff and no jitter: there is nothing to back off from, and nothing to
     * de-synchronize.
     */
    const BASE_DELAY_SECONDS = 10;

    /**
     * The longest the curve may ever ask for. A ceiling rather than unbounded
     * growth because a chain that has backed off to hours is indistinguishable
     * from a chain that died, and the rebuild already has a deliberate way to
     * stop (MAX_CONSECUTIVE_FAILURES).
     */
    const MAX_DELAY_SECONDS = 120;

    /**
     * Jitter shaves up to 1/JITTER_DIVISOR off the step, so the window is
     * [step - step/5, step]. Jitter is subtracted rather than added so the
     * ceiling above is a real ceiling and not a number the draw can exceed.
     *
     * The fraction is deliberately small enough to keep consecutive steps
     * DISJOINT (a step's floor sits above the previous step's ceiling), because
     * a curve whose steps overlap cannot be observed to grow from a single
     * sample, and a test that cannot observe growth cannot protect it.
     */
    const JITTER_DIVISOR = 5;

    /**
     * Draws a jitter amount in [0, span].
     *
     * Injected rather than called inline so the window's edges can be pinned by
     * a test without asserting on a random number, and so the one boundary this
     * class touches is visible in its signature.
     *
     * @var callable(int): int
     */
    private $jitterSource;

    /**
     * The shared database error vocabulary. Null until something actually
     * fails: a healthy tick asks only for a delay, and resolving a service it
     * will not use would let a container problem break a rebuild that had no
     * other reason to fail.
     *
     * @var ABJ_404_Solution_DatabaseInfrastructureErrorTaxonomy|null
     */
    private $taxonomy;

    /**
     * @param callable(int): int|null $jitterSource Draws in [0, span]; defaults to random_int().
     * @param ABJ_404_Solution_DatabaseInfrastructureErrorTaxonomy|null $taxonomy
     *        The shared database error vocabulary; resolved from the container
     *        on first use when not supplied. It is pure string matching: no
     *        connection, no options, no side effects.
     */
    public function __construct(?callable $jitterSource = null, $taxonomy = null) {
        $this->jitterSource = $jitterSource !== null ? $jitterSource : static function (int $span): int {
            return $span > 0 ? random_int(0, $span) : 0;
        };
        $this->taxonomy = $taxonomy instanceof ABJ_404_Solution_DatabaseInfrastructureErrorTaxonomy
            ? $taxonomy
            : null;
    }

    /**
     * @return ABJ_404_Solution_DatabaseInfrastructureErrorTaxonomy Built on first classification.
     */
    private function taxonomy(): ABJ_404_Solution_DatabaseInfrastructureErrorTaxonomy {
        if ($this->taxonomy === null) {
            $this->taxonomy = new ABJ_404_Solution_DatabaseInfrastructureErrorTaxonomy(
                abj_service('functions'));
        }
        return $this->taxonomy;
    }

    /**
     * How long the chain should wait before its next link.
     *
     * @param int $consecutiveFailures Failing ticks in a row, 0 on a healthy chain.
     * @return int Seconds, never above {@see MAX_DELAY_SECONDS}.
     */
    public function secondsUntilNextAttempt(int $consecutiveFailures): int {
        $failures = max(0, $consecutiveFailures);
        if ($failures === 0) {
            return self::BASE_DELAY_SECONDS;
        }

        // Doubled by loop rather than by 2 ** $failures: the count comes from a
        // stored option, and an option that has been corrupted into a large
        // number must not be able to turn the exponent into an overflow. The
        // loop stops the moment the ceiling is reached, so the cost is bounded
        // by the curve, not by the input.
        $step = self::BASE_DELAY_SECONDS;
        for ($i = 0; $i < $failures && $step < self::MAX_DELAY_SECONDS; $i++) {
            $step *= 2;
        }
        $step = min(self::MAX_DELAY_SECONDS, $step);

        $span = intdiv($step, self::JITTER_DIVISOR);
        if ($span <= 0) {
            return $step;
        }

        // Clamped rather than trusted. A jitter source that answered outside
        // the span it was asked for would push the delay past the ceiling or
        // below the previous step, which is exactly what the window exists to
        // make impossible.
        $draw = (int)($this->jitterSource)($span);
        $draw = max(0, min($span, $draw));

        return $step - $draw;
    }

    /**
     * Whether a failure is worth coming back for at all.
     *
     * Ambiguity resolves to TRUE, in both directions:
     *
     *   - A failure with no recognizable database error in it (a rebuilder that
     *     threw for its own reasons, a probe whose reason was not reported)
     *     cannot be PROVEN permanent, so it is retried. The
     *     consecutive-failure budget, not a guess about the message, is what
     *     bounds the cost of being wrong.
     *   - A message that reads as both -- a dropped connection whose text also
     *     quotes a missing table -- takes the retryable reading. Guessing
     *     "permanent" on an ambiguous string stops a chain a single retry would
     *     have recovered, which is the more expensive mistake of the two.
     *
     * The vocabulary is the shared one the DAO's own retry path uses
     * ({@see ABJ_404_Solution_DatabaseInfrastructureErrorTaxonomy}), so this
     * cannot drift into a second, private opinion about which database errors
     * are transient.
     *
     * @param string $failureText The failure as reported, driver text included.
     * @return bool
     */
    public function isWorthRetrying(string $failureText): bool {
        $text = trim($failureText);
        if ($text === '') {
            return true;
        }

        $taxonomy = $this->taxonomy();

        // Checked first so it wins on a message that matches both readings.
        if ($taxonomy->connectivity()->isTransientConnectionError($text)
            || $taxonomy->connectivity()->isDeadlockOrLockTimeoutError($text)
            || $taxonomy->connectivity()->isCommandsOutOfSyncError($text)
            || $taxonomy->hostState()->isGaleraConflictError($text)) {
            return true;
        }

        if ($taxonomy->schema()->isMissingPluginTableError($text)
            || $taxonomy->schema()->isMalformedStatementError($text)
            || $taxonomy->hostState()->isAccessDeniedError($text)) {
            return false;
        }

        return true;
    }
}
