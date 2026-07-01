<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds the per-row action button HTML (edit/logs/trash/delete/ignore/later/
 * dismiss/create-redirect) for the Captured 404 URLs table. Supports both
 * Simple and Advanced settings modes and the Trash filter variant. Extracted
 * from View_CapturedURLsTable because building action-button HTML for a row
 * is a distinct responsibility from assembling the row's other presentation
 * fields.
 */
class ABJ_404_Solution_CapturedRowActionButtonsRenderer {

    /** @var ABJ_404_Solution_Functions */
    private $f;

    public function __construct(ABJ_404_Solution_Functions $functions) {
        $this->f = $functions;
    }

    private function tpl(string $name): string {
        $raw = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/' . $name, false);
        return rtrim((string)$raw, "\n");
    }

    /**
     * Build an action link with SVG icon.
     *
     * @param array<string,string> $vars
     */
    private function buildActionLink(string $tplName, array $vars): string {
        $tpl = $this->tpl($tplName);
        foreach ($vars as $k => $v) {
            $tpl = $this->f->str_replace('{' . $k . '}', $v, $tpl);
        }
        return $tpl;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $tableOptions
     * @param array<string, string> $links Pre-built action URLs / titles.
     * @return array{edit:string,logs:string,trash:string,delete:string,ignore:string,later:string}
     */
    public function build(array $row, array $tableOptions, array $links): array {
        $svgEdit    = $this->tpl('viewRedirectsTableSvgEdit.html');
        $svgDismiss = $this->tpl('viewRedirectsTableSvgDismiss.html');
        $svgLogs    = $this->tpl('viewRedirectsTableSvgLogs.html');
        $svgTrash   = $this->tpl('viewRedirectsTableSvgTrash.html');
        $svgRestore = $this->tpl('viewRedirectsTableSvgRestore.html');
        $svgX       = $this->tpl('viewRedirectsTableSvgX.html');
        $svgClock   = $this->tpl('viewRedirectsTableSvgClock.html');

        $edit = $logs = $trash = $delete = $ignore = $later = '';

        $currentFilter = $tableOptions['filter'] ?? 0;
        $isSimpleModeRow = abj_service('settings_mode_preference')->getMode() === 'simple';

        if ($isSimpleModeRow) {
            $edit = $this->buildActionLink('viewRedirectsTableActionLink.html', array(
                'href' => esc_url($links['editlink']), 'class' => 'abj404-action-link',
                'title' => esc_attr__('Create Redirect', '404-solution'),
                'svg_path' => $svgEdit, 'label' => esc_html__('Create Redirect', '404-solution'),
            ));
            if ($row['status'] != ABJ404_STATUS_IGNORED) {
                $ignore = $this->buildActionLink('viewRedirectsTableActionLinkSeparated.html', array(
                    'href' => esc_url($links['ignorelink']), 'class' => 'abj404-action-link',
                    'title' => esc_attr__('Dismiss', '404-solution'),
                    'svg_path' => $svgDismiss, 'label' => esc_html__('Dismiss', '404-solution'),
                ));
            }
            return ['edit'=>$edit,'logs'=>$logs,'trash'=>$trash,'delete'=>$delete,'ignore'=>$ignore,'later'=>$later];
        }

        if ($currentFilter != ABJ404_TRASH_FILTER) {
            $edit = $this->buildActionLink('viewRedirectsTableActionLink.html', array(
                'href' => esc_url($links['editlink']), 'class' => 'abj404-action-link',
                'title' => esc_attr__('Edit', '404-solution'),
                'svg_path' => $svgEdit, 'label' => esc_html__('Edit', '404-solution'),
            ));
        }
        if (($row['logsid'] ?? 0) > 0) {
            $logs = $this->buildActionLink('viewRedirectsTableActionLink.html', array(
                'href' => esc_url($links['logslink']), 'class' => 'abj404-action-link',
                'title' => esc_attr__('View Logs', '404-solution'),
                'svg_path' => $svgLogs, 'label' => esc_html__('Logs', '404-solution'),
            ));
        }
        if ($currentFilter != ABJ404_TRASH_FILTER) {
            $trash = $this->buildActionLink('viewRedirectsTableActionLink.html', array(
                'href' => esc_url($links['trashlink']), 'class' => 'abj404-action-link danger',
                'title' => esc_attr($links['trashtitle']),
                'svg_path' => $svgTrash, 'label' => esc_html__('Trash', '404-solution'),
            ));
        }

        if ($currentFilter == ABJ404_TRASH_FILTER) {
            $trash = $this->buildActionLink('viewRedirectsTableActionLink.html', array(
                'href' => esc_url($links['trashlink']), 'class' => 'abj404-action-link',
                'title' => esc_attr__('Restore', '404-solution'),
                'svg_path' => $svgRestore, 'label' => esc_html__('Restore', '404-solution'),
            ));
            $delete = $this->buildActionLink('viewRedirectsTableActionLinkConfirm.html', array(
                'href' => esc_url($links['deletelink']), 'class' => 'abj404-action-link danger',
                'title' => esc_attr__('Delete Permanently', '404-solution'),
                'confirm_js' => esc_js(__('Are you sure you want to permanently delete this item?', '404-solution')),
                'svg_path' => $svgX, 'label' => esc_html__('Delete', '404-solution'),
            ));
        } else {
            if ($row['status'] != ABJ404_STATUS_IGNORED) {
                $ignore = $this->buildActionLink('viewRedirectsTableActionLinkSeparated.html', array(
                    'href' => esc_url($links['ignorelink']), 'class' => 'abj404-action-link',
                    'title' => esc_attr($links['ignoretitle']),
                    'svg_path' => $svgDismiss, 'label' => esc_html__('Ignore', '404-solution'),
                ));
            }
            if ($row['status'] != ABJ404_STATUS_LATER) {
                $later = $this->buildActionLink('viewRedirectsTableActionLinkSeparated.html', array(
                    'href' => esc_url($links['laterlink']), 'class' => 'abj404-action-link',
                    'title' => esc_attr($links['latertitle']),
                    'svg_path' => $svgClock, 'label' => esc_html__('Later', '404-solution'),
                ));
            }
        }
        return ['edit'=>$edit,'logs'=>$logs,'trash'=>$trash,'delete'=>$delete,'ignore'=>$ignore,'later'=>$later];
    }
}
