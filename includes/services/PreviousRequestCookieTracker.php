<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tracks the prior frontend request used by redirect loop prevention.
 */
class ABJ_404_Solution_PreviousRequestCookieTracker {

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_Logging|null $logging
     */
    function __construct($logging = null) {
        $this->logger = $logging !== null ? $logging : abj_service('logging');
    }

    /** @return string */
    function readCookieWithPreviousRqeuestShort(): string {
        $cookieName = ABJ404_PP . '_REQUEST_URI';
        $cookieNameShort = $cookieName . '_SHORT';

        if (array_key_exists($cookieNameShort, $_COOKIE) &&
                array_key_exists($cookieName, $_COOKIE) &&
                is_scalar($_COOKIE[$cookieName])) {
            return (string)$_COOKIE[$cookieName];
        }

        return '';
    }

    /** @return void */
    function setCookieWithPreviousRequest(): void {
        $requestUri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $requestedUrlRaw = abj_service('sanitizer')->normalizeUrlString($requestUri);
        $requestedUrlCleaned = preg_replace('/\?.*$/', '', $requestedUrlRaw);
        $requestedUrl = is_string($requestedUrlCleaned) ? $requestedUrlCleaned : $requestedUrlRaw;

        $cookieName = ABJ404_PP . '_REQUEST_URI';
        $cookieNameShort = $cookieName . '_SHORT';
        try {
            setcookie($cookieName, $requestedUrl, abj_clock()->now() + (60 * 4), "/");
            setcookie($cookieNameShort, $requestedUrl, abj_clock()->now() + (5), "/");

            if (!isset($_COOKIE[$cookieName . '_UPDATE_URL']) ||
                    empty($_COOKIE[$cookieName . '_UPDATE_URL'])) {
                $updateUrlRaw = abj_service('sanitizer')->normalizeUrlString($requestUri);
                $updateUrlCleaned = preg_replace('/\?.*$/', '', $updateUrlRaw);
                $updateUrl = is_string($updateUrlCleaned) ? $updateUrlCleaned : $updateUrlRaw;
                setcookie($cookieName . '_UPDATE_URL', $updateUrl, abj_clock()->now() + (60 * 4), "/");
            }

        } catch (Exception $e) {
            $this->logger->debugMessage("There was an issue setting a cookie: " . $e->getMessage());
            $expireTime = date("D, d M Y H:i:s T", abj_clock()->now() + (60 * 4));
            $c = "\n" . '<script>document.cookie = "' . $cookieName . '=' .
                esc_js($requestedUrl) .
                '; expires=' . $expireTime . '";</script>' . "\n";
            echo $c;
        }

        abj_service('request_context')->requested_url = $requestedUrl;
    }
}
