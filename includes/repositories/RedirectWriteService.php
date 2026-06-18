<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../redirects/RedirectCanonicalUrl.php';

/**
 * Application service for redirect row mutations and purge decisions.
 *
 * This keeps write validation, status-count invalidation, and regex-cache
 * invalidation out of the frontend redirect lookup repository.
 */
class ABJ_404_Solution_RedirectWriteService {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_RedirectRegexCacheStore */
    private $regexCacheStore;

    /** @var ABJ_404_Solution_PluginLogicUrlNormalization|null */
    private $urlNormalization;

    /** @var ABJ_404_Solution_RedirectsDenormMaintenanceService|null Memoized Step 3c maintenance service. */
    private $denormMaintenance = null;

    /**
     * Per-instance memoized cache of column-existence probes against the
     * redirects table.
     *
     * @var array<string, bool>
     */
    private $redirectsTableColumnsCache = array();

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_Logging|null $logging
     * @param ABJ_404_Solution_RedirectRegexCacheStore|null $regexCacheStore
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        $functions = null,
        $logging = null,
        $regexCacheStore = null,
        ?ABJ_404_Solution_PluginLogicUrlNormalization $urlNormalization = null
    ) {
        $this->dbCore = $dbCore;
        $this->f = $functions !== null ? $functions : abj_service('functions');
        $this->logger = $logging !== null ? $logging : abj_service('logging');
        $this->regexCacheStore = $regexCacheStore !== null ? $regexCacheStore : new ABJ_404_Solution_RedirectRegexCacheStore();
        $this->urlNormalization = $urlNormalization;
    }

    /** @return ABJ_404_Solution_PluginLogicUrlNormalization */
    private function urlNormalization() {
        if ($this->urlNormalization !== null) {
            return $this->urlNormalization;
        }
        return abj_service('plugin_logic')->urlNormalization();
    }

    /** @param int|string $id */
    public function deleteRedirect($id): void {
        $cleanedID = absint(sanitize_text_field((string)$id));

        if (is_numeric($id)) {
            $query = "delete from {wp_abj404_redirects} where id = %d";
            $this->dbCore->queryAndGetResults($query, array('query_params' => array($cleanedID)));
            $this->invalidateRedirectMutationCaches();
        }
    }

    public function setupRedirect(ABJ_404_Solution_RedirectSpec $spec): int {
        $fromURL = $spec->getFromURL();
        $status = $spec->getStatus();
        $type = $spec->getType();
        $finalDest = $spec->getFinalDest();
        $code = $spec->getCode();
        $disabled = $spec->getDisabled();
        $engine = $spec->getEngine();
        $score = $spec->getScore();

        if (!is_numeric($type)) {
            $this->logger->errorMessage("Wrong data type for redirect. TYPE is non-numeric. From: " .
                    esc_url($fromURL) . " to: " . esc_url($finalDest) . ", Type: " . esc_html((string)$type) . ", Status: " . $status);
        } else if (!is_numeric($status)) {
            $this->logger->errorMessage("Wrong data type for redirect. STATUS is non-numeric. From: " .
                    esc_url($fromURL) . " to: " . esc_url($finalDest) . ", Type: " . esc_html((string)$type) . ", Status: " . $status);
        }

        $statusAsInt = is_numeric($status) ? absint($status) : -1;
        $typeAsInt = is_numeric($type) ? absint($type) : -1;

        if ($statusAsInt === ABJ404_STATUS_AUTO &&
                !$this->isValidAutomaticRedirectDestination($typeAsInt, $finalDest)) {
            $this->logger->debugMessage("Skipping automatic redirect with invalid destination. " .
                    "From: " . esc_url($fromURL) . ", Dest: " . esc_html((string)$finalDest) .
                    ", Type: " . esc_html((string)$type) . ", Status: " . esc_html((string)$status));
            return 0;
        }

        $insertId = 0;

        if (!abj_service('request_context')->ignore_doprocess) {
            $now = abj_clock()->now();
            $redirectsTable = $this->dbCore->doTableNameReplacements("{wp_abj404_redirects}");
            $fromURL = $this->urlNormalization()->normalizeToRelativePath($fromURL);

            $insertData = array(
                'url' => $fromURL,
                'status' => $status,
                'type' => $type,
                'final_dest' => $finalDest,
                'code' => $code,
                'disabled' => $disabled,
                'timestamp' => $now,
            );
            $insertFormats = array('%s', '%d', '%d', '%s', '%d', '%d', '%d');

            if ($this->redirectsTableHasColumn('canonical_url')) {
                $insertData['canonical_url'] = ABJ_404_Solution_RedirectCanonicalUrl::compute($fromURL);
                $insertFormats[] = '%s';
            }
            if ($engine !== null) {
                $insertData['engine'] = substr((string)$engine, 0, 64);
                $insertFormats[] = '%s';
            }
            if ($score !== null) {
                $insertData['score'] = round((float)$score, 2);
                $insertFormats[] = '%f';
            }

            $insertSql = "INSERT INTO `" . $redirectsTable . "` (`" .
                implode('`, `', array_keys($insertData)) . "`) VALUES (" .
                implode(', ', $insertFormats) . ")";
            $insertResult = $this->dbCore->queryAndGetResults($insertSql, array(
                'query_params' => array_values($insertData),
            ));
            $insertIdRaw = $insertResult['insert_id'] ?? 0;
            $insertId = is_scalar($insertIdRaw) ? (int)$insertIdRaw : 0;

            // A captured-404 insert is the high-frequency frontend path: on a
            // busy site it fires continuously and only changes the captured +
            // high-impact counts. Use the debounced, captured-scoped invalidation
            // so the SUM(CASE) status-count aggregate is not cold-recomputed on
            // every admin load and the unrelated redirect-count cache stays warm
            // (report.md Finding 4). Admin-driven inserts (manual / auto / regex,
            // e.g. Add Redirect or a slug-change auto-redirect) are low-frequency
            // and keep the immediate full invalidation so the admin sees the
            // count change at once.
            if ($statusAsInt === ABJ404_STATUS_CAPTURED) {
                ABJ_404_Solution_ViewCacheInvalidator::invalidateCapturedStatusCountsCacheDebounced();
            } else {
                abj_service('view_read_service')->invalidateStatusCountsCache();
            }
            if ($status == ABJ404_STATUS_REGEX) {
                $this->regexCacheStore->clear();
            }

            // Step 3c: keep the new row's denorm display columns (dest_for_view
            // / published_status) current so an off-page sort/filter sees fresh
            // values immediately, not only after the nightly reconcile.
            if ($insertId > 0) {
                $this->recomputeDenormColumns(array($insertId));
            }
        }

        return $insertId;
    }

    /**
     * @param array<int, int|string> $types
     * @return array{status: string, rows_affected: int, redirect_types: array<int, int>}
     */
    public function deleteSpecifiedRedirects(array $types, string $purgeType): array {
        $result = array(
            'status' => 'noop',
            'rows_affected' => 0,
            'redirect_types' => array(),
        );

        if ($purgeType != 'abj404_logs' && $purgeType != 'abj404_redirects') {
            $this->logger->debugMessage("Error: An invalid purge type was selected. Type: " .
                    wp_kses_post((string)json_encode($purgeType)));
            $result['status'] = 'invalid_purge_type';
            return $result;
        }

        $redirectTypes = array();
        foreach ($types as $aType) {
            if (('' . $aType != ABJ404_TYPE_HOME) && ('' . $aType != ABJ404_TYPE_404_DISPLAYED)) {
                array_push($redirectTypes, absint($aType));
            }
        }

        if (empty($redirectTypes)) {
            $this->logger->debugMessage("Error: No valid redirect types were selected. Types: " .
                    wp_kses_post((string)json_encode($redirectTypes)));
            $result['status'] = 'no_valid_types';
            return $result;
        }

        array_push($redirectTypes, 0);

        $redirectTypes = array_map('absint', $redirectTypes);
        $result['redirect_types'] = $redirectTypes;

        if ($purgeType == 'abj404_logs') {
            $result['status'] = 'logs_only';
            return $result;
        }

        $typesForSQL = implode(',', $redirectTypes);

        $query = "update {wp_abj404_redirects} set disabled = 1 where status in (" . $typesForSQL . ")";
        $purgeResult = $this->dbCore->queryAndGetResults($query);
        $rowsAffectedRaw = $purgeResult['rows_affected'] ?? 0;
        $redirectCount = is_scalar($rowsAffectedRaw) ? (int)$rowsAffectedRaw : 0;

        $this->invalidateRedirectMutationCaches();

        $result['status'] = 'redirects_purged';
        $result['rows_affected'] = $redirectCount;
        return $result;
    }

    public function updateRedirect(ABJ_404_Solution_RedirectUpdate $update): string {
        $type = $update->getType();
        $idForUpdate = $update->getId();
        if (($type < 0) || ($idForUpdate <= 0)) {
            $this->logger->errorMessage("Bad data passed for update redirect request. Type: " .
                esc_html((string)$type) . ", Dest: " . esc_html($update->getDestination()) .
                ", ID(s): " . esc_html((string)$idForUpdate));
            return 'bad_update_request';
        }

        $startTs = $update->getStartTs();
        $endTs = $update->getEndTs();

        $redirectsTable = $this->dbCore->doTableNameReplacements("{wp_abj404_redirects}");

        $updateData = array(
            'url' => $update->getFromUrl(),
            'status' => $update->getStatusType(),
            'type' => absint($type),
            'final_dest' => $update->getDestination(),
            'code' => esc_attr($update->getCode()),
        );
        $updateFormats = array('%s', '%d', '%d', '%s', '%d');

        if ($startTs !== null) {
            $updateData['start_ts'] = (int)$startTs;
            $updateFormats[] = '%d';
        }
        if ($endTs !== null) {
            $updateData['end_ts'] = (int)$endTs;
            $updateFormats[] = '%d';
        }

        $setFragments = array();
        $idx = 0;
        foreach ($updateData as $col => $unusedValue) {
            $format = isset($updateFormats[$idx]) ? $updateFormats[$idx] : '%s';
            $setFragments[] = '`' . $col . '` = ' . $format;
            $idx++;
        }
        $updateSql = "UPDATE `" . $redirectsTable . "` SET " . implode(', ', $setFragments) .
            " WHERE `id` = %d";
        $updateParams = array_values($updateData);
        $updateParams[] = absint($idForUpdate);
        $this->dbCore->queryAndGetResults($updateSql, array('query_params' => $updateParams));

        $nullParts = [];
        if ($startTs === null) {
            $nullParts[] = '`start_ts` = NULL';
        }
        if ($endTs === null) {
            $nullParts[] = '`end_ts` = NULL';
        }
        if (!empty($nullParts)) {
            $nullSql = "UPDATE `" . $redirectsTable . "` SET " . implode(', ', $nullParts) .
                " WHERE id = %d";
            $this->dbCore->queryAndGetResults($nullSql, array('query_params' => array(absint($idForUpdate))));
        }

        $this->invalidateRedirectMutationCaches();

        $this->moveRedirectsToTrash(absint($idForUpdate), 0);

        // Step 3c: an edit can change the destination type/target, so recompute
        // the row's denorm display columns from the new final_dest.
        $this->recomputeDenormColumns(array(absint($idForUpdate)));

        return '';
    }

    /**
     * @param array<int, int|string> $ids
     * @return array<int, array<string, mixed>>
     */
    public function getRedirectsByIDs($ids): array {
        if (!is_array($ids) || empty($ids)) {
            return array();
        }
        $validids = array_map('absint', $ids);
        $multipleIds = implode(',', $validids);

        $query = "select id, url, type, status, final_dest, code, COALESCE(engine, '') as engine, start_ts, end_ts from {wp_abj404_redirects} " .
                "where id in (" . $multipleIds . ")";
        $result = $this->dbCore->queryAndGetResults($query);
        $rawRows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();

        $rows = array();
        foreach ($rawRows as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * @param int $id
     * @param string $newstatus
     */
    public function updateRedirectTypeStatus($id, $newstatus): string {
        $query = "update {wp_abj404_redirects} set status = %s where id = %d";
        $result = $this->dbCore->queryAndGetResults($query, array(
            'query_params' => array($newstatus, absint($id))
        ));

        $this->invalidateRedirectMutationCaches();

        return is_string($result['last_error']) ? $result['last_error'] : '';
    }

    /**
     * @param int|string $id
     * @param int|string $trash
     */
    public function moveRedirectsToTrash($id, $trash): string {
        $message = "";
        $hadError = false;
        if ($this->f->regexMatch('[0-9]+', '' . $id)) {
            $redirectsTable = $this->dbCore->doTableNameReplacements("{wp_abj404_redirects}");
            $updateResult = $this->dbCore->queryAndGetResults(
                "UPDATE `" . $redirectsTable . "` SET disabled = %d WHERE id = %d",
                array('query_params' => array(absint(esc_html((string)$trash)), absint($id)))
            );
            $updateError = isset($updateResult['last_error']) && is_string($updateResult['last_error']) ? $updateResult['last_error'] : '';
            $hadError = $updateError !== '';

            $this->invalidateRedirectMutationCaches();
        } else {
            $hadError = true;
        }
        if ($hadError) {
            $message = __('Error: Unknown Database Error!', '404-solution');
        }
        return $message;
    }

    /**
     * @param int $type
     * @param mixed $finalDest
     */
    private function isValidAutomaticRedirectDestination($type, $finalDest): bool {
        $destId = absint(is_scalar($finalDest) ? $finalDest : 0);

        if ($type === ABJ404_TYPE_POST) {
            if ($destId <= 0) {
                return false;
            }
            if (!function_exists('get_post')) {
                return true;
            }
            $ref = ABJ_404_Solution_PostRef::fromWpPost(get_post($destId));
            if ($ref === null) {
                return false;
            }
            return $ref->isPublished();
        }

        if ($type === ABJ404_TYPE_CAT || $type === ABJ404_TYPE_TAG) {
            if ($destId <= 0) {
                return false;
            }
            if (!function_exists('get_term')) {
                return true;
            }
            $taxonomy = ($type === ABJ404_TYPE_CAT) ? 'category' : 'post_tag';
            $term = get_term($destId, $taxonomy);
            if ($term === null || is_wp_error($term)) {
                return false;
            }
            return is_object($term);
        }

        if ($type === ABJ404_TYPE_HOME) {
            return true;
        }

        return false;
    }

    private function redirectsTableHasColumn(string $columnName): bool {
        $key = strtolower($columnName);
        if ($this->redirectsTableColumnsCache !== array()) {
            return isset($this->redirectsTableColumnsCache[$key]);
        }
        global $wpdb;
        if (!isset($wpdb)) {
            return true;
        }
        $redirectsTable = $this->dbCore->doTableNameReplacements("{wp_abj404_redirects}");
        // @utf8-audit: opt-out - redirectsTableHasColumn probes an internally resolved plugin table name.
        $result = $this->dbCore->queryAndGetResults(
            "SHOW COLUMNS FROM `" . esc_sql($redirectsTable) . "`",
            array('log_errors' => false, 'log_too_slow' => false)
        );
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if ($rows === array()) {
            return true;
        }
        $primed = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $field => $value) {
                if (strtolower((string)$field) !== 'field') {
                    continue;
                }
                if (!is_scalar($value)) {
                    continue;
                }
                $primed[strtolower((string)$value)] = true;
            }
        }
        if ($primed === array()) {
            return true;
        }
        $this->redirectsTableColumnsCache = $primed;
        return isset($this->redirectsTableColumnsCache[$key]);
    }

    private function invalidateRedirectMutationCaches(): void {
        abj_service('view_read_service')->invalidateStatusCountsCache();
        $this->regexCacheStore->clear();
    }

    /**
     * Recompute the dest_for_view / published_status denorm columns for the
     * given redirect ids via the Step 3c maintenance service, built from this
     * service's own injected db_core + logger. The maintenance write degrades
     * gracefully on a schema-drifted / read-only host, so no extra guard is
     * needed here.
     *
     * @param array<int, int> $ids
     * @return void
     */
    private function recomputeDenormColumns(array $ids): void {
        if ($this->denormMaintenance === null) {
            $this->denormMaintenance = new ABJ_404_Solution_RedirectsDenormMaintenanceService(
                $this->dbCore,
                $this->logger
            );
        }
        $this->denormMaintenance->recomputeByRedirectIds($ids);
    }
}
