<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decides whether a request is from a plugin admin AFTER the primary check has
 * already failed or thrown.
 *
 * This is a distinct job from asking the question in the first place. The
 * primary answer comes from the admin access policy; this owns what to do when
 * that answer is missing, which is a ladder of progressively weaker sources
 * (re-ask the policy, then the current WP user, then network super-admin) with
 * a fail-closed default at the bottom. AJAX handlers only need the verdict.
 *
 * It lives here rather than beside those handlers because it is policy, not
 * response-building, and because two different endpoint groups want different
 * rungs of the ladder: most stop after re-asking the policy, while the
 * pagination endpoint also consults the WP user.
 *
 * @see ABJ_404_Solution_AjaxAdminEndpointSupport
 */
class ABJ_404_Solution_AdminStatusFallbackResolver {

    /**
     * @param bool $isPluginAdmin Current best-known admin status (e.g. from before the throw).
     * @param bool $includeWpUserFallback If true, also fall back to wp_get_current_user() and
     *                                    is_super_admin() (used only by getPaginationLinks; other
     *                                    handlers stop at the policy re-check).
     * @return bool
     */
    public static function resolve(bool $isPluginAdmin, bool $includeWpUserFallback = false): bool {
        if ($isPluginAdmin) {
            return true;
        }
        $isPluginAdmin = self::askPolicyAgain($isPluginAdmin);
        if (!$includeWpUserFallback || $isPluginAdmin) {
            return $isPluginAdmin;
        }
        return self::askWordPress();
    }

    /**
     * Re-ask the admin access policy, failing closed if it cannot answer.
     *
     * @param bool $isPluginAdmin
     * @return bool
     */
    private static function askPolicyAgain(bool $isPluginAdmin): bool {
        $adminAccessPolicy = abj_service('admin_access_policy');
        if (!is_object($adminAccessPolicy) || !method_exists($adminAccessPolicy, 'isPluginAdmin')) {
            return $isPluginAdmin;
        }
        try {
            return (bool)$adminAccessPolicy->isPluginAdmin();
        } catch (Throwable $e) {
            // Failing closed to non-admin is deliberate. Losing the reason is
            // not: this runs only after something upstream already threw, so
            // swallowing it turns an admin-access outage into "the page shows
            // less" with nothing linking the two.
            $logger = abj_service('logging');
            if (is_object($logger) && method_exists($logger, 'debugMessage')) {
                $logger->debugMessage(
                    'Admin-status re-check threw; failing closed to non-admin. '
                    . get_class($e) . ' (code ' . (string)$e->getCode() . '): ' . $e->getMessage(),
                    $e
                );
            }
            return false;
        }
    }

    /**
     * The weakest rungs: the current WordPress user, then network super-admin.
     *
     * @return bool
     */
    private static function askWordPress(): bool {
        if (function_exists('wp_get_current_user')) {
            $user = ABJ_404_Solution_UserRef::fromWpUser(wp_get_current_user());
            if ($user !== null && $user->isAdministrator()) {
                return true;
            }
        }
        return function_exists('is_super_admin') && is_super_admin();
    }
}
