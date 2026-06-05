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

    /** @param ABJ_404_Solution_Clock|null $clock */
    public function __construct($clock = null) {
        $this->clock = $clock instanceof ABJ_404_Solution_Clock
            ? $clock
            : $this->defaultClock();
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
            abj_service('redirects_repository'),
            abj_service('content_repository'),
            abj_service('logging')
        );
        $delimiter = $svc->detectCsvDelimiterFromFile($fileHandle);
        rewind($fileHandle);

        $rowResult = abj_service('view_read_service')->runWithDeferredInvalidation(function () use (
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
        fclose($fileHandle);

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

        abj_service('view_build_orchestrator')->invalidateViewDoneAndScheduleRebuild();
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
            abj_service('view_read_service'),
            abj_service('logging'),
            abj_service('redirects_repository')
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
            abj_service('view_read_service')->doRedirectsExport($nativeTemp);
            $error = $svc->convertExportCsvToRedirectionFormat($nativeTemp, $tempFile);
            @unlink($nativeTemp);
            if ($error !== '') {
                return $this->error("Export conversion failed: {$error}");
            }
        } else {
            abj_service('view_read_service')->doRedirectsExport($tempFile);
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
