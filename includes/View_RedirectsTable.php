<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/admin/RedirectsTableColumns.php';
require_once __DIR__ . '/admin/RedirectDestinationWarningPolicy.php';
require_once __DIR__ . '/admin/RedirectDestinationLinkResolver.php';
require_once __DIR__ . '/admin/RedirectRowActionsPresenter.php';
require_once __DIR__ . '/admin/RedirectRowPresenter.php';
require_once __DIR__ . '/admin/RedirectAddModalPresenter.php';
require_once __DIR__ . '/admin/RedirectsTablePagePresenter.php';

/**
 * Page Redirects admin page renderer. Owns the page wrapper, the table shell,
 * and the modern Add Redirect modal. Row presentation, columns, destination
 * warnings, and destination link resolution live in admin presenter/policy
 * classes.
 *
 * Outside callers (via the View facade __call dispatch):
 *   - includes/ajax/ViewUpdater.php (pagination AJAX -> getAdminRedirectsPageTable)
 *   - PluginLogic admin page entry points (echoAdminRedirectsPage)
 *   - tests/BugProof_ReleaseReadinessTest greps buildRedirectsColumnDefs source
 */
class ABJ_404_Solution_View_RedirectsTable extends ABJ_404_Solution_ViewComponent {

    /** @var ABJ_404_Solution_RedirectsTableColumns|null */
    private $redirectsTableColumns = null;

    /** @var ABJ_404_Solution_RedirectDestinationWarningPolicy|null */
    private $destinationWarningPolicy = null;

    /** @var ABJ_404_Solution_RedirectDestinationLinkResolver|null */
    private $destinationLinkResolver = null;

    /** @var ABJ_404_Solution_RedirectRowPresenter|null */
    private $redirectRowPresenter = null;

    /** @var ABJ_404_Solution_RedirectAddModalPresenter|null */
    private $redirectAddModalPresenter = null;

    /** @var ABJ_404_Solution_RedirectsTablePagePresenter|null */
    private $redirectsTablePagePresenter = null;

