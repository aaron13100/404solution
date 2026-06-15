<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WP-CLI command group for 404 Solution.
 *
 * Registered as: wp abj404 <subcommand>
 */
class ABJ_404_Solution_WPCLICommands extends \WP_CLI_Command {

    /** @var ABJ_404_Solution_WPCLIImportExportCommandService|null Injected service; null => container/new fallback. */
    private $injectedImportExportService;

    /**
     * @param ABJ_404_Solution_WPCLIImportExportCommandService|null $importExportService Optional injected
     *     import/export application service. WP-CLI instantiates this command class with no constructor
     *     arguments, so the parameter is optional and production wiring is unchanged. The seam exists so
     *     tests (and any embedder) can drive the import/export workflow against an explicit DataAccess
     *     without mutating global container state.
     */
    public function __construct($importExportService = null) {
        $this->injectedImportExportService =
            $importExportService instanceof ABJ_404_Solution_WPCLIImportExportCommandService
                ? $importExportService
                : null;
    }

    /**
     * List redirects.
     *
     * @subcommand list
     *
     * @param array<int, string> $args
     * @param array<string, string> $assocArgs
     * @return void
     */
    public function list_redirects($args, $assocArgs) {
        $this->presenter()->present($this->redirectService()->listRedirects(
            $this->assocString($assocArgs, 'status', ''),
            $this->assocString($assocArgs, 'format', 'table')
        ));
    }

    /**
     * Create a manual redirect.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assocArgs
     * @return void
     */
    public function create($args, $assocArgs) {
        $this->presenter()->present($this->redirectService()->createRedirect(
            isset($assocArgs['from']) ? trim((string)$assocArgs['from']) : '',
            isset($assocArgs['to']) ? trim((string)$assocArgs['to']) : '',
            isset($assocArgs['code']) ? (int)$assocArgs['code'] : 301,
            isset($assocArgs['regex'])
        ));
    }

    /**
     * Move a redirect to the trash.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assocArgs
     * @return void
     */
    public function delete($args, $assocArgs) {
        $this->presenter()->present($this->redirectService()->deleteRedirect(
            isset($args[0]) ? trim((string)$args[0]) : ''
        ));
    }

    /**
     * Show summary statistics.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assocArgs
     * @return void
     */
    public function stats($args, $assocArgs) {
        $container = ABJ_404_Solution_ServiceContainer::getInstance();
        $statsRepository = null;
        if (!$container->has('stats_repository') && $container->has('data_access')) {
            $candidate = $container->get('data_access');
            if (is_object($candidate) && method_exists($candidate, 'getStatsDashboardSnapshot')) {
                $statsRepository = $candidate;
            }
        }
        if ($statsRepository === null) {
            $statsRepository = ABJ_404_Solution_StatsRepositoryResolver::resolve(__CLASS__);
        }
        $snapshot = $statsRepository->getStatsDashboardSnapshot(false);
        $data = is_array($snapshot['data']) ? $snapshot['data'] : array();

        /** @var array<string, mixed> $dataAsStrMap */
        $dataAsStrMap = $data;
        $redirects = isset($dataAsStrMap['redirects']) && is_array($dataAsStrMap['redirects']) ? $dataAsStrMap['redirects'] : array();
        $captured = isset($dataAsStrMap['captured']) && is_array($dataAsStrMap['captured']) ? $dataAsStrMap['captured'] : array();

        $this->presenter()->present(array(
            'type' => 'format',
            'format' => 'table',
            'rows' => array(
                array('metric' => 'Auto (301)', 'count' => intval($redirects['auto301'] ?? 0)),
                array('metric' => 'Auto (302)', 'count' => intval($redirects['auto302'] ?? 0)),
                array('metric' => 'Manual (301)', 'count' => intval($redirects['manual301'] ?? 0)),
                array('metric' => 'Manual (302)', 'count' => intval($redirects['manual302'] ?? 0)),
                array('metric' => 'Trashed', 'count' => intval($redirects['trashed'] ?? 0)),
                array('metric' => 'Captured 404s', 'count' => intval($captured['captured'] ?? 0)),
                array('metric' => 'Ignored', 'count' => intval($captured['ignored'] ?? 0)),
                array('metric' => 'Captured trash', 'count' => intval($captured['trashed'] ?? 0)),
            ),
            'fields' => array('metric', 'count'),
        ));
    }

    /**
     * Purge captured 404s.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assocArgs
     * @return void
     */
    public function purge($args, $assocArgs) {
        $prepared = $this->redirectService()->preparePurge(
            isset($args[0]) ? strtolower(trim((string)$args[0])) : ''
        );
        if (($prepared['type'] ?? '') !== 'confirm') {
            $this->presenter()->present($prepared);
            return;
        }

        $count = isset($prepared['count']) && is_numeric($prepared['count']) ? (int)$prepared['count'] : 0;
        $this->presenter()->confirm(
            "This will permanently delete {$count} captured 404 entr" . ($count === 1 ? 'y' : 'ies') . '. Continue?',
            $assocArgs
        );
        $this->presenter()->present($this->redirectService()->purgeCaptured($count));
    }

