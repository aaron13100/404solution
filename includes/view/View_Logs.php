<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../admin/AdminTraceLabels.php';
require_once __DIR__ . '/../admin/AdminLogsPageShell.php';
require_once __DIR__ . '/../admin/AdminLogsTable.php';
require_once __DIR__ . '/../admin/AdminTableColumnHeaders.php';
require_once __DIR__ . '/../admin/AdminPaginationLinks.php';
require_once __DIR__ . '/../admin/AdminStatusFilterTabs.php';

/**
 * Public View facade methods for logs, pagination, and status filters.
 */
class ABJ_404_Solution_View_Logs extends ABJ_404_Solution_ViewComponent {

    /** @var ABJ_404_Solution_AdminTraceLabels|null */
    private $traceLabels = null;
    /** @var ABJ_404_Solution_AdminLogsPageShell|null */
    private $pageShell = null;
    /** @var ABJ_404_Solution_AdminLogsTable|null */
    private $logsTable = null;
    /** @var ABJ_404_Solution_AdminTableColumnHeaders|null */
    private $columnHeaders = null;
    /** @var ABJ_404_Solution_AdminPaginationLinks|null */
    private $paginationLinks = null;
    /** @var ABJ_404_Solution_AdminStatusFilterTabs|null */
    private $statusFilterTabs = null;

    /** @return void */
    function echoAdminLogsPage() {
        echo $this->pageShell()->render();
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptionOverrides Internal per-request read options.
     */
    function getAdminLogsPageTable($sub, array $tableOptionOverrides = array()): string {
        $tableOptions = $this->logic->settingsUpdate()->getTableOptions((string)$sub);
        $tableOptions = array_replace($tableOptions, $tableOptionOverrides);
        return $this->logsTable()->render((string)$sub, $tableOptions);
    }

    /**
     * @param string $text
     * @return string
     */
    public function translateTraceLabel(string $text): string {
        return $this->traceLabels()->translate($text);
    }

    /**
     * @param string $outcome
     * @return string
     */
    public function traceOutcomeClass(string $outcome): string {
        return $this->traceLabels()->outcomeClass($outcome);
    }

    /**
     * @param string $sub
     * @param array<string, array<string, string>> $columns
     * @return string
     */
    function getTableColumns($sub, $columns): string {
        return $this->columnHeaders()->render((string)$sub, $columns);
    }

    /**
     * The pagination strip for one subpage.
     *
     * There is deliberately no top/bottom or show-filter argument: the strip
     * renders the same markup wherever it is placed, and an argument that
     * cannot change the output invites callers to render it once per
     * placement and pay the row-count read each time.
     *
     * @param string $sub
     * @param array<string, mixed> $tableOptionOverrides Internal per-request read options.
     * @return string
     */
    function getPaginationLinks($sub, array $tableOptionOverrides = array()): string {
        return $this->paginationLinks()->render((string)$sub, $tableOptionOverrides);
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @return string
     */
    function getTabFilters($sub, $tableOptions): string {
        return $this->statusFilterTabs()->render((string)$sub, is_array($tableOptions) ? $tableOptions : array());
    }

    /**
     * @param string $sub
     * @return string
     */
    function getSubSubSub($sub): string {
        return $this->statusFilterTabs()->renderList((string)$sub);
    }

    private function traceLabels(): ABJ_404_Solution_AdminTraceLabels {
        if ($this->traceLabels === null) {
            $this->traceLabels = new ABJ_404_Solution_AdminTraceLabels();
        }
        return $this->traceLabels;
    }

    private function pageShell(): ABJ_404_Solution_AdminLogsPageShell {
        if ($this->pageShell === null) {
            $this->pageShell = new ABJ_404_Solution_AdminLogsPageShell($this->f, $this->logic, $this->shared);
        }
        return $this->pageShell;
    }

    private function logsTable(): ABJ_404_Solution_AdminLogsTable {
        if ($this->logsTable === null) {
            $this->logsTable = new ABJ_404_Solution_AdminLogsTable(
                $this->f,
                $this->shared,
                $this->logger,
                $this->logsRepository,
                $this->traceLabels()
            );
        }
        return $this->logsTable;
    }

    private function columnHeaders(): ABJ_404_Solution_AdminTableColumnHeaders {
        if ($this->columnHeaders === null) {
            $this->columnHeaders = new ABJ_404_Solution_AdminTableColumnHeaders(
                $this->f, $this->logic, $this->shared, $this->viewReadService);
        }
        return $this->columnHeaders;
    }

    private function paginationLinks(): ABJ_404_Solution_AdminPaginationLinks {
        if ($this->paginationLinks === null) {
            $this->paginationLinks = new ABJ_404_Solution_AdminPaginationLinks(
                $this->f,
                $this->logic,
                $this->shared,
                $this->viewReadService
            );
        }
        return $this->paginationLinks;
    }

    private function statusFilterTabs(): ABJ_404_Solution_AdminStatusFilterTabs {
        if ($this->statusFilterTabs === null) {
            $this->statusFilterTabs = new ABJ_404_Solution_AdminStatusFilterTabs(
                $this->f,
                $this->logic,
                $this->logger,
                $this->viewReadService,
                $this->listTableChrome
            );
        }
        return $this->statusFilterTabs;
    }
}
