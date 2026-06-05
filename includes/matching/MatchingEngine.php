<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Interface for pluggable URL matching engines.
 *
 * Each engine represents one strategy for matching a 404 request to an existing page
 * (e.g., slug lookup, spelling correction, title matching). The pipeline iterates
 * engines in order; the first non-null result wins.
 */
interface ABJ_404_Solution_MatchingEngine {

    /**
     * Human-readable name used in log entries and debug output.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Pre-flight check: should this engine even attempt a match?
     *
     * Return false to skip expensive work (e.g., empty slug, synthetic URL patterns).
     *
     * @param ABJ_404_Solution_MatchRequest $request
     * @return bool
     */
    public function shouldRun(ABJ_404_Solution_MatchRequest $request): bool;

    /**
     * Attempt to find a matching page/post for the request.
     *
     * @param ABJ_404_Solution_MatchRequest $request
     * @return ABJ_404_Solution_MatchResult|null Null means no match; pipeline tries next engine.
     */
    public function match(ABJ_404_Solution_MatchRequest $request): ?ABJ_404_Solution_MatchResult;
}
