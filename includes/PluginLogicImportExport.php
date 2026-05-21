<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Import/export functionality delegated to ABJ_404_Solution_ImportExportService.
 * Standalone class extracted from PluginLogicTrait_ImportExport.
 */
class ABJ_404_Solution_PluginLogicImportExport {

    /** @var callable */
    private $serviceFactory;

    /** @var ABJ_404_Solution_ImportExportService|null */
    private $service = null;

    /**
     * @param callable $serviceFactory Returns an ABJ_404_Solution_ImportExportService instance.
     */
    function __construct(callable $serviceFactory) {
        $this->serviceFactory = $serviceFactory;
    }

    /** @return ABJ_404_Solution_ImportExportService */
    private function getService() {
        if ($this->service === null) {
            $this->service = ($this->serviceFactory)();
        }
        return $this->service;
    }

    /** @return string */
    function getExportFilename(string $format = 'native'): string {
        return $this->getService()->getExportFilename($format);
    }

    /** @return void */
    function doExport(): void {
        $this->getService()->doExport();
    }

    /**
     * @param string $sourceFile Native export file path.
     * @param string $destinationFile Output file path.
     * @return string Empty string on success, error message otherwise.
     */
    function convertExportCsvToRedirectionFormat($sourceFile, $destinationFile) {
        return $this->getService()->convertExportCsvToRedirectionFormat($sourceFile, $destinationFile);
    }

    /** @return string */
    function doImportFile(): string {
        return $this->getService()->doImportFile();
    }

    /**
     * @param array<string, mixed> $dataArray
     * @param bool $dryRun
     * @return array<int, string>
     */
    function loadDataArrayFromFile(array $dataArray, bool $dryRun = false): array {
        return $this->getService()->loadDataArrayFromFile($dataArray, $dryRun);
    }

    /** @return array<string, string> */
    function splitCsvLine(string $line): array {
        return $this->getService()->splitCsvLine($line);
    }

    /**
     * @param array<int, string> $columns
     * @return bool
     */
    function isCompatibleImportHeaderRow(array $columns): bool {
        return $this->getService()->isCompatibleImportHeaderRow($columns);
    }

    /**
     * @param array<int, string> $columns
     * @return array<int, string>
     */
    function normalizeImportHeaders(array $columns): array {
        $result = $this->getService()->normalizeImportHeaders($columns);
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
        return $this->getService()->mapImportRowByHeaders($row, $normalizedHeaders);
    }

    /**
     * @param array<int, string> $columns
     * @return string
     */
    function detectImportFormatFromHeaders(array $columns): string {
        return $this->getService()->detectImportFormatFromHeaders($columns);
    }

}
