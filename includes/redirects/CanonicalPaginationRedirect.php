<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Answers a 404 that WordPress core would have canonicalized away on its own.
 *
 * The decision -- is this request in that class, and what is its canonical URL
 * -- belongs to {@see ABJ_404_Solution_CanonicalPaginationUrlResolver}, which
 * also documents the class of URL and why the plugin has to handle it. This
 * class owns what happens once the answer is known: keep the evidence, then
 * emit the redirect.
 *
 * WHY THE PLUGIN HAS TO DO THIS AT ALL
 *
 * `redirect_canonical()` is hooked on `template_redirect` at priority 10. This
 * plugin's listener defaults to priority 9, so on a default install it answers
 * FIRST and core's canonical redirect never gets the chance. The URL then
 * reaches the suggestion pipeline, which either invents a destination for it
 * (auto-redirects on) or captures it and lets it accumulate hits forever
 * (auto-redirects off). Either way a URL that was never broken is treated as
 * broken. The same thing happens at any priority when a plugin, cache layer,
 * or server rule has suppressed core's canonical redirect.
 *
 * CAPTURE IS NOT SKIPPED
 *
 * Losing the record would lose the evidence that the site has a canonical
 * problem, which is exactly what let one of these sit unnoticed on a
 * production site for months. The row is written as CAPTURED with its `engine`
 * column naming the class, so a run of them is visible in the existing
 * captured-404 table and hit log without adding any new admin surface.
 *
 * WHY IT IS NOT A MATCHING ENGINE
 *
 * Matching engines run only when auto-redirects are enabled, and their results
 * are persisted as AUTO redirect rows keyed to a post id. This is neither: it
 * has to run regardless of the auto-redirect setting (the capture-forever case
 * is the auto-redirects-off configuration), it produces a URL rather than a
 * content match, and it must not create a redirect rule -- a stored rule would
 * be re-dispatched through the destination-mapping path, which re-appends the
 * request's query string and would rebuild the very URL being canonicalized.
 */
class ABJ_404_Solution_CanonicalPaginationRedirect {

    /**
     * Stored in the `engine` column and used as the log match-reason. Kept
     * untranslated on purpose: it is a persisted discriminator, so it must not
     * change with the admin's locale.
     */
    const ENGINE_NAME = 'wordpress canonical';

    /** Canonical normalization is permanent, so core issues a 301 and so do we. */
    const REDIRECT_STATUS = 301;

    /** @var ABJ_404_Solution_RedirectsRepositoryInterface */
    private $redirectsRepository;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_FrontendHitRecorder */
    private $hitRecorder;

    /** @var ABJ_404_Solution_CanonicalPaginationUrlResolver */
    private $urlResolver;

    /**
     * @param ABJ_404_Solution_RedirectsRepositoryInterface $redirectsRepository
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_FrontendHitRecorder $hitRecorder
     * @param ABJ_404_Solution_CanonicalPaginationUrlResolver|null $urlResolver
     */
    function __construct($redirectsRepository, $logger, $hitRecorder, $urlResolver = null) {
        $this->redirectsRepository = $redirectsRepository;
        $this->logger = $logger;
        $this->hitRecorder = $hitRecorder;
        $this->urlResolver = ($urlResolver !== null)
            ? $urlResolver
            : new ABJ_404_Solution_CanonicalPaginationUrlResolver();
    }

    /**
     * Emit the canonical 301 when this request belongs to the class, otherwise
     * leave the request untouched for the rest of the pipeline.
     *
     * @param string $requestedURL requested path with sorted query string, the
     *                             same form the redirects table is keyed by.
     * @param array<string, mixed> $options plugin options for this request.
     * @param ABJ_404_Solution_FrontendPipelineTrace $trace
     * @return bool true when a redirect was emitted and the caller must stop.
     */
    function redirectIfCanonicalizable(string $requestedURL, array $options,
            ABJ_404_Solution_FrontendPipelineTrace $trace): bool {

        $resolution = $this->urlResolver->resolve($requestedURL);
        if ($resolution['status'] !== ABJ_404_Solution_CanonicalPaginationUrlResolver::STATUS_MATCHED) {
            if ($resolution['reason'] !== null) {
                $this->logger->debugMessage($resolution['reason']);
            }
            return false;
        }
        $canonicalUrl = $resolution['url'];

        $trace->add('WordPress canonical', 'Matched',
            'reserved pagination query var -> ' . $canonicalUrl);

        $this->captureEvidence($requestedURL, $options);
        if (!$this->sendCanonicalRedirect($canonicalUrl)) {
            return false;
        }

        // A hit describes a redirect WordPress accepted, not merely one the
        // resolver proposed. Recording after the boundary keeps a canceled
        // Location from appearing as a successful redirect in the hit log.
        $this->hitRecorder->record($requestedURL, $canonicalUrl, self::ENGINE_NAME, null, $trace->getSteps());
        return $this->terminateAfterAcceptedRedirect();
    }

