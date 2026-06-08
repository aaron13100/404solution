<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hits-table lifecycle hook on the read side: trigger the rebuild policy
 * check on each read so a stale hits table gets refreshed before it's
 * served.
 */
interface ABJ_404_Solution_ViewHitsLifecycleInterface {

    /** @return void */
    public function maybeUpdateRedirectsForViewHitsTable(): void;
}
