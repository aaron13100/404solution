<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Dispatches unresolved frontend requests to the configured 404 response path.
 */
class ABJ_404_Solution_NotFoundResponseService {

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_RedirectsRepositoryInterface */
    private $redirectsRepo;

    /** @var ABJ_404_Solution_LogsRepositoryInterface */
    private $logsRepo;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_PluginLogicOptionsResolver */
    private $optionsRepository;

    /** @var ABJ_404_Solution_PreviousRequestCookieTracker */
    private $previousRequestCookieTracker;

    /**
     * @param ABJ_404_Solution_NotFoundResponseDependencies|null $deps
     */
    function __construct(?ABJ_404_Solution_NotFoundResponseDependencies $deps = null) {
        $deps = $deps ?? new ABJ_404_Solution_NotFoundResponseDependencies();
        $this->f = $deps->functions !== null ? $deps->functions : abj_service('functions');
        $this->redirectsRepo = $deps->redirectsRepo !== null ? $deps->redirectsRepo : abj_service('redirects_repository');
        $this->logsRepo = $deps->logsRepo !== null ? $deps->logsRepo : abj_service('logs_repository');
        $this->logger = $deps->logging !== null ? $deps->logging : abj_service('logging');
        $this->optionsRepository = $deps->optionsRepository !== null ? $deps->optionsRepository : abj_service('options_repository');
        $this->previousRequestCookieTracker = $deps->previousRequestCookieTracker !== null
            ? $deps->previousRequestCookieTracker
            : abj_service('previous_request_cookie_tracker');
    }

    /**
     * @param string $requestedURL
     * @param string $reason
     * @param bool $useUserSpecified404
     * @param array<string, mixed>|null $optionsOverride
     * @return void
     */
    function sendTo404Page(string $requestedURL, string $reason = '', bool $useUserSpecified404 = true, $optionsOverride = null): void {
        $options = (is_array($optionsOverride) ? $optionsOverride : $this->optionsRepository->getOptions());

        $behavior = isset($options['dest404_behavior']) ? $options['dest404_behavior'] : '';
        if ($behavior === 'suggest') {
            $systemPage = ABJ_404_Solution_SystemPage::getInstance();
            if (!$systemPage->systemPageExists()) {
                $systemPage->handleSystemPageDeleted();
                $options = $this->optionsRepository->getOptions(true);
            }
        }

        $dest404pageRaw = isset($options['dest404page']) ? $options['dest404page'] : null;
        $dest404page = is_string($dest404pageRaw) ? $dest404pageRaw : (ABJ404_TYPE_404_DISPLAYED . '|' . ABJ404_TYPE_404_DISPLAYED);

        if ($useUserSpecified404 && $this->thereIsAUserSpecified404Page($dest404page)) {
            $permalink = ABJ_404_Solution_PermalinkResolver::permalinkInfoToArray($dest404page, 0, null, $options);

            if (!in_array($permalink['status'], array('publish', 'published'))) {
                $msg = __("The user specified 404 page wasn't found. " .
                        "Please update the user-specified 404 page on the Options page.",
                        '404-solution');
                $this->logger->infoMessage($msg);

            } else {
                $redirect = $this->redirectsRepo->getExistingRedirectForURL($requestedURL);
                $pType = is_scalar($permalink['type']) ? (string)$permalink['type'] : '';
                $pId = is_scalar($permalink['id']) ? (string)$permalink['id'] : '';
                $pLink = is_scalar($permalink['link']) ? (string)$permalink['link'] : '';
                $defRedir = is_scalar($options['default_redirect']) ? (string)$options['default_redirect'] : '301';
                if (!isset($redirect['id']) || $redirect['id'] == 0) {
                    $this->redirectsRepo->setupRedirect(ABJ_404_Solution_RedirectSpec::create(
                        $requestedURL, (string)ABJ404_STATUS_CAPTURED, $pType, $pId, $defRedir, 0
                    ));
                }

                $this->logsRepo->logRedirectHit(ABJ_404_Solution_RedirectHitLogEntry::create($requestedURL, $pLink, 'user specified 404 page. ' . $reason));

                setcookie(ABJ404_PP . '_STATUS_404', 'true', abj_clock()->now() + 20, "/");

                $this->forceRedirect(esc_url($pLink), (int)$defRedir);
                exit;
            }
        }

        if (@$options['capture_404'] == '1') {
            $redirect = $this->redirectsRepo->getExistingRedirectForURL($requestedURL);
            $defRedir2 = is_scalar($options['default_redirect']) ? (string)$options['default_redirect'] : '301';
            if (!isset($redirect['id']) || $redirect['id'] == 0) {
                $this->redirectsRepo->setupRedirect(ABJ_404_Solution_RedirectSpec::create(
                    $requestedURL, (string)ABJ404_STATUS_CAPTURED, (string)ABJ404_TYPE_404_DISPLAYED, (string)ABJ404_TYPE_404_DISPLAYED, $defRedir2, 0
                ));
            }
        } else {
            $optionsJson = json_encode($options);
            $this->logger->debugMessage("No permalink found to redirect to. capture_404 is off. Requested URL: " . $requestedURL .
                    " | Redirect: (none)" . " | is_single(): " . is_single() . " | " .
                    "is_page(): " . is_page() . " | is_feed(): " . is_feed() . " | is_trackback(): " .
                    is_trackback() . " | is_preview(): " . is_preview() . " | options: " . wp_kses_post(is_string($optionsJson) ? $optionsJson : ''));
        }
    }

