<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: covered through public WP-CLI command entry points in tests/WPCLICommandsTest.php

/**
 * Application service for WP-CLI import and export file workflows.
 */
class ABJ_404_Solution_WPCLIImportExportCommandService {

    /** @var ABJ_404_Solution_Clock */
    private $clock;

    /** @var ABJ_404_Solution_DataAccess|null Injected aggregate root; null => resolve via the service locator. */
    private $dataAccess;

    /** @var ABJ_404_Solution_Logging|null Injected logger; null => resolve via the service locator. */
    private $logging;

    /**
     * @param ABJ_404_Solution_Clock|null $clock
     * @param ABJ_404_Solution_DataAccess|null $dataAccess Data-access aggregate root. When provided, the
     *     import/export collaborators (redirects + content repositories, view read service) are taken
     *     from it instead of the global service locator. Defaults to
     *     abj_service('data_access') so production wiring is unchanged. This injection seam exists so
     *     callers (WP-CLI, tests) can run the import/export workflow against an explicit DataAccess
     *     without mutating global container state.
     * @param ABJ_404_Solution_Logging|null $logging Logger. Defaults to abj_service('logging').
     */
    public function __construct($clock = null, $dataAccess = null, $logging = null) {
        $this->clock = $clock instanceof ABJ_404_Solution_Clock
            ? $clock
            : $this->defaultClock();
        $this->dataAccess = $dataAccess instanceof ABJ_404_Solution_DataAccess ? $dataAccess : null;
        $this->logging = $logging instanceof ABJ_404_Solution_Logging ? $logging : null;
    }

    /**
     * Resolve the redirects repository: from the injected DataAccess when present, else the
     * service-locator singleton (the exact resolution the original code used). Resolving the
     * individual collaborator rather than the whole DataAccess aggregate is deliberate: building
     * the full aggregate would also construct unrelated sub-services (retention/cleanup) that this
     * workflow never uses, changing construction-time behavior.
     * @return ABJ_404_Solution_RedirectsRepository
     */
    private function redirectsRepository() {
        if ($this->dataAccess instanceof ABJ_404_Solution_DataAccess) {
            return $this->dataAccess->getRedirectsRepo();
        }
        /** @var ABJ_404_Solution_RedirectsRepository $repo */
        $repo = abj_service('redirects_repository');
        return $repo;
    }

    /**
     * Resolve the content repository: from the injected DataAccess when present, else the
     * service-locator singleton.
     * @return ABJ_404_Solution_ContentRepository
     */
    private function contentRepository() {
        if ($this->dataAccess instanceof ABJ_404_Solution_DataAccess) {
            return $this->dataAccess->getContentRepo();
        }
        /** @var ABJ_404_Solution_ContentRepository $repo */
        $repo = abj_service('content_repository');
        return $repo;
    }

    /**
     * Resolve the view read service: from the injected DataAccess when present, else the
     * service-locator singleton.
     * @return ABJ_404_Solution_ViewReadService
     */
    private function viewReadService() {
        if ($this->dataAccess instanceof ABJ_404_Solution_DataAccess) {
            return $this->dataAccess->getViewReadService();
        }
        /** @var ABJ_404_Solution_ViewReadService $svc */
        $svc = abj_service('view_read_service');
        return $svc;
    }

    /**
     * Resolve the logger: the injected instance when present, else the service-locator singleton.
     * @return ABJ_404_Solution_Logging
     */
    private function loggingService() {
        if ($this->logging instanceof ABJ_404_Solution_Logging) {
            return $this->logging;
        }
        /** @var ABJ_404_Solution_Logging $logging */
        $logging = abj_service('logging');
        return $logging;
    }

