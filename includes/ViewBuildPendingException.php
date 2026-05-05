<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thrown by runRedirectsForViewStaged() / runRedirectsForViewCountStaged()
 * when the precomputed view_done table is missing or invalidated.
 *
 * Distinct from ABJ_404_Solution_ViewQueryFailureException: a query *failure*
 * is a real error worth surfacing diagnostics for. A *pending* build is the
 * normal cold-start state. view_done has not yet been built, the AJAX gate
 * is supposed to translate this into a `viewBuildPending` response, and
 * background cron / the JS poller will advance the build.
 *
 * This class lets callers (getRedirectsForView, getRedirectsForViewCount,
 * the warmup pipeline, and tests) match on "build-not-ready" specifically
 * without conflating it with infrastructure errors. Carries the current
 * progress text so admin notices can render the same state the AJAX
 * progress response shows.
 */
class ABJ_404_Solution_ViewBuildPendingException extends Exception {

    /** @var string */
    private $progressText;

    /**
     * @param string $message
     * @param string $progressText
     * @param Throwable|null $previous
     */
    public function __construct(string $message, string $progressText = '', ?Throwable $previous = null) {
        parent::__construct($message, 0, $previous);
        $this->progressText = $progressText;
    }

    /** @return string */
    public function getProgressText(): string {
        return $this->progressText;
    }
}
