<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GDPR privacy operations on logsv2 rows, keyed by lookup value (user name).
 *
 * Three operations: paginated id-lookup for a username, paginated row-lookup
 * for export, and anonymize-by-ids for erasure. The anonymize path overwrites
 * user_ip with the sentinel string '(Anonymized)' and nulls referrer,
 * requested_url_detail, and username.
 *
 * Extracted from LogsRepository under M201. Consumed by Privacy.php via the
 * LogsRepository facade.
 */
class ABJ_404_Solution_LogsPrivacyService {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore) {
        $this->dbCore = $dbCore;
    }

    /**
     * Paginated id-only lookup. Returns logsv2 row ids whose username (via
     * lkup) matches the given value.
     *
     * @param string $lkupValue
     * @param int $page 1-indexed
     * @param int $perPage clamped to [1, 500]
     * @return int[]
     */
    public function getLogsv2IdsForLookupValue($lkupValue, $page = 1, $perPage = 100) {
        $lkupValue = trim($lkupValue);
        if ($lkupValue === '') { return array(); }
        $page = max(1, absint($page));
        $perPage = max(1, min(500, absint($perPage)));
        $offset = ($page - 1) * $perPage;
        $logsTable = $this->dbCore->doTableNameReplacements("{wp_abj404_logsv2}");
        $lookupTable = $this->dbCore->doTableNameReplacements("{wp_abj404_lookup}");
        $sql = "SELECT l.id FROM `{$logsTable}` l INNER JOIN `{$lookupTable}` u ON l.username = u.id WHERE u.lkup_value = %s ORDER BY l.id DESC LIMIT %d OFFSET %d";
        $result = $this->dbCore->queryAndGetResults($sql, array('query_params' => array($lkupValue, $perPage, $offset)));
        if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) { return array(); }
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        $ids = array();
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['id'])) { $ids[] = absint($row['id']); }
        }
        return array_values(array_filter($ids));
    }

    /**
     * Paginated row-lookup for export. Fetches a fixed projection of the
     * logsv2 columns relevant to a GDPR export.
     *
     * @param string $lkupValue
     * @param int $page
     * @param int $perPage
     * @return array<int, array<string, mixed>>
     */
    public function getLogsv2RowsForLookupValue($lkupValue, $page = 1, $perPage = 50) {
        $ids = $this->getLogsv2IdsForLookupValue($lkupValue, $page, $perPage);
        if (empty($ids)) { return array(); }
        $logsTable = $this->dbCore->doTableNameReplacements("{wp_abj404_logsv2}");
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = "SELECT id, timestamp, user_ip, referrer, requested_url, requested_url_detail, dest_url FROM `{$logsTable}` WHERE id IN ({$placeholders}) ORDER BY id DESC";
        $result = $this->dbCore->queryAndGetResults($sql, array('query_params' => $ids));
        if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) { return array(); }
        return is_array($result['rows'] ?? null) ? $result['rows'] : array();
    }

    /**
     * Anonymize logsv2 rows by id (GDPR erasure). Overwrites user_ip with
     * '(Anonymized)' and nulls referrer, requested_url_detail, username.
     *
     * @param int[] $ids
     * @return bool true on success, false on query error
     */
    public function anonymizeLogsv2RowsByIds($ids) {
        if (!is_array($ids) || empty($ids)) { return true; }
        $ids = array_values(array_filter(array_map('absint', $ids)));
        if (empty($ids)) { return true; }
        $logsTable = $this->dbCore->doTableNameReplacements("{wp_abj404_logsv2}");
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = "UPDATE `{$logsTable}` SET user_ip = %s, referrer = NULL, requested_url_detail = NULL, username = NULL WHERE id IN ({$placeholders})";
        $params = array_merge(array('(Anonymized)'), $ids);
        $result = $this->dbCore->queryAndGetResults($sql, array('query_params' => $params));
        if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) { return false; }
        return true;
    }
}
