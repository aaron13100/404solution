<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalizes frontend ignore state and the WordPress normal post query fallback.
 */
class ABJ_404_Solution_RequestIgnoreNormalizer {

    /** @var mixed Object exposing getOptions(). */
    private $optionsProvider;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_RedirectsRepositoryInterface */
    private $redirectsRepo;

    /** @var ABJ_404_Solution_LogsRepositoryInterface */
    private $logsRepo;

    /** @var ABJ_404_Solution_NotFoundResponseService */
    private $notFoundResponse;

    /**
     * @param ABJ_404_Solution_RequestIgnoreNormalizerDependencies|null $deps
     */
    function __construct(?ABJ_404_Solution_RequestIgnoreNormalizerDependencies $deps = null) {
        $deps = $deps ?? new ABJ_404_Solution_RequestIgnoreNormalizerDependencies();
        $this->optionsProvider = $deps->optionsProvider !== null ? $deps->optionsProvider : abj_service('options_repository');
        $this->f = $deps->functions !== null ? $deps->functions : abj_service('functions');
        $this->logger = $deps->logging !== null ? $deps->logging : abj_service('logging');
        $this->redirectsRepo = $deps->redirectsRepo !== null ? $deps->redirectsRepo : abj_service('redirects_repository');
        $this->logsRepo = $deps->logsRepo !== null ? $deps->logsRepo : abj_service('logs_repository');
        $this->notFoundResponse = $deps->notFoundResponse !== null ? $deps->notFoundResponse : abj_service('not_found_response');
    }

    /**
     * @param string $urlRequest the requested URL
     * @param string $urlSlugOnly only the slug
     * @return void
     */
    function initializeIgnoreValues(string $urlRequest, string $urlSlugOnly): void {
        $options = $this->getOptions();
        $httpUserAgent = array_key_exists('HTTP_USER_AGENT', $_SERVER) && is_scalar($_SERVER['HTTP_USER_AGENT']) ?
                $this->f->strtolower((string)$_SERVER['HTTP_USER_AGENT']) : '';

        $ignoreReasonDoNotProcess = $this->getAdminIgnoreReason($urlRequest);
        $ignoreReasonDoNotProcess = $this->getUserAgentDoNotProcessReason(
            $options,
            $httpUserAgent,
            $urlRequest,
            $ignoreReasonDoNotProcess
        );
        $ignoreReasonDoNotProcess = $this->getFolderIgnoreReason($options, $urlSlugOnly, $ignoreReasonDoNotProcess);
        abj_service('request_context')->ignore_donotprocess = is_string($ignoreReasonDoNotProcess) ? $ignoreReasonDoNotProcess : false;

        $ignoreReasonDoProcess = $this->getUserAgentDoProcessReason($options, $httpUserAgent, $urlRequest);
        abj_service('request_context')->ignore_doprocess = is_string($ignoreReasonDoProcess) ? $ignoreReasonDoProcess : false;
    }

    /**
     * Forward to a real page for queries like ?p=10.
     *
     * @param array<string, mixed> $options
     * @return void
     */
    function tryNormalPostQuery(array $options): void {
        global $wp_query;

        $query = is_object($wp_query) && isset($wp_query->query) && is_array($wp_query->query) ? $wp_query->query : array();
        if (!isset($query['p'])) {
            return;
        }
        $pageidRaw = $query['p'];
        if (!is_scalar($pageidRaw)) {
            return;
        }
        $pageid = (int)$pageidRaw;
        if (!empty($pageid)) {
            $rawPermalink = get_permalink($pageid);
            $permalink = abj_service('sanitizer')->normalizeUrlString($rawPermalink !== false ? $rawPermalink : null);
            $status = get_post_status($pageid);
            if (($permalink != false) &&
                    (in_array($status, array('publish', 'published')))) {
                $homeURL = get_home_url();
                if ($homeURL == null) {
                    $homeURL = '';
                }
                $urlHomeDirectory = parse_url($homeURL, PHP_URL_PATH);
                if ($urlHomeDirectory == null) {
                    $urlHomeDirectory = '';
                }
                $urlHomeDirectory = rtrim($urlHomeDirectory, '/');
                $fromURL = $urlHomeDirectory . '/?p=' . $pageid;
                $redirect = $this->redirectsRepo->getExistingRedirectForURL($fromURL);
                $defaultRedirect = is_scalar($options['default_redirect'] ?? null) ? (string)$options['default_redirect'] : '301';
                if (!isset($redirect['id']) || $redirect['id'] == 0) {
                    $this->redirectsRepo->setupRedirect(ABJ_404_Solution_RedirectSpec::create(
                        $fromURL, (string)ABJ404_STATUS_AUTO, (string)ABJ404_TYPE_POST,
                        (string)$pageid, $defaultRedirect, 0, 'page ID'
                    ));
                }
                $this->logsRepo->logRedirectHit(ABJ_404_Solution_RedirectHitLogEntry::create($fromURL, $permalink, 'page ID'));
                $this->notFoundResponse->forceRedirect($permalink, (int)$defaultRedirect);
                exit;
            }
        }
    }

