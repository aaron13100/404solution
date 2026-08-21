<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads and shapes REST API list/stat/test data without owning route wiring.
 */
final class ABJ_404_Solution_RestApiReadService {

    /** @var ABJ_404_Solution_ViewReadServiceInterface */
    private $viewRead;
    /** @var ABJ_404_Solution_RedirectsRepositoryInterface */
    private $redirectsRepo;
    /** @var ABJ_404_Solution_LogsRepositoryInterface */
    private $logsRepo;
    /** @var ABJ_404_Solution_StatsRepositoryInterface */
    private $statsRepo;
    /** @var ABJ_404_Solution_DatabaseQueryInterface */
    private $dbCore;
    /** @var ABJ_404_Solution_PluginLogic */
    private $logic;
    /** @var ABJ_404_Solution_RestApiResponsePresenter */
    private $presenter;

    /**
     * @param ABJ_404_Solution_ViewReadServiceInterface $viewRead
     * @param ABJ_404_Solution_RedirectsRepositoryInterface $redirectsRepo
     * @param ABJ_404_Solution_LogsRepositoryInterface $logsRepo
     * @param ABJ_404_Solution_DatabaseQueryInterface $dbCore
     * @param ABJ_404_Solution_PluginLogic $logic
     */
    public function __construct(
        $viewRead,
        $redirectsRepo,
        $logsRepo,
        ABJ_404_Solution_StatsRepositoryInterface $statsRepo,
        $dbCore,
        $logic,
        ABJ_404_Solution_RestApiResponsePresenter $presenter
    ) {
        $this->viewRead = $viewRead;
        $this->redirectsRepo = $redirectsRepo;
        $this->logsRepo = $logsRepo;
        $this->statsRepo = $statsRepo;
        $this->dbCore = $dbCore;
        $this->logic = $logic;
        $this->presenter = $presenter;
    }

    /**
     * @param array{page: int, per_page: int, status_filter: string, filter_text: string} $input
     * @return \WP_REST_Response
     */
    public function redirects(array $input): \WP_REST_Response {
        $tableOptions = array(
            'orderby' => 'url',
            'order'   => 'ASC',
            'paged'   => $input['page'],
            'perpage' => $input['per_page'],
            'filter'  => $input['status_filter'],
            'logsid'  => 0,
            'sub'     => 'abj404_redirects',
        );

        $rows = $this->viewRead->getRedirectsForView('abj404_redirects', $tableOptions);
        $total = $this->viewRead->getRedirectsForViewCount('abj404_redirects', $tableOptions);

        return $this->presenter->paginated(is_array($rows) ? $rows : array(), intval($total), $input['page'], $input['per_page']);
    }

    /**
     * @param array{page: int, per_page: int} $input
     * @return \WP_REST_Response
     */
    public function captured(array $input): \WP_REST_Response {
        $types = array(ABJ404_STATUS_CAPTURED, ABJ404_STATUS_IGNORED, ABJ404_STATUS_LATER);
        $total = $this->viewRead->getRecordCount($types, 0);

        $redirectsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
        $statusIn = implode(', ', array_map('absint', $types));
        $limitStart = ($input['page'] - 1) * $input['per_page'];

        $queryResult = $this->dbCore->queryAndGetResults(
            "SELECT id, url, status, type, final_dest, code, timestamp, disabled
             FROM `{$redirectsTable}`
             WHERE status IN ({$statusIn}) AND disabled = 0
             ORDER BY url ASC
             LIMIT %d, %d",
            ['query_params' => [$limitStart, $input['per_page']]]
        );
        $rows = is_array($queryResult['rows'] ?? null) ? $queryResult['rows'] : array();

        return $this->presenter->paginated($rows, intval($total), $input['page'], $input['per_page']);
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function stats() {
        try {
            $snapshot = $this->statsRepo->getStatsDashboardSnapshot(true);
            $data = is_array($snapshot['data']) ? $snapshot['data'] : array();
            return $this->presenter->stats($data);
        } catch (\Throwable $e) {
            return $this->presenter->statsError($e);
        }
    }

    /**
     * @param array{page: int, per_page: int} $input
     * @return \WP_REST_Response
     */
    public function logs(array $input): \WP_REST_Response {
        $tableOptions = array(
            'orderby' => 'timestamp',
            'order'   => 'DESC',
            'paged'   => $input['page'],
            'perpage' => $input['per_page'],
            'logsid'  => 0,
            'filter'  => '',
        );

        $rows = $this->logsRepo->getLogRecords($tableOptions);
        $total = $this->viewRead->getLogsCount(0);

        return $this->presenter->paginated(is_array($rows) ? $rows : array(), intval($total), $input['page'], $input['per_page']);
    }

    /**
     * @param array{url: string} $input
     * @return \WP_REST_Response
     */
    public function testRedirect(array $input): \WP_REST_Response {
        $normalizedUrl = $this->logic->urlNormalization()->normalizeToRelativePath($input['url']);
        if (!is_string($normalizedUrl) || $normalizedUrl === '') {
            $normalizedUrl = $input['url'];
        }

        $redirect = $this->redirectsRepo->getExistingRedirectForURL($normalizedUrl);

        if (!is_array($redirect) || empty($redirect) || !isset($redirect['id']) || !is_scalar($redirect['id']) || intval($redirect['id']) === 0) {
            $matchedRegex = $this->findMatchedRegexRedirect($normalizedUrl);
            if ($matchedRegex !== null) {
                return $this->presenter->testRegexMatch($normalizedUrl, $matchedRegex);
            }

            return $this->presenter->testNoMatch($normalizedUrl);
        }

        return $this->presenter->testStoredMatch($normalizedUrl, $redirect);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findMatchedRegexRedirect(string $normalizedUrl) {
        $regexRedirects = $this->viewRead->getRedirectsWithRegEx();
        if (!is_iterable($regexRedirects)) {
            return null;
        }

        foreach ($regexRedirects as $rr) {
            if (!is_array($rr) || empty($rr['url'])) {
                continue;
            }
            $pattern = is_string($rr['url']) ? $rr['url'] : '';
            $delimited = '{' . $pattern . '}';
            if ($pattern !== '' && @preg_match($delimited, $normalizedUrl) === 1) {
                return $rr;
            }
        }

        return null;
    }
}
