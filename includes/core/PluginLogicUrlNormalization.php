<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * URL normalization and multilingual redirect translation helpers.
 * Standalone class extracted from PluginLogicTrait_UrlNormalization.
 */
class ABJ_404_Solution_PluginLogicUrlNormalization {

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var string */
    private $urlHomeDirectory;

    /** @var int */
    private $urlHomeDirectoryLength;

    /**
     * @param ABJ_404_Solution_Functions $f
     * @param string $urlHomeDirectory
     * @param int $urlHomeDirectoryLength
     */
    function __construct($f, $urlHomeDirectory, $urlHomeDirectoryLength) {
        $this->f = $f;
        $this->urlHomeDirectory = $urlHomeDirectory;
        $this->urlHomeDirectoryLength = $urlHomeDirectoryLength;
    }

    /** If a page's URL is /blogName/pageName then this returns /pageName.
     * @param string|null $urlRequest
     * @return string
     */
    function removeHomeDirectory($urlRequest): string {
    	if ($urlRequest === null) {
    		return '';
    	}
    	$f = $this->f;
    	$urlHomeDirectory = $this->urlHomeDirectory;
    	$homeLen = $this->urlHomeDirectoryLength;

    	if ($homeLen === 0) {
    		return $urlRequest;
    	}

    	if ($this->f->substr($urlRequest, 0, $homeLen) == $urlHomeDirectory) {
    		$nextChar = $this->f->substr($urlRequest, $homeLen, 1);
    		if ($nextChar === '/' || $nextChar === '?' || $nextChar === '#' || $nextChar === '') {
    			if ($nextChar === '/' || $nextChar === '') {
    				$urlRequest = $this->f->substr($urlRequest, ($homeLen + 1));
    			} else {
    				$urlRequest = '/' . $this->f->substr($urlRequest, $homeLen);
    			}
    		}
    	}

        return $urlRequest;
    }

    /**
     * Normalize URL to relative path by removing WordPress subdirectory.
     *
     * @param string|null $url Full URL or path
     * @return string Relative path without subdirectory
     */
    function normalizeToRelativePath($url): string {
        if ($url === '') {
            return '/';
        }

        if ($url === null) {
            return '/';
        }
        $url = trim($url);

        if (preg_match('#^https?://#i', $url)) {
            $parsed = parse_url($url);
            if ($parsed === false || !isset($parsed['path'])) {
                return '/';
            }
            $url = $parsed['path'];
            if (!empty($parsed['query'])) {
                $url .= '?' . $parsed['query'];
            }
            if (!empty($parsed['fragment'])) {
                $url .= '#' . $parsed['fragment'];
            }
        }

        if (strpos($url, '//') === 0) {
            $parsed = parse_url('http:' . $url);
            if ($parsed !== false && isset($parsed['path'])) {
                $url = $parsed['path'];
                if (!empty($parsed['query'])) {
                    $url .= '?' . $parsed['query'];
                }
                if (!empty($parsed['fragment'])) {
                    $url .= '#' . $parsed['fragment'];
                }
            } else {
                return '/';
            }
        }

        $relativePath = $this->removeHomeDirectory($url);

        if ($relativePath === '') {
            return '/';
        }

        $relativePathCleaned = preg_replace('#/+#', '/', $relativePath);
        $relativePath = is_string($relativePathCleaned) ? $relativePathCleaned : $relativePath;

        $relativePath = '/' . ltrim($relativePath, '/');

        return $relativePath;
    }

    /**
     * Normalize a user-provided path for storage/matching.
     *
     * @param string|null $url
     * @return string
     */
    function normalizeUserProvidedPath($url) {
        $url = abj_service('sanitizer')->normalizeUrlString($url);
        if ($url === '') {
            return '';
        }

        return $this->normalizeToRelativePath($url);
    }

    /**
     * Normalize an external destination URL for storage.
     *
     * @param string|null $url
     * @return string
     */
    function normalizeExternalDestinationUrl($url) {
        return abj_service('sanitizer')->normalizeUrlString($url);
    }

