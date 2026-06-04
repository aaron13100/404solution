<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders the Redirect Logs list table from log repository rows.
 */
class ABJ_404_Solution_AdminLogsTable {

    /** @var ABJ_404_Solution_Functions */
    private $f;
    /** @var ABJ_404_Solution_View_Shared */
    private $shared;
    /** @var ABJ_404_Solution_Logging */
    private $logger;
    /** @var ABJ_404_Solution_LogsRepositoryInterface */
    private $logsRepository;
    /** @var ABJ_404_Solution_AdminTraceLabels */
    private $traceLabels;

    public function __construct(ABJ_404_Solution_Functions $functions, ABJ_404_Solution_View_Shared $shared,
            ABJ_404_Solution_Logging $logging, ABJ_404_Solution_LogsRepositoryInterface $logsRepository,
            ABJ_404_Solution_AdminTraceLabels $traceLabels) {
        $this->f = $functions;
        $this->shared = $shared;
        $this->logger = $logging;
        $this->logsRepository = $logsRepository;
        $this->traceLabels = $traceLabels;
    }

    /** @param array<string, mixed> $tableOptions */
    public function render(string $sub, array $tableOptions): string {
        $headerCells = $this->renderHeaderCells($tableOptions);
        $rows = $this->logsRepository->getLogRecords($tableOptions);
        /** @var array<int, array<string, mixed>> $typedLogRows */
        $typedLogRows = array_values(array_filter($rows, 'is_array'));
        $this->shared->rememberTableDataSignature($sub, $typedLogRows);

        $body = $this->renderBodyRows($typedLogRows);
        return $this->f->str_replace(
            array('{header_cells}', '{body_rows}', '{trace_script}'),
            array($headerCells, $body, $this->tpl('viewLogsTraceToggleScript.html')),
            $this->tpl('viewLogsTableShell.html')
        );
    }

