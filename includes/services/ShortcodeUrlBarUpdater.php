<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Restores the browser URL while rendering suggestions on a custom 404 page.
 */
class ABJ_404_Solution_ShortcodeUrlBarUpdater {

    /**
     * @return void
     */
    public function updateIfNecessary(): void {
        $logging = abj_service('logging');
        $debugMessage = '';
        $options = abj_service('options_repository')->getOptions();

        $requestedURLForRestore = $this->requestedUrlForRestore();
        $shouldUpdateURL = $this->shouldUpdateURL($options, $requestedURLForRestore, $debugMessage);

        if ($shouldUpdateURL) {
            $userFriendlyURL = $this->userFriendlyURL($requestedURLForRestore);
            echo $this->renderUrlReplaceScript($userFriendlyURL);

            $currentReqUri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
            $debugMessage .= "Updating the URL from " . $currentReqUri .
                " to " . esc_url($userFriendlyURL) . ", ";
        }

        $debugMessage .= $this->debugOptionsSummary($options);
        $debugMessage .= "is_single(): " . is_single() . " | " . "is_page(): " . is_page() .
            " | is_feed(): " . is_feed() . " | is_trackback(): " . is_trackback() . " | is_preview(): " .
            is_preview();

        $logging->debugMessage("updateURLbarIfNecessary: " . $debugMessage);
    }

    /**
     * @param array<string, mixed> $options
     * @param string $requestedURLForRestore
     * @param string $debugMessage
     * @return bool
     */
    private function shouldUpdateURL(array $options, string $requestedURLForRestore, string &$debugMessage): bool {
        $shouldUpdateURL = true;
        if (!isset($options['update_suggest_url']) || $options['update_suggest_url'] != 1) {
            $shouldUpdateURL = false;
            $debugMessage .= "do not update (update_suggest_url is off), ";
        }
        if ($requestedURLForRestore === '') {
            $shouldUpdateURL = false;
            $debugMessage .= "do not update (no cookie found), ";
        }
        if (!$this->custom404ContextAllowsURLUpdate($options, $debugMessage)) {
            $shouldUpdateURL = false;
        }
        return $shouldUpdateURL;
    }

    /**
     * @param array<string, mixed> $options
     * @param string $debugMessage
     * @return bool
     */
    private function custom404ContextAllowsURLUpdate(array $options, string &$debugMessage): bool {
        $queryParamName = ABJ404_PP . '_ref';
        if (isset($_GET[$queryParamName]) && !empty($_GET[$queryParamName])) {
            $debugMessage .= "ok to update (manual redirect to custom 404 page), ";
            return true;
        }

        $dest404page = $this->dest404page($options);
        $notFoundResponse = abj_service('not_found_response');
        if (!is_object($notFoundResponse) || !method_exists($notFoundResponse, 'thereIsAUserSpecified404Page') ||
                !$notFoundResponse->thereIsAUserSpecified404Page($dest404page)) {
            $debugMessage .= "do not update (no custom 404 page specified), ";
            return false;
        }

        return $this->currentRequestMatches404Page($dest404page, $options, $debugMessage);
    }

    /**
     * @param string $dest404page
     * @param array<string, mixed> $options
     * @param string $debugMessage
     * @return bool
     */
    private function currentRequestMatches404Page(string $dest404page, array $options, string &$debugMessage): bool {
        $permalink = ABJ_404_Solution_PermalinkResolver::permalinkInfoToArray($dest404page, 0, null, $options);
        $requestUriRaw = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $requestUriPath = parse_url($requestUriRaw, PHP_URL_PATH);
        $permLinkStr = isset($permalink['link']) && is_string($permalink['link']) ? $permalink['link'] : '';
        $requestUriPathStr = is_string($requestUriPath) ? $requestUriPath : '';
        $status = isset($permalink['status']) && is_string($permalink['status']) ? $permalink['status'] : '';
        $functions = abj_service('functions');
        if (!is_object($functions) || !method_exists($functions, 'endsWithCaseSensitive')) {
            $debugMessage .= "do not update (URL helper unavailable), ";
            return false;
        }

        if (!$functions->endsWithCaseSensitive($permLinkStr, $requestUriPathStr) && $status != 'trash') {
            $debugMessage .= "do not update (not on custom 404 page (" . $permLinkStr . ")), ";
            return false;
        }
        $debugMessage .= "ok to update (displaying custom 404 page (" . $permLinkStr . ")), ";
        return true;
    }

