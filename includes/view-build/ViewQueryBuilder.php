<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Compatibility facade for admin view query construction.
 *
 * Focused collaborators own policy decisions, live redirects SQL, staged
 * view_done SQL, and staged read execution. This facade preserves the public
 * methods used by ViewReadService and legacy tests.
 */
class ABJ_404_Solution_ViewQueryBuilder {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_ViewQueryPolicy */
    private $policy;

    /** @var ABJ_404_Solution_RedirectsForViewSqlBuilder */
    private $redirectsSqlBuilder;

    /** @var ABJ_404_Solution_ViewDoneQueryBuilder */
    private $viewDoneQueryBuilder;

    /** @var ABJ_404_Solution_ViewDoneReader */
    private $viewDoneReader;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions $f
     * @param ABJ_404_Solution_LogsRepository $logsRepo
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        ABJ_404_Solution_Functions $f,
        ABJ_404_Solution_LogsRepository $logsRepo,
        $logger
    ) {
        $this->dbCore = $dbCore;
        $this->policy = new ABJ_404_Solution_ViewQueryPolicy();
        $this->redirectsSqlBuilder = new ABJ_404_Solution_RedirectsForViewSqlBuilder(
            $dbCore, $f, $logsRepo, $this->policy, $logger
        );
        $this->viewDoneQueryBuilder = new ABJ_404_Solution_ViewDoneQueryBuilder($dbCore, $this->policy);
        $this->viewDoneReader = new ABJ_404_Solution_ViewDoneReader($dbCore, $this->viewDoneQueryBuilder);
    }

    /**
     * @param ABJ_404_Solution_ViewReadServiceInterface $host
     * @return void
     */
    public function setHost(ABJ_404_Solution_ViewReadServiceInterface $host): void {
        $this->redirectsSqlBuilder->setHost($host);
    }

    /**
     * @param ABJ_404_Solution_ViewBuildOrchestratorInterface $viewBuildOrchestrator
     * @return void
     */
    public function setViewBuildOrchestrator(ABJ_404_Solution_ViewBuildOrchestratorInterface $viewBuildOrchestrator): void {
        $this->viewDoneReader->setViewBuildOrchestrator($viewBuildOrchestrator);
    }

    /** @return string */
    public function buildHighImpactCapturedCountQuery(): string {
        // Plain equality gives the optimizer an indexable requested_url probe;
        // the BINARY predicate keeps exact-match URL semantics.
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
     * @param int|null $limit
     * @return array<int, array<string, mixed>>
     */
    public function queryRegexRedirects(?int $limit = null) {
        $query = "select \n  {wp_abj404_redirects}.id,\n  {wp_abj404_redirects}.url,\n  {wp_abj404_redirects}.status,\n"
            . "  {wp_abj404_redirects}.type,\n  {wp_abj404_redirects}.final_dest,\n  {wp_abj404_redirects}.code,\n"
            . "  {wp_abj404_redirects}.timestamp,\n {wp_posts}.id as wp_post_id\n "
            . "from {wp_abj404_redirects}\n "
            . "  LEFT OUTER JOIN {wp_posts} \n "
            . "    on {wp_abj404_redirects}.final_dest = {wp_posts}.id \n "
            . "where status in (" . ABJ404_STATUS_REGEX . ") \n "
            . "     and disabled = 0\n"
            . "order by {wp_abj404_redirects}.id ASC";
        if ($limit !== null) {
            $query .= "\nlimit " . max(1, intval($limit));
        }

        $results = $this->dbCore->queryAndGetResults($query);
        $rows = is_array($results['rows'] ?? null) ? $results['rows'] : array();
        /** @var array<int, array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    public function getOptimizedRedirectsForViewCountQuery(string $sub, array $tableOptions): string {
        return $this->redirectsSqlBuilder->getOptimizedRedirectsForViewCountQuery($sub, $tableOptions);
    }

    /**
     * @param ABJ_404_Solution_ViewListQueryRequest $request
     * @return string
     */
    public function getRedirectsForViewQuery(ABJ_404_Solution_ViewListQueryRequest $request) {
        return $this->redirectsSqlBuilder->getRedirectsForViewQuery($request);
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return array<int, array<string, mixed>>
     */
    public function readFromViewDone(string $sub, array $tableOptions): array {
        return $this->viewDoneReader->readFromViewDone($sub, $tableOptions);
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    public function buildViewDoneCountQuery(string $sub, array $tableOptions): string {
        return $this->viewDoneQueryBuilder->buildCountQuery($sub, $tableOptions);
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    public function resolveStatusTypeList(string $sub, array $tableOptions): string {
        return $this->policy->resolveStatusTypeList($sub, $tableOptions);
    }

    /**
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    public function resolveOrderByColumn(array $tableOptions): string {
        return $this->policy->resolveOrderByColumn($tableOptions);
    }

}