    /**
     * Generate normalized lookup variants for URL matching.
     *
     * @param string|null $url
     * @return array<int, string>
     */
    function getNormalizedUrlCandidates($url) {
        $decoded = $this->normalizeUserProvidedPath($url);
        if ($decoded === '') {
            return array();
        }

        $candidates = array($decoded);

        $lower = function_exists('mb_strtolower') ? mb_strtolower($decoded, 'UTF-8') : strtolower($decoded);
        if ($lower !== $decoded) {
            $candidates[] = $lower;
        }

        $encoded = $this->normalizeToRelativePath(abj_service('url_encoder')->encodeUrlForLegacyMatch($decoded));
        if ($encoded !== $decoded) {
            $candidates[] = $encoded;
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Translate a redirect destination URL to the current language when possible.
     *
     * @param string $location Full URL or path to redirect to.
     * @param string $requestedURL Original requested path/URL that triggered the 404.
     * @return string URL to use for redirect.
     */
    function maybeTranslateRedirectUrl($location, $requestedURL = '') {
        if (!is_string($location) || $location === '') {
            return $location;
        }

        $translated = $this->translatePressRedirectUrl($location, $requestedURL);
        if ($translated !== null && $translated !== '') {
            $location = $translated;
        }

        if ($translated === null || $translated === '') {
            $translated = $this->wpmlRedirectUrl($location, $requestedURL);
            if ($translated !== null && $translated !== '') {
                $location = $translated;
            }
        }

        if ($translated === null || $translated === '') {
            $translated = $this->polylangRedirectUrl($location, $requestedURL);
            if ($translated !== null && $translated !== '') {
                $location = $translated;
            }
        }

        return apply_filters('abj404_translate_redirect_url', $location, $requestedURL);
    }

    /** @return string|null */
    public function translatePressRedirectUrl(string $location, string $requestedURL) {
        if (!$this->translatePressIntegrationAvailable()) {
            return null;
        }

        if (!$this->isLocalUrl($location)) {
            return null;
        }

        $language = $this->getTranslatePressLanguageFromRequest($requestedURL);
        if ($language === '') {
            return null;
        }

        $translated = $this->translatePressTranslateUrl($location, $language);
        if (!is_string($translated) || $translated === '' || $translated === $location) {
            return null;
        }

        if (!$this->isLocalUrl($translated)) {
            return null;
        }

        return $translated;
    }

    /** @return bool */
    public function translatePressIntegrationAvailable(): bool {
        return function_exists('trp_get_language_from_url') ||
            function_exists('trp_get_current_language') ||
            function_exists('trp_get_url_for_language') ||
            function_exists('trp_translate_url') ||
            has_filter('trp_translate_url');
    }

    /** @return mixed */
    public function translatePressTranslateUrl(string $url, string $language) {
        if (function_exists('trp_get_url_for_language')) {
            return trp_get_url_for_language($language, $url);
        }

        if (function_exists('trp_translate_url')) {
            return trp_translate_url($url, $language);
        }

        return apply_filters('trp_translate_url', $url, $language);
    }

    public function getTranslatePressLanguageFromRequest(string $requestedURL): string {
        $fullRequestedUrl = $this->buildFullUrlFromRequest($requestedURL);

        if (function_exists('trp_get_language_from_url')) {
            $language = trp_get_language_from_url($fullRequestedUrl);
            if (is_string($language) && $language !== '') {
                return $language;
            }
        }

        if (function_exists('trp_get_current_language')) {
            $language = trp_get_current_language();
            if (is_string($language) && $language !== '') {
                return $language;
            }
        }

        return '';
    }

    /** @return string|null */
    private function wpmlRedirectUrl(string $location, string $requestedURL) {
        if (!$this->wpmlIntegrationAvailable()) {
            return null;
        }

        if (!$this->isLocalUrl($location)) {
            return null;
        }

        $language = $this->getWpmlLanguageFromRequest($requestedURL);
        if ($language === '') {
            return null;
        }

        $translated = $this->wpmlTranslateUrl($location, $language);
        if (!is_string($translated) || $translated === '' || $translated === $location) {
            return null;
        }

        if (!$this->isLocalUrl($translated)) {
            return null;
        }

        return $translated;
    }

    private function wpmlIntegrationAvailable(): bool {
        return function_exists('wpml_current_language') ||
            has_filter('wpml_current_language') ||
            has_filter('wpml_language_from_url') ||
            has_filter('wpml_permalink');
    }

    /** @return mixed */
    private function wpmlTranslateUrl(string $url, string $language) {
        if (has_filter('wpml_permalink')) {
            return apply_filters('wpml_permalink', $url, $language);
        }

        return null;
    }

    private function getWpmlLanguageFromRequest(string $requestedURL): string {
        $fullRequestedUrl = $this->buildFullUrlFromRequest($requestedURL);

        if (has_filter('wpml_language_from_url')) {
            $language = apply_filters('wpml_language_from_url', '', $fullRequestedUrl);
            if (is_string($language) && $language !== '') {
                return $language;
            }
        }

        if (function_exists('wpml_current_language')) {
            $language = wpml_current_language();
            if (is_string($language) && $language !== '') {
                return $language;
            }
        }

        if (has_filter('wpml_current_language')) {
            $language = apply_filters('wpml_current_language', null);
            if (is_string($language) && $language !== '') {
                return $language;
            }
        }

        return '';
    }

    /** @return string|null */
    private function polylangRedirectUrl(string $location, string $requestedURL) {
        if (!$this->polylangIntegrationAvailable()) {
            return null;
        }

        if (!$this->isLocalUrl($location)) {
            return null;
        }

        $language = $this->getPolylangLanguageFromRequest($requestedURL);
        if ($language === '') {
            return null;
        }

        $translated = $this->polylangTranslateUrl($location, $language);
        if (!is_string($translated) || $translated === '' || $translated === $location) {
            return null;
        }

        if (!$this->isLocalUrl($translated)) {
            return null;
        }

        return $translated;
    }

    private function polylangIntegrationAvailable(): bool {
        return function_exists('pll_current_language') ||
            function_exists('pll_translate_url');
    }

    /** @return mixed */
    private function polylangTranslateUrl(string $url, string $language) {
        if (function_exists('pll_translate_url')) {
            return pll_translate_url($url, $language);
        }

        return null;
    }

    private function getPolylangLanguageFromRequest(string $requestedURL): string {
        if (function_exists('pll_current_language')) {
            $language = pll_current_language();
            if (is_string($language) && $language !== '') {
                return $language;
            }
        }

        return '';
    }

    public function buildFullUrlFromRequest(string $requestedURL): string {
        $path = $requestedURL;
        if ($path === '') {
            $userRequest = ABJ_404_Solution_UserRequest::getInstance();
            if ($userRequest !== null) {
                $path = $userRequest->getPathWithSortedQueryString();
            }
        }

        if ($path === '') {
            return home_url('/');
        }

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        return home_url($path);
    }

    public function isLocalUrl(string $url): bool {
        if (!is_string($url) || $url === '') {
            return false;
        }

        $parsedUrl = function_exists('wp_parse_url') ? wp_parse_url($url) : parse_url($url);
        if (!is_array($parsedUrl) || !isset($parsedUrl['host'])) {
            return true;
        }

        $siteUrl = home_url();
        $parsedSite = function_exists('wp_parse_url') ? wp_parse_url($siteUrl) : parse_url($siteUrl);
        $siteHost = is_array($parsedSite) && isset($parsedSite['host']) ? strtolower($parsedSite['host']) : '';

        return $siteHost !== '' && strtolower($parsedUrl['host']) === $siteHost;
    }

}
