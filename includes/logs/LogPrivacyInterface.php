<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GDPR / privacy operations over logsv2 rows keyed by lookup value (the
 * hashed user fingerprint): paged ID and row reads for export, plus
 * bulk anonymization.
 */
interface ABJ_404_Solution_LogPrivacyInterface {

    /**
     * @param string $lkupValue
     * @param int $page
     * @param int $perPage
     * @return int[]
     */
    public function getLogsv2IdsForLookupValue($lkupValue, $page = 1, $perPage = 100);

    /**
     * @param string $lkupValue
     * @param int $page
     * @param int $perPage
     * @return array<int, array<string, mixed>>
     */
    public function getLogsv2RowsForLookupValue($lkupValue, $page = 1, $perPage = 50);

    /**
     * @param int[] $ids
     * @return bool
     */
    public function anonymizeLogsv2RowsByIds($ids);
}