    /**
     * Write the captured-404 row that keeps the evidence, stamping the class
     * onto the existing `engine` column so a run of these reads as a signal
     * about the SITE rather than about any one URL.
     *
     * Respects `capture_404`: an admin who turned capture off is asking for no
     * rows at all, and the redirect itself does not depend on the record.
     *
     * @param string $requestedURL
     * @param array<string, mixed> $options
     * @return void
     */
    private function captureEvidence(string $requestedURL, array $options): void {
        $captureSetting = isset($options['capture_404']) && is_scalar($options['capture_404'])
            ? (string)$options['capture_404'] : '';
        if ($captureSetting !== '1') {
            return;
        }

        $defaultRedirect = isset($options['default_redirect']) && is_scalar($options['default_redirect'])
            ? (string)$options['default_redirect'] : (string)self::REDIRECT_STATUS;

        $this->redirectsRepository->setupRedirectIfSourceAbsent(
            ABJ_404_Solution_RedirectSpec::fromArray(array(
                'fromURL' => $requestedURL,
                'status' => (string)ABJ404_STATUS_CAPTURED,
                'type' => (string)ABJ404_TYPE_404_DISPLAYED,
                'finalDest' => (string)ABJ404_TYPE_404_DISPLAYED,
                'code' => $defaultRedirect,
                'disabled' => 0,
                'engine' => self::ENGINE_NAME,
            ))
        );
    }

    /**
     * Emit the canonical 301.
     *
     * Deliberately does not route through
     * `ABJ_404_Solution_NotFoundResponseService::forceRedirect()`. That method
     * appends the current request's comment-page and query parts to the
     * destination, which is correct for a destination-mapping redirect (the
     * visitor asked for `?colour=red` on a moved page and should keep it) but
     * is exactly wrong here: it would re-attach the `?page=1` this redirect
     * exists to remove and rebuild the URL that just 404'd.
     *
     * @param string $canonicalUrl
     * @return bool true when the redirect was emitted.
     */
    private function sendCanonicalRedirect(string $canonicalUrl): bool {
        if (headers_sent()) {
            // The response body is already going out; a Location header can no
            // longer be set. Fall through to normal 404 handling rather than
            // half-emitting a redirect.
            $this->logger->warn('Canonical pagination redirect to "' . $canonicalUrl .
                '" skipped: headers were already sent.');
            return false;
        }

        if (function_exists('abj404_benchmark_emit_headers')) {
            abj404_benchmark_emit_headers();
        }

        $redirectAccepted = wp_safe_redirect($canonicalUrl, self::REDIRECT_STATUS, ABJ404_NAME);
        if ($redirectAccepted === false) {
            $this->logger->warn('Canonical pagination redirect to "' . $canonicalUrl .
                '" was rejected by WordPress; continuing normal 404 handling. Status: ' .
                (string)self::REDIRECT_STATUS . ', source: ' . ABJ404_NAME . '.');
            return false;
        }
        $this->logger->debugMessage('WordPress canonical redirect: ' . $canonicalUrl);

        return true;
    }

    /**
     * Finish a redirect only after WordPress accepted its Location header and
     * the associated hit was persisted.
     *
     * @return bool true for test/embedding callers that suppress process exit.
     */
    private function terminateAfterAcceptedRedirect(): bool {
        if (!apply_filters('abj404_should_exit', true, array('source' => 'canonicalPaginationRedirect'))) {
            return true;
        }
        exit;
    }
}
