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

    /** Reset static state between tests. */
    public static function resetForTests(): void {
        self::$feedbackSubmitted = false;
    }

    /**
     * Display an admin dashboard notification (captured-404 count + review request).
     *
     * @return void
     */
    static function echoDashboardNotification() {
        $connector = abj_service('wordpress_connector');

        if (!is_admin() || !$connector->getPluginLogic()->userIsPluginAdmin()) {
            $connector->getLogger()->logUserCapabilities("echoDashboardNotification");
            return;
        }

        ABJ_404_Solution_WordPress_Connector::echoAdminRuntimeErrorNotice();

        global $pagenow;
        global $abj404view;

        $isPluginPage = array_key_exists('page', $_GET) && $_GET['page'] == ABJ404_PP;
        $isDashboard  = $pagenow == 'index.php' && !isset($_GET['page']);

        if ($isPluginPage) {
            $dbNotice = get_transient('abj404_plugin_db_notice');
            if (is_array($dbNotice) && isset($dbNotice['message']) && is_string($dbNotice['message'])) {
                $type = isset($dbNotice['type']) && is_string($dbNotice['type']) ? $dbNotice['type'] : 'warning';
                // Per owner directive: collation issues must NEVER surface as user notices.
                if ($type === 'collation') {
                    // intentionally do not render
                } else {
                    $warningTypes = array(
                        'stale_permalink_cache',
                        'warning',
                        'read_only',
                        'disk_full',
                        'query_quota',
                    );
                    $cssClass = in_array($type, $warningTypes, true) ? 'notice-warning' : 'notice-error';
                    echo '<div class="notice ' . esc_attr($cssClass) . '"><p>' .
                        esc_html($dbNotice['message']) . '</p></div>';
                }
            }
        }

        if ($isPluginPage || $isDashboard) {
            $captured404Count = $connector->getCapturedCountForNotification();
            if ($connector->getPluginLogic()->shouldNotifyAboutCaptured404s($captured404Count)) {
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
            $rawResponseNonce = isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '';
            $responseNonce = sanitize_text_field(ABJ_404_Solution_WordPress_Connector::normalizeRequestScalar($rawResponseNonce));
            if ($responseNonce === '' || !wp_verify_nonce($responseNonce, 'abj404_review_response')) {
                return;
            }

            $response = sanitize_text_field(ABJ_404_Solution_WordPress_Connector::normalizeRequestScalar($_GET['abj404_review_response']));
            $allowedResponses = array('yes', 'not_yet', 'ask_later', 'close_x', 'never');
            if (!in_array($response, $allowedResponses, true)) {
                return;
            }

            if ($response === 'yes') {
                update_user_meta(get_current_user_id(), 'abj404_review_step', 'show_review_link');
                delete_user_meta(get_current_user_id(), 'abj404_review_remind_later');
            } elseif ($response === 'not_yet') {
                update_user_meta(get_current_user_id(), 'abj404_review_step', 'show_feedback');
                delete_user_meta(get_current_user_id(), 'abj404_review_remind_later');
            } elseif ($response === 'ask_later') {
                update_user_meta(get_current_user_id(), 'abj404_review_remind_later', time() + (self::ASK_LATER_DELAY_DAYS * 86400));
                delete_user_meta(get_current_user_id(), 'abj404_review_step');
            } elseif ($response === 'close_x') {
                update_user_meta(get_current_user_id(), 'abj404_review_remind_later', time() + (self::CLOSE_X_SNOOZE_DAYS * 86400));
                delete_user_meta(get_current_user_id(), 'abj404_review_step');
            } elseif ($response === 'never') {
                update_user_meta(get_current_user_id(), 'abj404_review_dismissed', 'permanent');
                delete_user_meta(get_current_user_id(), 'abj404_review_step');
                delete_user_meta(get_current_user_id(), 'abj404_review_remind_later');
            }

            wp_safe_redirect(remove_query_arg(array('abj404_review_response', '_wpnonce')));
            exit;
        }

        if (isset($_GET['abj404_leaving_review'])) {
            $rawLeavingReviewNonce = isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '';
            $leavingReviewNonce = sanitize_text_field(ABJ_404_Solution_WordPress_Connector::normalizeRequestScalar($rawLeavingReviewNonce));
            if ($leavingReviewNonce !== '' && wp_verify_nonce($leavingReviewNonce, 'abj404_leaving_review')) {
                update_user_meta(get_current_user_id(), 'abj404_review_dismissed', 'permanent');
                delete_user_meta(get_current_user_id(), 'abj404_review_step');
                delete_user_meta(get_current_user_id(), 'abj404_review_remind_later');

                $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/reviewRedirectScript.html");
                $f = abj_service('functions');
                $html = $f->str_replace('{review_url}', esc_js('https://wordpress.org/support/plugin/404-solution/reviews/#new-post'), $html);
                echo $html;
                wp_safe_redirect(remove_query_arg(array('abj404_leaving_review', '_wpnonce')));
                exit;
            }
        }

        $rawFeedbackNonce = isset($_POST['abj404_feedback_nonce']) ? $_POST['abj404_feedback_nonce'] : '';
        $feedbackNonce = sanitize_text_field(ABJ_404_Solution_WordPress_Connector::normalizeRequestScalar($rawFeedbackNonce));
        if (isset($_POST['abj404_submit_feedback']) &&
            $feedbackNonce !== '' &&
            wp_verify_nonce($feedbackNonce, 'abj404_submit_feedback')) {

            $issuesRaw = isset($_POST['feedback_issues']) ? $_POST['feedback_issues'] : array();
            $issues = ABJ_404_Solution_WordPress_Connector::sanitizeFeedbackIssues($issuesRaw);

            $feedbackDetailsRaw = isset($_POST['feedback_details']) ? $_POST['feedback_details'] : '';
            $feedback_details = sanitize_textarea_field(ABJ_404_Solution_WordPress_Connector::normalizeRequestScalar($feedbackDetailsRaw));

            $feedback_data = array(
                'timestamp' => current_time('mysql'),
                'user_id' => get_current_user_id(),
                'site_url' => get_site_url(),
                'issues' => $issues,
                'details' => $feedback_details,
                'wp_version' => get_bloginfo('version'),
                'plugin_version' => ABJ404_VERSION,
                'php_version' => PHP_VERSION
            );

            $all_feedback_raw = get_option('abj404_user_feedback', array());
            $all_feedback = is_array($all_feedback_raw) ? $all_feedback_raw : array();
            $all_feedback[] = $feedback_data;
            update_option('abj404_user_feedback', $all_feedback);

            self::emailFeedback($feedback_data);

            update_user_meta(get_current_user_id(), 'abj404_review_dismissed', 'permanent');
            delete_user_meta(get_current_user_id(), 'abj404_review_step');
            delete_user_meta(get_current_user_id(), 'abj404_review_remind_later');

            self::$feedbackSubmitted = true;
        }
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
            $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/feedbackSuccessNotice.html");
            echo $html;
            return;
        }

        $dismissed = get_user_meta(get_current_user_id(), 'abj404_review_dismissed', true);
        if ($dismissed === 'permanent') {
            return;
        }

        $remind_later = get_user_meta(get_current_user_id(), 'abj404_review_remind_later', true);
        if ($remind_later && time() < $remind_later) {
            return;
        }

        $installed_time = get_option('abj404_installed_time');
        if (!$installed_time) {
            $installed_time = time();
            update_option('abj404_installed_time', $installed_time);
            return;
        }

        $days_installed = (time() - $installed_time) / 86400;
        if ($days_installed < self::INITIAL_DELAY_DAYS) {
            return;
        }

        $review_step = get_user_meta(get_current_user_id(), 'abj404_review_step', true);

        if ($review_step === 'show_review_link') {
            self::showReviewLinkNotice();
        } elseif ($review_step === 'show_feedback') {
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

        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/reviewQualificationQuestion.html");
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

        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/reviewLinkNotice.html");
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

        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/feedbackFormNotice.html");
        $f = abj_service('functions');
        $html = $f->str_replace('{nonce_field}', $nonce_field, $html);
        $html = $f->str_replace('{never_url}', esc_attr($never_url), $html);
        $html = $f->str_replace('{close_url}', esc_attr($close_url), $html);
        echo $html;
    }
}
