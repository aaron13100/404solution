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

    /**
     * Test-seam: install an externally-constructed instance, or pass null to
     * clear the cached singleton. Accepting null keeps a uniform contract with
     * the other singletons' setInstance() seams (M105 singleton-reset).
     *
     * @param self|null $instance
     * @return void
     */
    public static function setInstance(?self $instance): void {
        self::$instance = $instance;
    }

    /** Test-seam reset. @return void */
    public static function reset(): void {
        self::$instance = null;
        self::$checkingIsAdmin = false;
    }

    /**
     * Resolve the registered policy and return the current plugin-admin decision.
     *
     * Production call sites should use this accessor instead of reading
     * WordPress capabilities directly. The method fails closed when service
     * resolution is unavailable and logs the underlying context where possible.
     *
     * @return bool
     */
    public static function currentUserCanAccessPluginAdmin(): bool {
        try {
            $policy = function_exists('abj_service_optional') ? abj_service_optional('admin_access_policy') : null;
            if (is_object($policy) && method_exists($policy, 'isPluginAdmin')) {
                return (bool)$policy->isPluginAdmin();
            }

            self::warnStaticPolicyResolutionFailure(
                'plugin admin access policy resolution failed because admin_access_policy is unavailable.'
            );
            return false;
        } catch (\Throwable $e) {
            self::warnStaticPolicyResolutionFailure('plugin admin access policy resolution failed', $e);
            return false;
        }
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
            $options = $this->loadOptions();
            $logger = $this->logger !== null ? $this->logger : abj_service('logging');
            $logger = is_object($logger) ? $logger : null;
            $currentUserName = $this->currentUserName();
            $capability = $this->currentUserAdminCapability();

            $isPluginAdmin = $capability['is_plugin_admin'] ||
                $this->currentUserIsListedPluginAdmin($options, $currentUserName);

            $filtered = apply_filters('abj404_userIsPluginAdmin', $isPluginAdmin);

            $this->logPluginAdminDecision(
                $logger,
                $options,
                $currentUserName,
                $capability['can_manage_options'],
                $isPluginAdmin,
                (bool) $filtered
            );

            return (bool) $filtered;
        } finally {
            self::$checkingIsAdmin = false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadOptions(): array {
        $optionsRepo = $this->optionsRepo !== null ? $this->optionsRepo : abj_service('options_repository');
        if (!is_object($optionsRepo) || !method_exists($optionsRepo, 'getOptions')) {
            return array();
        }

        try {
            if (method_exists($optionsRepo, 'getPluginAdminUsersOption')) {
                return array(
                    'plugin_admin_users' => $optionsRepo->getPluginAdminUsersOption(),
                );
            }
            $resolvedOptions = $optionsRepo->getOptions(true);
            return is_array($resolvedOptions) ? $resolvedOptions : array();
        } catch (\Throwable $e) {
            $this->warn('plugin admin option lookup failed (code ' .
                $e->getCode() . '): ' . $e->getMessage());
            return array();
        }
    }

    /**
     * @return array{can_manage_options: bool, is_plugin_admin: bool}
     */
    private function currentUserAdminCapability(): array {
        $canManageOptions = false;
        $hasAdministratorRole = false;
        try {
            $canManageOptions = function_exists('current_user_can') && current_user_can('manage_options');
            $hasAdministratorRole = function_exists('current_user_can') && current_user_can('administrator');
        } catch (\Throwable $e) {
            $this->warn('plugin admin capability lookup failed (code ' .
                $e->getCode() . '): ' . $e->getMessage());
        }

        $isPluginAdmin = $canManageOptions || $hasAdministratorRole;
        if (function_exists('is_multisite') && is_multisite() && function_exists('is_super_admin') && is_super_admin()) {
            $isPluginAdmin = true;
        }

        return array(
            'can_manage_options' => $canManageOptions,
            'is_plugin_admin' => $isPluginAdmin,
        );
    }

    /** @return string|null */
    private function currentUserName() {
        global $current_user;
        if (!isset($current_user) || !isset($current_user->user_login)) {
            return null;
        }

        return is_string($current_user->user_login) ? $current_user->user_login : null;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function currentUserIsListedPluginAdmin(array $options, ?string $currentUserName): bool {
        if ($currentUserName === null || $currentUserName === '') {
            return false;
        }

        $extraAdmins = isset($options['plugin_admin_users']) ? $options['plugin_admin_users'] : array();
        $extraAdmins = $this->normalizeExtraAdmins($extraAdmins);
        return in_array($currentUserName, $extraAdmins, true);
    }

    /**
     * @param mixed $extraAdmins
     * @return array<int, string>
     */
    private function normalizeExtraAdmins($extraAdmins): array {
        $functions = $this->functions !== null ? $this->functions : abj_service('functions');

        if (is_array($extraAdmins)) {
            if (is_object($functions) && method_exists($functions, 'removeEmptyCustom')) {
                $extraAdmins = array_filter($extraAdmins, array($functions, 'removeEmptyCustom'));
            } else {
                $extraAdmins = array_filter($extraAdmins);
            }
            return $this->stringList($extraAdmins);
        }

        if (is_string($extraAdmins) && is_object($functions) && method_exists($functions, 'explodeNewline')) {
            $splitAdmins = $functions->explodeNewline($extraAdmins);
            return is_array($splitAdmins) ? $this->stringList(array_filter($splitAdmins)) : array();
        }

        return array();
    }

    /**
     * @param array<int|string, mixed> $values
     * @return array<int, string>
     */
    private function stringList(array $values): array {
        $strings = array();
        foreach ($values as $value) {
            if (is_scalar($value)) {
                $strings[] = (string) $value;
            }
        }
        return $strings;
    }

    /**
     * @param object|null $logger
     * @param array<string, mixed> $options
     * @return void
     */
    private function logPluginAdminDecision(?object $logger, array $options, ?string $currentUserName, bool $canManageOptions, bool $isPluginAdmin, bool $filtered): void {
        if (($filtered && $filtered === $isPluginAdmin) || !is_object($logger) || !method_exists($logger, 'debugMessage')) {
            return;
        }

        $logger->debugMessage(
            "userIsPluginAdmin detail: result=" . ($filtered ? 'true' : 'false') .
            ", pre-filter=" . ($isPluginAdmin ? 'true' : 'false') .
            ", manage_options=" . ($canManageOptions ? 'yes' : 'no') .
            ", user=" . ($currentUserName !== null ? $currentUserName : '(none)') .
            ", plugin_admin_users=[" . esc_html($this->extraAdminsSummary($options)) . "]" .
            ($filtered !== $isPluginAdmin ? ", NOTE: abj404_userIsPluginAdmin filter changed the result" : "")
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function extraAdminsSummary(array $options): string {
        $rawExtra = isset($options['plugin_admin_users']) ? $options['plugin_admin_users'] : array();
        if (is_string($rawExtra)) {
            return $rawExtra;
        }
        if (!is_array($rawExtra)) {
            return '';
        }

        return implode(', ', $this->normalizeExtraAdmins($rawExtra));
    }

    private function warn(string $message): void {
        $logger = $this->logger !== null ? $this->logger : (function_exists('abj_service_optional') ? abj_service_optional('logging') : null);
        if (is_object($logger) && method_exists($logger, 'warn')) {
            $logger->warn($message);
            return;
        }

        abj404_logPhpFallback('service-resolution-fallback', $message);
    }

    private static function warnStaticPolicyResolutionFailure(string $message, ?\Throwable $throwable = null): void {
        if (function_exists('abj404_logRuntimeWarning')) {
            abj404_logRuntimeWarning($message, $throwable);
            return;
        }

        $line = $message;
        if ($throwable !== null) {
            $line .= ' (code ' . (string)$throwable->getCode() . ') at ' .
                $throwable->getFile() . ':' . (string)$throwable->getLine() .
                ': ' . $throwable->getMessage();
        }
        abj404_logPhpFallback('service-resolution-fallback', $line);
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
