<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decides whether a computed redirect destination can actually terminate.
 *
 * Every redirect the plugin emits passes through
 * {@see ABJ_404_Solution_NotFoundResponseService::forceRedirect()}, which
 * resolves a target and then appends the current request's comment-page and
 * query-string parts to it. Both of those steps can produce a destination that
 * sends the visitor straight back to the request being answered. This class is
 * the decision layer in front of the emission: hand it the destination and the
 * resolved target, and it answers with a destination that terminates, or false
 * meaning "emit no redirect at all".
 *
 * Two independent loops are recognized, because they are visible in different
 * places:
 *   - The cookie loop (avoidInfiniteRedirect): request A redirects to B and B
 *     redirects back to A. Only visible across requests, via the previous
 *     request cookie.
 *   - The self loop (avoidSelfRedirect): the destination IS the request. Visible
 *     within the single request being answered, with no cookie involved.
 *
 * Business logic only: no data access, no response writing.
 *
 * // allow-no-test-found: exercised by RedirectSelfLoopGuardTest
 */
class ABJ_404_Solution_RedirectLoopGuard {

    /** @var ABJ_404_Solution_Functions */
    private $functions;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_PreviousRequestCookieTracker */
    private $previousRequestCookieTracker;

    /**
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_Logging|null $logging
     * @param ABJ_404_Solution_PreviousRequestCookieTracker|null $previousRequestCookieTracker
     */
    function __construct($functions = null, $logging = null, $previousRequestCookieTracker = null) {
        $this->functions = $functions !== null ? $functions : abj_service('functions');
        $this->logger = $logging !== null ? $logging : abj_service('logging');
        $this->previousRequestCookieTracker = $previousRequestCookieTracker !== null
            ? $previousRequestCookieTracker
            : abj_service('previous_request_cookie_tracker');
    }

    /**
     * Reduce a destination to one that terminates.
     *
     * @param array{finalDestination: string, location: string} $request
     * @return string|false a destination that does not loop, or false when no
     *                      redirect can terminate and none should be sent.
     */
    function terminatingDestination(array $request) {
        $finalDestination = $request['finalDestination'];
        $location = $request['location'];
        $loopSafeDestination = $this->avoidInfiniteRedirect(array(
            'finalDestination' => $finalDestination,
            'location' => $location,
        ));
        if ($loopSafeDestination === false) {
            return false;
        }

        return $this->avoidSelfRedirect(array(
            'finalDestination' => $loopSafeDestination,
            'location' => $location,
        ));
    }

    /**
     * @param array{finalDestination: string, location: string} $request
     * @return string|false
     */
    private function avoidInfiniteRedirect(array $request) {
        $finalDestination = $request['finalDestination'];
        $location = $request['location'];
        $previousRequest = is_object($this->previousRequestCookieTracker)
            ? $this->previousRequestCookieTracker->readCookieWithPreviousRqeuestShort()
            : '';
        if (empty($previousRequest)) {
            return $finalDestination;
        }

        $finalDestNoHome = $this->redirectPathOnly($finalDestination);
        $locationNoHome = $this->redirectPathOnly($location);
        if ($previousRequest == $finalDestNoHome && $previousRequest != $locationNoHome) {
            $this->logger->infoMessage("Maybe avoided infite redirects to/from: " . $previousRequest);
            return $location;
        }

        if ($previousRequest == $finalDestination) {
            $this->logger->infoMessage("Avoided infite redirects to/from: " . $previousRequest);
            return false;
        }

        return $finalDestination;
    }

