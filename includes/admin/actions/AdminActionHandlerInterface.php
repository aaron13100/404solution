<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Contract for one admin action handler.
 *
 * Each handler corresponds 1:1 with a string action name (or prefix) submitted
 * via a wp-admin POST/GET to the plugin's options page. The dispatcher
 * (ABJ_404_Solution_PluginLogicAdminActions::handlePluginAction) centralizes
 * the nonce + is_admin() guard. Each handler owns only its action's specific
 * work, and returns the message that should be displayed back to the admin.
 *
 * Why the interface is needed:
 *   - Replaces a 12-branch if/else chain in PluginLogicAdminActions, where each
 *     branch repeated `check_admin_referer + is_admin + dispatch + debug-log
 *     on failure`. Centralizing those guards in the dispatcher makes it
 *     impossible to forget the nonce check for a new action.
 *
 * Implementations:
 *   - includes/admin/actions/*Handler.php (one class per action verb).
 */
interface ABJ_404_Solution_AdminActionHandlerInterface {

    /**
     * The nonce action string this handler expects (passed to wp_verify_nonce /
     * check_admin_referer in the dispatcher).
     *
     * @return string
     */
    public function nonceAction(): string;

    /**
     * The query/POST arg holding the nonce value. Default is '_wpnonce' (the
     * value used by check_admin_referer). One handler (SaveGscSettings) uses
     * a custom arg '_wpnonce_gsc'; UpdateOptions uses the POST field 'nonce'.
     *
     * @return string
     */
    public function nonceArg(): string;

    /**
     * Whether this handler uses check_admin_referer() (true) or the lower-level
     * wp_verify_nonce($_POST['<arg>'], ...) (false). Mirrors the pre-refactor
     * dispatch code exactly: only 'updateOptions' historically used
     * wp_verify_nonce() directly against $_POST['nonce']; everything else used
     * check_admin_referer().
     *
     * @return bool
     */
    public function useCheckAdminReferer(): bool;

    /**
     * Run the action-specific work.
     *
     * @param string $action The action verb (passed in case a handler matches
     *                       multiple verbs, e.g. the bulk* prefix handler).
     * @param string $sub    The current admin subpage (by reference; handler
     *                       may rewrite it, e.g. updateOptions sets it to
     *                       'abj404_options').
     * @return string Human-readable message to display, or '' for none.
     */
    public function handle(string $action, string &$sub): string;
}
