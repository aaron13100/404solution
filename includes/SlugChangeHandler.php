<?php


if (!defined('ABSPATH')) {
    exit;
}

class ABJ_404_Solution_SlugChangeHandler {

    private static $instance = null;

    /**
     * Track post IDs already processed within the current request.
     * WordPress fires save_post multiple times per save; this prevents duplicate redirects.
     * @var array
     */
    private static $processedPosts = [];

    /**
     * Get singleton instance
     * @return ABJ_404_Solution_SlugChangeHandler
     */
    public static function getInstance() {
        if (self::$instance == null) {
            self::$instance = new ABJ_404_Solution_SlugChangeHandler();
        }
        return self::$instance;
    }

    /**
     * Initialize the handler and register WordPress hooks
     */
    static function init() {
        $me = ABJ_404_Solution_SlugChangeHandler::getInstance();
        add_action('save_post', array($me, 'save_postHandler'), 10, 3);
    }

    /** We'll just make sure the permalink gets updated in case it's changed.
     * @global type $abj404dao
     * @param int $post_id The post ID.
     * @param post $post The post object.
     * @param bool $update Whether this is an existing post being updated or not.
     */
    function save_postHandler($post_id, $post, $update) {
        $abj404logging = ABJ_404_Solution_Logging::getInstance();

        // Prevent duplicate processing within same request
        // WordPress fires save_post multiple times per save operation
        if (isset(self::$processedPosts[$post_id])) {
            $abj404logging->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
                ": Already processed post ID " . $post_id . " in this request (skipped).");
            return;
        }

        // Validate $post parameter
        if (!is_object($post) || !isset($post->post_name)) {
            $abj404logging->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
                ": Invalid post object or missing post_name property for post ID " . $post_id . ".");
            return;
        }

        if (!$update) {
            $abj404logging->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
                ": Non-update skipped for post ID " . $post_id . ".");
            return;
        }

        // Check if we should create a redirect (respects per-post override from editor)
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $options = $abj404logic->getOptions();

        // Check for per-post override from Quick Edit, Classic Editor, or Gutenberg
        if (class_exists('ABJ_404_Solution_PostEditorIntegration')) {
            $shouldCreate = ABJ_404_Solution_PostEditorIntegration::shouldCreateRedirect($post_id, $options);
        } else {
            // Fallback to global setting if PostEditorIntegration not loaded
            $shouldCreate = @$options['auto_slugs'] == '1';
        }

        if (!$shouldCreate) {
            $abj404logging->debugMessage(__CLASS__ . "/" . __FUNCTION__ . ": Auto slug redirects off " .
                "or disabled for this post (skipped) (post ID " . $post_id . ").");
            return;
        }

        // Use post_status from $post object instead of database query
        $postStatus = isset($post->post_status) ? $post->post_status : get_post_status($post_id);
        if (!in_array($postStatus, array('publish', 'published'))) {
            $abj404logging->debugMessage(__CLASS__ . "/" . __FUNCTION__ . ": Post status: " .
                $postStatus . " (skipped) (post ID " . $post_id . ").");
            return;
        }

        // get the old slug
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();

        $oldURL = $abj404dao->getPermalinkFromCache($post_id);

        if ($oldURL == null || $oldURL == "") {
            $abj404logging->debugMessage("Couldn't find old slug for updated page. ID " .
                $post_id . ", old URL: " . $oldURL . ", post name: " . $post->post_name .
                ", update: " . $update);
            return;
        }

        $newURL = get_permalink($post);

        // Check if get_permalink returned an error
        if (is_wp_error($newURL)) {
            $abj404logging->debugMessage("Could not get permalink for post (WP_Error). ID: " .
                $post_id . ", error: " . $newURL->get_error_message());
            return;
        }

        if ($newURL === false || $newURL === null || $newURL === '') {
            $abj404logging->debugMessage("Could not get permalink for post (invalid return). ID: " .
                $post_id . ", returned: " . var_export($newURL, true));
            return;
        }

        // Safely parse the old URL
        $oldURLParsed = parse_url($oldURL);
        if ($oldURLParsed === false) {
            $abj404logging->debugMessage("Could not parse old URL (malformed). ID: " .
                $post_id . ", URL: " . $oldURL);
            return;
        }

        if (!isset($oldURLParsed['path']) || $oldURLParsed['path'] === '') {
            $abj404logging->debugMessage("Old URL has no path component. ID: " .
                $post_id . ", URL: " . $oldURL);
            return;
        }

        $oldSlug = $oldURLParsed['path'];

        if ($oldURL == $newURL) {
            $abj404logging->debugMessage("Save post listener: Old and new URL are the same. (Ignored) " .
                "ID: " . $post_id . ", old URL: " . $oldURL . ", old slug: " . $oldSlug .
                ", new slug: " . $post->post_name . ", update: " . $update);

                return;
        }

        // Mark as processed before creating redirect to prevent duplicates
        self::$processedPosts[$post_id] = true;

        // create a redirect from the old to the new.
        $abj404dao->setupRedirect($oldSlug, ABJ404_STATUS_AUTO, ABJ404_TYPE_POST,
                $post_id, $options['default_redirect'] ?? '301', 0);
        $abj404logging->infoMessage("Added automatic redirect after slug change from " .
            $oldURL . ' to ' . $newURL . " for post ID " . $post_id);
    }
}