    /** @param string|null $dest404page @return bool */
    function thereIsAUserSpecified404Page($dest404page): bool {
        if ($dest404page == null) {
            return false;
        }
        $check1 = ($dest404page !== (ABJ404_TYPE_404_DISPLAYED . '|' . ABJ404_TYPE_404_DISPLAYED));
        $check2 = ($dest404page !== (string)ABJ404_TYPE_404_DISPLAYED);
        return $check1 && $check2;
    }

    /** @return string */
    function getCommentPartAndQueryPartOfRequest() {
        $requestUri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if ($requestUri !== '' &&
                strpos($requestUri, '?') === false &&
                strpos($requestUri, '/comment-page-') === false) {
            return '';
        }

        $userRequest = ABJ_404_Solution_UserRequest::getInstance();
        if ($userRequest === null) {
            return '';
        }
        $queryString = $userRequest->getQueryString();
        $queryParts = abj_service('query_string_helper')->removePageIDFromQueryString(is_string($queryString) ? $queryString : '');
        $queryParts = ($queryParts == '') ? '' : '?' . $queryParts;
        $commentPart = $userRequest->getCommentPagePart();
        return (is_string($commentPart) ? $commentPart : '') . $queryParts;
    }

    /**
     * @param string $location
     * @param int $status
     * @param int|string $type only 0 for sending to a 404 page
     * @param string $requestedURL
     * @param bool $isCustom404
     * @return bool true if the user is sent to the default 404 page.
     */
    function forceRedirect(string $location, int $status = 302, $type = -1, string $requestedURL = '', bool $isCustom404 = false): bool {
        if ($status === 410) {
            status_header(410);
            $templatePath = dirname(__DIR__) . '/html/gone410.html';
            if (file_exists($templatePath)) {
                $siteName = function_exists('get_bloginfo') ? get_bloginfo('name') : '';
                $siteUrl  = function_exists('home_url') ? home_url('/') : '/';
                $templateContent = file_get_contents($templatePath);
                if (is_string($templateContent)) {
                    $templateContent = str_replace(
                        array('{site_name}', '{site_url}', '{heading}', '{message}', '{back_home}'),
                        array(
                            esc_html($siteName),
                            esc_url($siteUrl),
                            esc_html__('This content has been permanently removed.', '404-solution'),
                            esc_html__('The page you requested no longer exists and has not been moved to a new location.', '404-solution'),
                            esc_html__('Back to home page', '404-solution'),
                        ),
                        $templateContent
                    );
                    echo $templateContent;
                }
            }
            exit;
        }

        if ($status === 451) {
            status_header(451);
            $templatePath = dirname(__DIR__) . '/html/gone451.html';
            if (file_exists($templatePath)) {
                $siteName = function_exists('get_bloginfo') ? get_bloginfo('name') : '';
                $siteUrl  = function_exists('home_url') ? home_url('/') : '/';
                $templateContent = file_get_contents($templatePath);
                if (is_string($templateContent)) {
                    $templateContent = str_replace(
                        array('{site_name}', '{site_url}', '{heading}', '{message}', '{back_home}'),
                        array(
                            esc_html($siteName),
                            esc_url($siteUrl),
                            esc_html__('451 Unavailable For Legal Reasons', '404-solution'),
                            esc_html__('This content is unavailable due to a legal demand.', '404-solution'),
                            esc_html__('Back to home page', '404-solution'),
                        ),
                        $templateContent
                    );
                    echo $templateContent;
                }
            }
            exit;
        }

        if ($status === 0 && $location !== '') {
            status_header(200);
            $templatePath = dirname(__DIR__) . '/html/metaRefresh.html';
            if (file_exists($templatePath)) {
                $templateContent = file_get_contents($templatePath);
                if (is_string($templateContent)) {
                    $templateContent = str_replace(
                        array('{url}', '{delay}', '{title}', '{message}'),
                        array(
                            esc_url($location),
                            '0',
                            esc_html__('Redirecting...', '404-solution'),
                            esc_html__('You are being redirected. Click the link if not redirected automatically.', '404-solution'),
                        ),
                        $templateContent
                    );
                    echo $templateContent;
                }
            }
            exit;
        }

        $pluginLogic = abj_service('plugin_logic');
        if (is_object($pluginLogic) && method_exists($pluginLogic, 'pageOrdering')) {
            $finalDestination = $pluginLogic->pageOrdering()
                ->buildFinalRedirectDestination($location, $requestedURL, $isCustom404);
        } else {
            $finalDestination = (string)$location . $this->getCommentPartAndQueryPartOfRequest();
        }

        $previousRequest = is_object($this->previousRequestCookieTracker)
            ? $this->previousRequestCookieTracker->readCookieWithPreviousRqeuestShort()
            : '';
        $schemePos = $this->f->strpos($finalDestination, '://');
        $finalDestNoHome = ($schemePos !== false)
            ? $this->f->substr($finalDestination, $schemePos + 3) : $finalDestination;
        $slashPos = $this->f->strpos($finalDestNoHome, '/');
        $finalDestNoHome = ($slashPos !== false)
            ? $this->f->substr($finalDestNoHome, $slashPos) : '/';

        $schemePos2 = $this->f->strpos($location, '://');
        $locationNoHome = ($schemePos2 !== false)
            ? $this->f->substr($location, $schemePos2 + 3) : $location;
        $slashPos2 = $this->f->strpos($locationNoHome, '/');
        $locationNoHome = ($slashPos2 !== false)
            ? $this->f->substr($locationNoHome, $slashPos2) : '/';
        if (!empty($previousRequest)) {
            if ($previousRequest == $finalDestNoHome && $previousRequest != $locationNoHome) {
                $this->logger->infoMessage("Maybe avoided infite redirects to/from: " . $previousRequest);
                $finalDestination = $location;

            } else if ($previousRequest == $finalDestination) {
                $this->logger->infoMessage("Avoided infite redirects to/from: " . $previousRequest);
                return false;
            }
        }

        if ($type == ABJ404_TYPE_404_DISPLAYED) {
            $this->sendTo404Page($requestedURL, '', false);
            return true;
        }

        if (is_object($this->previousRequestCookieTracker)) {
            $this->previousRequestCookieTracker->setCookieWithPreviousRequest();
        }
        if (!headers_sent()) {
            if (function_exists('abj404_benchmark_emit_headers')) {
                try {
                    abj404_benchmark_emit_headers();
                } catch (Throwable $e) {
                    $this->warnBenchmarkHeaderFailure($e);
                }
            }
            $useSafe = false;
            if (function_exists('wp_safe_redirect')) {
                $destHost = parse_url($finalDestination, PHP_URL_HOST);
                if ($destHost === null || $destHost === false || $destHost === '') {
                    $useSafe = true;
                } else {
                    $homeHost = parse_url(home_url(), PHP_URL_HOST);
                    if (is_string($homeHost) && $homeHost !== '' && strtolower($homeHost) === strtolower($destHost)) {
                        $useSafe = true;
                    }
                }
            }

            if ($useSafe) {
                wp_safe_redirect($finalDestination, $status, ABJ404_NAME);
            } else {
                wp_redirect($finalDestination, $status, ABJ404_NAME);
            }
            if (!apply_filters('abj404_should_exit', true, array('source' => 'forceRedirect_header'))) {
                return false;
            }
            exit;
        }

        if (function_exists('abj404_benchmark_emit_headers')) {
            abj404_benchmark_emit_headers();
        }
        $template = ABJ_404_Solution_FileSystemService::readFileContents(
            dirname(__DIR__) . '/html/notFoundRedirectFallback.html',
            false
        );
        echo $this->f->str_replace(
            array('{destination_json}', '{destination_url}', '{destination_label}'),
            array((string)wp_json_encode($finalDestination), esc_url($finalDestination), esc_html($finalDestination)),
            $template
        );
        if (!apply_filters('abj404_should_exit', true, array('source' => 'forceRedirect_js'))) {
            return false;
        }
        exit;
    }

    private function warnBenchmarkHeaderFailure(Throwable $e): void {
        $message = 'abj404_benchmark_emit_headers failed: ' . $e->getMessage();
        if (is_object($this->logger) && method_exists($this->logger, 'warn')) {
            $this->logger->warn($message);
            return;
        }

        abj404_logPhpFallback('service-resolution-fallback', $message);
    }

}
