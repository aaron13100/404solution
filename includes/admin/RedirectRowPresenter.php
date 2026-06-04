<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Presents one Page Redirects table row using the row templates.
 */
class ABJ_404_Solution_RedirectRowPresenter {

    /** @var ABJ_404_Solution_Functions */
    private $functions;

    /** @var ABJ_404_Solution_RedirectDestinationWarningPolicy */
    private $warningPolicy;

    /** @var ABJ_404_Solution_RedirectDestinationLinkResolver */
    private $linkResolver;

    /** @var ABJ_404_Solution_RedirectRowActionsPresenter */
    private $actionsPresenter;

    public function __construct(ABJ_404_Solution_Functions $functions,
            ABJ_404_Solution_RedirectDestinationWarningPolicy $warningPolicy,
            ABJ_404_Solution_RedirectDestinationLinkResolver $linkResolver,
            ABJ_404_Solution_RedirectRowActionsPresenter $actionsPresenter) {
        $this->functions = $functions;
        $this->warningPolicy = $warningPolicy;
        $this->linkResolver = $linkResolver;
        $this->actionsPresenter = $actionsPresenter;
    }

    /**
     * @param array<string, mixed> $row
     * @param string $sub
     * @param array<string, mixed> $tableOptions
     * @param array<mixed> $deadDestIds
     */
    public function render(array $row, string $sub, array $tableOptions, array $deadDestIds, int $y): string {
        $state = $this->viewState($row, $sub, $tableOptions, $deadDestIds, $y);
        return $this->fillRedirectRowTemplate($this->templateReplacements($row, $state));
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $tableOptions
     * @param array<mixed> $deadDestIds
     * @return array{
     *   rowType: mixed,
     *   rowStatus: int,
     *   rowFinalDest: string,
     *   rowUrl: string,
     *   lastUsed: int,
     *   destLink: array{link: string, title: string},
     *   link: string,
     *   regexState: array{class: string, normalStyle: string, warningStyle: string},
     *   rowClass: string,
     *   actions: array{
     *     edit: string,
     *     logs: string,
     *     trash: string,
     *     delete: string,
     *     links: array{editlink: string, logslink: string, trashlink: string, ajaxTrashLink: string, trashtitle: string, deletelink: string}
     *   },
     *   warning: array{exists: string, notExists: string, text: string, destForView: string},
     *   rowEngine: string
     * }
     */
    private function viewState(array $row, string $sub, array $tableOptions, array $deadDestIds, int $y): array {
        $rowType = $this->rowType($row);
        $rowStatus = $this->rowStatus($row);
        $rowFinalDest = $this->rowFinalDest($row);
        $destForView = $this->destForView($row);
        $rowUrl = $this->rowUrl($row);
        $lastUsed = $this->lastUsed($row);
        $destLink = $this->linkResolver->resolve($rowType, $rowFinalDest);
        $destinationIsMissing = $this->destinationIsMissing($rowType, $rowFinalDest);
        $regexState = $this->regexState($rowUrl, $rowStatus);

        return array(
            'rowType' => $rowType,
            'rowStatus' => $rowStatus,
            'rowFinalDest' => $rowFinalDest,
            'rowUrl' => $rowUrl,
            'lastUsed' => $lastUsed,
            'destLink' => $destLink,
            'link' => $destLink['link'] !== '' ? "href='" . esc_url($destLink['link']) . "'" : '',
            'regexState' => $regexState,
            'rowClass' => $this->rowClass($y) . $this->destinationClass($row, $destinationIsMissing) . $regexState['class'],
            'actions' => $this->actionsPresenter->render($row, $sub, $tableOptions),
            'warning' => $this->warningPolicy->resolve(
                $row,
                $rowType,
                $rowFinalDest,
                $destForView,
                $destinationIsMissing,
                $deadDestIds
            ),
            'rowEngine' => $this->rowEngine($row),
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param array{
     *   rowType: mixed,
     *   rowStatus: int,
     *   rowFinalDest: string,
     *   rowUrl: string,
     *   lastUsed: int,
     *   destLink: array{link: string, title: string},
     *   link: string,
     *   regexState: array{class: string, normalStyle: string, warningStyle: string},
     *   rowClass: string,
     *   actions: array{
     *     edit: string,
     *     logs: string,
     *     trash: string,
     *     delete: string,
     *     links: array{editlink: string, logslink: string, trashlink: string, ajaxTrashLink: string, trashtitle: string, deletelink: string}
     *   },
     *   warning: array{exists: string, notExists: string, text: string, destForView: string},
     *   rowEngine: string
     * } $state
     * @return array<string, string>
     */
    private function templateReplacements(array $row, array $state): array {
        $rowStatus = $state['rowStatus'];
        $lastUsed = $state['lastUsed'];
        $rowEngine = $state['rowEngine'];
        $rowCode = is_scalar($row['code'] ?? '') ? (string)($row['code'] ?? '') : '';
        $last = $lastUsed != 0 ? (string)wp_date('Y/m/d h:i:s A', abs($lastUsed)) : __('Never Used', '404-solution');
        $rowId = is_scalar($row['id'] ?? '') ? (string)($row['id'] ?? '') : '';
        $regexState = $state['regexState'];
        $actions = $state['actions'];
        $warning = $state['warning'];
        $destLink = $state['destLink'];
        $links = $actions['links'];

        return array(
            '{rowid}' => $rowId,
            '{rowClass}' => $state['rowClass'],
            '{visitorURL}' => esc_url(home_url($state['rowUrl'])),
            '{rowURL}' => esc_html($state['rowUrl']),
            '{url-is-normal}' => $regexState['normalStyle'],
            '{url-looks-like-regex}' => $regexState['warningStyle'],
            '{editBtnHTML}' => $actions['edit'],
            '{logsBtnHTML}' => $actions['logs'],
            '{trashBtnHTML}' => $actions['trash'],
            '{deleteBtnHTML}' => $actions['delete'],
            '{statusBadgeClass}' => $this->statusBadgeClass($rowStatus),
            '{codeBadgeClass}' => $this->codeBadgeClass($rowCode),
            '{lastUsedClass}' => $lastUsed == 0 ? 'abj404-never-used' : '',
            '{link}' => $this->scalarString($state['link'] ?? ''),
            '{title}' => esc_attr($destLink['title']),
            '{dest}' => esc_attr($warning['destForView']),
            '{destination-exists}' => $warning['exists'],
            '{destination-does-not-exist}' => $warning['notExists'],
            '{destination-warning-text}' => $warning['text'],
            '{status}' => $this->scalarString($row['status_for_view'] ?? ''),
            '{statusTitle}' => $this->statusTitle($rowStatus),
            '{engineHTML}' => $this->engineLabelHtml($rowEngine),
            '{rowScore}' => '',
            '{scoreCell}' => $this->buildScoreCell($row['score'] ?? null, $rowEngine),
            '{type}' => $this->scalarString($row['type_for_view'] ?? ''),
            '{rowCode}' => esc_html($this->codeDisplay($rowCode)),
            '{hits}' => esc_html((string)(is_scalar($row['logshits'] ?? 0) ? (int)($row['logshits'] ?? 0) : 0)),
            '{logsLink}' => $links['logslink'],
            '{trashLink}' => $links['trashlink'],
            '{ajaxTrashLink}' => $links['ajaxTrashLink'],
            '{trashtitle}' => $links['trashtitle'],
            '{deletelink}' => $links['deletelink'],
            '{created_date}' => esc_html((string)wp_date(
                'Y/m/d h:i:s A',
                abs(is_scalar($row['timestamp'] ?? 0) ? intval($row['timestamp'] ?? 0) : 0)
            )),
            '{last_used_date}' => esc_html($last),
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return mixed
     */
    private function rowType(array $row) {
        return $row['type'] ?? 0;
    }

    /** @param array<string, mixed> $row */
    private function rowStatus(array $row): int {
        return is_scalar($row['status'] ?? 0) ? (int)($row['status'] ?? 0) : 0;
    }

    /** @param array<string, mixed> $row */
    private function rowFinalDest(array $row): string {
        return is_string($row['final_dest'] ?? '') ? (string)($row['final_dest'] ?? '') : '';
    }

    /** @param array<string, mixed> $row */
    private function destForView(array $row): string {
        return trim(is_scalar($row['dest_for_view'] ?? '') ? (string)($row['dest_for_view'] ?? '') : '');
    }

    /** @param array<string, mixed> $row */
    private function rowUrl(array $row): string {
        return is_string($row['url'] ?? '') ? (string)($row['url'] ?? '') : '';
    }

    /** @param array<string, mixed> $row */
    private function lastUsed(array $row): int {
        return is_scalar($row['last_used'] ?? 0) ? (int)($row['last_used'] ?? 0) : 0;
    }

    /** @param mixed $rowType */
    private function destinationIsMissing($rowType, string $rowFinalDest): bool {
        return $rowType != ABJ404_TYPE_404_DISPLAYED && trim((string)$rowFinalDest) === '';
    }

    /** @param array<string, mixed> $row */
    private function rowEngine(array $row): string {
        return is_string($row['engine'] ?? '') ? trim((string)($row['engine'] ?? '')) : '';
    }

    /**
     * @param array<string, string> $replacements
     */
    public function fillRedirectRowTemplate(array $replacements): string {
        $html = $this->tpl('tableRowPageRedirects.html');
        $html = $this->functions->str_replace(array_keys($replacements), array_values($replacements), $html);
        return $this->functions->doNormalReplacements($html);
    }

    /**
     * @param mixed $rawScore
     */
    public function buildScoreCell($rawScore, string $rowEngine): string {
        if ($rawScore !== null && $rawScore !== '') {
            $scoreNum = (float)(is_numeric($rawScore) ? $rawScore : 0);
            $scorePct = number_format($scoreNum, 0);
            return $this->functions->str_replace(
                array('{badge_class}', '{score_pct}'),
                array($this->scoreBadgeClass($scoreNum), esc_html($scorePct)),
                $this->tpl('viewRedirectsTableScoreBadge.html')
            );
        }

        $noScoreTitle = ($rowEngine !== '')
            ? __('No confidence score for this engine', '404-solution')
            : __('Manual redirect, no confidence score', '404-solution');
        return $this->functions->str_replace(
            '{title_attr}',
            esc_attr($noScoreTitle),
            $this->tpl('viewRedirectsTableScoreManual.html')
        );
    }

    /**
     * @param mixed $rowStatus
     */
    private function statusTitle($rowStatus): string {
        if ($rowStatus == ABJ404_STATUS_MANUAL) {
            return __('Manually created', '404-solution');
        }
        if ($rowStatus == ABJ404_STATUS_AUTO) {
            return __('Automatically created', '404-solution');
        }
        if ($rowStatus == ABJ404_STATUS_REGEX) {
            return __('Regular Expression (Manually Created)', '404-solution');
        }
        return __('Unknown', '404-solution');
    }

    /**
     * @param mixed $rowStatus
     */
    private function statusBadgeClass($rowStatus): string {
        if ($rowStatus == ABJ404_STATUS_AUTO) {
            return 'abj404-badge-auto';
        }
        if ($rowStatus == ABJ404_STATUS_REGEX) {
            return 'abj404-badge-regex';
        }
        return 'abj404-badge-manual';
    }

    private function codeBadgeClass(string $rowCode): string {
        $codeBadgeMap = array(
            '301' => 'abj404-badge-301',
            '302' => 'abj404-badge-302',
            '307' => 'abj404-badge-307',
            '308' => 'abj404-badge-308',
            '410' => 'abj404-badge-410',
            '451' => 'abj404-badge-451',
            '0' => 'abj404-badge-meta',
        );
        return isset($codeBadgeMap[$rowCode]) ? $codeBadgeMap[$rowCode] : 'abj404-badge-302';
    }

    private function codeDisplay(string $rowCode): string {
        if (abj_service('settings_mode_preference')->getMode() === 'simple') {
            return ABJ_404_Solution_View_RedirectTypeUI::getPlainLanguageCodeLabel($rowCode);
        }
        return $rowCode;
    }

    private function scoreBadgeClass(float $scoreNum): string {
        if ($scoreNum >= 80) {
            return 'abj404-score-high';
        }
        if ($scoreNum >= 50) {
            return 'abj404-score-medium';
        }
        return 'abj404-score-low';
    }

    /** @return array{class: string, normalStyle: string, warningStyle: string} */
    private function regexState(string $rowUrl, int $rowStatus): array {
        /** @var ABJ_404_Solution_RegexHelper $regexHelper */
        $regexHelper = abj_service('regex_helper');
        $urlLooksLikeRegex = $regexHelper->urlLooksLikeRegex($rowUrl);
        if ($urlLooksLikeRegex && $rowStatus != ABJ404_STATUS_REGEX) {
            return array('class' => ' url-looks-like-regex', 'normalStyle' => 'display: none;', 'warningStyle' => '');
        }
        return array('class' => '', 'normalStyle' => '', 'warningStyle' => 'display: none;');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function destinationClass(array $row, bool $destinationIsMissing): string {
        if ($destinationIsMissing) {
            return ' destination-does-not-exist';
        }
        if (array_key_exists('published_status', $row) && $row['published_status'] == '0') {
            return ' destination-does-not-exist';
        }
        return '';
    }

    private function rowClass(int $y): string {
        return $y == 0 ? 'alternate' : 'normal-non-alternate';
    }

    private function engineLabelHtml(string $rowEngine): string {
        if ($rowEngine === '') {
            return '';
        }
        return $this->functions->str_replace(
            '{engine}',
            esc_html($rowEngine),
            $this->tpl('viewRedirectsTableEngineLabel.html')
        );
    }

    /**
     * @param mixed $value
     */
    private function scalarString($value): string {
        return is_string($value) ? $value : '';
    }

    private function tpl(string $name): string {
        $raw = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/' . $name, false);
        return rtrim((string)$raw, "\n");
    }
}
