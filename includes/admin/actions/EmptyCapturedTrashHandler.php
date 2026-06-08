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
        // Surgical reconciliation of view_done with the source-table
        // mutation. doEmptyTrash() just deleted rows from
        // wp_abj404_redirects, but view_done is a staged-rebuild
        // snapshot: it still holds the pre-delete rows until S11 swaps a
        // fresh buffer in, and the inline rebuildViewDoneInBackground
        // pipeline yields per-stage on time pressure so it rarely
        // completes S1..S11 in the same request. Subsequent admin GETs
        // then serve the stale snapshot and the trashed URLs re-appear
        // in the table.
        //
        // syncViewDoneWithSource() closes the visibility gap on the
        // entire write surface (INSERT IGNORE + DELETE LEFT JOIN +
        // UPDATE INNER JOIN). For Empty Trash specifically the
        // DELETE-orphan branch does the work; the INSERT IGNORE and
        // UPDATE INNER JOIN are no-ops since this action only removes
        // rows. A normal cron tick will still run later and re-derive
        // view_done from scratch; this surgical reconciliation just
        // closes the serve-stale-data window between doEmptyTrash() and
        // that tick.
        $viewBuild = $this->parent->getViewBuild();
        $viewBuild->invalidateViewDoneAndScheduleRebuild();
        $viewBuild->syncViewDoneWithSource();
        return __('All trashed URLs have been deleted!', '404-solution');
    }
}
