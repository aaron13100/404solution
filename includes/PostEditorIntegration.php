<?php

/**
 * Post Editor Integration for 404 Solution
 *
 * Adds "Create redirect from old URL to new URL" checkbox to:
 * - Quick Edit on Posts/Pages list
 * - Gutenberg block editor sidebar
 * - Classic Editor meta box
 *
 * This allows users to override the global auto_slugs setting on a per-edit basis.
 */
class ABJ_404_Solution_PostEditorIntegration {

    private static $instance = null;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize all editor integrations
     */
    public static function init() {
        $me = self::getInstance();

        // Quick Edit integration
        add_filter('manage_posts_columns', array($me, 'addRedirectColumn'), 10, 2);
        add_filter('manage_pages_columns', array($me, 'addRedirectColumn'));
        add_action('manage_posts_custom_column', array($me, 'renderRedirectColumn'), 10, 2);
        add_action('manage_pages_custom_column', array($me, 'renderRedirectColumn'), 10, 2);
        add_action('quick_edit_custom_box', array($me, 'renderQuickEditCheckbox'), 10, 2);
        add_action('admin_enqueue_scripts', array($me, 'enqueueQuickEditScript'));

        // Classic Editor meta box
        add_action('add_meta_boxes', array($me, 'addMetaBox'));

        // Gutenberg sidebar panel
        add_action('init', array($me, 'registerPostMeta'));
        add_action('enqueue_block_editor_assets', array($me, 'enqueueGutenbergScript'));
    }

    /**
     * Get the default value for the redirect checkbox based on global settings
     */
    public function getDefaultRedirectSetting() {
        $options = ABJ_404_Solution_PluginLogic::getInstance()->getOptions();
        return @$options['auto_slugs'] == '1';
    }

    // ==================== Quick Edit Integration ====================

    /**
     * Add a hidden column to store redirect default for Quick Edit JavaScript
     */
    public function addRedirectColumn($columns, $post_type = null) {
        // Add our column (it will be hidden via CSS)
        $columns['abj404_redirect'] = '';
        return $columns;
    }

    /**
     * Render the hidden column data (used by Quick Edit JavaScript)
     */
    public function renderRedirectColumn($column, $post_id) {
        if ($column === 'abj404_redirect') {
            $default = $this->getDefaultRedirectSetting() ? '1' : '0';
            // Output data attribute for JavaScript to read
            echo '<span class="abj404-redirect-default" data-default="' . esc_attr($default) . '"></span>';
        }
    }

    /**
     * Render the Quick Edit checkbox
     */
    public function renderQuickEditCheckbox($column_name, $post_type) {
        if ($column_name !== 'abj404_redirect') {
            return;
        }

        // Only show for post types that can have slugs
        if (!post_type_supports($post_type, 'slug') && !in_array($post_type, array('post', 'page'))) {
            return;
        }

        static $nonce_printed = false;
        if (!$nonce_printed) {
            wp_nonce_field('abj404_quick_edit', 'abj404_quick_edit_nonce');
            $nonce_printed = true;
        }
        ?>
        <fieldset class="inline-edit-col-right abj404-quick-edit">
            <div class="inline-edit-col">
                <label class="inline-edit-group">
                    <input type="checkbox" name="abj404_create_redirect" value="1">
                    <span class="checkbox-title"><?php echo esc_html__('Create redirect from old URL to new URL', '404-solution'); ?></span>
                </label>
            </div>
        </fieldset>
        <?php
    }

    /**
     * Enqueue Quick Edit JavaScript on post list screens
     */
    public function enqueueQuickEditScript($hook) {
        if ($hook !== 'edit.php') {
            return;
        }

        wp_enqueue_script(
            'abj404-quick-edit-redirect',
            plugin_dir_url(__FILE__) . 'js/quick-edit-redirect.js',
            array('jquery', 'inline-edit-post'),
            ABJ404_VERSION,
            true
        );

        // Hide the redirect column (we only use it for data storage)
        wp_add_inline_style('wp-admin', '.column-abj404_redirect { display: none; }');
    }

    // ==================== Classic Editor Meta Box ====================

    /**
     * Add meta box to Classic Editor only (not Gutenberg)
     * Gutenberg has its own integration via gutenberg-redirect.js
     */
    public function addMetaBox() {
        $post_types = get_post_types(array('public' => true), 'names');

        foreach ($post_types as $post_type) {
            add_meta_box(
                'abj404_redirect_meta_box',
                __('404 Solution', '404-solution'),
                array($this, 'renderMetaBox'),
                $post_type,
                'side',
                'default',
                array(
                    // Prevent this meta box from appearing in Gutenberg
                    // We have a custom Gutenberg integration that shows the checkbox
                    // only when the slug is modified
                    '__block_editor_compatible_meta_box' => false,
                    '__back_compat_meta_box' => true
                )
            );
        }
    }

