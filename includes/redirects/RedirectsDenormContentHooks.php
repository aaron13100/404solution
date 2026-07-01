<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: exercised by RedirectsDenormMaintenanceIntegrationTest

/**
 * WordPress content-change hooks that keep the redirects denorm columns fresh
 * (Denorm Step 3c, i461).
 *
 * When a post or term that a redirect targets changes, the redirect's
 * dest_for_view + published_status (the resolved destination label and publish
 * state) go stale. This class subscribes to the relevant core hooks, extracts
 * the changed object id, and asks
 * {@see ABJ_404_Solution_RedirectsDenormMaintenanceService} to recompute the
 * affected redirect rows via a final_dest reverse lookup.
 *
 * Hook subscriptions:
 *   - save_post              -> a post's title or status may have changed.
 *   - transition_post_status -> publish <-> draft/pending/trash flips
 *                               published_status.
 *   - deleted_post           -> a targeted post is gone (dest goes broken).
 *   - edited_term            -> a category/tag name change updates dest_for_view.
 *
 * This is the integration/controller layer: it does WordPress-specific argument
 * unpacking only and delegates all data access to the maintenance service. The
 * maintenance service is resolved lazily from the container (so the hook
 * registration does not pin a stale instance) but may be injected for testing.
 * Diagnostic logging lives in the maintenance service (the layer that issues
 * the SQL); this controller holds no logger of its own.
 */
class ABJ_404_Solution_RedirectsDenormContentHooks {

    /** @var ABJ_404_Solution_RedirectsDenormMaintenanceService|null */
    private $maintenance;

    /**
     * Post IDs whose denorm recompute already ran in the current request.
     * WordPress fires save_post 2-4 times per save; this prevents the
     * final_dest reverse lookup + denorm UPDATE from running that many times
     * for one save (canonical d1a315e6 SlugChangeHandler shape).
     * @var array<int, bool>
     */
    private static $processedPosts = [];

    /**
     * @param ABJ_404_Solution_RedirectsDenormMaintenanceService|null $maintenance
     */
    public function __construct($maintenance = null) {
        $this->maintenance = $maintenance;
    }

    /**
     * Register the content-change hooks. Wired from Loader.php in all contexts
     * (admin, WP-CLI, REST) because posts and terms change outside wp-admin too.
     *
     * @return void
     */
    public static function init(): void {
        $me = new self();
        add_action('save_post', array($me, 'onSavePost'), 10, 1);
        add_action('transition_post_status', array($me, 'onTransitionPostStatus'), 10, 3);
        add_action('deleted_post', array($me, 'onDeletedPost'), 10, 1);
        add_action('edited_term', array($me, 'onEditedTerm'), 10, 1);
    }

    /** @return ABJ_404_Solution_RedirectsDenormMaintenanceService */
    private function maintenance() {
        if ($this->maintenance === null) {
            $this->maintenance = new ABJ_404_Solution_RedirectsDenormMaintenanceService(
                abj_service('db_core'),
                abj_service('logging')
            );
        }
        return $this->maintenance;
    }

    /**
     * save_post: the post may have a new title or status. Skip revisions and
     * autosaves -- they are not the live object a redirect targets, and they
     * fire save_post with their own ids.
     *
     * @param int|string $postId
     * @return void
     */
    public function onSavePost($postId): void {
        $id = is_scalar($postId) ? (int)$postId : 0;
        if ($id <= 0) {
            return;
        }
        if (function_exists('wp_is_post_revision') && wp_is_post_revision($id)) {
            return;
        }
        if (function_exists('wp_is_post_autosave') && wp_is_post_autosave($id)) {
            return;
        }
        // Request-level dedup: save_post fires 2-4 times per save.
        if (isset(self::$processedPosts[$id])) {
            return;
        }
        self::$processedPosts[$id] = true;
        $this->safelyRecompute(function () use ($id) {
            $this->maintenance()->recomputeForChangedPost($id);
        }, 'onSavePost(post=' . $id . ')');
    }

    /**
     * transition_post_status: a publish <-> unpublish flip changes
     * published_status for redirects targeting this post. Only acts on a real
     * status change (skips the no-op same-status notification WordPress also
     * fires).
     *
     * @param string $newStatus
     * @param string $oldStatus
     * @param \WP_Post|mixed $post
     * @return void
     */
    public function onTransitionPostStatus($newStatus, $oldStatus, $post): void {
        if ($newStatus === $oldStatus) {
            return;
        }
        if (!is_object($post) || !property_exists($post, 'ID')) {
            return;
        }
        $id = is_scalar($post->ID) ? (int)$post->ID : 0;
        if ($id <= 0) {
            return;
        }
        $this->safelyRecompute(function () use ($id) {
            $this->maintenance()->recomputeForChangedPost($id);
        }, 'onTransitionPostStatus(post=' . $id . ')');
    }

    /**
     * deleted_post: a targeted post no longer exists, so its redirects resolve
     * to a broken/empty destination.
     *
     * @param int|string $postId
     * @return void
     */
    public function onDeletedPost($postId): void {
        $id = is_scalar($postId) ? (int)$postId : 0;
        if ($id <= 0) {
            return;
        }
        $this->safelyRecompute(function () use ($id) {
            $this->maintenance()->recomputeForChangedPost($id);
        }, 'onDeletedPost(post=' . $id . ')');
    }

    /**
     * edited_term: a category/tag was renamed, so dest_for_view for redirects
     * targeting it must refresh.
     *
     * @param int|string $termId
     * @return void
     */
    public function onEditedTerm($termId): void {
        $id = is_scalar($termId) ? (int)$termId : 0;
        if ($id <= 0) {
            return;
        }
        $this->safelyRecompute(function () use ($id) {
            $this->maintenance()->recomputeForChangedTerm($id);
        }, 'onEditedTerm(term=' . $id . ')');
    }

    /**
     * Run a denorm-recompute call without letting a failure escape this
     * hook. These callbacks fire on core WordPress content-change hooks
     * (save_post, transition_post_status, ...) registered for every request,
     * including wp-cron.php runs that may also be executing other plugins'
     * scheduled tasks. A transient failure here (most commonly: the plugin's
     * own classmap racing a concurrent self-update, see the VRMU incident in
     * project memory) must not crash the whole cron request out from under
     * unrelated tasks.
     *
     * @param callable $work fn(): void
     * @param string $context Logged on failure, e.g. 'onSavePost(post=123)'.
     * @return void
     */
    private function safelyRecompute(callable $work, string $context): void {
        try {
            $work();
        } catch (\Throwable $e) {
            if (function_exists('abj404_logRuntimeWarning')) {
                abj404_logRuntimeWarning('RedirectsDenormContentHooks: ' . $context . ' failed', $e);
            }
        }
    }
}