    /** @param array<string, mixed> $tableOptions */
    private function renderHeaderCells(array $tableOptions): string {
        $columns = array(
            'url' => array('title' => __('URL', '404-solution'), 'orderby' => 'url'),
            'host' => array('title' => __('IP Address', '404-solution'), 'orderby' => ''),
            'refer' => array('title' => __('Referrer', '404-solution'), 'orderby' => ''),
            'dest' => array('title' => __('Action', '404-solution'), 'orderby' => ''),
            'timestamp' => array('title' => __('Date', '404-solution'), 'orderby' => 'timestamp'),
        );

        $plainHeaderTpl = $this->tpl('viewLogsTableHeaderCellPlain.html');
        $sortHeaderTpl = $this->tpl('viewLogsTableHeaderCellSortable.html');
        $headerCells = '';
        foreach ($columns as $col) {
            $orderbyCol = $col['orderby'];
            if ($orderbyCol === '') {
                $headerCells .= $this->f->str_replace('{title}', esc_html($col['title']), $plainHeaderTpl);
                continue;
            }

            $sortUrl = '?page=' . ABJ404_PP . '&subpage=abj404_logs&orderby=' . $orderbyCol;
            $sortState = $this->shared->getHeaderSortState($tableOptions, $orderbyCol, false);
            $sortUrl .= '&order=' . $sortState['nextOrder'];
            $sortClass = trim($sortState['thClass']);
            $sortClassAttr = ($sortClass !== '') ? ' class="' . $sortClass . '"' : '';

            $headerCells .= $this->f->str_replace(
                array('{class_attr}', '{sort_url}', '{title}', '{sort_indicator}'),
                array($sortClassAttr, esc_url($sortUrl), esc_html($col['title']), $sortState['indicator']),
                $sortHeaderTpl
            );
        }

        return $headerCells;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function renderBodyRows(array $rows): string {
        $displayed = 0;
        $bodyRows = '';
        foreach ($rows as $row) {
            $rendered = $this->renderLogRow($row);
            $bodyRows .= $rendered['row_html'] . $rendered['trace_html'];
            $displayed++;
        }

        $this->logger->debugMessage($displayed . ' log records displayed on the page.');
        if ($displayed === 0) {
            $bodyRows .= $this->f->str_replace('{message}', __('No Results To Display', '404-solution'), $this->tpl('viewLogsEmptyRow.html'));
        }

        return $bodyRows;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{row_html: string, trace_html: string}
     */
    private function renderLogRow(array $row): array {
        $logId = is_scalar($row['log_id'] ?? '') ? (string)($row['log_id'] ?? '') : '';
        $rawTrace = isset($row['pipeline_trace']) && is_string($row['pipeline_trace']) ? $row['pipeline_trace'] : null;
        $traceSteps = ABJ_404_Solution_LogsRepository::decompressPipelineTrace($rawTrace);
        $hasTrace = is_array($traceSteps) && !empty($traceSteps);

        $rowHtml = $this->f->str_replace(
            array('{trace_toggle}', '{url_cell}', '{ip_cell}', '{referrer_cell}', '{action_cell}', '{date_cell}'),
            array(
                $this->renderTraceToggle($logId, $hasTrace),
                $this->renderUrlCell($row),
                $this->renderIpCell($row),
                $this->renderReferrerCell($row),
                $this->renderActionCell($row),
                $this->renderDateCell($row),
            ),
            $this->tpl('viewLogsTableRow.html')
        );

        return array(
            'row_html' => $rowHtml,
            'trace_html' => ($hasTrace && $logId !== '') ? $this->renderTraceDetailRow($logId, $traceSteps) : '',
        );
    }

    private function renderTraceToggle(string $logId, bool $hasTrace): string {
        if (!$hasTrace) {
            return $this->f->str_replace('{title}', esc_attr__('No trace available', '404-solution'), $this->tpl('viewLogsTraceToggleDisabled.html'));
        }
        return $this->f->str_replace(
            array('{row_id}', '{title}'),
            array(esc_attr($logId), esc_attr__('Show pipeline trace', '404-solution')),
            $this->tpl('viewLogsTraceToggleEnabled.html')
        );
    }

    /** @param array<string, mixed> $row */
    private function renderUrlCell(array $row): string {
        $url = is_string($row['url'] ?? '') ? (string)($row['url'] ?? '') : '';
        $urlDetail = is_string($row['url_detail'] ?? '') ? (string)($row['url_detail'] ?? '') : '';
        $detailSpan = '';
        if ($urlDetail !== '' && trim($urlDetail) !== '') {
            $detailSpan = $this->f->str_replace('{detail}', esc_html(trim($urlDetail)), $this->tpl('viewLogsUrlDetailSpan.html'));
        }
        return $this->f->str_replace(
            array('{full_url}', '{url_attr}', '{url_text}', '{url_detail_span}'),
            array(esc_url(home_url($url)), esc_attr($url), esc_html($url), $detailSpan),
            $this->tpl('viewLogsUrlCell.html')
        );
    }

    /** @param array<string, mixed> $row */
    private function renderIpCell(array $row): string {
        $remoteHost = is_string($row['remote_host'] ?? '') ? (string)($row['remote_host'] ?? '') : '';
        return esc_html($remoteHost);
    }

    /** @param array<string, mixed> $row */
    private function renderReferrerCell(array $row): string {
        $referrer = is_string($row['referrer'] ?? '') ? (string)($row['referrer'] ?? '') : '';
        if ($referrer === '') {
            return $this->tpl('viewLogsReferrerMuted.html');
        }
        return $this->f->str_replace(
            array('{referrer_url}', '{referrer_attr}', '{referrer_text}'),
            array(esc_url($referrer), esc_attr($referrer), esc_html($referrer)),
            $this->tpl('viewLogsReferrerLink.html')
        );
    }

    /** @param array<string, mixed> $row */
    private function renderActionCell(array $row): string {
        $action = trim(is_string($row['action'] ?? '') ? (string)($row['action'] ?? '') : '');
        $engineVal = is_string($row['engine'] ?? '') ? (string)($row['engine'] ?? '') : '';
        if ($action === '' || $action == '404' || $action == 'http://404') {
            $actionCell = $this->f->str_replace('{label}', __('404', '404-solution'), $this->tpl('viewLogsActionBadge404.html'));
        } else {
            $actionCell = $this->f->str_replace(
                array('{label}', '{action_url}', '{action_attr}', '{action_text}'),
                array(__('Redirect', '404-solution'), esc_url($action), esc_attr($action), esc_html($action)),
                $this->tpl('viewLogsActionBadgeRedirect.html')
            );
        }
        if ($engineVal !== '') {
            $actionCell .= $this->f->str_replace(
                array('{engine_title}', '{engine_text}'),
                array(esc_attr__('Engine', '404-solution'), esc_html($engineVal)),
                $this->tpl('viewLogsEngineLabel.html')
            );
        }
        return $actionCell;
    }

    /** @param array<string, mixed> $row */
    private function renderDateCell(array $row): string {
        $timeToDisplay = abs(is_scalar($row['timestamp'] ?? 0) ? intval($row['timestamp'] ?? 0) : 0);
        $rowUsername = is_string($row['username'] ?? '') ? (string)($row['username'] ?? '') : '';
        $usernamePart = '';
        if ($rowUsername !== '') {
            $usernamePart = $this->f->str_replace('{username}', esc_html($rowUsername), $this->tpl('viewLogsUsernameLabel.html'));
        }
        return $this->f->str_replace(
            array('{date_part}', '{time_part}', '{username_part}'),
            array((string)wp_date('Y/m/d', $timeToDisplay), (string)wp_date('h:i:s A', $timeToDisplay), $usernamePart),
            $this->tpl('viewLogsDateCell.html')
        );
    }

    /** @param array<int, array<string, string>> $traceSteps */
    private function renderTraceDetailRow(string $logId, array $traceSteps): string {
        $stepsHtml = '';
        foreach ($traceSteps as $step) {
            $detail = ($step['detail'] !== '')
                ? $this->f->str_replace('{detail_text}', esc_html($step['detail']), $this->tpl('viewLogsTraceStepDetail.html'))
                : '';
            $stepsHtml .= $this->f->str_replace(
                array('{step_name}', '{outcome_class}', '{outcome}', '{detail}'),
                array(
                    esc_html($this->traceLabels->translate($step['step'])),
                    $this->traceLabels->outcomeClass($step['outcome']),
                    esc_html($this->traceLabels->translate($step['outcome'])),
                    $detail,
                ),
                $this->tpl('viewLogsTraceStep.html')
            );
        }
        return $this->f->str_replace(
            array('{row_id}', '{trace_steps}'),
            array(esc_attr($logId), $stepsHtml),
            $this->tpl('viewLogsTraceDetailRow.html')
        );
    }

    private function tpl(string $name): string {
        $raw = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . '/../html/' . $name, false);
        return rtrim((string)$raw, "\n");
    }
}
