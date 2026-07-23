<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders redirect and captured URL status filter links.
 */
class ABJ_404_Solution_AdminStatusFilterTabs {

    /** @var ABJ_404_Solution_Functions */
    private $f;
    /** @var ABJ_404_Solution_PluginLogic */
    private $logic;
    /** @var ABJ_404_Solution_Logging */
    private $logger;
    /** @var ABJ_404_Solution_ViewReadServiceInterface */
    private $viewReadService;
    /** @var ABJ_404_Solution_View_ListTableChrome */
    private $listTableChrome;

    /** @param ABJ_404_Solution_ViewReadServiceInterface $viewReadService */
    public function __construct(ABJ_404_Solution_Functions $functions, ABJ_404_Solution_PluginLogic $pluginLogic,
            ABJ_404_Solution_Logging $logging, $viewReadService,
            ABJ_404_Solution_View_ListTableChrome $listTableChrome) {
        $this->f = $functions;
        $this->logic = $pluginLogic;
        $this->logger = $logging;
        $this->viewReadService = $viewReadService;
        $this->listTableChrome = $listTableChrome;
    }

    /**
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     */
    public function render(string $sub, array $tableOptions): string {
        if (empty($tableOptions)) {
            $tableOptions = $this->logic->settingsUpdate()->getTableOptions($sub);
        }

        return $this->tpl('viewLogsTabFiltersClearBar.html')
            . $this->renderList($sub)
            . $this->tpl('viewLogsTabFiltersClearBarClose.html');
    }

    public function renderList(string $sub): string {
        $tableOptions = $this->logic->settingsUpdate()->getTableOptions($sub);
        $filter = isset($tableOptions['filter']) ? intval(is_scalar($tableOptions['filter']) ? $tableOptions['filter'] : 0) : 0;
        $url = $this->baseUrl($sub, $tableOptions);
        $filterState = $this->filterState($sub);
        $types = $filterState['types'];
        $counts = $filterState['counts'];
        $countsIncomplete = !empty($counts['_incomplete']);

        $html = $this->f->str_replace(
            '{placeholder_attr}',
            $countsIncomplete ? ' data-tab-counts-placeholder="1"' : '',
            $this->tpl('viewLogsTabFiltersListOpen.html')
        );
        if ($sub != 'abj404_captured') {
            $html .= $this->filterItem('', $url, ($filter == 0) ? ' class="current"' : '', __('All', '404-solution'), $this->displayCount($counts['all'] ?? 0, $countsIncomplete));
        }
        foreach ($types as $type) {
            $item = $this->typedFilterItem($sub, $url, $filter, (int)$type, $counts, $countsIncomplete);
            if ($item !== '') {
                $html .= $item;
            }
        }

        $trashurl = $url . '&filter=' . ABJ404_TRASH_FILTER;
        $trashClass = (($tableOptions['filter'] ?? 0) == ABJ404_TRASH_FILTER) ? ' class="current"' : '';
        $html .= $this->filterItem(' | ', $trashurl, $trashClass, __('Trash', '404-solution'), $this->displayCount($counts['trash'] ?? 0, $countsIncomplete));
        $html .= $this->tpl('viewLogsTabFiltersListClose.html');
        $html .= $this->tpl('viewLogsTabFiltersFormGap.html');

        return $html . $this->f->str_replace(
            '{action_url}',
            $this->listTableChrome->getBulkOperationsFormURL($sub, $tableOptions),
            $this->tpl('viewLogsTabFiltersBulkForm.html')
        );
    }

    /** @param array<string, mixed> $tableOptions */
    private function baseUrl(string $sub, array $tableOptions): string {
        $orderby = isset($tableOptions['orderby']) && is_string($tableOptions['orderby']) ? $tableOptions['orderby'] : 'url';
        $order = isset($tableOptions['order']) && is_string($tableOptions['order']) ? $tableOptions['order'] : 'ASC';
        $url = '?page=' . ABJ404_PP;
        if ($sub == 'abj404_captured') {
            $url .= '&subpage=abj404_captured';
        } else if ($sub == 'abj404_redirects') {
            $url .= '&subpage=abj404_redirects';
        } else {
            $this->logger->errorMessage('Unexpected sub page: ' . $sub);
        }
        $url .= '&orderby=' . sanitize_text_field($orderby);
        $url .= '&order=' . sanitize_text_field($order);

        return $url;
    }

