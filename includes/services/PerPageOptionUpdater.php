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
        $tracer = ABJ_404_Solution_OptionPersistenceTracer::begin();
        try {
            $normalization = static function () use ($rows): int {
                $showRows = max($rows, ABJ404_OPTION_MIN_PERPAGE);
                return min($showRows, ABJ404_OPTION_MAX_PERPAGE);
            };
            $showRows = $tracer === null
                ? $normalization()
                : $tracer->traceOperation('rows_per_page_normalization', $normalization);

            $optionsRead = static function () {
                $repository = abj_service('options_repository');
                return array($repository, $repository->getOptions());
            };
            $resolved = $tracer === null
                ? $optionsRead()
                : $tracer->traceOperation('options_read', $optionsRead);
            $repository = $resolved[0];
            $options = $resolved[1];
            $options['perpage'] = $showRows;
            $repository->updateOptions($options);
        } finally {
            if ($tracer !== null) {
                $tracer->finish();
            }
        }
    }
}
