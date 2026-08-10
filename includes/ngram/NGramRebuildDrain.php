<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/NGramRebuildProgressState.php';

/**
 * Drives the n-gram rebuilder across a row range, one bounded chunk of batches
 * per call, and owns the single invariant that makes the rebuild trustworthy:
 *
 *   THE CURSOR NEVER MOVES PAST ROWS THAT DID NOT REBUILD.
 *
 * Nothing ever revisits an offset the cursor has already passed, so any row the
 * cursor skips is skipped for the life of that rebuild. Every defect this class
 * was extracted from was a variation on breaking that one rule: advancing past a
 * batch that threw, advancing past a batch that RETURNED failed rows, and
 * advancing on a batch whose result could not be read at all.
 *
 * Why it is its own module rather than two private methods on the runner: the
 * multisite and single-site cron paths both drain, and while the algorithm
 * existed as two copies inside the runner the copies had already drifted apart
 * in production -- one checked a condition the other dropped on the floor. One
 * named owner is what makes a third path unable to get it wrong.
 *
 * Deliberately holds no logger and issues no SQL. A failure is REPORTED back in
 * the outcome (see {@see drain()}'s `failureContext`) and the orchestrator
 * decides what to do about it, so the policy that owns the cursor stays free of
 * the presentation and cron concerns around it.
 *
 * Assumes the caller has already switched to whichever site is being rebuilt;
 * this class rebuilds "whatever is currently switched in".
 */
class ABJ_404_Solution_NGramRebuildDrain {

    /**
     * Rows rebuilt per batch, and batches per call.
     *
     * These are constants rather than parameters threaded down through the
     * private methods deliberately. They were previously passed as two adjacent
     * ints, which meant every call site could transpose them and silently
     * change the cron workload by a factor of 2.5 with nothing to catch it.
     * Constants make that transposition unrepresentable.
     */
    const BATCH_SIZE = 50;
    const MAX_BATCHES_PER_RUN = 20;

    /**
     * The one operation this drain needs from its rebuilder.
     *
     * Held as a callable rather than as the whole object because rebuildCache()
     * is genuinely all this class uses, and because the rebuilder arrives
     * duck-typed: DatabaseUpgradeNGram::resolveNGramRebuilder() may hand over an
     * ABJ_404_Solution_NGramRebuilder, a legacy facade, or any object exposing
     * the method, so there is no single interface to typehint against.
     *
     * @var callable(int, int): mixed
     */
    private $rebuildCache;

    /** @var ABJ_404_Solution_NGramRebuildProgressState */
    private $progress;

    /**
     * @param callable $rebuildCache Bound rebuildCache() of the validated rebuilder.
     * @param ABJ_404_Solution_NGramRebuildProgressState $progress Owns the cursor.
     */
    public function __construct($rebuildCache, ABJ_404_Solution_NGramRebuildProgressState $progress) {
        $this->rebuildCache = $rebuildCache;
        $this->progress = $progress;
    }

    /**
     * Rebuild up to MAX_BATCHES_PER_RUN batches of the set currently switched
     * in, advancing the active cursor only as each batch actually lands.
     *
     * At most one failure can be reported per call, because every failure path
     * stops the loop where it is rather than carrying on past unrebuilt rows.
     *
     * @param int $totalRows Rows in the set being rebuilt.
     * @param string $context Human-readable subject, for the failure message.
     * @return array{offset:int, threw:bool, processed:int, success:int, rowsFailed:int, percent:float, failureContext:string|null}
     */
    public function drain(int $totalRows, string $context): array {
        $offset = $this->progress->cursor();
        $batchesProcessed = 0;
        $processed = 0;
        $success = 0;
        $rowsFailed = 0;
        $threw = false;
        $failureContext = null;

        while ($batchesProcessed < self::MAX_BATCHES_PER_RUN && $offset < $totalRows) {
            try {
                $stats = $this->runRebuildBatch($offset);
            } catch (Throwable $e) {
                // Do NOT advance the cursor. The rows in this batch were not
                // rebuilt, and an advanced cursor is never revisited -- that is
                // what silently skipped them and then let the caller declare the
                // cache complete without them.
                $failureContext = "Error during N-gram rebuild for {$context} at offset {$offset}: "
                    . $e->getMessage();
                $threw = true;
                break;
            }

            $processed += $stats['processed'];
            $success += $stats['success'];
            $rowsFailed += $stats['failed'];

            if ($stats['failed'] > 0) {
                // Same rule as the thrown-batch path, one level down: rows that
                // did not rebuild must stay in front of the cursor. Advancing
                // past a batch that reported failures skips exactly those rows,
                // and nothing revisits an offset the cursor has passed -- which
                // is the whole defect this class exists to make unrepresentable.
                $failureContext = sprintf(
                    'N-gram rebuild reported %d failed row(s) for %s at offset %d; holding the cursor '
                    . 'so the next tick retries them.',
                    $stats['failed'], $context, $offset
                );
                break;
            }

            $offset += self::BATCH_SIZE;
            $batchesProcessed++;
            $this->progress->setCursor($offset);

            if ($stats['processed'] < self::BATCH_SIZE) {
                break;
            }
        }

        return array(
            'offset' => $offset,
            'threw' => $threw,
            'processed' => $processed,
            'success' => $success,
            'rowsFailed' => $rowsFailed,
            'percent' => $totalRows > 0
                ? (float)min(100, round(($offset / $totalRows) * 100, 1)) : 100.0,
            'failureContext' => $failureContext,
        );
    }