    /**
     * @param array<string, mixed> $options
     * @return string
     */
    private function dest404page(array $options): string {
        $fallback = ABJ404_TYPE_404_DISPLAYED . '|' . ABJ404_TYPE_404_DISPLAYED;
        $dest404pageRaw = isset($options['dest404page']) ? $options['dest404page'] : $fallback;
        return is_string($dest404pageRaw) ? $dest404pageRaw : $fallback;
    }

    /**
     * @param string $requestedURLForRestore
     * @return string
     */
    private function userFriendlyURL(string $requestedURLForRestore): string {
        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        return $scheme . '://' . $host . esc_url($requestedURLForRestore);
    }

    /**
     * @return string
     */
    private function requestedUrlForRestore(): string {
        $updateURLCookieName = ABJ404_PP . '_REQUEST_URI_UPDATE_URL';
        $legacyRequestKey = ABJ404_PP . '_REQUEST_URI';
        if (isset($_REQUEST[$updateURLCookieName]) && is_string($_REQUEST[$updateURLCookieName]) &&
                $_REQUEST[$updateURLCookieName] !== '') {
            return $_REQUEST[$updateURLCookieName];
        }
        if (isset($_REQUEST[$legacyRequestKey]) && is_string($_REQUEST[$legacyRequestKey]) &&
                $_REQUEST[$legacyRequestKey] !== '') {
            return $_REQUEST[$legacyRequestKey];
        }
        return '';
    }

    /**
     * @param string $userFriendlyURL
     * @return string
     */
    private function renderUrlReplaceScript(string $userFriendlyURL): string {
        $urlJson = wp_json_encode($userFriendlyURL);
        $template = ABJ_404_Solution_FileSystemService::readFileContents(
            dirname(__DIR__) . '/html/shortcodeUrlReplaceScript.html',
            false
        );
        return str_replace('{url_json}', is_string($urlJson) ? $urlJson : 'null', $template) . "\n";
    }

    /**
     * @param array<string, mixed> $options
     * @return string
     */
    private function debugOptionsSummary(array $options): string {
        $scAutoRedirects = isset($options['auto_redirects']) && is_scalar($options['auto_redirects']) ? (string)$options['auto_redirects'] : '';
        $scAutoScore = isset($options['auto_score']) && is_scalar($options['auto_score']) ? (string)$options['auto_score'] : '';
        $scTemplatePriority = isset($options['template_redirect_priority']) && is_scalar($options['template_redirect_priority']) ? (string)$options['template_redirect_priority'] : '';
        $scAutoCats = isset($options['auto_cats']) && is_scalar($options['auto_cats']) ? (string)$options['auto_cats'] : '';
        $scAutoTags = isset($options['auto_tags']) && is_scalar($options['auto_tags']) ? (string)$options['auto_tags'] : '';
        $scDest404 = isset($options['dest404page']) && is_scalar($options['dest404page']) ? (string)$options['dest404page'] : '';
        return "is404: " . is_404() . ", " .
            esc_html('auto_redirects: ' . $scAutoRedirects .
            ', auto_score: ' . $scAutoScore .
            ', template_redirect_priority: ' . $scTemplatePriority .
            ', auto_cats: ' . $scAutoCats .
            ', auto_tags: ' . $scAutoTags .
            ', dest404page: ' . $scDest404) . ", ";
    }
}
