<?php


if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: covered by tests/LoggingTest.php through ABJ_404_Solution_Logging::logUserCapabilities().

/**
 * Builds the current-user capability diagnostic line for the debug log.
 *
 * This class owns presentation of the capability snapshot only. The caller is
 * responsible for writing the returned line so normal debug-mode gating and
 * write-time PII redaction still apply in one place.
 */
class ABJ_404_Solution_LoggingCapabilityDiagnostics {

    /**
     * @param string $msg Operator-supplied context to include with the snapshot.
     * @return string
     */
    public function format(string $msg): string {
        $f = abj_service('functions');
        $user = wp_get_current_user();
        $usercaps = $f->str_replace(',"', ', "', wp_kses_post((string)json_encode($user->get_role_caps())));

        $userIsPluginAdminStr = "false";
        if (abj_service('admin_access_policy')->isPluginAdmin()) {
            $userIsPluginAdminStr = "true";
        }

        return "User caps msg: " . esc_html($msg == '' ? '(none)' : $msg) . ", is_admin(): " . is_admin() .
            ", current_user_can('manage_options'): " . current_user_can('manage_options') .
            ", current_user_can('administrator'): " . current_user_can('administrator') .
            ", userIsPluginAdmin(): " . $userIsPluginAdminStr .
            ", user_login: " . esc_html($user->user_login ?? '(none)') .
            ", user caps: " . wp_kses_post((string)json_encode($user->caps)) . ", get_role_caps: " .
            $usercaps . ", WP ver: " . get_bloginfo('version') . ", mbstring: " .
            (extension_loaded('mbstring') ? 'true' : 'false');
    }
}
