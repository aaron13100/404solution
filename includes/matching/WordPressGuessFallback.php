<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Last-resort redirect attempt using WordPress's built-in 404 permalink guess.
 *
 * WordPress matches partial slugs via LIKE 'slug%', a complementary strategy
 * to our Levenshtein-based spell checker (e.g. /redes matches /redes-social
 * because the slug starts with "redes"). When a guess matches and is not
 * excluded, this records the redirect and emits it, then exits. If no guess
 * matches, the guess is a self-redirect, the destination is excluded, or the
 * emit is blocked, it records a trace step and returns so the caller can
 * fall through to the normal-post query / 404 page.
 *
 * Gated by the `abj404_wp_guess_fallback_enabled` filter and the engine
 * profile resolver (per-URL engine enablement).
 */
class ABJ_404_Solution_WordPressGuessFallback {

    /** @var ABJ_404_Solution_PluginLogicUrlNormalization */
    private $urlNormalization;

    /** @var ABJ_404_Solution_RedirectsRepository */
    private $redirectsRepository;

    /** @var ABJ_404_Solution_NotFoundResponseService */
    private $notFoundResponse;

    /** @var ABJ_404_Solution_RedirectExclusionPolicy */
    private $exclusionPolicy;

    /** @var mixed */
    private $logsRepository;

    /**
     * @param ABJ_404_Solution_PluginLogicUrlNormalization $urlNormalization
     * @param ABJ_404_Solution_RedirectsRepository $redirectsRepository
     * @param ABJ_404_Solution_NotFoundResponseService $notFoundResponse
     * @param ABJ_404_Solution_RedirectExclusionPolicy $exclusionPolicy
     * @param mixed $logsRepository Object with logRedirectHit(); shape is duck-typed.
     */
    function __construct($urlNormalization, $redirectsRepository, $notFoundResponse, $exclusionPolicy, $logsRepository) {
        $this->urlNormalization = $urlNormalization;
        $this->redirectsRepository = $redirectsRepository;
        $this->notFoundResponse = $notFoundResponse;
        $this->exclusionPolicy = $exclusionPolicy;
        $this->logsRepository = $logsRepository;
    }

    /**
     * @param bool   $autoRedirectsAreOn Whether auto-redirect creation is enabled.
     * @param string $requestedURL       The normalized requested URL that 404'd.
     * @param array<string, mixed> $options
     * @param ABJ_404_Solution_FrontendPipelineTrace $trace
     */
    function tryFallback(bool $autoRedirectsAreOn, string $requestedURL, array $options, ABJ_404_Solution_FrontendPipelineTrace $trace): void {
        $wpGuessFallbackEnabled = $autoRedirectsAreOn && $this->shouldRunGuess($requestedURL);
        if (!$wpGuessFallbackEnabled) {
            $reason = !$autoRedirectsAreOn ? 'auto_redirects off' : 'engine profile/filter';
            $trace->add('WordPress URL guess', 'Skipped: ' . $reason);
            return;
        }

        // The original branch ran only when redirect_guess_404_permalink() existed and
        // did nothing (no trace) when it was absent; preserve that silent no-op.
        if (!function_exists('redirect_guess_404_permalink')) {
            return;
        }

        $wpGuess = redirect_guess_404_permalink();
        if ($wpGuess && is_string($wpGuess)) {
            // attemptGuessRedirect() either exit()s on a successful redirect or returns
            // so the shared 'No match' trace below is recorded for every fall-through.
            $this->attemptGuessRedirect($wpGuess, $requestedURL, $options, $trace);
        }
        $trace->add('WordPress URL guess', 'No match');
    }

