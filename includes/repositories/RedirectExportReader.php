<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads redirects that are eligible for server-format export.
 */
class ABJ_404_Solution_RedirectExportReader {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     */
    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore) {
        $this->dbCore = $dbCore;
    }

    /**
     * @return array<int, array{source: string, dest: string, code: int, is_regex: bool}>
     */
    public function getExportableRedirects(): array {
        $manualStatus = (int)ABJ404_STATUS_MANUAL;
        $regexStatus  = (int)ABJ404_STATUS_REGEX;
        $typeExternal = (int)ABJ404_TYPE_EXTERNAL;
        $typeHome     = (int)ABJ404_TYPE_HOME;

        $rows = $this->queryExportableRedirectRows($manualStatus, $regexStatus);
        if (empty($rows)) {
            return array();
        }

        $result = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $redirect = $this->mapExportableRedirectRow($this->exportAssocRow($row), $regexStatus, $typeExternal, $typeHome);
            if ($redirect !== null) {
                $result[] = $redirect;
            }
        }

        return $result;
    }

    /**
     * @param int $manualStatus
     * @param int $regexStatus
     * @return array<int, mixed>
     */
    private function queryExportableRedirectRows(int $manualStatus, int $regexStatus): array {
        $redirectsTable = $this->dbCore->doTableNameReplacements('{wp_abj404_redirects}');
        $cacheTable     = $this->dbCore->doTableNameReplacements('{wp_abj404_permalink_cache}');

        $queryResult = $this->dbCore->queryAndGetResults(
            "SELECT r.url, r.status, r.type, r.final_dest, r.code, r.disabled,
                    pc.url AS cached_url
             FROM {$redirectsTable} r
             LEFT JOIN {$cacheTable} pc ON r.final_dest = pc.id
             WHERE r.status IN (%d, %d)
               AND (r.disabled IS NULL OR r.disabled = 0)
               AND r.url IS NOT NULL AND r.url != ''
             ORDER BY r.url",
            array('query_params' => array($manualStatus, $regexStatus))
        );

        $rows = $queryResult['rows'] ?? array();
        return is_array($rows) ? $rows : array();
    }

    /**
     * @param array<mixed, mixed> $row
     * @return array<string, mixed>
     */
    private function exportAssocRow(array $row): array {
        $assoc = array();
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $assoc[$key] = $value;
            }
        }
        return $assoc;
    }

    /**
     * @param array<string, mixed> $row
     * @param int $regexStatus
     * @param int $typeExternal
     * @param int $typeHome
     * @return array{source: string, dest: string, code: int, is_regex: bool}|null
     */
    private function mapExportableRedirectRow(array $row, int $regexStatus, int $typeExternal, int $typeHome) {
        $source = $this->exportRowString($row, 'url');
        $status = $this->exportRowInt($row, 'status', 0);
        $code = $this->exportRowInt($row, 'code', 301);
        $type = $this->exportRowInt($row, 'type', 0);
        $finalDest = $this->exportRowString($row, 'final_dest');
        $cachedUrl = $this->exportRowString($row, 'cached_url');
        $dest = $this->resolveExportDestination($source, $code, $type, $finalDest, $cachedUrl, $typeExternal, $typeHome);
        if ($dest === null) {
            return null;
        }

        return array(
            'source'   => $source,
            'dest'     => $dest,
            'code'     => $code,
            'is_regex' => ($status === $regexStatus),
        );
    }

    /**
     * @param string $source
     * @param int $code
     * @param int $type
     * @param string $finalDest
     * @param string $cachedUrl
     * @param int $typeExternal
     * @param int $typeHome
     * @return string|null
     */
    private function resolveExportDestination(
        string $source,
        int $code,
        int $type,
        string $finalDest,
        string $cachedUrl,
        int $typeExternal,
        int $typeHome
    ) {
        if ($code === 410 || $code === 451) {
            return $source;
        }
        if ($cachedUrl !== '') {
            return $cachedUrl;
        }
        if ($type === $typeExternal) {
            return $finalDest;
        }
        if ($type === $typeHome) {
            return function_exists('home_url') ? home_url('/') : '/';
        }
        if (is_numeric($finalDest) && (int)$finalDest > 0) {
            if (function_exists('get_permalink')) {
                $url = get_permalink((int)$finalDest);
                return ($url !== false && is_string($url)) ? $url : ('/?p=' . $finalDest);
            }
            return '/?p=' . $finalDest;
        }
        if ($finalDest !== '') {
            return $finalDest;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @param string $key
     * @param string $default
     * @return string
     */
    private function exportRowString(array $row, string $key, string $default = ''): string {
        $value = $row[$key] ?? null;
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        return $default;
    }

    /**
     * @param array<string, mixed> $row
     * @param string $key
     * @param int $default
     * @return int
     */
    private function exportRowInt(array $row, string $key, int $default): int {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value;
        }
        return $default;
    }
}