    /**
     * Never answer a request with a redirect back to that same request.
     *
     * The destination handed to forceRedirect() is the resolved target with
     * the current request's comment-page and query-string parts appended
     * (buildFinalRedirectDestination). When the target's own path is already
     * the requested path, appending the request's query reconstructs the
     * requested URL exactly and the 301 sends the visitor straight back. That
     * is what a homepage 404 destination does to a request like `/?page=1`:
     * `get_home_url()` has no trailing slash, so the destination becomes
     * "https://site.com" . "?page=1", which is the request. Every trip round
     * the loop is a fresh WordPress 404, so the captured-404 row's hit count
     * climbs by one per iteration until the browser gives up.
     *
     * avoidInfiniteRedirect() cannot see this: its cookie deliberately stores
     * the request with the query string stripped, so a loop that exists only
     * because of the query string is invisible to it. This check compares the
     * outgoing Location against the request being answered directly, with no
     * cookie involved, so it also holds on the very first request, for
     * cookie-less clients (crawlers, curl), and behind caches that drop
     * Set-Cookie.
     *
     * Scheme is deliberately not part of the comparison. A destination that
     * differs from the request only by scheme still 404s on arrival and loops
     * one hop later; dropping the appended query fixes the loop and the scheme
     * in a single redirect.
     *
     * @param array{finalDestination: string, location: string} $request the
     *        destination including appended comment-page/query parts and the
     *        resolved target before those parts were appended.
     * @return string|false a destination that is not the current request, or
     *                      false when no redirect can terminate.
     */
    private function avoidSelfRedirect(array $request) {
        $finalDestination = $request['finalDestination'];
        $location = $request['location'];
        if ($finalDestination === '') {
            return $finalDestination;
        }

        $requestAuthority = $this->normalizeAuthority(
            isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : ''
        );
        $requestUri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])
            ? $_SERVER['REQUEST_URI'] : '';
        if ($requestUri === '') {
            // No request context (CLI, WP-Cron): nothing to compare against.
            return $finalDestination;
        }

        $requestKey = $this->urlIdentityKey(array(
            'url' => $requestUri,
            'requestAuthority' => $requestAuthority,
        ));
        $destinationIsRequest = ($this->urlIdentityKey(array(
            'url' => $finalDestination,
            'requestAuthority' => $requestAuthority,
        )) === $requestKey);
        $targetIsRequest = ($location !== '' && $this->urlIdentityKey(array(
            'url' => $location,
            'requestAuthority' => $requestAuthority,
        )) === $requestKey);

        if ($targetIsRequest || ($destinationIsRequest && $location === '')) {
            // The configured target IS the requested URL. Dropping the appended
            // parts cannot help, so emit no redirect and let WordPress answer
            // the request it already resolved.
            $this->logger->warn('Skipped a redirect whose destination is the URL being requested: ' .
                $finalDestination);
            return false;
        }

        if ($destinationIsRequest) {
            // The appended query/comment part is what turned the target back
            // into the request. Honor the configured destination without it.
            $this->logger->infoMessage('Dropped the request query string from a redirect destination that ' .
                'would otherwise have pointed back at the request. Destination: ' . $location);
            return $location;
        }

        return $finalDestination;
    }

    /**
     * Reduce a URL to the parts that decide whether two URLs are the same
     * resource: authority (host plus non-default port), path, and the query as
     * an order-independent set.
     *
     * Empty paths normalize to "/" ("https://site.com" and "https://site.com/"
     * are one URL). Trailing slashes are otherwise left alone, because
     * "/foo" and "/foo/" are different URLs and redirecting between them
     * terminates. A URL with no host of its own is read as host-relative and
     * takes the request's authority.
     *
     * The port has to be in the key and has to be normalized on both sides:
     * $_SERVER['HTTP_HOST'] carries it inline ("localhost:8888") while
     * parse_url() splits it into its own component, so comparing bare hosts
     * silently never matches on any site not served from port 80/443.
     *
     * @param array{url: string, requestAuthority: string} $request
     * @return string comparison key; never treat it as a URL.
     */
    private function urlIdentityKey(array $request): string {
        $url = $request['url'];
        $requestAuthority = $request['requestAuthority'];
        $parts = parse_url($url);
        if (!is_array($parts)) {
            // Unparseable URLs are only ever equal to themselves.
            return "\0unparseable\0" . $url;
        }

        $authority = $requestAuthority;
        if (isset($parts['host']) && is_string($parts['host']) && $parts['host'] !== '') {
            $port = isset($parts['port']) ? (int)$parts['port'] : 0;
            $authority = $this->normalizeAuthority(
                $parts['host'] . ($port > 0 ? ':' . $port : '')
            );
        }

        $path = isset($parts['path']) && is_string($parts['path']) && $parts['path'] !== ''
            ? $parts['path'] : '/';
        $query = isset($parts['query']) && is_string($parts['query']) ? $parts['query'] : '';

        $pairs = ($query === '') ? array() : explode('&', $query);
        sort($pairs);

        return $authority . '|' . $path . '|' . implode('&', $pairs);
    }

    /**
     * Lowercase a host[:port] pair and drop the ports browsers leave implicit,
     * so "Site.com:443" and "site.com" compare equal.
     *
     * @param string $authority
     * @return string
     */
    private function normalizeAuthority(string $authority): string {
        $normalized = strtolower(trim($authority));
        if ($normalized === '') {
            return '';
        }
        $colonPos = strrpos($normalized, ':');
        if ($colonPos === false) {
            return $normalized;
        }
        $port = $this->functions->substr($normalized, $colonPos + 1);
        if ($port === '80' || $port === '443') {
            return $this->functions->substr($normalized, 0, $colonPos);
        }
        return $normalized;
    }

    private function redirectPathOnly(string $url): string {
        $schemePos = $this->functions->strpos($url, '://');
        $withoutHost = ($schemePos !== false)
            ? $this->functions->substr($url, $schemePos + 3) : $url;
        $slashPos = $this->functions->strpos($withoutHost, '/');
        return ($slashPos !== false) ? $this->functions->substr($withoutHost, $slashPos) : '/';
    }

}
