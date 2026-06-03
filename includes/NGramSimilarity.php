<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * N-gram set similarity primitives.
 *
 * Owns the Dice coefficient (set-overlap similarity) and the proximity-merge
 * routine that interleaves two pre-sorted result arrays by distance to a
 * target ngram count. Pure computation; no I/O.
 *
 * Used by NGramFilter::findSimilarPages() during candidate selection and by
 * NGramCacheRepository::getCachedNGramsFiltered() to merge below/above-target
 * query halves. Algorithm tests exercise these directly.
 */
class ABJ_404_Solution_NGramSimilarity {

    /**
     * Compute Dice coefficient similarity between two N-gram sets.
     *
     * Dice coefficient: 2 * |intersection| / (|set1| + |set2|)
     * Range: 0.0 (no overlap) to 1.0 (identical).
     *
     * Threshold correlation:
     * - 0.4 = ~30% edit distance (recommended)
     * - 0.5 = ~20% edit distance
     * - 0.6 = ~10% edit distance
     *
     * @param array{bi?: array<int, string>, tri?: array<int, string>} $ngrams1
     * @param array{bi?: array<int, string>, tri?: array<int, string>} $ngrams2
     * @return float Similarity score 0.0 to 1.0
     */
    public function diceCoefficient($ngrams1, $ngrams2) {
        $set1 = array_merge(
            isset($ngrams1['bi']) ? $ngrams1['bi'] : [],
            isset($ngrams1['tri']) ? $ngrams1['tri'] : []
        );
        $set2 = array_merge(
            isset($ngrams2['bi']) ? $ngrams2['bi'] : [],
            isset($ngrams2['tri']) ? $ngrams2['tri'] : []
        );

        if (empty($set1) || empty($set2)) {
            return 0.0;
        }

        $set1 = array_flip($set1);
        $set2 = array_flip($set2);

        $intersection = count(array_intersect_key($set1, $set2));

        // Defensive: empty() check above should guarantee denominator > 0.
        $denominator = count($set1) + count($set2);
        return ($denominator > 0) ? (2.0 * $intersection) / $denominator : 0.0;
    }

    /**
     * Merge two arrays sorted by proximity to target, interleaving results.
     *
     * Both input arrays must be pre-sorted by proximity to the target:
     * - $below: ngram_count <= target, ordered DESC by ngram_count (closest first)
     * - $above: ngram_count > target, ordered ASC by ngram_count (closest first)
     *
     * @param array<int, mixed> $below
     * @param array<int, mixed> $above
     * @param int $targetNgramCount
     * @param int $limit
     * @return array<int, mixed>
     */
    public function mergeByProximity($below, $above, $targetNgramCount, $limit) {
        $result = [];
        $i = 0;
        $j = 0;
        $belowCount = count($below);
        $aboveCount = count($above);

        while (count($result) < $limit && ($i < $belowCount || $j < $aboveCount)) {
            $belowEntry = $below[$i] ?? null;
            $aboveEntry = $above[$j] ?? null;
            $belowNgramRaw = (is_array($belowEntry) && isset($belowEntry['ngram_count'])) ? $belowEntry['ngram_count'] : 0;
            $belowNgramCount = is_scalar($belowNgramRaw) ? (int)$belowNgramRaw : 0;
            $aboveNgramRaw = (is_array($aboveEntry) && isset($aboveEntry['ngram_count'])) ? $aboveEntry['ngram_count'] : 0;
            $aboveNgramCount = is_scalar($aboveNgramRaw) ? (int)$aboveNgramRaw : 0;
            $distBelow = ($i < $belowCount)
                ? abs($belowNgramCount - $targetNgramCount)
                : PHP_INT_MAX;
            $distAbove = ($j < $aboveCount)
                ? abs($aboveNgramCount - $targetNgramCount)
                : PHP_INT_MAX;

            // Prefer below on tie (includes exact matches)
            if ($distBelow <= $distAbove) {
                $result[] = $below[$i];
                $i++;
            } else {
                $result[] = $above[$j];
                $j++;
            }
        }

        return $result;
    }
}
