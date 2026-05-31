<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Authorization policy: decides whether the current WordPress user
 * counts as a "plugin admin" for 404 Solution and grants the
 * `manage_options` capability on the plugin's own admin screens via
 * the `user_has_cap` filter.
 *
 * Owns logic previously hosted on PluginLogic (userIsPluginAdmin and
 * the static override_user_can_access_admin_page filter callback).
 * Composed through abj_service('admin_access_policy'). The static
 * `wpUserHasCapFilter` is wired into WordPress by PluginLogic during
 * bootstrap so the filter signature matches WP's expectations.
 */
class ABJ_404_Solution_PluginAdminAccessPolicy {

    /**
     * Avoid infinite recursion when current_user_can() re-enters via filter.
     * @var bool
     */
    private static $checkingIsAdmin = false;

    /** @var self|null */
    private static $instance = null;

    /** @var object|null */
    private $optionsRepo;

    /** @var object|null */
    private $functions;

    /** @var object|null */
    private $logger;

    /**
     * @param object|null $optionsRepo Anything responding to getOptions($skipDbCheck)
     * @param object|null $functions   Functions facade (removeEmptyCustom, explodeNewline)
     * @param object|null $logger      Logger facade (debugMessage)
     */
    public function __construct($optionsRepo = null, $functions = null, $logger = null) {
        $this->optionsRepo = $optionsRepo;
        $this->functions = $functions;
        $this->logger = $logger;
    }

    /** @return self */
    public static function getInstance(): self {
        if (self::$instance !== null) {
            return self::$instance;
        }
        self::$instance = new self();
        return self::$instance;
    }

    /** Test-seam: install an externally-constructed instance. @return void */
    public static function setInstance(self $instance): void {
        self::$instance = $instance;
    }

    /** Test-seam reset. @return void */
    public static function reset(): void {
        self::$instance = null;
        self::$checkingIsAdmin = false;
    }

    /**
     * Whether the current WP user qualifies as a 404 Solution admin.
     *
     * Capability sources, ORed together:
     *   - manage_options or the administrator role
     *   - multisite super admin
     *   - presence in the plugin's plugin_admin_users option (login match)
     *
     * The result then runs through the abj404_userIsPluginAdmin filter
     * so site owners can override either direction.
     *
     * Recursion-safe: re-entry returns false to avoid the filter chain
     * looping back into itself.
     *
     * @return bool
     */
    public function isPluginAdmin(): bool {
        if (self::$checkingIsAdmin) {
            return false;
        }

        self::$checkingIsAdmin = true;
        try {
            $optionsRepo = $this->optionsRepo !== null ? $this->optionsRepo : abj_service('options_repository');
            $options = array();
            if (is_object($optionsRepo) && method_exists($optionsRepo, 'getOptions')) {
                try {
                    $resolvedOptions = $optionsRepo->getOptions(true);
                    if (is_array($resolvedOptions)) {
                        $options = $resolvedOptions;
                    }
                } catch (\Throwable $e) {
                    error_log('404 Solution: plugin admin option lookup failed (code ' .
                        $e->getCode() . '): ' . $e->getMessage());
                }
            }
            $functions = $this->functions !== null ? $this->functions : abj_service('functions');
            $logger = $this->logger !== null ? $this->logger : abj_service('logging');
            global $current_user;

            $canManageOptions = false;
            $hasAdministratorRole = false;
            try {
                $canManageOptions = function_exists('current_user_can') && current_user_can('manage_options');
                $hasAdministratorRole = function_exists('current_user_can') && current_user_can('administrator');
            } catch (\Throwable $e) {
                error_log('404 Solution: plugin admin capability lookup failed (code ' .
                    $e->getCode() . '): ' . $e->getMessage());
            }
            $isPluginAdmin = $canManageOptions || $hasAdministratorRole;
            if (function_exists('is_multisite') && is_multisite() && function_exists('is_super_admin') && is_super_admin()) {
                $isPluginAdmin = true;
            }

            $extraAdmins = isset($options['plugin_admin_users']) ? $options['plugin_admin_users'] : array();
            $currentUserName = null;
            if (isset($current_user)) {
                $currentUserName = $current_user->user_login;
            }
            if ($currentUserName != null && $currentUserName != false) {
                $check = false;
                if (is_array($extraAdmins)) {
                    if (is_object($functions) && method_exists($functions, 'removeEmptyCustom')) {
                        $extraAdmins = array_filter($extraAdmins, array($functions, 'removeEmptyCustom'));
                    } else {
                        $extraAdmins = array_filter($extraAdmins);
                    }
                    $check = true;
                } else if (is_string($extraAdmins) && is_object($functions) && method_exists($functions, 'explodeNewline')) {
                    $extraAdmins = $functions->explodeNewline($extraAdmins);
                    $check = true;
                }
                /** @var array<int|string, mixed> $extraAdmins */
                if ($check && is_array($extraAdmins) && in_array($currentUserName, $extraAdmins)) {
                    $isPluginAdmin = true;
                }
            }

            $filtered = apply_filters('abj404_userIsPluginAdmin', $isPluginAdmin);

            if ((!$filtered || ($filtered !== $isPluginAdmin)) && is_object($logger) && method_exists($logger, 'debugMessage')) {
                $extraAdminsSummary = '';
                $rawExtra = isset($options['plugin_admin_users']) ? $options['plugin_admin_users'] : array();
                if (is_array($rawExtra)) {
                    $extraAdminsSummary = implode(', ', array_filter($rawExtra));
                } else if (is_string($rawExtra)) {
                    $extraAdminsSummary = $rawExtra;
                }

                $logger->debugMessage(
                    "userIsPluginAdmin detail: result=" . ($filtered ? 'true' : 'false') .
                    ", pre-filter=" . ($isPluginAdmin ? 'true' : 'false') .
                    ", manage_options=" . ($canManageOptions ? 'yes' : 'no') .
                    ", user=" . ($currentUserName !== null ? $currentUserName : '(none)') .
                    ", plugin_admin_users=[" . esc_html($extraAdminsSummary) . "]" .
                    ($filtered !== $isPluginAdmin ? ", NOTE: abj404_userIsPluginAdmin filter changed the result" : "")
                );
            }

            return (bool) $filtered;
        } finally {
            self::$checkingIsAdmin = false;
        }
    }

    /**
     * `user_has_cap` filter callback: while a plugin admin is viewing one
     * of the plugin's own admin screens, grant `manage_options` so WP's
     * capability gate lets them through even if their WP role does not.
     *
     * Static because WordPress invokes filter callbacks by name with a
     * fixed (allcaps, caps, args, user) signature.
     *
     * @param array<string, bool> $allcaps
     * @param array<int, string>  $caps
     * @param array<int, mixed>   $args
     * @param \WP_User            $user
     * @return array<string, bool>
     */
    public static function wpUserHasCapFilter($allcaps, $caps, $args, $user) {
        if (!is_admin()) {
            return $allcaps;
        }

        $policy = abj_service('admin_access_policy');
        if (!is_object($policy) || !method_exists($policy, 'isPluginAdmin')) {
            return $allcaps;
        }

        if (!$policy->isPluginAdmin()) {
            return $allcaps;
        }

        $userRequest = ABJ_404_Solution_UserRequest::getInstance();
        $queryParts = $userRequest !== null ? $userRequest->getQueryString() : null;
        $isViewing404AdminPage = is_string($queryParts) && strpos($queryParts, ABJ404_PP) !== false;

        if ($isViewing404AdminPage) {
            $allcaps['manage_options'] = true;
        }

        return $allcaps;
    }
}
