<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Presents the edit, logs, trash, restore, and delete actions for a redirect row.
 */
class ABJ_404_Solution_RedirectRowActionsPresenter {

    /** @var ABJ_404_Solution_Functions */
    private $functions;

    /** @var ABJ_404_Solution_View_Shared */
    private $shared;

    public function __construct(ABJ_404_Solution_Functions $functions, ABJ_404_Solution_View_Shared $shared) {
        $this->functions = $functions;
        $this->shared = $shared;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $tableOptions
     * @return array{
     *   edit: string,
     *   logs: string,
     *   trash: string,
     *   delete: string,
     *   links: array{editlink: string, logslink: string, trashlink: string, ajaxTrashLink: string, trashtitle: string, deletelink: string}
     * }
     */
    public function render(array $row, string $sub, array $tableOptions): array {
        $links = $this->shared->buildTableActionLinks($row, $sub, $tableOptions, false);
        $links = array(
            'logslink' => $links['logslink'],
            'trashlink' => $links['trashlink'],
            'ajaxTrashLink' => $links['ajaxTrashLink'],
            'trashtitle' => $links['trashtitle'],
            'deletelink' => $links['deletelink'],
            'editlink' => $links['editlink'],
        );
        $currentFilter = $tableOptions['filter'] ?? 0;
        $logsId = is_scalar($row['logsid'] ?? 0) ? (int)($row['logsid'] ?? 0) : 0;

        return array(
            'edit' => $this->editActionLink($currentFilter, $links),
            'logs' => $this->logsActionLink($logsId),
            'trash' => $this->trashActionLink($currentFilter),
            'delete' => $this->deleteActionLink($currentFilter),
            'links' => $links,
        );
    }

    /**
     * @param mixed $currentFilter
     * @param array<string, string> $links
     */
    private function editActionLink($currentFilter, array $links): string {
        if ($currentFilter == ABJ404_TRASH_FILTER) {
            return '';
        }
        return $this->actionLink('viewRedirectsTableActionLink.html', array(
            'href' => esc_url($links['editlink']),
            'class' => 'abj404-action-link',
            'title' => '{Edit Redirect Details}',
            'svg_path' => $this->tpl('viewRedirectsTableSvgEdit.html'),
            'label' => '{Edit}',
        ));
    }

    private function logsActionLink(int $logsId): string {
        if ($logsId <= 0) {
            return '';
        }
        return $this->actionLink('viewRedirectsTableActionLink.html', array(
            'href' => '{logsLink}',
            'class' => 'abj404-action-link',
            'title' => '{View Redirect Logs}',
            'svg_path' => $this->tpl('viewRedirectsTableSvgLogs.html'),
            'label' => '{Logs}',
        ));
    }

    /**
     * @param mixed $currentFilter
     */
    private function trashActionLink($currentFilter): string {
        if ($currentFilter == ABJ404_TRASH_FILTER) {
            return $this->actionLink('viewRedirectsTableActionLink.html', array(
                'href' => '{trashLink}',
                'class' => 'abj404-action-link',
                'title' => '{Restore}',
                'svg_path' => $this->tpl('viewRedirectsTableSvgRestore.html'),
                'label' => '{Restore}',
            ));
        }

        return $this->actionLink('viewRedirectsTableActionLinkAjax.html', array(
            'data_url' => '{ajaxTrashLink}',
            'class' => 'abj404-action-link danger ajax-trash-link',
            'title' => '{Trash Redirected URL}',
            'svg_path' => $this->tpl('viewRedirectsTableSvgTrash.html'),
            'label' => '{Trash}',
        ));
    }

    /**
     * @param mixed $currentFilter
     */
    private function deleteActionLink($currentFilter): string {
        if ($currentFilter != ABJ404_TRASH_FILTER) {
            return '';
        }
        return $this->actionLink('viewRedirectsTableActionLinkSeparated.html', array(
            'href' => '{deletelink}',
            'class' => 'abj404-action-link danger',
            'title' => '{Delete Redirect Permanently}',
            'svg_path' => $this->tpl('viewRedirectsTableSvgTrash.html'),
            'label' => '{Delete}',
        ));
    }

    /** @param array<string, string> $vars */
    private function actionLink(string $tplName, array $vars): string {
        $tpl = $this->tpl($tplName);
        foreach ($vars as $key => $value) {
            $tpl = $this->functions->str_replace('{' . $key . '}', $value, $tpl);
        }
        return $tpl;
    }

    private function tpl(string $name): string {
        $raw = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/' . $name, false);
        return rtrim((string)$raw, "\n");
    }
}
