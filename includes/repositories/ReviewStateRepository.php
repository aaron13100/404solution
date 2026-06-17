<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Data-access layer for the review-request flow.
 *
 * Owns every read and write of the per-user review-solicitation state and the
 * site-level feedback store, so the controller (ABJ_404_Solution_ReviewFeedback)
 * never touches user-meta / option storage directly. The meta keys and their
 * stored values (the step-machine markers) are encapsulated here; callers speak
 * in terms of state transitions, not storage strings.
 *
 * Storage map:
 *   user meta abj404_review_step          -> '' | STEP_REVIEW_LINK | STEP_FEEDBACK
 *   user meta abj404_review_remind_later  -> int unix-seconds "snooze until"
 *   user meta abj404_review_dismissed     -> '' | DISMISSED_PERMANENT
 *   option    abj404_installed_time       -> int unix-seconds first-seen
 *   option    abj404_user_feedback        -> array<int, array<string, mixed>>
 *
 * Limitations: operates on the current user (get_current_user_id()); not safe to
 * call before WordPress has resolved the current user. All methods degrade to
 * the empty/zero state when meta or options are missing.
 */
class ABJ_404_Solution_ReviewStateRepository {

    /** Step value: the satisfied user has been offered the review link. */
    public const STEP_REVIEW_LINK = 'show_review_link';

    /** Step value: the unsatisfied user has been offered the feedback form. */
    public const STEP_FEEDBACK = 'show_feedback';

    /** Dismissed value: the user will never be asked again. */
    private const DISMISSED_PERMANENT = 'permanent';

    private const META_STEP = 'abj404_review_step';
    private const META_REMIND_LATER = 'abj404_review_remind_later';
    private const META_DISMISSED = 'abj404_review_dismissed';
    private const OPTION_INSTALLED_TIME = 'abj404_installed_time';
    private const OPTION_FEEDBACK = 'abj404_user_feedback';

    /**
     * Advance a satisfied user to the review-link step and cancel any pending
     * snooze. (The 'yes' response.)
     *
     * @return void
     */
    public function advanceToReviewLinkStep(): void {
        $userId = get_current_user_id();
        update_user_meta($userId, self::META_STEP, self::STEP_REVIEW_LINK);
        delete_user_meta($userId, self::META_REMIND_LATER);
    }

    /**
     * Advance an unsatisfied user to the feedback-form step and cancel any
     * pending snooze. (The 'not_yet' response.)
     *
     * @return void
     */
    public function advanceToFeedbackStep(): void {
        $userId = get_current_user_id();
        update_user_meta($userId, self::META_STEP, self::STEP_FEEDBACK);
        delete_user_meta($userId, self::META_REMIND_LATER);
    }

    /**
     * Snooze the review request until the given unix timestamp and clear the
     * current step. (The 'ask_later' and 'close_x' responses.)
     *
     * @param int $timestamp unix-seconds to suppress the request until
     * @return void
     */
    public function snoozeReminderUntil(int $timestamp): void {
        $userId = get_current_user_id();
        update_user_meta($userId, self::META_REMIND_LATER, $timestamp);
        delete_user_meta($userId, self::META_STEP);
    }

    /**
     * Permanently dismiss the review request and clear all transient state.
     * (The 'never' response, the leaving-review click, and feedback submission.)
     *
     * @return void
     */
    public function dismissPermanently(): void {
        $userId = get_current_user_id();
        update_user_meta($userId, self::META_DISMISSED, self::DISMISSED_PERMANENT);
        delete_user_meta($userId, self::META_STEP);
        delete_user_meta($userId, self::META_REMIND_LATER);
    }

    /**
     * Append one feedback submission to the site-level feedback store.
     *
     * @param array<string, mixed> $entry
     * @return void
     */
    public function appendFeedbackEntry(array $entry): void {
        $existingRaw = get_option(self::OPTION_FEEDBACK, array());
        $existing = is_array($existingRaw) ? $existingRaw : array();
        $existing[] = $entry;
        update_option(self::OPTION_FEEDBACK, $existing);
    }

    /**
     * @return string '' | self::STEP_REVIEW_LINK | self::STEP_FEEDBACK
     */
    public function getCurrentStep(): string {
        $step = get_user_meta(get_current_user_id(), self::META_STEP, true);
        return is_string($step) ? $step : '';
    }

    /**
     * @return int unix-seconds the request is snoozed until, or 0 if not snoozed
     */
    public function getReminderTimestamp(): int {
        $remindLater = get_user_meta(get_current_user_id(), self::META_REMIND_LATER, true);
        return is_numeric($remindLater) ? (int) $remindLater : 0;
    }

    /**
     * @return bool true if the user has permanently dismissed the request
     */
    public function isPermanentlyDismissed(): bool {
        $dismissed = get_user_meta(get_current_user_id(), self::META_DISMISSED, true);
        return $dismissed === self::DISMISSED_PERMANENT;
    }

    /**
     * @return int first-seen unix timestamp, or 0 if unset / invalid
     */
    public function getInstalledTime(): int {
        $installedTimeRaw = get_option(self::OPTION_INSTALLED_TIME);
        return is_numeric($installedTimeRaw) ? (int) $installedTimeRaw : 0;
    }

    /**
     * @param int $timestamp first-seen unix timestamp to persist
     * @return void
     */
    public function setInstalledTime(int $timestamp): void {
        update_option(self::OPTION_INSTALLED_TIME, $timestamp);
    }
}
