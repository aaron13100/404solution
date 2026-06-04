<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves the missing URL that powers the frontend suggestions shortcode.
 */
class ABJ_404_Solution_ShortcodeRequestedUrlResolver {

    /**
     * @param ABJ_404_Solution_Functions $functions
     * @return array{url: string, cookieScripts: string}
     */
    public function resolve($functions): array {
        $urlRequest = '';
        $cookieScripts = '';
        $urlEncoder = abj_service('url_encoder');

        $cookieName = ABJ404_PP . '_REQUEST_URI';
        $cookieVal = isset($_COOKIE[$cookieName]) && is_string($_COOKIE[$cookieName]) ? $_COOKIE[$cookieName] : '';
        if ($cookieVal !== '') {
            $urlRequest = $urlEncoder->normalizeURLForCacheKey(abj_service('sanitizer')->normalizeUrlString($cookieVal));
            $cookieScripts .= $this->renderCookieDeleteScript($cookieName, false);
        }

        $updateURLCookieName = ABJ404_PP . '_REQUEST_URI_UPDATE_URL';
        $updateCookieVal = isset($_COOKIE[$updateURLCookieName]) && is_string($_COOKIE[$updateURLCookieName]) ? $_COOKIE[$updateURLCookieName] : '';
        if ($updateCookieVal !== '') {
            if ($urlRequest == '') {
                $urlRequest = $urlEncoder->normalizeURLForCacheKey(abj_service('sanitizer')->normalizeUrlString($updateCookieVal));
            }
            $cookieScripts .= $this->renderCookieDeleteScript($updateURLCookieName, true);
        }

        $requestContext = abj_service('request_context');
        $ctxUrlRaw = is_object($requestContext) && property_exists($requestContext, 'requested_url')
            ? $requestContext->requested_url : '';
        $ctxUrl = is_string($ctxUrlRaw) ? $ctxUrlRaw : '';
        if ($ctxUrl !== '') {
            $urlRequest = $urlEncoder->normalizeURLForCacheKey(abj_service('sanitizer')->normalizeUrlString($ctxUrl));
        }

        $queryParamName = ABJ404_PP . '_ref';
        $getParamVal = isset($_GET[$queryParamName]) && is_string($_GET[$queryParamName]) ? $_GET[$queryParamName] : '';
        if ($urlRequest == '' && $getParamVal !== '') {
            $urlRequest = $urlEncoder->normalizeURLForCacheKey(abj_service('sanitizer')->normalizeUrlString($getParamVal));
        }

        return array('url' => $urlRequest, 'cookieScripts' => $cookieScripts);
    }

    /**
     * @param string $cookieName
     * @param bool $includeDeleteComment
     * @return string
     */
    private function renderCookieDeleteScript(string $cookieName, bool $includeDeleteComment): string {
        $template = ABJ_404_Solution_FileSystemService::readFileContents(
            dirname(__DIR__) . '/html/shortcodeCookieDeleteScript.html',
            false
        );
        return str_replace(
            array('{delete_comment}', '{cookie_name}'),
            array($includeDeleteComment ? ' /* delete the cookie */' : '', $cookieName),
            $template
        ) . "\n";
    }
}
