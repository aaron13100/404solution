<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Import/export functionality delegated to focused services:
 *   - ABJ_404_Solution_ExportService for export-side calls
 *   - ABJ_404_Solution_ImportService for import-side calls
 *
 * Standalone class composed from PluginLogic. Holds factories so the
 * underlying services are constructed lazily on first use.
 */
class ABJ_404_Solution_PluginLogicImportExport {

    /** @var callable */
    private $exportServiceFactory;

    /** @var callable */
    private $importServiceFactory;

    /** @var ABJ_404_Solution_ExportService|null */
    private $exportService = null;

    /** @var ABJ_404_Solution_ImportService|null */
    private $importService = null;

    /**
     * @param callable $exportServiceFactory Returns an ABJ_404_Solution_ExportService instance.
     * @param callable $importServiceFactory Returns an ABJ_404_Solution_ImportService instance.
     */
    function __construct(callable $exportServiceFactory, callable $importServiceFactory) {
        $this->exportServiceFactory = $exportServiceFactory;
        $this->importServiceFactory = $importServiceFactory;
    }

    /** @return ABJ_404_Solution_ExportService */
    private function getExportService() {
        if ($this->exportService === null) {
            $this->exportService = ($this->exportServiceFactory)();
        }
        return $this->exportService;
    }

    /** @return ABJ_404_Solution_ImportService */
    private function getImportService() {
        if ($this->importService === null) {
            $this->importService = ($this->importServiceFactory)();
        }
        return $this->importService;
    }

    /** @return string */
    function getExportFilename(string $format = 'native'): string {
        return $this->getExportService()->getExportFilename($format);
    }

    /** @return void */
    function doExport(): void {
        $this->getExportService()->doExport();
    }

    /**
     * @param string $sourceFile Native export file path.
     * @param string $destinationFile Output file path.
     * @return string Empty string on success, error message otherwise.
     */
    function convertExportCsvToRedirectionFormat($sourceFile, $destinationFile) {
        return $this->getExportService()->convertExportCsvToRedirectionFormat($sourceFile, $destinationFile);
    }

    /** @return string */
    function doImportFile(): string {
        return $this->getImportService()->doImportFile();
    }

    /**
     * @param array<string, mixed> $dataArray
     * @param bool $dryRun
     * @return array<int, string>
     */
    function loadDataArrayFromFile(array $dataArray, bool $dryRun = false): array {
        return $this->getImportService()->loadDataArrayFromFile($dataArray, $dryRun);
    }

    /** @return array<string, string> */
    function splitCsvLine(string $line): array {
        return $this->getImportService()->splitCsvLine($line);
    }

    /**
     * @param array<int, string> $columns
     * @return bool
     */
    function isCompatibleImportHeaderRow(array $columns): bool {
        return $this->getImportService()->isCompatibleImportHeaderRow($columns);
    }

    /**
     * @param array<int, string> $columns
     * @return array<int, string>
     */
    function normalizeImportHeaders(array $columns): array {
        $result = $this->getImportService()->normalizeImportHeaders($columns);
        return array_map(function ($v) {
            return is_string($v) ? $v : '';
        }, $result);
    }

    /**
     * @param array<int, string> $row
     * @param array<int, string> $normalizedHeaders
     * @return array<string, string>
     */
    function mapImportRowByHeaders(array $row, array $normalizedHeaders): array {
        return $this->getImportService()->mapImportRowByHeaders($row, $normalizedHeaders);
    }

    /**
     * @param array<int, string> $columns
     * @return string
     */
    function detectImportFormatFromHeaders(array $columns): string {
        return $this->getImportService()->detectImportFormatFromHeaders($columns);
    }

}
