<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin admin-notice renderers shown only on the plugin's own admin page.
 *
 * abj404_show_runtime_integrity_notice() warns when required plugin files are
 * missing. abj404_show_view_build_cron_notices() surfaces staged-view-build
 * cron-stuck / schedule-failure / rollup-stale notices and the rebuild-health
 * pause notice.
 *
 * abj404_show_plugin_db_notice() surfaces user-actionable database errors
 * (never the developer-level collation notices) on the plugin page.
 *
 * The add_action('admin_notices', ...) registrations stay in 404-solution.php;
 * this file only defines the renderers.
 */
// allow-no-test-found: boot-time admin_notices global renderers wired via add_action in 404-solution.php; no same-named unit file. abj404_show_plugin_db_notice (and the other notice renderers) are exercised in CollationAutoRecoveryTest.

if (!function_exists('abj404_show_plugin_db_notice')) {
	/** @return void */
	function abj404_show_plugin_db_notice() {
		if (!is_admin() || !abj404_current_user_is_plugin_admin()) {
			return;
		}
		$page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
		if ($page !== ABJ404_PP) {
			return;
		}
		$notice = get_transient('abj404_plugin_db_notice');
		if (!is_array($notice)) {
			return;
		}
		$type = isset($notice['type']) ? $notice['type'] : '';
		// Collation issues are developer-level; don't show them to the user.
		if ($type === 'collation') {
			return;
		}
		$noticeMessage = isset($notice['message']) && is_string($notice['message']) ? $notice['message'] : '';
		if ($noticeMessage === '') {
			return;
		}
		$guidance = isset($notice['guidance']) && is_string($notice['guidance']) ? $notice['guidance'] : '';
		echo '<div class="notice notice-error"><p><strong>404 Solution:</strong> ' . esc_html($noticeMessage) . '</p>';
		if ($guidance !== '') {
			echo '<p>' . esc_html($guidance) . '</p>';
		}
		if (!empty($notice['error_string'])) {
			$errorString = is_string($notice['error_string']) ? $notice['error_string'] : '';
			echo '<details><summary>' . esc_html(__('Show database error details', '404-solution')) . '</summary>';
			echo '<pre style="white-space:pre-wrap;word-break:break-all;max-width:100%;margin:6px 0;">' . esc_html($errorString) . '</pre></details>';
		}
		echo '</div>';
	}
}

if (!function_exists('abj404_show_runtime_integrity_notice')) {
	/** @return void */
	function abj404_show_runtime_integrity_notice() {
		if (!is_admin() || !abj404_current_user_is_plugin_admin()) {
			return;
		}
		$page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
		if ($page !== ABJ404_PP) {
			return;
		}
		$missing = get_transient('abj404_runtime_missing_files');
		if (!is_array($missing) || count($missing) === 0) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>404 Solution:</strong> ';
		echo esc_html(__('Some required plugin files are missing. Please reinstall the plugin package.', '404-solution'));
		$missingBasenames = array();
		foreach ($missing as $missingPath) {
			if (is_string($missingPath)) {
				$missingBasenames[] = basename($missingPath);
			}
		}
		echo '</p><p><code>' . esc_html(implode(', ', $missingBasenames)) . '</code></p></div>';
	}
}

if (!function_exists('abj404_show_view_build_cron_notices')) {
	/**
	 * Render the staged-view-build cron-stuck and schedule-failure notices.
	 * Set by DataAccess::scheduleViewDoneRebuild() when WordPress cron has
	 * stopped advancing (earliest overdue ready-job >= 24h old) or when
	 * wp_schedule_single_event itself fails. 24h dedup transients.
	 *
	 * @return void
	 */
	function abj404_show_view_build_cron_notices() {
		if (!is_admin() || !abj404_current_user_is_plugin_admin()) {
			return;
		}
		$page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
		if ($page !== ABJ404_PP) {
			return;
		}
		$keys = array(
			'abj404_view_build_stuck_wp_cron_disabled',
			'abj404_view_build_cron_schedule_failed',
			'abj404_logs_hits_rollup_stale',
		);
		if (class_exists('ABJ_404_Solution_ServiceContainer')
				&& ABJ_404_Solution_ServiceContainer::safeHas('rebuild_health')) {
			$rebuildHealth = ABJ_404_Solution_ServiceContainer::safeGet('rebuild_health');
			if ($rebuildHealth instanceof ABJ_404_Solution_RebuildHealthState) {
				$payload = $rebuildHealth->getNoticePayload();
				if (is_array($payload)) {
					$count = isset($payload['failure_count']) ? (int)$payload['failure_count'] : 0;
					$class = isset($payload['last_failure_class']) && is_string($payload['last_failure_class'])
						? $payload['last_failure_class']
						: 'unknown';
					$nextAllowed = isset($payload['next_allowed_at']) ? (int)$payload['next_allowed_at'] : 0;
					$seconds = max(0, $nextAllowed - abj404_now());
					echo '<div class="notice notice-warning"><p><strong>404 Solution:</strong> '
						. esc_html(sprintf(
							__('View/hits rebuild paused after %d consecutive failures (class: %s). Next retry in about %d minutes.', '404-solution'),
							$count,
							$class,
							(int)ceil($seconds / 60)
						)) . '</p>';
					if (!empty($payload['last_failure_msg']) && is_string($payload['last_failure_msg'])) {
						echo '<details><summary>' . esc_html(__('Show details', '404-solution'))
							. '</summary><pre style="white-space:pre-wrap;word-break:break-all;max-width:100%;margin:6px 0;">'
							. esc_html($payload['last_failure_msg']) . '</pre></details>';
					}
					$retryUrl = admin_url('admin.php?page=' . ABJ404_PP . '&subpage=abj404_redirects&abj404_force_view_rebuild=1');
					echo '<p><a class="button button-secondary" href="' . esc_url($retryUrl) . '">'
						. esc_html(__('Retry Now', '404-solution')) . '</a></p>';
					echo '</div>';
				}
			}
		}
		foreach ($keys as $key) {
			$notice = get_transient($key);
			if (!is_array($notice)) {
				continue;
			}
			$noticeMessage = isset($notice['message']) && is_string($notice['message']) ? $notice['message'] : '';
			if ($noticeMessage === '') {
				continue;
			}
			echo '<div class="notice notice-warning"><p><strong>404 Solution:</strong> '
				. esc_html($noticeMessage) . '</p>';
			if (!empty($notice['error_string'])) {
				$noticeErrorString = is_string($notice['error_string']) ? $notice['error_string'] : '';
				echo '<details><summary>' . esc_html(__('Show details', '404-solution'))
					. '</summary><pre style="white-space:pre-wrap;word-break:break-all;max-width:100%;margin:6px 0;">'
					. esc_html($noticeErrorString) . '</pre></details>';
			}
			echo '</div>';
		}
	}
}
