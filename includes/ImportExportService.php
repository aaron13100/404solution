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

    /**
     * @param ABJ_404_Solution_DataAccess $dataAccess
     * @param ABJ_404_Solution_Logging $logging
     */
    function __construct($dataAccess, $logging) {
        $this->dao = $dataAccess;
        $this->logger = $logging;
    }

    /**
     * @param string $format
     * @return string
     */
    function getExportFilename($format = 'native') {
        if ($format === 'redirection') {
            return abj404_getUploadsDir() . 'export-redirection.csv';
        }
        return abj404_getUploadsDir() . 'export.csv';
    }

    /** @return void */
    function doExport() {
        $format = isset($_REQUEST['export_format']) ? sanitize_text_field((string)$_REQUEST['export_format']) : 'native';

        $serverFormats = array('htaccess', 'nginx', 'cloudflare', 'netlify', 'vercel');
        if (in_array($format, $serverFormats, true)) {
            $this->doServerFormatExport($format);
            return;
        }

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
     * Serve a server-level or edge/CDN format export directly (no temp file needed).
     *
     * @param string $format One of: htaccess, nginx, cloudflare, netlify, vercel.
     * @return void
     */
    private function doServerFormatExport($format) {
        switch ($format) {
            case 'htaccess':
                $content  = $this->generateHtaccessRules();
                $filename = 'redirects.htaccess';
                $mime     = 'text/plain; charset=utf-8';
                break;
            case 'nginx':
                $content  = $this->generateNginxRules();
                $filename = 'redirects-nginx.conf';
                $mime     = 'text/plain; charset=utf-8';
                break;
            case 'cloudflare':
                $content  = $this->generateCloudflareWorkerScript();
                $filename = 'redirects-worker.js';
                $mime     = 'application/javascript; charset=utf-8';
                break;
            case 'netlify':
                $content  = $this->generateNetlifyRedirects();
                $filename = '_redirects';
                $mime     = 'text/plain; charset=utf-8';
                break;
            case 'vercel':
                $content  = $this->generateVercelRedirects();
                $filename = 'vercel-redirects.json';
                $mime     = 'application/json; charset=utf-8';
                break;
            default:
                $this->logger->warn('Unknown server export format: ' . $format);
                return;
        }

        header('Content-Description: File Transfer');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . strlen($content));
        header('Content-Type: ' . $mime);
        echo $content;
        exit();
    }

    /**
     * Fetch all exportable (manual + regex, non-trashed) redirects and resolve
     * destination URLs.
     *
     * Each returned element has:
     *   source   string  The from-URL stored in the DB (relative path or full URL).
     *   dest     string  Resolved destination URL or path.
     *   code     int     HTTP status code (301, 302, 410, …).
     *   is_regex bool    Whether this is a regex redirect.
     *
     * @return array<int, array{source: string, dest: string, code: int, is_regex: bool}>
     */
    function getExportableRedirects() {
        $dao = abj_service('data_access');
        $redirectsTable = $dao->doTableNameReplacements('{wp_abj404_redirects}');
        $cacheTable     = $dao->doTableNameReplacements('{wp_abj404_permalink_cache}');

        $manualStatus = defined('ABJ404_STATUS_MANUAL') ? (int)ABJ404_STATUS_MANUAL : 1;
        $regexStatus  = defined('ABJ404_STATUS_REGEX')  ? (int)ABJ404_STATUS_REGEX  : 6;
        $typeExternal = defined('ABJ404_TYPE_EXTERNAL') ? (int)ABJ404_TYPE_EXTERNAL : 4;
        $typeHome     = defined('ABJ404_TYPE_HOME')     ? (int)ABJ404_TYPE_HOME     : 5;

        $queryResult = $dao->queryAndGetResults(
            "SELECT r.url, r.status, r.type, r.final_dest, r.code, r.disabled,
                    pc.url AS cached_url
             FROM {$redirectsTable} r
             LEFT JOIN {$cacheTable} pc ON r.final_dest = pc.id
             WHERE r.status IN (%d, %d)
               AND (r.disabled IS NULL OR r.disabled = 0)
               AND r.url IS NOT NULL AND r.url != ''
             ORDER BY r.url",
            ['query_params' => [$manualStatus, $regexStatus]]
        );

        $rows = $queryResult['rows'] ?? [];
        if (!is_array($rows) || empty($rows)) {
            return array();
        }

        $result = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $source   = isset($row['url']) ? (string)$row['url'] : '';
            $isRegex  = (isset($row['status']) && (int)$row['status'] === $regexStatus);
            $code     = isset($row['code']) ? (int)$row['code'] : 301;
            $type     = isset($row['type']) ? (int)$row['type'] : 0;
            $finalDest = isset($row['final_dest']) ? (string)$row['final_dest'] : '';

            // Resolve destination
            if ($code === 410 || $code === 451) {
                $dest = $source;
            } elseif (!empty($row['cached_url'])) {
                $dest = (string)$row['cached_url'];
            } elseif ($type === $typeExternal) {
                $dest = $finalDest;
            } elseif ($type === $typeHome) {
                $dest = function_exists('home_url') ? home_url('/') : '/';
            } elseif (is_numeric($finalDest) && (int)$finalDest > 0) {
                // Post/page/term ID — try get_permalink
                if (function_exists('get_permalink')) {
                    $url = get_permalink((int)$finalDest);
                    $dest = ($url !== false && is_string($url)) ? $url : ('/?p=' . $finalDest);
                } else {
                    $dest = '/?p=' . $finalDest;
                }
            } elseif ($finalDest !== '') {
                $dest = $finalDest;
            } else {
                // No destination — skip
                continue;
            }

            $result[] = array(
                'source'   => $source,
                'dest'     => $dest,
                'code'     => $code,
                'is_regex' => $isRegex,
            );
        }

        return $result;
    }

    /**
     * Generate Apache .htaccess redirect rules.
     *
     * @return string
     */
    function generateHtaccessRules() {
        $redirects = $this->getExportableRedirects();
        $lines = array('# 404 Solution redirects', 'RewriteEngine On', '');

        foreach ($redirects as $r) {
            $source = $r['source'];
            $dest   = $r['dest'];
            $code   = $r['code'];

            // Strip leading slash for RewriteRule pattern (anchored with ^)
            $pattern = ltrim($source, '/');

            if (!$r['is_regex']) {
                // Escape regex metacharacters in literal paths
                $pattern = preg_quote($pattern, '/');
                $pattern = $pattern . '/?';
            }

            if ($code === 410 || $code === 451) {
                // Apache [G] flag sends a 410 Gone response; it is the closest equivalent for 451.
                $lines[] = 'RewriteRule ^' . $pattern . '$ - [G,L]';
            } elseif ($code === 0) {
                // Meta Refresh requires serving an HTML response — not supported in .htaccess.
                $lines[] = '# Meta Refresh: ' . $source . ' → ' . $dest . ' (serve HTML; not representable as a RewriteRule)';
            } else {
                $flag    = ($code === 301) ? 'R=301' : 'R=' . $code;
                $lines[] = 'RewriteRule ^' . $pattern . '$ ' . $dest . ' [' . $flag . ',L]';
            }
        }

        if (count($redirects) === 0) {
            $lines[] = '# No manual redirects found.';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Generate Nginx location block redirect rules.
     *
     * @return string
     */
    function generateNginxRules() {
        $redirects = $this->getExportableRedirects();
        $lines = array('# 404 Solution redirects', '');

        foreach ($redirects as $r) {
            $source = $r['source'];
            $dest   = $r['dest'];
            $code   = $r['code'];

            if ($r['is_regex']) {
                $directive = 'location ~* ' . $source;
            } else {
                $directive = 'location = ' . $source;
            }

            if ($code === 410 || $code === 451) {
                $lines[] = $directive . ' { return ' . $code . '; }';
            } elseif ($code === 0) {
                // Meta Refresh requires serving an HTML response — not representable as a return directive.
                $lines[] = '# Meta Refresh: ' . $source . ' → ' . $dest . ' (serve HTML; not representable as a return directive)';
            } else {
                $lines[] = $directive . ' { return ' . $code . ' ' . $dest . '; }';
            }
        }

        if (count($redirects) === 0) {
            $lines[] = '# No manual redirects found.';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Generate a Cloudflare Workers JavaScript snippet for handling redirects.
     *
     * @return string
     */
    function generateCloudflareWorkerScript() {
        $redirects = $this->getExportableRedirects();

        $entries = array();
        foreach ($redirects as $r) {
            $sourceJson = json_encode($r['source'], JSON_UNESCAPED_SLASHES);
            $destJson   = json_encode($r['dest'], JSON_UNESCAPED_SLASHES);
            // Fall back to UTF-8 sanitization if json_encode fails on invalid bytes
            if ($sourceJson === false) {
                $sourceJson = json_encode(mb_convert_encoding($r['source'], 'UTF-8', 'UTF-8'), JSON_UNESCAPED_SLASHES);
            }
            if ($destJson === false) {
                $destJson = json_encode(mb_convert_encoding($r['dest'], 'UTF-8', 'UTF-8'), JSON_UNESCAPED_SLASHES);
            }
            if ($sourceJson === false || $destJson === false) {
                continue;
            }
            $code = (int)$r['code'];
            $entries[] = "  " . $sourceJson . ": { dest: " . $destJson . ", status: " . $code . " }";
        }

        $map = implode(",\n", $entries);

        $script  = "const REDIRECTS = {\n";
        $script .= ($map !== '' ? $map . "\n" : '');
        $script .= "};\n";
        $script .= "\n";
        $script .= "addEventListener('fetch', event => {\n";
        $script .= "  event.respondWith(handleRequest(event.request));\n";
        $script .= "});\n";
        $script .= "\n";
        $script .= "async function handleRequest(request) {\n";
        $script .= "  const url = new URL(request.url);\n";
        $script .= "  const rule = REDIRECTS[url.pathname] || REDIRECTS[url.pathname.replace(/\\/$/, '')];\n";
        $script .= "  if (rule) {\n";
        $script .= "    if (rule.status === 410 || rule.status === 451) return new Response(null, { status: rule.status });\n";
        $script .= "    if (rule.status === 0) return new Response('<meta http-equiv=\"refresh\" content=\"0;url=' + rule.dest + '\">', { status: 200, headers: { 'Content-Type': 'text/html' } });\n";
        $script .= "    return Response.redirect(rule.dest.startsWith('http') ? rule.dest : url.origin + rule.dest, rule.status);\n";
        $script .= "  }\n";
        $script .= "  return fetch(request);\n";
        $script .= "}\n";

        return $script;
    }

    /**
     * Generate a Netlify _redirects file.
     *
     * @return string
     */
    function generateNetlifyRedirects() {
        $redirects = $this->getExportableRedirects();
        $lines = array('# 404 Solution redirects');

        foreach ($redirects as $r) {
            $source = $r['source'];
            $dest   = $r['dest'];
            $code   = (int)$r['code'];
            $lines[] = $source . '  ' . $dest . '  ' . $code;
        }

        if (count($redirects) === 0) {
            $lines[] = '# No manual redirects found.';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Generate a Vercel redirects JSON array (for use in vercel.json).
     *
     * Note: Vercel does not natively support 410 Gone responses; those redirects
     * are omitted from this export.
     *
     * @return string JSON array string.
     */
    function generateVercelRedirects() {
        $redirects = $this->getExportableRedirects();
        $entries = array();

        foreach ($redirects as $r) {
            if ($r['code'] === 410 || $r['code'] === 451 || $r['code'] === 0) {
                // Vercel has no native 410/451/meta-refresh support; skip.
                continue;
            }
            $entries[] = array(
                'source'      => $r['source'],
                'destination' => $r['dest'],
                'permanent'   => ($r['code'] === 301),
            );
        }

        $encoded = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? $encoded : '[]';
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
            return __('Error: Native export file does not exist.', '404-solution');
        }

        $in = fopen($sourceFile, 'r');
        if ($in === false) {
            return __('Error: Could not read native export file.', '404-solution');
        }

        $out = fopen($destinationFile, 'w');
        if ($out === false) {
            fclose($in);
            return __('Error: Could not create Redirection export file.', '404-solution');
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
            $code = isset($row[6]) ? trim((string)$row[6]) : '301';
            if ($code === '' || !is_numeric($code)) {
                $code = '301';
            }
            fputcsv($out, array($from, $to, $regexFlag, $code), ',', '"', '\\');
        }

        fclose($in);
        fclose($out);
        return '';
    }

    /**
     * Expected formats:
     * - from_url,status,type,to_url,wp_type
     * - from_url,to_url
     *
     * @return string
     */
    function doImportFile() {
        $anyIssuesToNote = array();
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] != UPLOAD_ERR_OK) {
            return __('File upload error.', '404-solution');
        }

        $dryRun = isset($_POST['dry_run']) && sanitize_text_field((string)$_POST['dry_run']) === '1';
        $processedRows = 0;
        $validRows = 0;
        $invalidRows = 0;

        $allowed_extensions = array('csv', 'txt');
        $file_ext = strtolower(pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($file_ext, $allowed_extensions)) {
            return __('Error: Invalid file type. Only CSV/TXT files are allowed.', '404-solution');
        }

        $max_file_size = 5 * 1024 * 1024;
        if ($_FILES['import_file']['size'] > $max_file_size) {
            return __('Error: File too large. Maximum size is 5MB.', '404-solution');
        }

        $allowed_mime_types = array('text/csv', 'text/plain', 'application/csv', 'text/comma-separated-values', 'application/vnd.ms-excel');
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return __('Error: Unable to determine file type.', '404-solution');
        }
        $mime_type = finfo_file($finfo, $_FILES['import_file']['tmp_name']);
        if (!in_array($mime_type, $allowed_mime_types)) {
            return __('Error: Invalid file type. Only CSV files are allowed.', '404-solution');
        }

        $file_handle = fopen($_FILES['import_file']['tmp_name'], 'r');
        if (!$file_handle) {
            return __('Error opening the file.', '404-solution');
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
                __('Dry run complete. Valid redirects: %d. Invalid rows: %d. Total rows processed: %d.', '404-solution'),
                $validRows,
                $invalidRows,
                $processedRows
            );
            if (count($anyIssuesToNote) > 0) {
                $msg .= ' ' . __('Preview issues:', '404-solution') . ' ' .
                    implode(", <BR/>\n", array_slice($anyIssuesToNote, 0, 20));
            }
            return $msg;
        }

        if (count($anyIssuesToNote) > 0) {
            return __('Error:', '404-solution') . ' ' . implode(", <BR/>\n", $anyIssuesToNote);
        }

        return __('The file seems to have loaded okay. Please check the redirects page.', '404-solution');
    }

    /**
     * @param array<string, mixed> $dataArray
     * @param bool $dryRun
     * @return array<int, string>
     */
    function loadDataArrayFromFile($dataArray, $dryRun = false) {
        $fromURL = isset($dataArray['from_url']) && is_string($dataArray['from_url']) ? $dataArray['from_url'] : '';
        if ($fromURL === 'from_url' || $fromURL === 'request') {
            return array();
        }

        $status = ABJ404_STATUS_MANUAL;
        $final_dest = isset($dataArray['to_url']) && is_string($dataArray['to_url']) ? $dataArray['to_url'] : '';
        $anyIssuesToNote = array();

        $maybeExisting2 = $this->dao->getExistingRedirectForURL($fromURL);
        if ((count($maybeExisting2) > 0 && $maybeExisting2['id'] != 0)) {
            $msg = __('Ignored importing redirect because a redirect with the same from URL already exists. URL:', '404-solution') . ' ' . $fromURL;
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
            $msg = __('Unrecognized destination type while importing file. Destination:', '404-solution') . ' ' . $final_dest;
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

            /** @var object{id?: int|string, term_id?: int|string}|null $postFromSlug */
            $postFromSlug = isset($postsFromSlugRows[0]) ? $postsFromSlugRows[0] : null;
            /** @var object{term_id?: int|string}|null $postFromCategory */
            $postFromCategory = isset($postsFromCategoryRows[0]) ? $postsFromCategoryRows[0] : null;
            /** @var object{term_id?: int|string}|null $postFromTag */
            $postFromTag = isset($postsFromTagRows[0]) ? $postsFromTagRows[0] : null;

            if ($postFromSlug && isset($postFromSlug->id)) {
                $type = $typePost;
                $final_dest = (string)$postFromSlug->id;
            } else if ($postFromCategory && isset($postFromCategory->term_id)) {
                $type = $typeCat;
                $final_dest = (string)$postFromCategory->term_id;
            } else if ($postFromTag && isset($postFromTag->term_id)) {
                $type = $typeTag;
                $final_dest = (string)$postFromTag->term_id;
            } else {
                // Slug doesn't resolve to any post/category/tag — use EXTERNAL
                // so the path is used as-is by the redirect pipeline. Storing a
                // non-numeric final_dest with TYPE_POST would cause the redirect
                // to silently 404 (get_permalink() expects a numeric ID).
                $type = ABJ404_TYPE_EXTERNAL;
                $this->logger->warn(__("Couldn't find post from slug. slug:", '404-solution') . ' ' . $slug);
            }
        }

        if (!$dryRun) {
            $engine = isset($dataArray['engine']) && is_string($dataArray['engine']) && $dataArray['engine'] !== ''
                ? $dataArray['engine'] : 'import';
            $code = isset($dataArray['code']) && is_numeric($dataArray['code'])
                ? (string)(int)$dataArray['code'] : '301';
            $this->dao->setupRedirect($fromURL, (string)$status, (string)$type, (string)$final_dest, $code, 0, $engine);
        }

        return $anyIssuesToNote;
    }

    /**
     * @param mixed $line
     * @return array<string, string>
     */
    function splitCsvLine($line) {
        if (!is_string($line)) {
            $line = is_scalar($line) ? (string)$line : '';
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

        return array('error' => sprintf(__('Invalid CSV format. %d columns found but 2 or 5 expected.', '404-solution'), count($data)));
    }

    /**
     * @param array<int, string> $columns
     * @return bool
     */
    function isCompatibleImportHeaderRow($columns) {
        $normalized = $this->normalizeImportHeaders($columns);
        $fromIndex = $this->findImportHeaderIndex($normalized, array('from_url', 'request', 'source', 'url', 'match_url'));
        $toIndex = $this->findImportHeaderIndex($normalized, array('to_url', 'target', 'destination', 'action_data', 'redirect_to', 'url_to'));
        return ($fromIndex !== -1 && $toIndex !== -1);
    }

    /**
     * @param array<int, string> $columns
     * @return array<int, string|null>
     */
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
     * @param array<int, string> $columns Raw header row.
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

    /**
     * @param array<int, string> $row
     * @param array<int, string|null> $normalizedHeaders
     * @return array<string, string>
     */
    function mapImportRowByHeaders($row, $normalizedHeaders) {
        $fromIndex = $this->findImportHeaderIndex($normalizedHeaders, array('from_url', 'request', 'source', 'url', 'match_url'));
        $toIndex = $this->findImportHeaderIndex($normalizedHeaders, array('to_url', 'target', 'destination', 'action_data', 'redirect_to', 'url_to'));

        if ($fromIndex === -1 || $toIndex === -1) {
            return array('error' => __('Invalid CSV format. Could not map source/destination columns.', '404-solution'));
        }

        $from = array_key_exists($fromIndex, $row) ? trim((string)$row[$fromIndex]) : '';
        $to = array_key_exists($toIndex, $row) ? trim((string)$row[$toIndex]) : '';

        if ($from === '' && $to === '') {
            return array('from_url' => '', 'to_url' => '');
        }

        $result = array(
            'from_url' => $from,
            'to_url' => $to,
        );

        $engineIndex = $this->findImportHeaderIndex($normalizedHeaders, array('engine'));
        if ($engineIndex !== -1 && array_key_exists($engineIndex, $row)) {
            $result['engine'] = trim((string)$row[$engineIndex]);
        }

        $codeIndex = $this->findImportHeaderIndex($normalizedHeaders, array('code', 'redirect_code', 'http_code'));
        if ($codeIndex !== -1 && array_key_exists($codeIndex, $row)) {
            $result['code'] = trim((string)$row[$codeIndex]);
        }

        return $result;
    }

    /**
     * @param array<int, string|null> $headers
     * @param array<int, string> $candidates
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
     * @param array<int, string> $columns Already parsed CSV columns for one row.
     * @return array<string, string>
     */
    function mapImportRowWithoutHeaders($columns) {
        $columns = array_values($columns);
        if (count($columns) === 7) {
            return array(
                'from_url' => trim((string)$columns[0]),
                'status'   => trim((string)$columns[1]),
                'type'     => trim((string)$columns[2]),
                'to_url'   => trim((string)$columns[3]),
                'wp_type'  => trim((string)$columns[4]),
                'engine'   => trim((string)$columns[5]),
                'code'     => trim((string)$columns[6]),
            );
        }
        if (count($columns) === 6) {
            return array(
                'from_url' => trim((string)$columns[0]),
                'status'   => trim((string)$columns[1]),
                'type'     => trim((string)$columns[2]),
                'to_url'   => trim((string)$columns[3]),
                'wp_type'  => trim((string)$columns[4]),
                'engine'   => trim((string)$columns[5]),
            );
        }
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
        return array('error' => sprintf(__('Invalid CSV format. %d columns found but 2, 5, 6, or 7 expected.', '404-solution'), count($columns)));
    }

    /**
     * Detect delimiter by inspecting the first non-empty line.
     *
     * @param resource $fileHandle
     * @return string
     */
    function detectCsvDelimiterFromFile($fileHandle) {
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
