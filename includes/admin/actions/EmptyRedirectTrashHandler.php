<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles action 'emptyRedirectTrash': permanently deletes all trashed rows
 * from the redirects table (status in $abj404_redirect_types) via
 * PluginLogicAdminActions::doEmptyTrash(), then invalidates the view cache.
 */
class ABJ_404_Solution_EmptyRedirectTrashHandler implements ABJ_404_Solution_AdminActionHandlerInterface {

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
        $this->parent->doEmptyTrash('abj404_redirects');
        // Surgical orphan-cleanup of view_done. See the matching comment in
        // EmptyCapturedTrashHandler::handle() for the full rationale; tl;dr
        // the staged-rebuild pipeline does not reliably complete S1..S11
        // inside this request, so view_done holds the pre-delete snapshot
        // until the cron tick catches up and stale trash rows reappear on
        // the next admin GET. The orphan-DELETE below removes only the
        // view_done rows whose source row was just deleted, restoring the
        // invariant immediately.
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