    /**
     * @return array{types: array<int, int>, counts: array<string, int>}
     */
    private function filterState(string $sub): array {
        global $abj404_redirect_types;
        global $abj404_captured_types;

        if ($sub == 'abj404_redirects') {
            $types = $this->numericTypes($abj404_redirect_types, array(ABJ404_STATUS_MANUAL, ABJ404_STATUS_AUTO, ABJ404_STATUS_REGEX));
            return array('types' => $types, 'counts' => $this->viewReadService->getRedirectStatusCounts());
        }
        if ($sub == 'abj404_captured') {
            $types = $this->numericTypes($abj404_captured_types, array(ABJ404_STATUS_CAPTURED, ABJ404_STATUS_IGNORED, ABJ404_STATUS_LATER));
            return array('types' => $types, 'counts' => $this->viewReadService->getCapturedStatusCounts());
        }

        $this->logger->debugMessage('Unexpected sub type for tab filter: ' . $sub);
        return array('types' => array(ABJ404_STATUS_CAPTURED, ABJ404_STATUS_IGNORED, ABJ404_STATUS_LATER), 'counts' => array());
    }

    /**
     * @param mixed $candidate
     * @param array<int, int> $defaults
     * @return array<int, int>
     */
    private function numericTypes($candidate, array $defaults): array {
        if (!is_array($candidate)) {
            return $defaults;
        }
        // One pass: validate and convert together. Two passes (validate
        // everything, then convert everything) left the conversion loop's own
        // guards provably dead -- it could only ever see values the first loop
        // had already accepted -- so its `return $defaults` was unreachable
        // code standing in for a check that had already happened. Appending to
        // $ints also reindexes, which is what the array_values() call the
        // first pass needed was for.
        $ints = array();
        foreach ($candidate as $value) {
            if (is_int($value)) {
                $ints[] = $value;
                continue;
            }
            if (is_string($value) && ctype_digit($value)) {
                $ints[] = intval($value);
                continue;
            }
            return $defaults;
        }
        if (empty($ints)) {
            return $defaults;
        }
        return $ints;
    }

    /** @param array<string, int> $counts */
    private function typedFilterItem(string $sub, string $url, int $filter, int $type, array $counts, bool $countsIncomplete): string {
        if ($type == ABJ404_STATUS_REGEX) {
            return '';
        }
        $definition = $this->filterDefinition($type, $counts);
        if ($definition === null) {
            $this->logger->errorMessage('Unrecognized redirect type in View: ' . esc_html((string)$type));
            $definition = array('title' => __('Unknown', '404-solution'), 'count' => 0);
        }

        $prefix = ($sub != 'abj404_captured' || $type != ABJ404_STATUS_CAPTURED) ? ' | ' : '';
        $class = ($filter == $type) ? ' class="current"' : '';
        return $this->filterItem(
            $prefix,
            $url . '&filter=' . $type,
            $class,
            $definition['title'],
            $this->displayCount($definition['count'], $countsIncomplete)
        );
    }

    /**
     * @param array<string, int> $counts
     * @return array{title: string, count: int}|null
     */
    private function filterDefinition(int $type, array $counts): ?array {
        $definitions = array(
            ABJ404_STATUS_MANUAL => array(
                'title' => __('Manual Redirects', '404-solution'),
                'count' => intval($counts['manual'] ?? 0) + intval($counts['regex'] ?? 0),
            ),
            ABJ404_STATUS_AUTO => array(
                'title' => __('Automatic Redirects', '404-solution'),
                'count' => intval($counts['auto'] ?? 0),
            ),
            ABJ404_STATUS_CAPTURED => array(
                'title' => 'Captured URLs',
                'count' => intval($counts['captured'] ?? 0),
            ),
            ABJ404_STATUS_IGNORED => array(
                'title' => 'Ignored 404s',
                'count' => intval($counts['ignored'] ?? 0),
            ),
            ABJ404_STATUS_LATER => array(
                'title' => 'Organize Later',
                'count' => intval($counts['later'] ?? 0),
            ),
        );
        return $definitions[$type] ?? null;
    }

    /** @param int|string $count */
    private function filterItem(string $prefix, string $url, string $class, string $title, $count): string {
        return $this->f->str_replace(
            array('{prefix}', '{url}', '{class_attr}', '{title}', '{count}'),
            array($prefix, esc_url($url), $class, $title, esc_html((string)$count)),
            $this->tpl('viewLogsTabFilterItem.html')
        );
    }

    /** @param mixed $count */
    private function displayCount($count, bool $incomplete): string {
        return $incomplete ? '-' : (string)intval(is_scalar($count) ? $count : 0);
    }

    private function tpl(string $name): string {
        $raw = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . '/../html/' . $name, false);
        return rtrim((string)$raw, "\n");
    }
}
