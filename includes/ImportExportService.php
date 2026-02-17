<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles redirect CSV import/export behavior.
 *
 * Kept intentionally focused so PluginLogic stays readable.
 */
class ABJ_404_Solution_ImportExportService {

    /** @var ABJ_404_Solution_DataAccess */
    private $dao;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    function __construct($dataAccess, $logging) {
        $this->dao = $dataAccess;
        $this->logger = $logging;
    }

    function getExportFilename($format = 'native') {
        if ($format === 'redirection') {
            return abj404_getUploadsDir() . 'export-redirection.csv';
        }
        return abj404_getUploadsDir() . 'export.csv';
    }

    function doExport() {
        $format = isset($_REQUEST['export_format']) ? sanitize_text_field((string)$_REQUEST['export_format']) : 'native';
        $tempFile = $this->getExportFilename($format);

        if ($format === 'redirection') {
            $nativeExportFile = $this->getExportFilename('native');
            $this->dao->doRedirectsExport($nativeExportFile);
            $error = $this->convertExportCsvToRedirectionFormat($nativeExportFile, $tempFile);
            if ($error !== '') {
                $this->logger->warn($error);
                return;
            }
        } else {
            $this->dao->doRedirectsExport($tempFile);
        }

        if (file_exists($tempFile)) {
            header('Content-Description: File Transfer');
            header('Content-Disposition: attachment; filename=' . basename($tempFile));
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($tempFile));
            header('Content-Type: text/csv; charset=utf-8');
            readfile($tempFile);
            exit();
        }

