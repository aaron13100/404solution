<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages the auto-created "Page Not Found" system page used by 404 Solution
 * when the "Suggest similar pages" behavior is selected.
 *
 * The system page contains the [abj404_solution_page_suggestions] shortcode and
 * renders through the theme's own page.php template, inheriting all theme fonts,
 * colors, and layout automatically.
 *
 * The page is tagged with post meta _abj404_system_page = 1 so the plugin can
 * find it reliably.
 */
class ABJ_404_Solution_SystemPage {

    /** Post meta key used to identify the system page. */
    const META_KEY = '_abj404_system_page';

    /** @var self|null */
    private static $instance = null;

    /** @return self */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Find the existing system page ID, or return 0 if none exists.
     *
     * @return int The page ID, or 0 if not found.
     */
    public function getSystemPageId(): int {
        $pages = get_posts(array(
            'post_type'      => 'page',
            'post_status'    => array('publish', 'draft', 'private'),
            'meta_key'       => self::META_KEY,
            'meta_value'     => '1',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ));

        if (!empty($pages) && is_array($pages)) {
            return (int) $pages[0];
        }

        return 0;
    }

    /**
     * Check whether the system page exists and is published.
     *
     * @return bool
     */
    public function systemPageExists(): bool {
        $pageId = $this->getSystemPageId();
        if ($pageId <= 0) {
            return false;
        }
        return get_post_status($pageId) === 'publish';
    }

    /**
     * Get or create the system page. If the page already exists and is published,
     * return its ID. Otherwise, create a new one.
     *
     * @return int The page ID, or 0 on failure.
     */
    public function getOrCreateSystemPage(): int {
        $existingId = $this->getSystemPageId();

        if ($existingId > 0 && get_post_status($existingId) === 'publish') {
            return $existingId;
        }

        // If the page exists but is trashed/drafted, try to republish it
        if ($existingId > 0) {
            $result = wp_update_post(array(
                'ID'          => $existingId,
                'post_status' => 'publish',
            ), true);
            if (!is_wp_error($result)) {
                return $existingId;
            }
        }

        return $this->createSystemPage();
    }

    /**
     * Create a fresh system page with the shortcode.
     *
     * @return int The new page ID, or 0 on failure.
     */
    public function createSystemPage(): int {
        $title = __('Page Not Found', '404-solution');

        $pageId = wp_insert_post(array(
            'post_title'   => $title,
            'post_content' => '[abj404_solution_page_suggestions]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => get_current_user_id() ?: 1,
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ), true);

        if (is_wp_error($pageId)) {
            return 0;
        }

        // Tag with system page meta
        update_post_meta($pageId, self::META_KEY, '1');

        // Exclude from sitemaps
        update_post_meta($pageId, '_yoast_wpseo_meta-robots-noindex', '1');
        update_post_meta($pageId, 'rank_math_robots', array('noindex'));

        return (int) $pageId;
    }

    /**
     * Delete the system page permanently.
     *
     * @return bool True if deleted, false otherwise.
     */
    public function deleteSystemPage(): bool {
        $pageId = $this->getSystemPageId();
        if ($pageId <= 0) {
            return false;
        }

        $result = wp_delete_post($pageId, true);
        return $result !== false && $result !== null;
    }

    /**
     * Check if a given post ID is the system page.
     *
     * @param int $postId
     * @return bool
     */
    public static function isSystemPage(int $postId): bool {
        if ($postId <= 0) {
            return false;
        }
        return get_post_meta($postId, self::META_KEY, true) === '1';
    }

    /**
     * When the system page is deleted or trashed externally, flip the behavior
     * setting to 'theme_default' and set a transient for the admin notice.
     *
     * @return void
     */
    public function handleSystemPageDeleted(): void {
        $logic = abj_service('plugin_logic');
        $options = $logic->getOptions(true);

        if (isset($options['dest404_behavior']) && $options['dest404_behavior'] === 'suggest') {
            $options['dest404_behavior'] = 'theme_default';
            $options['dest404page'] = '0|' . ABJ404_TYPE_404_DISPLAYED;
            $logic->updateOptions($options);

            set_transient('abj404_system_page_deleted', '1', DAY_IN_SECONDS);
        }
    }

