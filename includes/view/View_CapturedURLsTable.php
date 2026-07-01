<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Captured 404 URLs admin page renderer. Owns the AJAX-refreshed table-body
 * shell and per-row presentation formatting (status badges, last-used dates,
 * template variable assembly) for the "Captured 404 URLs" admin page
 * (subpage abj404_captured). Delegates page-chrome rendering to
 * CapturedPageChromeRenderer, per-row action-button HTML to
 * CapturedRowActionButtonsRenderer, and the "linked from" source-evidence
 * panel to CapturedSourceEvidenceRenderer.
 *
 * Outside callers (via the View facade __call dispatch):
 *   - includes/ajax/ViewUpdater.php (pagination AJAX -> getCapturedURLSPageTable)
 *   - PluginLogic admin page entry points (echoAdminCapturedURLsPage)
 */
class ABJ_404_Solution_View_CapturedURLsTable extends ABJ_404_Solution_ViewComponent {

    /** @var ABJ_404_Solution_CapturedTableHeaderRenderer|null */
    private $capturedTableHeaderRenderer = null;

    /** @var ABJ_404_Solution_CapturedPageChromeRenderer|null */
    private $pageChromeRenderer = null;

    private function tpl(string $name): string {
        $raw = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/' . $name, false);
        return rtrim((string)$raw, "\n");
    }

    /** @return void */
    public function echoAdminCapturedURLsPage() {
        echo $this->pageChromeRenderer()->render();
    }

    private function pageChromeRenderer(): ABJ_404_Solution_CapturedPageChromeRenderer {
        if ($this->pageChromeRenderer === null) {
            $this->pageChromeRenderer = new ABJ_404_Solution_CapturedPageChromeRenderer(
                $this->logic,
                $this->listTableChrome,
                $this->f
            );
        }
        return $this->pageChromeRenderer;
    }

    public function getCapturedURLSPageTable(string $sub): string {
        $tableOptions = $this->logic->settingsUpdate()->getTableOptions($sub);
        $rows = $this->viewReadService->getRedirectsForView($sub, $tableOptions);
        /** @var array<int, array<string, mixed>> $typedRows */
        $typedRows = array_values(array_filter($rows, 'is_array'));
        $this->shared->rememberTableDataSignature($sub, $typedRows);

        return $this->f->str_replace(
            array('{select_all_label}', '{header_cells}', '{body_rows}'),
            array(
                esc_attr__('Select all', '404-solution'),
                $this->buildCapturedHeaderCells($tableOptions),
                $this->buildCapturedBodyRows($sub, $tableOptions, $typedRows),
            ),
            $this->tpl('viewRedirectsTableCapturedTableShell.html')
        );
    }

    /** @param array<string, mixed> $tableOptions */
    private function buildCapturedHeaderCells(array $tableOptions): string {
        return $this->capturedTableHeaderRenderer()->render($tableOptions);
    }

    private function capturedTableHeaderRenderer(): ABJ_404_Solution_CapturedTableHeaderRenderer {
        if ($this->capturedTableHeaderRenderer === null) {
            $this->capturedTableHeaderRenderer = new ABJ_404_Solution_CapturedTableHeaderRenderer(
                $this->f,
                $this->shared,
                $this->viewReadService
            );
        }
        return $this->capturedTableHeaderRenderer;
    }

    /**
     * @param array<string, mixed> $tableOptions
     * @param array<int, array<string, mixed>> $rows
     */
    private function buildCapturedBodyRows(string $sub, array $tableOptions, array $rows): string {
        if (count($rows) === 0) {
            // Same i455 bug class as the redirects table: a pending/errored/
            // stale-empty staged read must not be shown as "No records" while
            // the live source count says captured rows exist.
            $emptyMessage = $this->viewReadService->lastRedirectsViewReadWasIncomplete()
                ? __('Preparing the captured 404s table. The list is still loading and will appear in a moment.', '404-solution')
                : __('No Captured 404 Records To Display', '404-solution');
            return $this->f->str_replace(
                '{message}',
                $emptyMessage,
                $this->tpl('viewRedirectsTableCapturedEmptyRow.html')
            ) . "\n";
        }

        $sourceEvidenceByUrl = $this->sourceEvidenceByVisibleUrl($rows);
        $bodyRows = '';
        foreach ($rows as $row) {
            $bodyRows .= $this->capturedBodyRow($sub, $tableOptions, $row, $sourceEvidenceByUrl);
        }
        return $bodyRows;
    }