        $this->logger->infoMessage("I don't see any data to export.");
    }

    /**
     * Convert native export format to a Redirection-compatible CSV shape.
     *
     * @param string $sourceFile Native export file path.
     * @param string $destinationFile Output file path.
     * @return string Empty string on success, error message otherwise.
     */
    function convertExportCsvToRedirectionFormat($sourceFile, $destinationFile) {
        if (!file_exists($sourceFile)) {
            return 'Error: Native export file does not exist.';
        }

        $in = fopen($sourceFile, 'r');
        if ($in === false) {
            return 'Error: Could not read native export file.';
        }

        $out = fopen($destinationFile, 'w');
        if ($out === false) {
            fclose($in);
            return 'Error: Could not create Redirection export file.';
        }

        fputcsv($out, array('source', 'target', 'regex', 'code'), ',', '"', '\\');
        fgetcsv($in, 0, ',', '"', '\\');
        while (($row = fgetcsv($in, 0, ',', '"', '\\')) !== false) {
            if (!is_array($row) || count($row) < 4) {
                continue;
            }
            $from = trim((string)$row[0]);
            $status = trim((string)$row[1]);
            $to = trim((string)$row[3]);
            if ($from === '' || $to === '') {
                continue;
            }

            $regexFlag = (strtolower($status) === 'regex') ? '1' : '0';
            fputcsv($out, array($from, $to, $regexFlag, '301'), ',', '"', '\\');
        }

        fclose($in);
        fclose($out);
        return '';
    }

    /**
     * Expected formats:
     * - from_url,status,type,to_url,wp_type
     * - from_url,to_url
     */
    function doImportFile() {
        $anyIssuesToNote = array();
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] != UPLOAD_ERR_OK) {
            return 'File upload error.';
        }

        $dryRun = isset($_POST['dry_run']) && sanitize_text_field((string)$_POST['dry_run']) === '1';
        $processedRows = 0;
        $validRows = 0;
        $invalidRows = 0;

        $allowed_extensions = array('csv', 'txt');
        $file_ext = strtolower(pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($file_ext, $allowed_extensions)) {
            return 'Error: Invalid file type. Only CSV/TXT files are allowed.';
        }

        $max_file_size = 5 * 1024 * 1024;
        if ($_FILES['import_file']['size'] > $max_file_size) {
            return 'Error: File too large. Maximum size is 5MB.';
        }

        $allowed_mime_types = array('text/csv', 'text/plain', 'application/csv', 'text/comma-separated-values', 'application/vnd.ms-excel');
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $_FILES['import_file']['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime_type, $allowed_mime_types)) {
            return 'Error: Invalid file type. Only CSV files are allowed.';
        }

        $file_handle = fopen($_FILES['import_file']['tmp_name'], 'r');
        if (!$file_handle) {
            return 'Error opening the file.';
        }

        $delimiter = $this->detectCsvDelimiterFromFile($file_handle);
        rewind($file_handle);

        $headerColumns = null;
        while (($row = fgetcsv($file_handle, 0, $delimiter, '"', '\\')) !== false) {
            $data = array_map(function($v) {
                return trim((string)$v);
            }, $row);

            if (count($data) === 1 && $data[0] === '') {
                continue;
            }

            if ($headerColumns === null && $this->isCompatibleImportHeaderRow($data)) {
                $headerColumns = $this->normalizeImportHeaders($data);
                continue;
            }

            if ($headerColumns !== null) {
                $dataArray = $this->mapImportRowByHeaders($data, $headerColumns);
            } else {
                $dataArray = $this->mapImportRowWithoutHeaders($data);
            }

            if (isset($dataArray['error'])) {
                fclose($file_handle);
                return $dataArray['error'];
            }

            if (isset($dataArray['from_url']) &&
                    ($dataArray['from_url'] === 'from_url' || $dataArray['from_url'] === 'request')) {
                continue;
            }

            $processedRows++;
            $issues = $this->loadDataArrayFromFile($dataArray, $dryRun);
            if (count($issues) > 0) {
                $invalidRows++;
            } else {
                $validRows++;
            }
            $anyIssuesToNote = array_merge($anyIssuesToNote, $issues);
        }
        fclose($file_handle);

        if ($dryRun) {
            $msg = sprintf(
                'Dry run complete. Valid redirects: %d. Invalid rows: %d. Total rows processed: %d.',
                $validRows,
                $invalidRows,
                $processedRows
            );
            if (count($anyIssuesToNote) > 0) {
                $msg .= ' Preview issues: ' .
                    implode(", <BR/>\n", array_slice($anyIssuesToNote, 0, 20));
            }
            return $msg;
        }

        if (count($anyIssuesToNote) > 0) {
            return 'Error: ' . implode(", <BR/>\n", $anyIssuesToNote);
        }

        return __('The file seems to have loaded okay. Please check the redirects page.', '404-solution');
    }

    function loadDataArrayFromFile($dataArray, $dryRun = false) {
        if ($dataArray['from_url'] == 'from_url' || $dataArray['from_url'] == 'request') {
            return array();
        }

        $fromURL = $dataArray['from_url'];
        $status = ABJ404_STATUS_MANUAL;
        $final_dest = $dataArray['to_url'];
        $anyIssuesToNote = array();

        $maybeExisting2 = $this->dao->getExistingRedirectForURL($fromURL);
        if ((count($maybeExisting2) > 0 && $maybeExisting2['id'] != 0)) {
            $msg = 'Ignored importing redirect because a redirect with the same from URL already exists. URL: ' . $fromURL;
            $this->logger->warn($msg);
            $anyIssuesToNote[] = $msg;
            return $anyIssuesToNote;
        }

        $typePost = defined('ABJ404_TYPE_POST') ? constant('ABJ404_TYPE_POST') : 1;
        $typeCat = defined('ABJ404_TYPE_CAT') ? constant('ABJ404_TYPE_CAT') : 2;
        $typeTag = defined('ABJ404_TYPE_TAG') ? constant('ABJ404_TYPE_TAG') : 3;

        if (empty($final_dest)) {
            $type = ABJ404_TYPE_404_DISPLAYED;
        } else if ($final_dest == '5') {
            $type = ABJ404_TYPE_HOME;
        } else if (strpos($final_dest, 'http') !== false) {
            $type = ABJ404_TYPE_EXTERNAL;
            $urlPattern = '/[!#$&\'()*+,;=]/';
            if (preg_match($urlPattern, $fromURL)) {
                $status = ABJ404_STATUS_REGEX;
            }
        } else if (strpos($final_dest, '/') === 0) {
            $type = $typePost;
        } else {
            $msg = 'Unrecognized destination type while importing file. Destination: ' . $final_dest;
            $this->logger->warn($msg);
            $anyIssuesToNote[] = $msg;
            return $anyIssuesToNote;
        }

        if ($type == ABJ404_TYPE_404_DISPLAYED) {
            $final_dest = ABJ404_TYPE_404_DISPLAYED;
        } else if (strpos($final_dest, 'http') !== false) {
            $type = ABJ404_TYPE_EXTERNAL;
        } else if ($type == ABJ404_TYPE_HOME) {
            $final_dest = ABJ404_TYPE_HOME;
        } else {
            $slug = trim($final_dest, '/');
            $postsFromSlugRows = $this->dao->getPublishedPagesAndPostsIDs($slug);
            $postsFromCategoryRows = $this->dao->getPublishedCategories(null, $slug);
            $postsFromTagRows = $this->dao->getPublishedTags($slug);

            $postFromSlug = isset($postsFromSlugRows[0]) ? $postsFromSlugRows[0] : null;
            $postFromCategory = isset($postsFromCategoryRows[0]) ? $postsFromCategoryRows[0] : null;
            $postFromTag = isset($postsFromTagRows[0]) ? $postsFromTagRows[0] : null;

            if ($postFromSlug) {
                $type = $typePost;
                $final_dest = $postFromSlug->id;
            } else if ($postFromCategory) {
                $type = $typeCat;
                $final_dest = $postFromCategory->term_id;
            } else if ($postFromTag) {
                $type = $typeTag;
                $final_dest = $postFromTag->term_id;
            } else {
                $this->logger->warn("Couldn't find post from slug. slug: " . $slug);
            }
        }

        if (!$dryRun) {
            $this->dao->setupRedirect($fromURL, $status, $type, $final_dest, 301);
        }

        return $anyIssuesToNote;
    }

    function splitCsvLine($line) {
        if (!is_string($line)) {
            $line = (string)$line;
        }

        $data = array_map(function($v) {
            return trim((string)$v);
        }, str_getcsv($line, ',', '"', '\\'));

        if (count($data) === 5) {
            return array(
                'from_url' => $data[0],
                'status'   => $data[1],
                'type'     => $data[2],
                'to_url'   => $data[3],
                'wp_type'  => $data[4]
            );
        } else if (count($data) === 2) {
            return array(
                'from_url' => $data[0],
                'to_url'   => $data[1]
            );
        }

        return array('error' => 'Invalid CSV format. ' . count($data) . ' found but 2 or 5 expected.');
    }

    function isCompatibleImportHeaderRow($columns) {
        $normalized = $this->normalizeImportHeaders($columns);
        $fromIndex = $this->findImportHeaderIndex($normalized, array('from_url', 'request', 'source', 'url', 'match_url'));
        $toIndex = $this->findImportHeaderIndex($normalized, array('to_url', 'target', 'destination', 'action_data', 'redirect_to', 'url_to'));
        return ($fromIndex !== -1 && $toIndex !== -1);
    }

    function normalizeImportHeaders($columns) {
        return array_map(function($value) {
            $value = preg_replace('/^\xEF\xBB\xBF/', '', (string)$value);
            $value = trim(strtolower((string)$value));
            return preg_replace('/[^a-z0-9_]/', '', str_replace(' ', '_', $value));
        }, $columns);
    }

    /**
     * Best-effort format detection for import UX and diagnostics.
     *
     * @param array $columns Raw header row.
     * @return string One of: native, redirection, safe_redirect_manager, simple_301, unknown.
     */
    function detectImportFormatFromHeaders($columns) {
        $normalized = $this->normalizeImportHeaders($columns);

        if (in_array('source', $normalized, true) &&
                in_array('target', $normalized, true) &&
                in_array('regex', $normalized, true)) {
            return 'redirection';
        }

        if (in_array('redirect_from', $normalized, true) &&
                in_array('redirect_to', $normalized, true)) {
            return 'safe_redirect_manager';
        }

        if (in_array('request', $normalized, true) &&
                in_array('destination', $normalized, true)) {
            return 'simple_301';
        }

        if ((in_array('from_url', $normalized, true) &&
                in_array('to_url', $normalized, true)) ||
                (in_array('from_url', $normalized, true) &&
                in_array('status', $normalized, true) &&
                in_array('type', $normalized, true) &&
                in_array('to_url', $normalized, true))) {
            return 'native';
        }

        return 'unknown';
    }

    function mapImportRowByHeaders($row, $normalizedHeaders) {
        $fromIndex = $this->findImportHeaderIndex($normalizedHeaders, array('from_url', 'request', 'source', 'url', 'match_url'));
        $toIndex = $this->findImportHeaderIndex($normalizedHeaders, array('to_url', 'target', 'destination', 'action_data', 'redirect_to', 'url_to'));

        if ($fromIndex === -1 || $toIndex === -1) {
            return array('error' => 'Invalid CSV format. Could not map source/destination columns.');
        }

        $from = array_key_exists($fromIndex, $row) ? trim((string)$row[$fromIndex]) : '';
        $to = array_key_exists($toIndex, $row) ? trim((string)$row[$toIndex]) : '';

        if ($from === '' && $to === '') {
            return array('from_url' => '', 'to_url' => '');
        }

        return array(
            'from_url' => $from,
            'to_url' => $to,
        );
    }

    /**
     * @param array $headers
     * @param array $candidates
     * @return int
     */
    private function findImportHeaderIndex($headers, $candidates) {
        foreach ($candidates as $candidate) {
            $idx = array_search($candidate, $headers, true);
            if ($idx !== false) {
                return (int)$idx;
            }
        }
        return -1;
    }

    /**
     * @param array $columns Already parsed CSV columns for one row.
     * @return array
     */
    private function mapImportRowWithoutHeaders($columns) {
        $columns = array_values($columns);
        if (count($columns) === 5) {
            return array(
                'from_url' => trim((string)$columns[0]),
                'status'   => trim((string)$columns[1]),
                'type'     => trim((string)$columns[2]),
                'to_url'   => trim((string)$columns[3]),
                'wp_type'  => trim((string)$columns[4]),
            );
        }
        if (count($columns) === 2) {
            return array(
                'from_url' => trim((string)$columns[0]),
                'to_url'   => trim((string)$columns[1]),
            );
        }
        return array('error' => 'Invalid CSV format. ' . count($columns) . ' found but 2 or 5 expected.');
    }

    /**
     * Detect delimiter by inspecting the first non-empty line.
     *
     * @param resource $fileHandle
     * @return string
     */
    private function detectCsvDelimiterFromFile($fileHandle) {
        while (($line = fgets($fileHandle)) !== false) {
            if (trim($line) === '') {
                continue;
            }
            $comma = count(str_getcsv($line, ',', '"', '\\'));
            $semicolon = count(str_getcsv($line, ';', '"', '\\'));
            $tab = count(str_getcsv($line, "\t", '"', '\\'));

            if ($semicolon > $comma && $semicolon >= $tab) {
                return ';';
            }
            if ($tab > $comma && $tab > $semicolon) {
                return "\t";
            }
            return ',';
        }
        return ',';
    }
}