    /**
     * Import redirects from a CSV file.
     *
     * @subcommand import
     *
     * @param array<int, string> $args
     * @param array<string, string> $assocArgs
     * @return void
     */
    public function import_redirects($args, $assocArgs) {
        $this->presenter()->present($this->importExportService()->importRedirects(
            isset($args[0]) ? (string)$args[0] : '',
            isset($assocArgs['dry-run'])
        ));
    }

    /**
     * Export redirects to stdout or a file.
     *
     * @subcommand export
     *
     * @param array<int, string> $args
     * @param array<string, string> $assocArgs
     * @return void
     */
    public function export_redirects($args, $assocArgs) {
        $this->presenter()->present($this->importExportService()->exportRedirects(
            $this->assocString($assocArgs, 'format', 'native'),
            isset($assocArgs['output']) ? trim((string)$assocArgs['output']) : ''
        ));
    }

    /**
     * Flush one or more internal caches.
     *
     * @subcommand flush-cache
     *
     * @param array<int, string> $args
     * @param array<string, string> $assocArgs
     * @return void
     */
    public function flush_cache($args, $assocArgs) {
        $type = $this->assocString($assocArgs, 'type', 'all');
        $validTypes = array('spelling', 'ngram', 'permalink', 'all');
        if (!in_array($type, $validTypes, true)) {
            $this->presenter()->present(array('type' => 'error', 'message' => 'Invalid type. Choose one of: ' . implode(', ', $validTypes)));
            return;
        }

        $dbCore = abj_service('db_core');
        $contentRepository = abj_service('content_repository');
        $flushed = array();

        if ($type === 'spelling' || $type === 'all') {
            $contentRepository->deleteSpellingCache();
            $flushed[] = 'spelling';
        }

        if ($type === 'permalink' || $type === 'all') {
            $contentRepository->truncatePermalinkCacheTable();
            $flushed[] = 'permalink';
        }

        if ($type === 'ngram' || $type === 'all') {
            $ngramTable = $dbCore->doTableNameReplacements('{wp_abj404_ngram_cache}');
            $dbCore->queryAndGetResults(
                "TRUNCATE TABLE `{$ngramTable}`",
                array('skip_repair' => true)
            );
            delete_option('abj404_ngram_cache_initialized');
            delete_option('abj404_ngram_rebuild_offset');
            $flushed[] = 'ngram';
        }

        $this->presenter()->present(array('type' => 'success', 'message' => 'Flushed caches: ' . implode(', ', $flushed)));
    }

    /**
     * Test which stored redirect would fire for a given URL.
     *
     * @subcommand test
     *
     * @param array<int, string> $args
     * @param array<string, string> $assocArgs
     * @return void
     */
    public function test_redirect($args, $assocArgs) {
        $this->presenter()->present($this->redirectService()->testRedirect(
            isset($args[0]) ? trim((string)$args[0]) : ''
        ));
    }

    private function presenter(): ABJ_404_Solution_WPCLICommandPresenter {
        $presenter = ABJ_404_Solution_ServiceContainer::safeGet('wpcli_command_presenter');
        if ($presenter instanceof ABJ_404_Solution_WPCLICommandPresenter) {
            return $presenter;
        }
        return new ABJ_404_Solution_WPCLICommandPresenter();
    }

    private function redirectService(): ABJ_404_Solution_WPCLIRedirectCommandService {
        $service = ABJ_404_Solution_ServiceContainer::safeGet('wpcli_redirect_command_service');
        if ($service instanceof ABJ_404_Solution_WPCLIRedirectCommandService) {
            return $service;
        }
        return new ABJ_404_Solution_WPCLIRedirectCommandService();
    }

    private function importExportService(): ABJ_404_Solution_WPCLIImportExportCommandService {
        if ($this->injectedImportExportService instanceof ABJ_404_Solution_WPCLIImportExportCommandService) {
            return $this->injectedImportExportService;
        }
        $service = ABJ_404_Solution_ServiceContainer::safeGet('wpcli_import_export_command_service');
        if ($service instanceof ABJ_404_Solution_WPCLIImportExportCommandService) {
            return $service;
        }
        return new ABJ_404_Solution_WPCLIImportExportCommandService();
    }

    /**
     * @param array<string, string> $assocArgs
     * @param string $key
     * @param string $default
     * @return string
     */
    private function assocString(array $assocArgs, string $key, string $default): string {
        return isset($assocArgs[$key]) ? strtolower(trim((string)$assocArgs[$key])) : $default;
    }
}
