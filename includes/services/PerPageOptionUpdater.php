<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clamps and persists the admin table rows-per-page preference.
 *
 * Shared by the admin action facade and AJAX pagination paths so every caller
 * applies the same min/max bounds before writing the options row.
 */
class ABJ_404_Solution_PerPageOptionUpdater {

    /**
     * @param int $rows Requested number of rows per page.
     * @return void
     */
    public function update(int $rows): void {
        $showRows = max($rows, ABJ404_OPTION_MIN_PERPAGE);
        $showRows = min($showRows, ABJ404_OPTION_MAX_PERPAGE);

        $options = abj_service('options_repository')->getOptions();
        $options['perpage'] = $showRows;
        abj_service('options_repository')->updateOptions($options);
    }
}
