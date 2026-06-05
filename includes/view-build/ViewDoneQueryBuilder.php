<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds SQL for reads against the staged view_done admin table.
 */
class ABJ_404_Solution_ViewDoneQueryBuilder {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @var ABJ_404_Solution_ViewQueryPolicy */
    private $policy;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_ViewQueryPolicy $policy
     */
    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore, ABJ_404_Solution_ViewQueryPolicy $policy) {
        $this->dbCore = $dbCore;
        $this->policy = $policy;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    public function buildReadQuery(string $sub, array $tableOptions): string {
        $statusTypes = $this->policy->resolveStatusTypeList($sub, $tableOptions);
        $trashClause = 'AND disabled = ' . intval($this->policy->resolveTrashValue($tableOptions));
        $scoreRangeClause = $this->policy->buildScoreRangeClause($tableOptions, '');
        $filterTextClause = $this->policy->buildFilterTextClause($sub, $tableOptions);
        $orderBy = $this->policy->resolveOrderByColumn($tableOptions);
        $order = $this->policy->resolveOrderDirection($tableOptions);

        $rawPaged = $tableOptions['paged'] ?? 1;
        $paged = max(1, is_scalar($rawPaged) ? intval($rawPaged) : 1);
        $rawPerpage = $tableOptions['perpage'] ?? ABJ404_OPTION_DEFAULT_PERPAGE;
        $perpage = max(1, is_scalar($rawPerpage) ? intval($rawPerpage) : (int)ABJ404_OPTION_DEFAULT_PERPAGE);
        $limitStart = ($paged - 1) * $perpage;

        return "SELECT id, url, status, status_for_view, type, type_for_view,\n"
            . "       final_dest, dest_for_view, published_status, code, timestamp,\n"
            . "       engine, score, wp_post_id, wp_post_type,\n"
            . "       logshits, logsid, last_used\n"
            . "FROM `" . $this->viewDoneTableName() . "`\n"
            . "WHERE status IN (" . $statusTypes . ")\n"
            . " " . $trashClause . "\n"
            . " " . $scoreRangeClause . "\n"
            . " " . $filterTextClause . "\n"
            . "ORDER BY published_status ASC, " . $orderBy . " " . $order . ", url ASC, id " . $order . "\n"
            . "LIMIT " . $limitStart . ", " . $perpage;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    public function buildCountQuery(string $sub, array $tableOptions): string {
        $statusTypes = $this->policy->resolveStatusTypeList($sub, $tableOptions);
        $trashClause = 'AND disabled = ' . intval($this->policy->resolveTrashValue($tableOptions));
        $scoreRangeClause = $this->policy->buildScoreRangeClause($tableOptions, '');
        $filterTextClause = $this->policy->buildFilterTextClause($sub, $tableOptions);

        return "SELECT COUNT(*) AS cnt\n"
            . "FROM `" . $this->viewDoneTableName() . "`\n"
            . "WHERE status IN (" . $statusTypes . ")\n"
            . " " . $trashClause . "\n"
            . " " . $scoreRangeClause . "\n"
            . " " . $filterTextClause;
    }

    /** @return string */
    private function viewDoneTableName(): string {
        return $this->dbCore->doTableNameReplacements('{wp_abj404_view_done}');
    }
}
