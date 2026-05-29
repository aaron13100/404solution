<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Score-band domain constants for redirect suggestion confidence.
 *
 * The spell-checker assigns each redirect suggestion a 0-100 confidence
 * score, or NULL when the redirect was created manually (no automated
 * scoring took place). The admin UI groups these into four bands so users
 * can filter by confidence:
 *
 *   - high:   score >= HIGH (80)
 *   - medium: MEDIUM (50) <= score < HIGH (80)
 *   - low:    score IS NOT NULL AND score < MEDIUM (50)
 *   - manual: score IS NULL (the MANUAL sentinel)
 *
 * Single source of truth for these thresholds. Used by ViewQueryBuilder
 * (for filtering list queries) and View_Stats (for the score-distribution
 * widget). Changing a threshold here changes both surfaces consistently.
 *
 * Value-object class only: no behavior, no state, just constants.
 */
final class ABJ_404_Solution_ScoreThresholds {

    /** High-confidence score band lower bound (inclusive). */
    const HIGH = 80;

    /** Medium-confidence score band lower bound (inclusive). Upper bound is HIGH (exclusive). */
    const MEDIUM = 50;

    /** Sentinel score-range selector value indicating "manual" (score IS NULL). */
    const MANUAL = 'manual';

    /** Score-range selector values that filter to a single band. */
    const RANGE_HIGH = 'high';
    const RANGE_MEDIUM = 'medium';
    const RANGE_LOW = 'low';
}