    /**
     * @param string $filePath
     * @param bool $dryRun
     * @return array<string, mixed>
     */
    public function importRedirects(string $filePath, bool $dryRun): array {
        if ($filePath === '') {
            return $this->error('Please provide a path to the CSV file. Usage: wp abj404 import <file>');
        }
        if (!file_exists($filePath)) {
            return $this->error("File not found: {$filePath}");
        }

        $fileHandle = fopen($filePath, 'r');
        if ($fileHandle === false) {
            return $this->error("Could not open file: {$filePath}");
        }

        $svc = new ABJ_404_Solution_ImportService(
            $this->redirectsRepository(),
            $this->contentRepository(),
            $this->loggingService()
        );
        // The file handle must close even if detectCsvDelimiterFromFile(),
        // the deferred-invalidation callback, or loadDataArrayFromFile()
        // (called per-row below with no per-row exception guard, unlike the
        // browser-upload path in ImportService::processDataArray()) throws.
        // See includes/import/ImportService.php::doImportFile() for the
        // reference fix of this same resource-lifecycle shape.
        try {
            $delimiter = $svc->detectCsvDelimiterFromFile($fileHandle);
            rewind($fileHandle);

            $rowResult = $this->viewReadService()->runWithDeferredInvalidation(function () use (
                    $svc, $fileHandle, $delimiter, $dryRun) {
                $local = array(
                    'headerColumns' => null,
                    'processedRows' => 0,
                    'validRows' => 0,
                    'invalidRows' => 0,
                    'anyIssuesToNote' => array(),
                    'error' => null,
                );

                while (($row = fgetcsv($fileHandle, 0, $delimiter, '"', '\\')) !== false) {
                    if (!is_array($row)) {
                        $local['error'] = 'Could not parse CSV row.';
                        return $local;
                    }
                    $data = array_map(function($value) {
                        return trim((string)$value);
                    }, $row);

                    if (count($data) === 1 && $data[0] === '') {
                        continue;
                    }

                    if ($local['headerColumns'] === null && $svc->isCompatibleImportHeaderRow($data)) {
                        $local['headerColumns'] = $svc->normalizeImportHeaders($data);
                        continue;
                    }

                    $dataArray = ($local['headerColumns'] !== null)
                        ? $svc->mapImportRowByHeaders($data, $local['headerColumns'])
                        : $svc->mapImportRowWithoutHeaders($data);

                    if (isset($dataArray['error'])) {
                        $local['error'] = $dataArray['error'];
                        return $local;
                    }

                    if (isset($dataArray['from_url']) &&
                            ($dataArray['from_url'] === 'from_url' || $dataArray['from_url'] === 'request')) {
                        continue;
                    }

                    $local['processedRows']++;
                    $issues = $svc->loadDataArrayFromFile($dataArray, $dryRun);
                    if (count($issues) > 0) {
                        $local['invalidRows']++;
                    } else {
                        $local['validRows']++;
                    }
                    $local['anyIssuesToNote'] = array_merge($local['anyIssuesToNote'], $issues);
                }
                return $local;
            });
        } finally {
            fclose($fileHandle);
        }

        if (!empty($rowResult['error'])) {
            return $this->error((string)$rowResult['error']);
        }

        $validRows = (int)$rowResult['validRows'];
        $invalidRows = (int)$rowResult['invalidRows'];
        $processedRows = (int)$rowResult['processedRows'];
        $warnings = array_map('strval', array_slice($rowResult['anyIssuesToNote'], 0, 20));

        if ($dryRun) {
            return $this->line(
                "Dry run: valid={$validRows}, invalid={$invalidRows}, total={$processedRows}",
                $warnings
            );
        }

        return $this->success(
            "Import complete. Valid={$validRows}, invalid={$invalidRows}, total={$processedRows}",
            $warnings
        );
    }

    /**
     * @param string $format
     * @param string $output
     * @return array<string, mixed>
     */
    public function exportRedirects(string $format, string $output): array {
        $svc = new ABJ_404_Solution_ExportService(
            $this->viewReadService(),
            $this->loggingService(),
            $this->redirectsRepository()
        );

        $serverGenerators = array(
            'htaccess' => 'generateHtaccessRules',
            'nginx' => 'generateNginxRules',
            'cloudflare' => 'generateCloudflareWorkerScript',
            'netlify' => 'generateNetlifyRedirects',
            'vercel' => 'generateVercelRedirects',
        );
        if (isset($serverGenerators[$format])) {
            $content = $svc->{$serverGenerators[$format]}();
            if ($output !== '') {
                if (file_put_contents($output, $content) === false) {
                    return $this->error("Could not write to file: {$output}");
                }
                return $this->success("Exported {$format} rules to: {$output}");
            }
            return array('type' => 'content', 'content' => $content);
        }

        $tempFile = sys_get_temp_dir() . '/abj404_export_' . $this->clock->now() . '.csv';
        if ($format === 'redirection') {
            $nativeTemp = sys_get_temp_dir() . '/abj404_export_native_' . $this->clock->now() . '.csv';
            $this->viewReadService()->doRedirectsExport($nativeTemp);
            $error = $svc->convertExportCsvToRedirectionFormat($nativeTemp, $tempFile);
            @unlink($nativeTemp);
            if ($error !== '') {
                return $this->error("Export conversion failed: {$error}");
            }
        } else {
            $this->viewReadService()->doRedirectsExport($tempFile);
        }

        if (!file_exists($tempFile)) {
            @unlink($tempFile);
            return $this->line('No redirects to export.');
        }

        if ($output !== '') {
            if (!rename($tempFile, $output)) {
                if (!copy($tempFile, $output)) {
                    @unlink($tempFile);
                    return $this->error("Could not write to file: {$output}");
                }
                @unlink($tempFile);
            }
            return $this->success("Exported {$format} redirects to: {$output}");
        }

        $csv = file_get_contents($tempFile);
        @unlink($tempFile);
        if ($csv === false) {
            return $this->error('Could not read export temp file.');
        }
        return array('type' => 'content', 'content' => $csv);
    }

    private function defaultClock(): ABJ_404_Solution_Clock {
        $clock = ABJ_404_Solution_ServiceContainer::safeGet('clock');
        if ($clock instanceof ABJ_404_Solution_Clock) {
            return $clock;
        }
        return new ABJ_404_Solution_SystemClock();
    }

    /**
     * @param array<int, string> $warnings
     * @return array{type: string, message: string, warnings: array<int, string>}
     */
    private function error(string $message, array $warnings = array()): array {
        return array('type' => 'error', 'message' => $message, 'warnings' => $warnings);
    }

    /**
     * @param array<int, string> $warnings
     * @return array{type: string, message: string, warnings: array<int, string>}
     */
    private function line(string $message, array $warnings = array()): array {
        return array('type' => 'line', 'message' => $message, 'warnings' => $warnings);
    }

    /**
     * @param array<int, string> $warnings
     * @return array{type: string, message: string, warnings: array<int, string>}
     */
    private function success(string $message, array $warnings = array()): array {
        return array('type' => 'success', 'message' => $message, 'warnings' => $warnings);
    }
}