    /** Load a template file from includes/html/ and trim its trailing newline. */
    private function tpl(string $name): string {
        $raw = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . '/html/' . $name, false);
        return rtrim((string)$raw, "\n");
    }

    /**
     * @return void
     */
    public function echoAdminRedirectsPage() {
        echo $this->pagePresenter()->render();
    }

    /**
     * Echo the modern Add Redirect modal.
     *
     * @param array<string, mixed> $tableOptions
     * @return void
     */
    public function echoAddRedirectModal($tableOptions) {
        echo $this->addModalPresenter()->render($tableOptions);
    }

    /**
     * @param string $sub
     * @return string
     */
    public function getAdminRedirectsPageTable($sub) {
        $tableOptions = $this->logic->settingsUpdate()->getTableOptions($sub);
        $columns = $this->buildRedirectsColumnDefs($tableOptions);

        $headerColumns = $this->logs->getTableColumns($sub, $columns);

        $deadDestIds = function_exists('get_transient') ? get_transient('abj404_dead_dest_ids') : false;
        if (!is_array($deadDestIds)) {
            $deadDestIds = array();
        }

        $rows = $this->viewReadService->getRedirectsForView($sub, $tableOptions);
        /** @var array<int, array<string, mixed>> $typedRedirectRows */
        $typedRedirectRows = array_values(array_filter($rows, 'is_array'));
        $this->shared->rememberTableDataSignature($sub, $typedRedirectRows);
        $displayed = 0;
        $y = 1;
        $bodyRows = '';
        foreach ($typedRedirectRows as $row) {
            $bodyRows .= $this->buildRedirectRowHTML($row, $sub, $tableOptions, $deadDestIds, $y);
            $y = ($y === 0) ? 1 : 0;
            $displayed++;
        }
        if ($displayed == 0) {
            $bodyRows .= $this->f->str_replace(
                array('{title}', '{help}'),
                array(
                    __('No Redirect Records To Display', '404-solution'),
                    __('Redirects will appear here once created.', '404-solution'),
                ),
                $this->tpl('viewRedirectsTableRedirectsEmptyState.html')
            );
        }

        return $this->f->str_replace(
            array('{header_columns}', '{body_rows}'),
            array($headerColumns, $bodyRows),
            $this->tpl('viewRedirectsTableRedirectsTableShell.html')
        );
    }

    /**
     * @param array<string, mixed> $tableOptions
     * @return array<string, array<string, string>>
     */
    public function buildRedirectsColumnDefs(array $tableOptions): array {
        return $this->columns()->build($tableOptions);
    }

    /**
     * @param array<string, mixed> $row
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param array<mixed> $deadDestIds
     * @param int $y
     * @return string
     */
    public function buildRedirectRowHTML(array $row, string $sub, array $tableOptions, array $deadDestIds, int $y): string {
        return $this->rowPresenter()->render($row, $sub, $tableOptions, $deadDestIds, $y);
    }

    /**
     * @param array<string, mixed> $row
     * @param mixed $rowType
     * @param string $rowFinalDest
     * @param string $destForView
     * @param bool $destinationIsMissing
     * @param array<mixed> $deadDestIds
     * @return array{exists: string, notExists: string, text: string, destForView: string}
     */
    public function resolveDestinationWarnings(array $row, $rowType, string $rowFinalDest, string $destForView, bool $destinationIsMissing, array $deadDestIds): array {
        return $this->warningPolicy()->resolve($row, $rowType, $rowFinalDest, $destForView, $destinationIsMissing, $deadDestIds);
    }

    /**
     * @param array<string, string> $replacements
     * @return string
     */
    public function fillRedirectRowTemplate(array $replacements): string {
        return $this->rowPresenter()->fillRedirectRowTemplate($replacements);
    }

    /**
     * @param mixed $rawScore
     * @param string $rowEngine
     * @return string
     */
    public function buildScoreCell($rawScore, string $rowEngine): string {
        return $this->rowPresenter()->buildScoreCell($rawScore, $rowEngine);
    }

    /**
     * @param mixed $rowType
     * @param string $rowFinalDest
     * @return array{link: string, title: string}
     */
    public function resolveRedirectDestLink($rowType, string $rowFinalDest): array {
        return $this->linkResolver()->resolve($rowType, $rowFinalDest);
    }

    private function columns(): ABJ_404_Solution_RedirectsTableColumns {
        if (!($this->redirectsTableColumns instanceof ABJ_404_Solution_RedirectsTableColumns)) {
            $this->redirectsTableColumns = new ABJ_404_Solution_RedirectsTableColumns($this->shared);
        }
        return $this->redirectsTableColumns;
    }

    private function warningPolicy(): ABJ_404_Solution_RedirectDestinationWarningPolicy {
        if (!($this->destinationWarningPolicy instanceof ABJ_404_Solution_RedirectDestinationWarningPolicy)) {
            $this->destinationWarningPolicy = new ABJ_404_Solution_RedirectDestinationWarningPolicy();
        }
        return $this->destinationWarningPolicy;
    }

    private function linkResolver(): ABJ_404_Solution_RedirectDestinationLinkResolver {
        if (!($this->destinationLinkResolver instanceof ABJ_404_Solution_RedirectDestinationLinkResolver)) {
            $this->destinationLinkResolver = new ABJ_404_Solution_RedirectDestinationLinkResolver($this->logger);
        }
        return $this->destinationLinkResolver;
    }

    private function rowPresenter(): ABJ_404_Solution_RedirectRowPresenter {
        if (!($this->redirectRowPresenter instanceof ABJ_404_Solution_RedirectRowPresenter)) {
            $this->redirectRowPresenter = new ABJ_404_Solution_RedirectRowPresenter(
                $this->f,
                $this->warningPolicy(),
                $this->linkResolver(),
                new ABJ_404_Solution_RedirectRowActionsPresenter($this->f, $this->shared)
            );
        }
        return $this->redirectRowPresenter;
    }

    private function addModalPresenter(): ABJ_404_Solution_RedirectAddModalPresenter {
        if (!($this->redirectAddModalPresenter instanceof ABJ_404_Solution_RedirectAddModalPresenter)) {
            $this->redirectAddModalPresenter = new ABJ_404_Solution_RedirectAddModalPresenter(
                $this->f,
                $this->optionsPresenter,
                $this->redirectTypeUI,
                $this->redirectConditions
            );
        }
        return $this->redirectAddModalPresenter;
    }

    private function pagePresenter(): ABJ_404_Solution_RedirectsTablePagePresenter {
        if (!($this->redirectsTablePagePresenter instanceof ABJ_404_Solution_RedirectsTablePagePresenter)) {
            $this->redirectsTablePagePresenter = new ABJ_404_Solution_RedirectsTablePagePresenter(
                $this->f,
                $this->logic,
                $this->listTableChrome,
                $this->addModalPresenter()
            );
        }
        return $this->redirectsTablePagePresenter;
    }
}
