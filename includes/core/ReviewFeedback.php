<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Review request and user feedback flow.
 *
 * Manages the multi-step review solicitation: qualification question,
 * review link for satisfied users, feedback form for unsatisfied users,
 * and permanent dismissal after any terminal action.
 */
class ABJ_404_Solution_ReviewFeedback {

    private const INITIAL_DELAY_DAYS = 30;
    private const ASK_LATER_DELAY_DAYS = 7;
    private const CLOSE_X_SNOOZE_DAYS = 14;

    /** Set to true by handleResponseRedirects() when feedback POST is processed. */
    private static bool $feedbackSubmitted = false;

    /** @var ABJ_404_Solution_ReviewStateRepository|null Lazily-created persistence layer. */
    private static ?ABJ_404_Solution_ReviewStateRepository $stateRepository = null;

    /** Reset static state between tests. */
    public static function resetForTests(): void {
        self::$feedbackSubmitted = false;
        self::$stateRepository = null;
    }

    /**
     * Review-state persistence layer (user meta + feedback option store).
     *
     * The repository is stateless, so a single lazily-created instance is reused
     * across this request. resetForTests() clears it for isolation.
     *
     * @return ABJ_404_Solution_ReviewStateRepository
     */
    private static function stateRepository(): ABJ_404_Solution_ReviewStateRepository {
        if (self::$stateRepository === null) {
            self::$stateRepository = new ABJ_404_Solution_ReviewStateRepository();
        }
        return self::$stateRepository;
    }

    /**
     * Display an admin dashboard notification (captured-404 count + review request).
     *
     * @return void
     */
    static function echoDashboardNotification() {
        $connector = abj_service('wordpress_connector');

        if (!is_admin() || !abj_service('admin_access_policy')->isPluginAdmin()) {
            $connector->getLogger()->logUserCapabilities("echoDashboardNotification");
            return;
        }

        ABJ_404_Solution_AdminRuntimeErrorNotice::echoAdminRuntimeErrorNotice();

        global $pagenow;
        global $abj404view;

        $isPluginPage = array_key_exists('page', $_GET) && $_GET['page'] == ABJ404_PP;
        $isDashboard  = $pagenow == 'index.php' && !isset($_GET['page']);

        if ($isPluginPage) {
            $dbNotice = get_transient('abj404_plugin_db_notice');
            if (is_array($dbNotice)) {
                $type = isset($dbNotice['type']) && is_string($dbNotice['type']) ? $dbNotice['type'] : 'warning';
                // Per owner directive: collation issues must NEVER surface as user notices.
                if ($type === 'collation') {
                    // intentionally do not render
                } else {
                    $message = isset($dbNotice['message']) && is_string($dbNotice['message']) ? $dbNotice['message'] : '';
                    if ($message !== '') {
                        $warningTypes = array(
                            'stale_permalink_cache',
                            'warning',
                            'read_only',
                            'disk_full',
                            'query_quota',
                        );
                        $cssClass = in_array($type, $warningTypes, true) ? 'notice-warning' : 'notice-error';
                        $html = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/notice.html");
                        $f = abj_service('functions');
                        $html = $f->str_replace('{class}', esc_attr('notice ' . $cssClass), $html);
                        $html = $f->str_replace('{message}', esc_html($message), $html);
                        echo $html;
                    }
                }
            }
        }

        if ($isPluginPage || $isDashboard) {
            $captured404Count = $connector->getCapturedCountForNotification();
            if ($connector->getPluginLogic()->pageOrdering()->shouldNotifyAboutCaptured404s($captured404Count)) {
                $msg = $abj404view->getDashboardNotificationCaptured($captured404Count);
                echo $msg;
            }

            self::maybeShowReviewRequest();
        }
    }