    /**
     * Render the Classic Editor meta box content
     */
    public function renderMetaBox($post) {
        // Only show for published posts (new posts have no old URL to redirect from)
        if ($post->post_status !== 'publish') {
            echo '<p class="description">' . esc_html__('Redirect options are available after the post is published.', '404-solution') . '</p>';
            return;
        }

        $default = $this->getDefaultRedirectSetting();
        $checked = $default ? 'checked' : '';

        wp_nonce_field('abj404_meta_box', 'abj404_meta_box_nonce');
        ?>
        <p>
            <label>
                <input type="checkbox" name="abj404_create_redirect" value="1" <?php echo $checked; ?>>
                <?php echo esc_html__('Create redirect from old URL to new URL', '404-solution'); ?>
            </label>
        </p>
        <p class="description">
            <?php echo esc_html__('If you change the permalink/slug, a redirect will be created from the old URL to the new one.', '404-solution'); ?>
        </p>
        <?php
    }

    // ==================== Gutenberg Integration ====================

    /**
     * Register post meta for Gutenberg REST API access
     */
    public function registerPostMeta() {
        register_post_meta('', '_abj404_create_redirect', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'default' => '',
            'auth_callback' => function() {
                return current_user_can('edit_posts');
            },
            'sanitize_callback' => function($value) {
                return $value === '1' ? '1' : ($value === '0' ? '0' : '');
            }
        ));
    }

    /**
     * Enqueue Gutenberg sidebar script
     */
    public function enqueueGutenbergScript() {
        // Only load on post edit screens
        $screen = get_current_screen();
        if (!$screen || $screen->base !== 'post') {
            return;
        }

        wp_enqueue_script(
            'abj404-gutenberg-redirect',
            plugin_dir_url(__FILE__) . 'js/gutenberg-redirect.js',
            array('wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n'),
            ABJ404_VERSION,
            true
        );

        // Pass default setting and translations to JavaScript
        wp_localize_script('abj404-gutenberg-redirect', 'abj404GutenbergRedirect', array(
            'defaultEnabled' => $this->getDefaultRedirectSetting(),
            'i18n' => array(
                'checkboxLabel' => __('Create redirect from old URL to new URL', '404-solution'),
                'slugChangedNotice' => __('Slug changed:', '404-solution'),
            )
        ));
    }

    // ==================== Save Handler Helper ====================

    /**
     * Check if redirect should be created for this post save
     * Called by SlugChangeHandler
     *
     * @param int $post_id Post ID
     * @param array $options Plugin options
     * @return bool Whether to create redirect
     */
    public static function shouldCreateRedirect($post_id, $options) {
        // Check Quick Edit / Classic Editor POST data
        if (isset($_POST['abj404_create_redirect'])) {
            // Verify nonce for Quick Edit
            if (isset($_POST['abj404_quick_edit_nonce']) &&
                wp_verify_nonce($_POST['abj404_quick_edit_nonce'], 'abj404_quick_edit')) {
                return $_POST['abj404_create_redirect'] === '1';
            }
            // Verify nonce for Classic Editor
            if (isset($_POST['abj404_meta_box_nonce']) &&
                wp_verify_nonce($_POST['abj404_meta_box_nonce'], 'abj404_meta_box')) {
                return $_POST['abj404_create_redirect'] === '1';
            }
        }

        // Check for unchecked checkbox in Quick Edit (checkbox not in POST = unchecked)
        if (isset($_POST['abj404_quick_edit_nonce']) &&
            wp_verify_nonce($_POST['abj404_quick_edit_nonce'], 'abj404_quick_edit') &&
            !isset($_POST['abj404_create_redirect'])) {
            return false;
        }

        // Check for unchecked checkbox in Classic Editor (checkbox not in POST = unchecked)
        if (isset($_POST['abj404_meta_box_nonce']) &&
            wp_verify_nonce($_POST['abj404_meta_box_nonce'], 'abj404_meta_box') &&
            !isset($_POST['abj404_create_redirect'])) {
            return false;
        }

        // Check Gutenberg post meta
        $meta = get_post_meta($post_id, '_abj404_create_redirect', true);
        if ($meta !== '' && $meta !== null) {
            // Clear the meta after reading (one-time use per edit session)
            delete_post_meta($post_id, '_abj404_create_redirect');
            return $meta === '1';
        }

        // Fall back to global setting
        return @$options['auto_slugs'] == '1';
    }
}
