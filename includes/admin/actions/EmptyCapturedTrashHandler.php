<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles action 'emptyCapturedTrash': permanently deletes all trashed rows
 * from the captured-URL queue (status in $abj404_captured_types) via
 * PluginLogicAdminActions::doEmptyTrash(), then invalidates the view cache.
 */
class ABJ_404_Solution_EmptyCapturedTrashHandler implements ABJ_404_Solution_AdminActionHandlerInterface {

    /** @var ABJ_404_Solution_PluginLogicAdminActions */
    private $parent;

    public function __construct(ABJ_404_Solution_PluginLogicAdminActions $parent) {
        $this->parent = $parent;
    }

    public function nonceAction(): string {
        return 'abj404_bulkProcess';
    }

    public function nonceArg(): string {
        return '_wpnonce';
    }

    public function useCheckAdminReferer(): bool {
        return true;
    }

    public function handle(string $action, string &$sub): string {
        $this->parent->doEmptyTrash('abj404_captured');
        // Surgical orphan-cleanup of view_done. doEmptyTrash() just deleted
        // rows from wp_abj404_redirects, but view_done is a staged-rebuild
        // snapshot: it still holds the pre-delete rows until S11 swaps a
        // fresh buffer in. The bare invalidate+rebuild combo (or even
        // advanceViewBuildOnce(true) in a loop) does NOT reliably complete
        // the S1..S11 pipeline inside this request -- advanceViewBuildOnce
        // returns status='ready' as soon as view_done has ANY rows
        // (viewDoneIsServeable), even when those rows are stale, so the
        // loop exits before the new buffer has been swapped in. Subsequent
        // admin GETs then serve the stale snapshot and the trashed URLs
        // re-appear in the table.
        //
        // The orphan-DELETE below removes only view_done rows whose source
        // (wp_abj404_redirects.id) no longer exists, which is exactly the
        // set Empty Trash just deleted. It restores the view_done <-> source
        // invariant immediately, without waiting for the cron-driven
        // rebuild to catch up. A normal cron tick will still run later and
        // re-derive view_done from scratch; this DELETE just closes the
        // serve-stale-data window between doEmptyTrash() and that tick.
        $viewBuild = $this->parent->getViewBuild();
        $viewBuild->invalidateViewDoneAndScheduleRebuild();
        $this->parent->getDbCore()->queryAndGetResults(
            "DELETE vd FROM {wp_abj404_view_done} vd"
            . " LEFT JOIN {wp_abj404_redirects} r ON vd.id = r.id"
            . " WHERE r.id IS NULL",
            array('log_errors' => false)
        );
        return __('All trashed URLs have been deleted!', '404-solution');
    }
}