    /**
     * Handle review GET redirects and feedback POST submission on admin_init.
     *
     * @return void
     */
    static function handleResponseRedirects() {
        if (!is_admin()) {
            return;
        }
        if (!isset($_GET['page']) || $_GET['page'] !== ABJ404_PP) {
            return;
        }

        if (isset($_GET['abj404_review_response'])) {
            self::handleReviewQualificationResponse();
            return;
        }

        // An invalid leaving-review nonce falls through to the feedback check,
        // matching the original control flow.
        if (isset($_GET['abj404_leaving_review']) && self::handleLeavingReviewClick()) {
            return;
        }

        self::handleFeedbackSubmission();
    }

    /**
     * Decode and apply the review qualification response (yes / not_yet /
     * ask_later / close_x / never), then redirect. Terminates this request leg.
     *
     * @return void
     */
    private static function handleReviewQualificationResponse(): void {
        $rawResponseNonce = isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '';
        $responseNonce = sanitize_text_field(ABJ_404_Solution_RequestInputNormalizer::normalizeScalar($rawResponseNonce));
        if ($responseNonce === '' || !wp_verify_nonce($responseNonce, 'abj404_review_response')) {
            return;
        }

        $response = sanitize_text_field(ABJ_404_Solution_RequestInputNormalizer::normalizeScalar($_GET['abj404_review_response']));
        $allowedResponses = array('yes', 'not_yet', 'ask_later', 'close_x', 'never');
        if (!in_array($response, $allowedResponses, true)) {
            return;
        }

        self::applyQualificationResponse($response);

        wp_safe_redirect(remove_query_arg(array('abj404_review_response', '_wpnonce')));
        exit;
    }

    /**
     * Persist the state transition implied by a validated qualification
     * response. (Persistence is delegated to the review-state repository.)
     *
     * @param string $response one of yes|not_yet|ask_later|close_x|never
     * @return void
     */
    private static function applyQualificationResponse(string $response): void {
        $repo = self::stateRepository();
        switch ($response) {
            case 'yes':
                $repo->advanceToReviewLinkStep();
                break;
            case 'not_yet':
                $repo->advanceToFeedbackStep();
                break;
            case 'ask_later':
                $repo->snoozeReminderUntil(abj_clock()->now() + (self::ASK_LATER_DELAY_DAYS * 86400));
                break;
            case 'close_x':
                $repo->snoozeReminderUntil(abj_clock()->now() + (self::CLOSE_X_SNOOZE_DAYS * 86400));
                break;
            case 'never':
                $repo->dismissPermanently();
                break;
        }
    }

    /**
     * Decode the leaving-review click; on a valid nonce permanently dismiss the
     * request, render the review-redirect script, and redirect (terminating the
     * request). Returns false on an invalid nonce so the caller falls through to
     * the feedback check, preserving the original control flow.
     *
     * @return bool true when the click was handled, false to fall through
     */
    private static function handleLeavingReviewClick(): bool {
        $rawLeavingReviewNonce = isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '';
        $leavingReviewNonce = sanitize_text_field(ABJ_404_Solution_RequestInputNormalizer::normalizeScalar($rawLeavingReviewNonce));
        if ($leavingReviewNonce === '' || !wp_verify_nonce($leavingReviewNonce, 'abj404_leaving_review')) {
            return false;
        }

        self::stateRepository()->dismissPermanently();
        self::echoReviewRedirectScript();
        wp_safe_redirect(remove_query_arg(array('abj404_leaving_review', '_wpnonce')));
        exit;
    }

