<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * N-gram filter usage telemetry.
 *
 * Records per-query counters (total queries, entries examined, candidates
 * returned, total duration in ms, rolling avg reduction) into a single
 * WordPress option. Self-resets monthly to bound option size. Read path
 * derives avg-per-query stats from the same option.
 *
 * Used by NGramFilter::findSimilarPages() (write) and the admin diagnostics
 * UI (read via getUsageStats).
 */
class ABJ_404_Solution_NGramUsageTelemetry {

    const OPTION_KEY = 'abj404_ngram_usage_stats';

    /** Reset stats after 30 days to avoid unbounded growth */
    const RESET_INTERVAL_SECONDS = 2592000; // 30 * 24 * 60 * 60

    /**
     * Track a single findSimilarPages invocation.
     *
     * @param int $totalInCache Total entries in cache
     * @param int $examined Number of entries examined
     * @param int $candidates Number of candidates returned
     * @param float $duration Time in milliseconds
     * @return void
     */
    public function trackNGramUsage($totalInCache, $examined, $candidates, $duration) {
        $defaultStats = [
            'total_queries' => 0,
            'total_entries_examined' => 0,
            'total_candidates_returned' => 0,
            'total_duration_ms' => 0,
            'avg_reduction_percent' => 0,
            'last_reset' => abj_clock()->now(),
        ];
        $statsRaw = get_option(self::OPTION_KEY, $defaultStats);
        /** @var array<string, mixed> $stats */
        $stats = is_array($statsRaw) ? $statsRaw : $defaultStats;

        $stats['total_queries'] = (isset($stats['total_queries']) && is_numeric($stats['total_queries'])) ? (int)$stats['total_queries'] + 1 : 1;
        $stats['total_entries_examined'] = (isset($stats['total_entries_examined']) && is_numeric($stats['total_entries_examined'])) ? (int)$stats['total_entries_examined'] + $examined : $examined;
        $stats['total_candidates_returned'] = (isset($stats['total_candidates_returned']) && is_numeric($stats['total_candidates_returned'])) ? (int)$stats['total_candidates_returned'] + $candidates : $candidates;
        $stats['total_duration_ms'] = (isset($stats['total_duration_ms']) && is_numeric($stats['total_duration_ms'])) ? (float)$stats['total_duration_ms'] + $duration : $duration;

        if ($totalInCache > 0) {
            $reductionPercent = (($totalInCache - $examined) / $totalInCache) * 100;
            $prevAvgReduction = (isset($stats['avg_reduction_percent']) && is_numeric($stats['avg_reduction_percent'])) ? (float)$stats['avg_reduction_percent'] : 0;
            $totalQueries = (int)$stats['total_queries'];
            $stats['avg_reduction_percent'] = (($prevAvgReduction * ($totalQueries - 1)) + $reductionPercent) / $totalQueries;
        }

        $monthAgo = abj_clock()->now() - self::RESET_INTERVAL_SECONDS;
        $lastReset = (isset($stats['last_reset']) && is_numeric($stats['last_reset'])) ? (int)$stats['last_reset'] : 0;
        if ($lastReset < $monthAgo) {
            $stats = [
                'total_queries' => 1,
                'total_entries_examined' => $examined,
                'total_candidates_returned' => $candidates,
                'total_duration_ms' => $duration,
                'avg_reduction_percent' => ($totalInCache > 0) ? (($totalInCache - $examined) / $totalInCache) * 100 : 0,
                'last_reset' => abj_clock()->now(),
            ];
        }

        update_option(self::OPTION_KEY, $stats);
    }

    /**
     * Get usage statistics with derived per-query averages.
     *
     * @return array<string, mixed>
     */
    public function getUsageStats() {
        $defaultStats = [
            'total_queries' => 0,
            'total_entries_examined' => 0,
            'total_candidates_returned' => 0,
            'total_duration_ms' => 0,
            'avg_reduction_percent' => 0,
            'last_reset' => abj_clock()->now(),
        ];
        $statsRaw = get_option(self::OPTION_KEY, $defaultStats);
        /** @var array<string, mixed> $stats */
        $stats = is_array($statsRaw) ? $statsRaw : $defaultStats;

        $totalQueries = (isset($stats['total_queries']) && is_numeric($stats['total_queries'])) ? (int)$stats['total_queries'] : 0;
        $totalExamined = (isset($stats['total_entries_examined']) && is_numeric($stats['total_entries_examined'])) ? (float)$stats['total_entries_examined'] : 0;
        $totalCandidates = (isset($stats['total_candidates_returned']) && is_numeric($stats['total_candidates_returned'])) ? (float)$stats['total_candidates_returned'] : 0;
        $totalDuration = (isset($stats['total_duration_ms']) && is_numeric($stats['total_duration_ms'])) ? (float)$stats['total_duration_ms'] : 0;

        if ($totalQueries > 0) {
            $stats['avg_examined_per_query'] = round($totalExamined / $totalQueries, 1);
            $stats['avg_candidates_per_query'] = round($totalCandidates / $totalQueries, 1);
            $stats['avg_duration_ms'] = round($totalDuration / $totalQueries, 2);
        } else {
            $stats['avg_examined_per_query'] = 0;
            $stats['avg_candidates_per_query'] = 0;
            $stats['avg_duration_ms'] = 0;
        }

        return $stats;
    }
}