    /** @return array<string, mixed> */
    private function getOptions(): array {
        if (is_object($this->optionsProvider) && method_exists($this->optionsProvider, 'getOptions')) {
            $options = $this->optionsProvider->getOptions();
            return is_array($options) ? $options : array();
        }
        return array();
    }

    /** @return string|null */
    private function getAdminIgnoreReason(string $urlRequest) {
        $adminURLRaw = parse_url(admin_url(), PHP_URL_PATH);
        $adminURL = is_string($adminURLRaw) ? $adminURLRaw : '/wp-admin/';
        if (is_admin() || $this->f->substr($urlRequest, 0, $this->f->strlen($adminURL)) == $adminURL) {
            $this->logger->debugMessage("Ignoring admin URL: " . $urlRequest);
            return 'Admin URL';
        }
        return null;
    }

    /**
     * @param array<string, mixed> $options
     * @param string|null $currentReason
     * @return string|null
     */
    private function getUserAgentDoNotProcessReason(array $options, string $httpUserAgent, string $urlRequest, $currentReason) {
        $ignoreDontProcess = is_string($options['ignore_dontprocess'] ?? null) ? $options['ignore_dontprocess'] : '';
        foreach ($this->f->explodeNewline($ignoreDontProcess) as $agentToIgnore) {
            if (stripos($httpUserAgent, trim($agentToIgnore)) !== false) {
                $ua = $this->currentUserAgent();
                $this->logger->debugMessage("Ignoring user agent (do not redirect): " .
                        esc_html($ua) . " for URL: " . esc_html($urlRequest));
                return 'User agent (do not redirect): ' . esc_html($ua);
            }
        }
        return $currentReason;
    }

    /**
     * @param array<string, mixed> $options
     * @param string|null $currentReason
     * @return string|null
     */
    private function getFolderIgnoreReason(array $options, string $urlSlugOnly, $currentReason) {
        $patternsToIgnore = is_array($options['folders_files_ignore_usable'] ?? null)
            ? $options['folders_files_ignore_usable'] : array();
        foreach ($patternsToIgnore as $patternToIgnore) {
            if (!is_scalar($patternToIgnore)) {
                continue;
            }
            $patternToIgnoreNoSlashes = stripslashes((string)$patternToIgnore);
            abj_service('request_context')->debug_info = 'Applying regex pattern to ignore\"' .
                $patternToIgnoreNoSlashes . '" to URL slug: ' . $urlSlugOnly;
            $matches = array();
            if ($this->f->regexMatch($patternToIgnoreNoSlashes, $urlSlugOnly, $matches)) {
                $this->logger->debugMessage("Ignoring file/folder (do not redirect) for URL: " .
                        esc_html($urlSlugOnly) . ", pattern used: " . $patternToIgnoreNoSlashes);
                $currentReason = 'Files and folders (do not redirect) pattern: ' .
                    esc_html($patternToIgnoreNoSlashes);
            }
            abj_service('request_context')->debug_info = 'Cleared after regex pattern to ignore.';
        }
        return $currentReason;
    }

    /**
     * @param array<string, mixed> $options
     * @return string|null
     */
    private function getUserAgentDoProcessReason(array $options, string $httpUserAgent, string $urlRequest) {
        $ignoreDoProcess = is_string($options['ignore_doprocess'] ?? null) ? $options['ignore_doprocess'] : '';
        foreach ($this->f->explodeNewline($ignoreDoProcess) as $agentToIgnore) {
            if (stripos($httpUserAgent, trim($agentToIgnore)) !== false) {
                $ua = $this->currentUserAgent();
                $this->logger->debugMessage("Ignoring user agent (process ok): " .
                        esc_html($ua) . " for URL: " . esc_html($urlRequest));
                return 'User agent (process ok): ' . $agentToIgnore;
            }
        }
        return null;
    }

    private function currentUserAgent(): string {
        return array_key_exists('HTTP_USER_AGENT', $_SERVER) && is_scalar($_SERVER['HTTP_USER_AGENT'])
            ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
    }
}
