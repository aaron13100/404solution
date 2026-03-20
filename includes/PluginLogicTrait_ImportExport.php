<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Import/export functionality delegated to ABJ_404_Solution_ImportExportService.
 * Used by ABJ_404_Solution_PluginLogic via `use`.
 */
trait ABJ_404_Solution_PluginLogicTrait_ImportExport {

    /** @return string */
    function getExportFilename(string $format = 'native'): string {
        return $this->getImportExportService()->getExportFilename($format);
    }

    /** @return void */
    function doExport(): void {
        $this->getImportExportService()->doExport();
    }

    /**
     * Convert native export format to a Redirection-compatible CSV shape.
     *
     * @param string $sourceFile Native export file path.
     * @param string $destinationFile Output file path.
     * @return string Empty string on success, error message otherwise.
     */
    function convertExportCsvToRedirectionFormat($sourceFile, $destinationFile) {
        return $this->getImportExportService()->convertExportCsvToRedirectionFormat($sourceFile, $destinationFile);
    }

    /** Expected formats are
     * from_url,status,type,to_url,wp_type
     * from_url,to_url
     */
    /** @return string */
    function doImportFile(): string {
        return $this->getImportExportService()->doImportFile();
    }

    /**
     * @param array<string, mixed> $dataArray
     * @param bool $dryRun
     * @return array<int, string>
     */
    function loadDataArrayFromFile(array $dataArray, bool $dryRun = false): array {
        return $this->getImportExportService()->loadDataArrayFromFile($dataArray, $dryRun);
    }

    /** @return array<string, string> */
    function splitCsvLine(string $line): array {
        return $this->getImportExportService()->splitCsvLine($line);
    }

    /**
     * Detect whether this row appears to be a compatible competitor header row.
     *
     * @param array<int, string> $columns
     * @return bool
     */
    function isCompatibleImportHeaderRow(array $columns): bool {
        return $this->getImportExportService()->isCompatibleImportHeaderRow($columns);
    }

    /**
     * Normalize import headers for matching.
     *
     * @param array<int, string> $columns
     * @return array<int, string>
     */
    function normalizeImportHeaders(array $columns): array {
        $result = $this->getImportExportService()->normalizeImportHeaders($columns);
        return array_map(function ($v) {
            return is_string($v) ? $v : '';
        }, $result);
    }

    /**
     * Map CSV row values into from_url/to_url using known competitor headers.
     *
     * @param array<int, string> $row
     * @param array<int, string> $normalizedHeaders
     * @return array<string, string>
     */
    function mapImportRowByHeaders(array $row, array $normalizedHeaders): array {
        return $this->getImportExportService()->mapImportRowByHeaders($row, $normalizedHeaders);
    }

    /**
     * Detect import format by CSV header row.
     *
     * @param array<int, string> $columns
     * @return string
     */
    function detectImportFormatFromHeaders(array $columns): string {
        return $this->getImportExportService()->detectImportFormatFromHeaders($columns);
    }

}