    /**
     * @param array<string, mixed> $tableOptions
     * @param array<string, mixed> $row
     * @param array<string, array<string, mixed>> $sourceEvidenceByUrl
     */
    private function capturedBodyRow(string $sub, array $tableOptions, array $row, array $sourceEvidenceByUrl): string {
        $hits = is_scalar($row['logshits'] ?? 0) ? (int)($row['logshits'] ?? 0) : 0;
        $lastUsed = $this->capturedLastUsedPresentation($row);
        $status = $this->capturedStatusPresentation($row);
        $btns = $this->capturedActionButtons($sub, $tableOptions, $row);
        $vars = $this->capturedRowTemplateVars($row, $hits, $lastUsed, $status, $btns, $sourceEvidenceByUrl);

        $tempHtml = $this->f->str_replace(
            array_keys($vars),
            array_values($vars),
            ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/tableRowCapturedURLs.html")
        );
        return $this->f->doNormalReplacements($tempHtml);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{date: string, class: string}
     */
    private function capturedLastUsedPresentation(array $row): array {
        $last_used = is_scalar($row['last_used'] ?? 0) ? (int)($row['last_used'] ?? 0) : 0;
        if ($last_used != 0) {
            return array(
                'date' => (string)wp_date("Y/m/d h:i:s A", abs($last_used)),
                'class' => '',
            );
        }

        return array(
            'date' => __('Never', '404-solution'),
            'class' => 'abj404-never-used',
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array{class: string, text: string, title: string}
     */
    private function capturedStatusPresentation(array $row): array {
        if ($row['status'] == ABJ404_STATUS_IGNORED) {
            return array(
                'class' => 'abj404-badge-ignored',
                'text' => __('Ignored', '404-solution'),
                'title' => __('Ignored URL - will not be suggested', '404-solution'),
            );
        }
        if ($row['status'] == ABJ404_STATUS_LATER) {
            return array(
                'class' => 'abj404-badge-later',
                'text' => __('Later', '404-solution'),
                'title' => __('Organize Later', '404-solution'),
            );
        }

        return array(
            'class' => 'abj404-badge-captured',
            'text' => __('Captured', '404-solution'),
            'title' => __('Captured 404 URL', '404-solution'),
        );
    }

    /**
     * @param array<string, mixed> $tableOptions
     * @param array<string, mixed> $row
     * @return array{edit: string, logs: string, trash: string, delete: string, ignore: string, later: string}
     */
    private function capturedActionButtons(string $sub, array $tableOptions, array $row): array {
        $links = $this->shared->buildTableActionLinks($row, $sub, $tableOptions, true);

        return $this->rowActionButtonsRenderer()->build($row, $tableOptions, array(
            'editlink' => $this->linkValue($links, 'editlink'),
            'logslink' => $this->linkValue($links, 'logslink'),
            'trashlink' => $this->linkValue($links, 'trashlink'),
            'trashtitle' => $this->linkValue($links, 'trashtitle'),
            'deletelink' => $this->linkValue($links, 'deletelink'),
            'ignorelink' => $this->linkValue($links, 'ignorelink'),
            'ignoretitle' => $this->linkValue($links, 'ignoretitle'),
            'laterlink' => $this->linkValue($links, 'laterlink'),
            'latertitle' => $this->linkValue($links, 'latertitle'),
        ));
    }

    /** @var ABJ_404_Solution_CapturedRowActionButtonsRenderer|null */
    private $rowActionButtonsRenderer = null;

    private function rowActionButtonsRenderer(): ABJ_404_Solution_CapturedRowActionButtonsRenderer {
        if ($this->rowActionButtonsRenderer === null) {
            $this->rowActionButtonsRenderer = new ABJ_404_Solution_CapturedRowActionButtonsRenderer($this->f);
        }
        return $this->rowActionButtonsRenderer;
    }

    /** @param array<string, mixed> $links */
    private function linkValue(array $links, string $key): string {
        return is_scalar($links[$key] ?? '') ? (string)($links[$key] ?? '') : '';
    }

    /**
     * @param array<string, mixed> $row
     * @param array{date: string, class: string} $lastUsed
     * @param array{class: string, text: string, title: string} $status
     * @param array{edit: string, logs: string, trash: string, delete: string, ignore: string, later: string} $btns
     * @param array<string, array<string, mixed>> $sourceEvidenceByUrl
     * @return array<string, string>
     */
    private function capturedRowTemplateVars(array $row, int $hits, array $lastUsed, array $status, array $btns,
            array $sourceEvidenceByUrl): array {
        $capturedRowUrl = is_string($row['url'] ?? '') ? (string)($row['url'] ?? '') : '';
        $capturedRowId = is_scalar($row['id'] ?? '') ? (string)($row['id'] ?? '') : '';
        $capturedEngine = is_string($row['engine'] ?? '') ? trim((string)($row['engine'] ?? '')) : '';
        $capturedEngineHTML = ($capturedEngine !== '') ? '<br><span class="abj404-engine-label">' . esc_html($capturedEngine) . '</span>' : '';
        $createdTimestamp = is_scalar($row['timestamp'] ?? 0) ? intval($row['timestamp'] ?? 0) : 0;
        $sourceEvidence = isset($sourceEvidenceByUrl[$capturedRowUrl]) && is_array($sourceEvidenceByUrl[$capturedRowUrl])
            ? $sourceEvidenceByUrl[$capturedRowUrl] : array();
        $sourceHtml = $this->sourceEvidenceRenderer()->htmlFor($capturedRowId, $sourceEvidence);

        $vars = array(
            '{rowid}' => $capturedRowId,
            '{rowClass}' => '',
            '{visitorURL}' => esc_url(home_url($capturedRowUrl)),
            '{url}' => esc_html($capturedRowUrl),
            '{statusBadgeClass}' => $status['class'],
            '{statusTitle}' => esc_attr($status['title']),
            '{status}' => $status['text'],
            '{engineHTML}' => $capturedEngineHTML,
            '{hits}' => esc_html((string)$hits),
            '{created_date}' => esc_html((string)wp_date("Y/m/d h:i:s A", abs($createdTimestamp))),
            '{last_used_date}' => esc_html($lastUsed['date']),
            '{lastUsedClass}' => $lastUsed['class'],
            '{editBtnHTML}' => $btns['edit'],
            '{logsBtnHTML}' => $btns['logs'],
            '{trashBtnHTML}' => $btns['trash'],
            '{deleteBtnHTML}' => $btns['delete'],
            '{ignoreBtnHTML}' => $btns['ignore'],
            '{laterBtnHTML}' => $btns['later'],
            '{internal_sources_trigger}' => $sourceHtml['trigger'],
            '{internal_sources_panel}' => $sourceHtml['panel'],
        );
        return $vars;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function sourceEvidenceByVisibleUrl(array $rows): array {
        return $this->sourceEvidenceRenderer()->evidenceByVisibleUrl($rows);
    }

    /** @var ABJ_404_Solution_CapturedSourceEvidenceRenderer|null */
    private $sourceEvidenceRenderer = null;

    private function sourceEvidenceRenderer(): ABJ_404_Solution_CapturedSourceEvidenceRenderer {
        if ($this->sourceEvidenceRenderer === null) {
            $this->sourceEvidenceRenderer = new ABJ_404_Solution_CapturedSourceEvidenceRenderer($this->f);
        }
        return $this->sourceEvidenceRenderer;
    }
}
