<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds SQL for live redirects-table admin view queries.
 */
class ABJ_404_Solution_RedirectsForViewSqlBuilder {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_LogsRepository */
    private $logsRepo;

    /** @var ABJ_404_Solution_ViewQueryPolicy */
    private $policy;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_ViewReadServiceInterface|null */
    private $host;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions $f
     * @param ABJ_404_Solution_LogsRepository $logsRepo
     * @param ABJ_404_Solution_ViewQueryPolicy $policy
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_Functions $f,
        ABJ_404_Solution_LogsRepository $logsRepo,
        ABJ_404_Solution_ViewQueryPolicy $policy,
        $logger
    ) {
        $this->dbCore = $dbCore;
        $this->f = $f;
        $this->logsRepo = $logsRepo;
        $this->policy = $policy;
        $this->logger = $logger;
    }

    /**
     * @param ABJ_404_Solution_ViewReadServiceInterface $host
     * @return void
     */
    public function setHost(ABJ_404_Solution_ViewReadServiceInterface $host): void {
        $this->host = $host;
    }

    /** @return string */
    public function buildHighImpactCapturedCountQuery(): string {
        $query = "SELECT COUNT(*) AS cnt
            FROM {wp_abj404_redirects} r
            INNER JOIN {wp_abj404_logs_hits} h
                ON h.requested_url =
                   COALESCE(r.canonical_url, CONCAT('/', TRIM(BOTH '/' FROM r.url)))
               AND BINARY h.requested_url = BINARY
                   COALESCE(r.canonical_url, CONCAT('/', TRIM(BOTH '/' FROM r.url)))
            WHERE r.status = " . ABJ404_STATUS_CAPTURED . " AND r.disabled = 0
              AND h.logshits >= 3";
        return $this->dbCore->doTableNameReplacements($query);
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    public function getOptimizedRedirectsForViewCountQuery(string $sub, array $tableOptions): string {
        $statusTypes = $this->policy->resolveStatusTypeList($sub, $tableOptions);
        $trashValue = $this->policy->resolveTrashValue($tableOptions);
        $scoreRangeClause = $this->policy->buildScoreRangeClause($tableOptions, 'wp_abj404_redirects.'); // allow-prefix-literal: SQL alias bound by FROM clause

        $query = "SELECT COUNT(*) AS count\n"
            . "FROM {wp_abj404_redirects} wp_abj404_redirects\n" // allow-prefix-literal: second token is the SQL alias name, not a table reference
            . "WHERE 1 and status IN (" . $statusTypes . ") AND disabled = " . intval($trashValue) . "\n"
            . $scoreRangeClause;

        return $this->dbCore->doTableNameReplacements($query);
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param bool $queryAllRowsAtOnce
     * @param int $limitStart
     * @param int $limitEnd
     * @param bool $selectCountOnly
     * @return string
     */
    public function getRedirectsForViewQuery($sub, $tableOptions, $queryAllRowsAtOnce, $limitStart, $limitEnd, $selectCountOnly) {
        $selectCountReplacement = '/* selecting data as usual */';
        if ($selectCountOnly) {
            $selectCountReplacement = "\n /*+ SET_VAR(max_join_size=18446744073709551615) */\n"
                . "count(*) as count\n /* only selecting for count";
        }

        $logsParts = $this->resolveLogsTableParts((bool)$queryAllRowsAtOnce, (bool)$selectCountOnly);
        $filterMarkers = $this->resolveFilterTextMarkers((string)$sub, $tableOptions);

        $query = $this->applyRedirectsTemplateReplacements(
            ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/../sql/getRedirectsForView.sql"),
            array(
                'selectCountReplacement' => $selectCountReplacement,
                'statusTypes' => $this->policy->resolveStatusTypeList((string)$sub, $tableOptions),
                'orderByString' => $selectCountOnly ? '' : $this->buildOrderByString($tableOptions),
                'limitStart' => (string)$limitStart,
                'limitEnd' => (string)$limitEnd,
                'searchFilterForRedirectsExists' => $filterMarkers['redirects'],
                'searchFilterForCapturedExists' => $filterMarkers['captured'],
                'filterText' => $this->policy->sanitizeFilterText($filterMarkers['rawFilterText']),
                'wpdbCollate' => $this->policy->resolveCollation($tableOptions),
                'logsTableColumns' => $logsParts['columns'],
                'logsTableJoin' => $logsParts['join'],
                'trashValue' => (string)$this->policy->resolveTrashValue($tableOptions),
                'scoreRangeClause' => $this->policy->buildScoreRangeClause($tableOptions, 'wp_abj404_redirects.'), // allow-prefix-literal: SQL alias bound by FROM clause
            )
        );
        $query = $this->dbCore->doTableNameReplacements($query);
        $query = $this->applyTranslations($query, $tableOptions);
        return $this->f->doNormalReplacements($query);
    }

    /**
     * @return array{columns: string, join: string}
     */
    private function resolveLogsTableParts(bool $queryAllRowsAtOnce, bool $selectCountOnly): array {
        $logsTableColumns = "null as logshits, \n null as logsid, \n null as last_used, \n";
        $logsTableJoin = '';
        if (!$queryAllRowsAtOnce || $selectCountOnly) {
            return array('columns' => $logsTableColumns, 'join' => $logsTableJoin);
        }

        if ($this->host !== null) {
            $this->host->maybeUpdateRedirectsForViewHitsTable();
        }
        if (!$this->logsRepo->logsHitsTableExists()) {
            $this->logger->debugMessage("logs_hits table not available, falling back to null columns");
            return array('columns' => $logsTableColumns, 'join' => $logsTableJoin);
        }

        $logsTableColumns = "logstable.logshits as logshits, \n"
            . "logstable.logsid, \n"
            . "logstable.last_used, \n";
        $logsTableJoin = "  LEFT OUTER JOIN {wp_abj404_logs_hits} logstable \n "
            . "  on binary logstable.requested_url = "
            . "binary COALESCE(wp_abj404_redirects.canonical_url, " // allow-prefix-literal: SQL alias
            . "concat('/', trim(both '/' from wp_abj404_redirects.url))) \n "; // allow-prefix-literal: SQL alias
        return array('columns' => $logsTableColumns, 'join' => $logsTableJoin);
    }

    /** @param array<string, mixed> $tableOptions @return string */
    private function buildOrderByString(array $tableOptions): string {
        $rawOrderBy = $tableOptions['orderby'] ?? '';
        $orderBy = $this->f->strtolower(is_string($rawOrderBy) ? $rawOrderBy : '');
        if ($orderBy == "final_dest") {
            $orderBy = "case when post_title is null then 1 else 0 end asc, post_title";
        } else {
            $orderBy = preg_replace('/[^a-zA-Z_]/', '', trim($orderBy));
        }
        $order = $this->policy->resolveOrderDirection($tableOptions);
        return "order by published_status asc, " . $orderBy . " " . $order
            . ", wp_abj404_redirects.url ASC, wp_abj404_redirects.id " . $order; // allow-prefix-literal: SQL alias bound by `FROM {wp_abj404_redirects} wp_abj404_redirects`
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array{redirects: string, captured: string, rawFilterText: string}
     */
    private function resolveFilterTextMarkers(string $sub, array $tableOptions): array {
        $searchFilterForRedirectsExists = "no redirects fiter text found";
        $searchFilterForCapturedExists = "no captured 404s filter text found";
        $rawFilterText = is_string($tableOptions['filterText'] ?? null) ? $tableOptions['filterText'] : '';
        if ($rawFilterText != '') {
            if ($sub == 'abj404_redirects') {
                $searchFilterForRedirectsExists = ' filter text enabled */';
            } else if ($sub == 'abj404_captured') {
                $searchFilterForCapturedExists = ' filter text enabled */';
            } else {
                $searchFilterForRedirectsExists = ' filter text enabled */';
                $searchFilterForCapturedExists = ' filter text enabled */';
            }
        }
        return array(
            'redirects' => $searchFilterForRedirectsExists,
            'captured' => $searchFilterForCapturedExists,
            'rawFilterText' => $rawFilterText,
        );
    }

    /**
     * @param string $query
     * @param array<string, string> $values
     * @return string
     */
    private function applyRedirectsTemplateReplacements(string $query, array $values): string {
        $query = $this->f->str_replace('{selecting-for-count-true-false}', $values['selectCountReplacement'], $query);
        $query = $this->f->str_replace('{statusTypes}', $values['statusTypes'], $query);
        $query = $this->f->str_replace('{orderByString}', $values['orderByString'], $query);
        $query = $this->f->str_replace('{limitStart}', $values['limitStart'], $query);
        $query = $this->f->str_replace('{limitEnd}', $values['limitEnd'], $query);
        $query = $this->f->str_replace('{searchFilterForRedirectsExists}', $values['searchFilterForRedirectsExists'], $query);
        $query = $this->f->str_replace('{searchFilterForCapturedExists}', $values['searchFilterForCapturedExists'], $query);
        $query = $this->f->str_replace('{filterText}', $values['filterText'], $query);
        $query = $this->f->str_replace('{wpdb_collate}', $values['wpdbCollate'], $query);
        $query = $this->f->str_replace('{logsTableColumns}', $values['logsTableColumns'], $query);
        $query = $this->f->str_replace('{logsTableJoin}', $values['logsTableJoin'], $query);
        $query = $this->f->str_replace('{trashValue}', $values['trashValue'], $query);
        return $this->f->str_replace('{scoreRangeClause}', $values['scoreRangeClause'], $query);
    }

    /**
     * @param string $query
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    private function applyTranslations(string $query, array $tableOptions): string {
        if (array_key_exists('translations', $tableOptions) && is_array($tableOptions['translations'])) {
            $keys = array_keys($tableOptions['translations']);
            $values = array_map(static function($value): string {
                return is_scalar($value) ? (string)$value : '';
            }, array_values($tableOptions['translations']));
            /** @var array<int, string> $keys */
            $query = $this->f->str_replace($keys, $values, $query);
        }
        return $query;
    }
}