    /**
     * Decode and persist a feedback-form submission (valid nonce only), email
     * it to the maintainers, and permanently dismiss the review request.
     *
     * @return void
     */
    private static function handleFeedbackSubmission(): void {
        $rawFeedbackNonce = isset($_POST['abj404_feedback_nonce']) ? $_POST['abj404_feedback_nonce'] : '';
        $feedbackNonce = sanitize_text_field(ABJ_404_Solution_RequestInputNormalizer::normalizeScalar($rawFeedbackNonce));
        if (!isset($_POST['abj404_submit_feedback']) ||
            $feedbackNonce === '' ||
            !wp_verify_nonce($feedbackNonce, 'abj404_submit_feedback')) {
            return;
        }

        $issuesRaw = isset($_POST['feedback_issues']) ? $_POST['feedback_issues'] : array();
        $issues = ABJ_404_Solution_RequestInputNormalizer::sanitizeFeedbackIssues($issuesRaw);

        $feedbackDetailsRaw = isset($_POST['feedback_details']) ? $_POST['feedback_details'] : '';
        $feedback_details = sanitize_textarea_field(ABJ_404_Solution_RequestInputNormalizer::normalizeScalar($feedbackDetailsRaw));

        $feedback_data = array(
            'timestamp' => abj_clock()->wpNowMysql(),
            'user_id' => get_current_user_id(),
            'site_url' => get_site_url(),
            'issues' => $issues,
            'details' => $feedback_details,
            'wp_version' => get_bloginfo('version'),
            'plugin_version' => ABJ404_VERSION,
            'php_version' => PHP_VERSION
        );

        self::stateRepository()->appendFeedbackEntry($feedback_data);
        self::emailFeedback($feedback_data);
        self::stateRepository()->dismissPermanently();

        self::$feedbackSubmitted = true;
    }

    /**
     * Render the client-side script that redirects to the wordpress.org review
     * page. The markup lives in an external template; this only fills it in.
     *
     * @return void
     */
    private static function echoReviewRedirectScript(): void {
        $html = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/reviewRedirectScript.html");
        $f = abj_service('functions');
        $html = $f->str_replace('{review_url}', esc_js('https://wordpress.org/support/plugin/404-solution/reviews/#new-post'), $html);
        echo $html;
    }

    /**
     * Display a review request notification after sustained plugin use.
     *
     * @return void
     */
    private static function maybeShowReviewRequest() {
        if (!isset($_GET['page']) || $_GET['page'] !== ABJ404_PP) {
            return;
        }

        if (self::$feedbackSubmitted) {
            $html = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/feedbackSuccessNotice.html");
            echo $html;
            return;
        }

        $repo = self::stateRepository();

        if ($repo->isPermanentlyDismissed()) {
            return;
        }

        $remindLaterUntil = $repo->getReminderTimestamp();
        if ($remindLaterUntil > 0 && abj_clock()->now() < $remindLaterUntil) {
            return;
        }

        $installedTime = $repo->getInstalledTime();
        if ($installedTime <= 0) {
            $repo->setInstalledTime(abj_clock()->now());
            return;
        }

        $days_installed = (abj_clock()->now() - $installedTime) / 86400;
        if ($days_installed < self::INITIAL_DELAY_DAYS) {
            return;
        }

        $review_step = $repo->getCurrentStep();

        if ($review_step === ABJ_404_Solution_ReviewStateRepository::STEP_REVIEW_LINK) {
            self::showReviewLinkNotice();
        } elseif ($review_step === ABJ_404_Solution_ReviewStateRepository::STEP_FEEDBACK) {
            self::showFeedbackFormNotice();
        } else {
            self::showQualificationQuestion();
        }
    }