    /**
     * On each 404 hit, verify that the system page still exists when behavior is 'suggest'.
     * This catches bulk deletes, DB restores, cleanup plugins, etc.
     *
     * @return void
     */
    public function verifySystemPageOnRequest(): void {
        $logic = abj_service('plugin_logic');
        $options = $logic->getOptions(true);

        if (!isset($options['dest404_behavior']) || $options['dest404_behavior'] !== 'suggest') {
            return;
        }

        if (!$this->systemPageExists()) {
            $this->handleSystemPageDeleted();
        }
    }

    /**
     * Hook: before_delete_post / wp_trash_post — detect when system page is trashed/deleted.
     *
     * @param int $postId
     * @return void
     */
    public static function onPostDeleteOrTrash(int $postId): void {
        if (!self::isSystemPage($postId)) {
            return;
        }

        $instance = self::getInstance();
        $instance->handleSystemPageDeleted();
    }

    /**
     * Hook: admin_notices on plugin settings page — show notice if system page was deleted.
     *
     * @return void
     */
    public static function maybeShowDeletedPageNotice(): void {
        if (get_transient('abj404_system_page_deleted') !== '1') {
            return;
        }

        // Only show on our plugin's settings page
        $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
        if ($page !== ABJ404_PP) {
            return;
        }

        $settingsUrl = admin_url('options-general.php?page=' . ABJ404_PP . '&subpage=abj404_options');
        $recreateUrl = wp_nonce_url(
            add_query_arg('abj404_recreate_system_page', '1', $settingsUrl),
            'abj404_recreate_system_page'
        );

        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p>';
        echo esc_html__('Your 404 suggestion page was deleted.', '404-solution');
        echo ' <a href="' . esc_url($recreateUrl) . '">' . esc_html__('Recreate it', '404-solution') . '</a>';
        echo ' ' . esc_html__('or choose a different option below.', '404-solution');
        echo '</p>';
        echo '</div>';

        delete_transient('abj404_system_page_deleted');
    }