    /**
     * Given a concrete WordPress permalink guess, decide whether to redirect to it and,
     * if so, record the redirect and emit it (which exits). Each branch (self-redirect,
     * excluded destination, blocked redirect) records a trace step and returns so the
     * caller can fall through to the normal-post query / 404 page.
     *
     * @param string $wpGuess      The candidate URL returned by redirect_guess_404_permalink().
     * @param string $requestedURL The normalized requested URL that 404'd.
     * @param array<string, mixed> $options
     * @param ABJ_404_Solution_FrontendPipelineTrace $trace
     */
    private function attemptGuessRedirect(string $wpGuess, string $requestedURL, array $options, ABJ_404_Solution_FrontendPipelineTrace $trace): void {
        $wpGuessEngineName = __('wp guess', '404-solution');

        $normalizedGuess = $this->normalizeGuessedUrlToRequestShape($wpGuess);
        if ($normalizedGuess !== '' && $normalizedGuess === $requestedURL) {
            $trace->add('WordPress URL guess', 'Ignored self-redirect guess', $wpGuess);
            return;
        }

        $trace->add('WordPress URL guess', 'Matched candidate', $wpGuess);
        $defaultRedirect = isset($options['default_redirect']) && is_scalar($options['default_redirect'])
            ? (string)$options['default_redirect'] : '301';
        $wpGuessType = (string)$this->typePost();
        $wpGuessPostId = $this->resolveGuessPostId($wpGuess);

        $wpGuessResult = new ABJ_404_Solution_MatchResult(
            $wpGuessPostId !== '' ? $wpGuessPostId : '0',
            $wpGuessType,
            $wpGuess,
            '',
            0.0,
            $wpGuessEngineName
        );
        if ($this->exclusionPolicy->isExcluded($wpGuessResult, $options)) {
            $trace->add('WordPress URL guess', 'Excluded destination: skipped', $wpGuess);
            return;
        }

        $trace->add('WordPress URL guess', 'Matched: redirecting', $wpGuess);
        $this->redirectsRepository->setupRedirect(ABJ_404_Solution_RedirectSpec::create(
            $requestedURL, (string)ABJ404_STATUS_AUTO,
            $wpGuessType, $wpGuessPostId, $defaultRedirect, 0, $wpGuessEngineName
        ));
        $this->writeHit($requestedURL, $wpGuess, $wpGuessEngineName, null, $trace->getSteps());
        $redirectSent = $this->notFoundResponse->forceRedirect(esc_url($wpGuess), (int)$defaultRedirect);
        if ($redirectSent !== false) {
            exit;
        }
        $trace->add('WordPress URL guess', 'Redirect blocked: continued', $wpGuess);
    }

    /**
     * Resolve a guessed URL to its WordPress post id as a string, or '' when the URL
     * maps to no post (or url_to_postid() is unavailable).
     *
     * @param string $wpGuess
     * @return string
     */
    private function resolveGuessPostId(string $wpGuess): string {
        if (!function_exists('url_to_postid')) {
            return '';
        }
        $postId = url_to_postid($wpGuess);
        return $postId > 0 ? (string)$postId : '';
    }

    /**
     * @param string $requestedURL
     * @return bool
     */
    private function shouldRunGuess(string $requestedURL): bool {
        $enabled = true;
        if (function_exists('apply_filters')) {
            $enabled = (bool) apply_filters(
                'abj404_wp_guess_fallback_enabled',
                true,
                $requestedURL
            );
        }
        if (!$enabled) {
            return false;
        }

        return ABJ_404_Solution_EngineProfileResolver::getInstance()
            ->isEngineEnabledForUrl($requestedURL, 'ABJ_404_Solution_WordPressUrlGuessEngine');
    }

    /**
     * Normalize a guessed URL into the same request-shape used by $requestedURL:
     * path (relative to WP home directory) plus sorted query string.
     *
     * @param string $guessedUrl
     * @return string
     */
    private function normalizeGuessedUrlToRequestShape(string $guessedUrl): string {
        $normalized = abj_service('sanitizer')->normalizeUrlString($guessedUrl);
        if ($normalized === '') {
            return '';
        }

        $parts = parse_url($normalized);
        if (!is_array($parts)) {
            return '';
        }

        $path = isset($parts['path']) ? $parts['path'] : '/';
        if ($path === '') {
            $path = '/';
        }
        $path = $this->urlNormalization->removeHomeDirectory($path);
        if ($path === '') {
            $path = '/';
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        /** @var array<string, string> $urlPartsStr */
        $urlPartsStr = array_map('strval', $parts);
        $sortedQuery = abj_service('query_string_helper')->sortQueryString($urlPartsStr);
        return $path . $sortedQuery;
    }

    /** @return int */
    private function typePost(): int {
        return (int)ABJ404_TYPE_POST;
    }

    /**
     * @param string $requestedUrl
     * @param string $action
     * @param string $matchReason
     * @param string|null $requestedUrlDetail
     * @param list<array{step: string, outcome: string, detail: string}>|null $pipelineTrace
     */
    private function writeHit(string $requestedUrl, string $action, string $matchReason, ?string $requestedUrlDetail = null, ?array $pipelineTrace = null): void {
        if (!is_object($this->logsRepository) || !is_callable(array($this->logsRepository, 'logRedirectHit'))) {
            return;
        }
        call_user_func(array($this->logsRepository, 'logRedirectHit'), ABJ_404_Solution_RedirectHitLogEntry::create($requestedUrl, $action, $matchReason, $requestedUrlDetail, $pipelineTrace));
    }
}