    /**
     * @param array<string, mixed> $feedback_data
     * @return void
     */
    private static function emailFeedback($feedback_data) {
        $to = '404solution@ajexperience.com';
        $subject = '404 Solution Feedback from ' . get_bloginfo('name');

        $message = "New feedback received from 404 Solution plugin\n\n";
        $message .= "Site: " . $feedback_data['site_url'] . "\n";
        $message .= "Date: " . $feedback_data['timestamp'] . "\n";
        $message .= "WordPress Version: " . $feedback_data['wp_version'] . "\n";
        $message .= "Plugin Version: " . $feedback_data['plugin_version'] . "\n";
        $message .= "PHP Version: " . $feedback_data['php_version'] . "\n\n";

        $message .= "Issues Selected:\n";
        $feedbackIssues = isset($feedback_data['issues']) && is_array($feedback_data['issues']) ? $feedback_data['issues'] : array();
        if (!empty($feedbackIssues)) {
            foreach ($feedbackIssues as $issue) {
                $issueStr = is_string($issue) ? $issue : (string)$issue;
                $message .= "  - " . ucfirst(str_replace('_', ' ', $issueStr)) . "\n";
            }
        } else {
            $message .= "  None selected\n";
        }

        $message .= "\nAdditional Details:\n";
        $message .= $feedback_data['details'] ? $feedback_data['details'] : "(No additional details provided)\n";

        $headers = array('Content-Type: text/plain; charset=UTF-8');

        wp_mail($to, $subject, $message, $headers);
    }

    /** @return void */
    private static function showQualificationQuestion() {
        $yes_url = wp_nonce_url(
            add_query_arg('abj404_review_response', 'yes'),
            'abj404_review_response'
        );
        $not_yet_url = wp_nonce_url(
            add_query_arg('abj404_review_response', 'not_yet'),
            'abj404_review_response'
        );
        $ask_later_url = wp_nonce_url(
            add_query_arg('abj404_review_response', 'ask_later'),
            'abj404_review_response'
        );
        $never_url = wp_nonce_url(
            add_query_arg('abj404_review_response', 'never'),
            'abj404_review_response'
        );
        $close_url = wp_nonce_url(
            add_query_arg('abj404_review_response', 'close_x'),
            'abj404_review_response'
        );

        $html = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/reviewQualificationQuestion.html");
        $f = abj_service('functions');
        $html = $f->str_replace('{yes_url}', esc_attr($yes_url), $html);
        $html = $f->str_replace('{not_yet_url}', esc_attr($not_yet_url), $html);
        $html = $f->str_replace('{ask_later_url}', esc_attr($ask_later_url), $html);
        $html = $f->str_replace('{never_url}', esc_attr($never_url), $html);
        $html = $f->str_replace('{close_url}', esc_attr($close_url), $html);
        echo $html;
    }

    /** @return void */
    private static function showReviewLinkNotice() {
        $review_link_url = wp_nonce_url(
            add_query_arg('abj404_leaving_review', '1'),
            'abj404_leaving_review'
        );

        $never_url = wp_nonce_url(
            add_query_arg('abj404_review_response', 'never'),
            'abj404_review_response'
        );
        $close_url = wp_nonce_url(
            add_query_arg('abj404_review_response', 'close_x'),
            'abj404_review_response'
        );

        $html = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/reviewLinkNotice.html");
        $f = abj_service('functions');
        $html = $f->str_replace('{review_link_url}', esc_attr($review_link_url), $html);
        $html = $f->str_replace('{never_url}', esc_attr($never_url), $html);
        $html = $f->str_replace('{close_url}', esc_attr($close_url), $html);
        echo $html;
    }

    /** @return void */
    private static function showFeedbackFormNotice() {
        $never_url = wp_nonce_url(
            add_query_arg('abj404_review_response', 'never'),
            'abj404_review_response'
        );
        $close_url = wp_nonce_url(
            add_query_arg('abj404_review_response', 'close_x'),
            'abj404_review_response'
        );

        ob_start();
        wp_nonce_field('abj404_submit_feedback', 'abj404_feedback_nonce');
        $nonce_field = ob_get_clean();
        if ($nonce_field === false) { $nonce_field = ''; }

        $html = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/feedbackFormNotice.html");
        $f = abj_service('functions');
        $html = $f->str_replace('{nonce_field}', $nonce_field, $html);
        $html = $f->str_replace('{never_url}', esc_attr($never_url), $html);
        $html = $f->str_replace('{close_url}', esc_attr($close_url), $html);
        echo $html;
    }
}