    /**
     * Hook: admin_init — handle the recreate system page action.
     *
     * @return void
     */
    public static function handleRecreateAction(): void {
        if (!isset($_GET['abj404_recreate_system_page'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        if (!wp_verify_nonce(
            isset($_GET['_wpnonce']) ? sanitize_text_field($_GET['_wpnonce']) : '',
            'abj404_recreate_system_page'
        )) {
            return;
        }

        $instance = self::getInstance();
        $pageId = $instance->createSystemPage();

        if ($pageId > 0) {
            $logic = abj_service('plugin_logic');
            $options = $logic->getOptions(true);
            $options['dest404_behavior'] = 'suggest';
            $options['dest404page'] = $pageId . '|' . ABJ404_TYPE_POST;
            $logic->updateOptions($options);
        }

        // Redirect back to settings page (without the action param)
        $settingsUrl = admin_url('options-general.php?page=' . ABJ404_PP . '&subpage=abj404_options');
        wp_safe_redirect($settingsUrl);
        exit;
    }

    /**
     * Hook: edit_form_after_title — show admin notice when editing the system page.
     *
     * @param \WP_Post $post
     * @return void
     */
    public static function showEditorNotice($post): void {
        if (!self::isSystemPage($post->ID)) {
            return;
        }

        $settingsUrl = admin_url('options-general.php?page=' . ABJ404_PP . '&subpage=abj404_options');
        echo '<div class="notice notice-info inline" style="margin: 12px 0;">';
        echo '<p>';
        echo esc_html__('This page is used by 404 Solution to display suggested pages to visitors.', '404-solution');
        echo ' <a href="' . esc_url($settingsUrl) . '">' . esc_html__('Learn more', '404-solution') . '</a>';
        echo '</p>';
        echo '</div>';
    }

    /**
     * Hook: wp_robots — add noindex to the system page.
     *
     * @param array<string, bool|string> $robots
     * @return array<string, bool|string>
     */
    public static function addNoindexToSystemPage($robots) {
        if (!is_page()) {
            return $robots;
        }

        $postId = get_the_ID();
        if ($postId && self::isSystemPage($postId)) {
            $robots['noindex'] = true;
        }

        return $robots;
    }

    /**
     * Hook: wp_page_menu_args / wp_get_nav_menu_items — exclude system page from nav menus.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function excludeFromPageMenu($args) {
        $pageId = self::getInstance()->getSystemPageId();
        if ($pageId > 0) {
            $existing = isset($args['exclude']) ? $args['exclude'] : '';
            $excludeList = $existing !== '' ? $existing . ',' . $pageId : (string) $pageId;
            $args['exclude'] = $excludeList;
        }
        return $args;
    }

    /**
     * Hook: template_redirect — show admin-only banner when system page is deleted
     * and an admin visits a 404 page.
     *
     * @return void
     */
    public static function maybeShowAdminFrontend404Banner(): void {
        if (!is_404()) {
            return;
        }

        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            return;
        }

        // Check if behavior was 'suggest' but page was deleted
        if (get_transient('abj404_system_page_deleted') !== '1') {
            return;
        }

        $settingsUrl = admin_url('options-general.php?page=' . ABJ404_PP . '&subpage=abj404_options');

        add_action('wp_footer', function() use ($settingsUrl) {
            echo '<div style="position:fixed;top:0;left:0;right:0;z-index:999999;background:#fff3cd;border-bottom:2px solid #ffc107;padding:12px 20px;font-family:-apple-system,BlinkMacSystemFont,sans-serif;font-size:14px;">';
            echo esc_html__('Your 404 Solution suggestion page was deleted. Visitors are seeing this default 404 page instead.', '404-solution');
            echo ' <a href="' . esc_url($settingsUrl) . '" style="color:#0073aa;text-decoration:underline;">';
            echo esc_html__('Go to settings', '404-solution');
            echo '</a>';
            echo '</div>';
        });
    }

    /**
     * Hook: enqueue_block_editor_assets — show notice in the block editor when editing the system page.
     *
     * Uses the wp.data notices store to create an info notice at the top of the block editor.
     *
     * @return void
     */
    public static function enqueueBlockEditorNotice(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->base !== 'post') {
            return;
        }

        // Check if the current post is the system page.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $postId = isset($_GET['post']) ? (int) $_GET['post'] : 0;
        if (!$postId || !self::isSystemPage($postId)) {
            return;
        }

        $settingsUrl = admin_url('options-general.php?page=' . ABJ404_PP . '&subpage=abj404_options');
        $message = esc_html__('This page is used by 404 Solution to display suggested pages to visitors.', '404-solution');
        $linkText = esc_html__('Learn more', '404-solution');

        wp_add_inline_script('wp-edit-post', sprintf(
            'wp.domReady(function(){' .
            'wp.data.dispatch("core/notices").createNotice("info",%s+%s,{id:"abj404-system-page-notice",isDismissible:false,' .
            'actions:[{label:%s,url:%s}]});' .
            '});',
            wp_json_encode($message . ' '),
            wp_json_encode(''),
            wp_json_encode($linkText),
            wp_json_encode($settingsUrl)
        ));
    }

    /**
     * Register all WordPress hooks for system page management.
     *
     * @return void
     */
    public static function registerHooks(): void {
        // Deletion protection
        add_action('before_delete_post', array(__CLASS__, 'onPostDeleteOrTrash'));
        add_action('wp_trash_post', array(__CLASS__, 'onPostDeleteOrTrash'));

        // Admin notices
        add_action('admin_notices', array(__CLASS__, 'maybeShowDeletedPageNotice'));

        // Recreate action
        add_action('admin_init', array(__CLASS__, 'handleRecreateAction'));

        // Editor notice (classic editor)
        add_action('edit_form_after_title', array(__CLASS__, 'showEditorNotice'));

        // Editor notice (block editor / Gutenberg)
        add_action('enqueue_block_editor_assets', array(__CLASS__, 'enqueueBlockEditorNotice'));

        // Exclude from sitemaps (WordPress 5.5+ native robots)
        add_filter('wp_robots', array(__CLASS__, 'addNoindexToSystemPage'));

        // Exclude from page menus
        add_filter('wp_page_menu_args', array(__CLASS__, 'excludeFromPageMenu'));

        // Admin-only frontend 404 banner
        add_action('template_redirect', array(__CLASS__, 'maybeShowAdminFrontend404Banner'));
    }
}