    /**
     * Whether a drain covered its whole set with nothing left behind.
     *
     * Reaching the end of the set is not sufficient. Retiring on offset alone is
     * how a partial cache gets published as complete, which is the same defect
     * as advancing past a thrown batch, one level down.
     *
     * The loop already holds the cursor in front of any batch that reported
     * failed rows, so today a drain cannot both carry failures and reach the
     * end. The rowsFailed check stays anyway: it states the invariant where the
     * CONSEQUENCE is (publishing the cache), not only where the cursor happens
     * to be maintained, so a later change to the loop cannot quietly turn "some
     * rows never rebuilt" back into "rebuild complete".
     *
     * @param array{offset:int, threw:bool, rowsFailed:int} $outcome
     * @param int $totalRows
     * @return bool
     */
    public static function isClean(array $outcome, int $totalRows): bool {
        return !$outcome['threw']
            && $outcome['rowsFailed'] === 0
            && $outcome['offset'] >= $totalRows;
    }

    /**
     * What stopped this drain, as text, or '' when nothing did.
     *
     * Kept here, beside the method that writes the field, because the
     * orchestrator had grown three different readings of it: `=== null` to
     * decide whether the tick counted as a success, `is_string()` to decide
     * whether to ask the retry policy about it, and `is_string() && !== ''` to
     * decide whether to record it against the consecutive-failure budget. Three
     * readings of one field is three chances to disagree, and the two that test
     * the TYPE disagree with the one that tests for null: they answer "nothing
     * failed" for a value that is not null.
     *
     * NULL, and only null, is the absence of a failure. Anything else is a
     * failure, and one whose description cannot be read says exactly that
     * rather than disappearing -- dropping it would leave the tick unrecorded
     * against the budget that stops the chain AND unmentioned in the log, so
     * the rebuild would re-arm at the base cadence indefinitely with nothing
     * anywhere saying why.
     *
     * @param array{failureContext?: mixed} $outcome
     * @return string Empty only when the drain reported no failure at all.
     */
    public static function failureTextOf(array $outcome): string {
        $failureContext = $outcome['failureContext'] ?? null;
        if ($failureContext === null) {
            return '';
        }
        if (is_string($failureContext) && $failureContext !== '') {
            return $failureContext;
        }
        // Unclassifiable by the retry policy, which reads driver text, so this
        // is treated as retryable -- the safe direction: the consecutive-failure
        // budget still ends the chain, it just costs a few backed-off ticks to
        // get there instead of one.
        return 'N-gram rebuild stopped for a reason it could not describe (got '
            . gettype($failureContext) . ').';
    }

    /**
     * Rebuild one batch and return its stats.
     *
     * A rebuilder that does not report a processed count is a broken
     * collaborator, and this method says so instead of substituting 0.
     * Substituting 0 was indistinguishable from "this batch found no more
     * rows", which is the loop's end-of-data signal -- so a rebuilder returning
     * junk read as a finished rebuild and the cache was marked complete.
     *
     * Takes only the offset: the batch size is a class constant, so there is no
     * pair of adjacent ints a caller can transpose.
     *
     * @param int $offset
     * @return array{processed: int, success: int, failed: int}
     * @throws RuntimeException When the rebuilder's result cannot be read.
     */
    private function runRebuildBatch(int $offset): array {
        $stats = ($this->rebuildCache)(self::BATCH_SIZE, $offset);

        if (!is_array($stats) || !isset($stats['processed']) || !is_numeric($stats['processed'])) {
            throw new RuntimeException(
                'N-gram rebuilder returned no readable processed count at offset ' . $offset .
                ' (got ' . gettype($stats) . '); refusing to read that as end-of-data.'
            );
        }

        // Range-check, not just presence-check. A negative count would walk the
        // cursor BACKWARDS into an endless loop, and a count larger than the
        // batch we asked for means the collaborator did something other than
        // what was requested -- in both cases the number is not a description of
        // this batch, and letting it advance the cursor publishes a cache whose
        // coverage nobody can account for.
        $processed = (int)$stats['processed'];
        if ($processed < 0 || $processed > self::BATCH_SIZE) {
            throw new RuntimeException(
                'N-gram rebuilder reported ' . $processed . ' rows processed for a batch of '
                . self::BATCH_SIZE . ' at offset ' . $offset . '; refusing to advance on a count '
                . 'that cannot describe this batch.'
            );
        }

        // The failed count is REQUIRED, not defaulted. Defaulting it to 0 makes
        // an unreadable result look like a clean batch, and a clean batch is
        // what lets isClean() publish the cache as complete.
        if (!isset($stats['failed']) || !is_numeric($stats['failed'])) {
            throw new RuntimeException(
                'N-gram rebuilder returned no readable failed count at offset ' . $offset
                . '; refusing to read an unreadable batch as a clean one.'
            );
        }
        $success = isset($stats['success']) && is_numeric($stats['success']) ? (int)$stats['success'] : 0;
        $failed = (int)$stats['failed'];

        return [
            'processed' => $processed,
            // Clamped rather than trusted: these two only drive reporting and
            // the completion guard, so an out-of-range value must not be able to
            // make a run look cleaner than it was.
            'success' => max(0, min($success, $processed)),
            'failed' => max(0, min($failed, $processed)),
        ];
    }
}
