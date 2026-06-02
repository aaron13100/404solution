<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Owns the redirect-export pipeline.
 *
 * Reads exportable (manual + regex, non-trashed) redirects, resolves their
 * destinations, and shapes them into one of seven output formats:
 *   - native CSV (the round-trip-able shape this plugin's own importer reads)
 *   - Redirection-plugin CSV (delegated through a native-to-Redirection conversion)
 *   - Apache .htaccess RewriteRule lines
 *   - Nginx location-block rules
 *   - Cloudflare Workers JavaScript
 *   - Netlify _redirects file
 *   - Vercel redirects JSON
 *
 * Does NOT own the import pipeline. See ABJ_404_Solution_ImportService.
 */
class ABJ_404_Solution_ExportService {

    /** @var ABJ_404_Solution_ViewReadServiceInterface */
    private $viewReadService;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * Constructor supports two signatures for backward compatibility:
     *   (1) New: (ViewReadService, Logging)
     *   (2) Legacy: (DataAccess, Logging) -- DataAccess implements ViewReadService methods
     *
     * @param mixed $viewReadServiceOrDataAccess
     * @param ABJ_404_Solution_Logging $logging
     */
    function __construct($viewReadServiceOrDataAccess, $logging) {
        /** @var ABJ_404_Solution_ViewReadServiceInterface $viewReadServiceOrDataAccess */
        $this->viewReadService = $viewReadServiceOrDataAccess;
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
            $this->viewReadService->doRedirectsExport($nativeExportFile);
            $error = $this->convertExportCsvToRedirectionFormat($nativeExportFile, $tempFile);
            if ($error !== '') {
                $this->logger->warn($error);
                return;
            }
        } else {
            $this->viewReadService->doRedirectsExport($tempFile);
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
     *   code     int     HTTP status code (301, 302, 410, ...).
     *   is_regex bool    Whether this is a regex redirect.
     *
     * @return array<int, array{source: string, dest: string, code: int, is_regex: bool}>
     */
    function getExportableRedirects() {
        $dbCore = abj_service('db_core');
        $redirectsTable = $dbCore->doTableNameReplacements('{wp_abj404_redirects}');
        $cacheTable     = $dbCore->doTableNameReplacements('{wp_abj404_permalink_cache}');

        $manualStatus = defined('ABJ404_STATUS_MANUAL') ? (int)ABJ404_STATUS_MANUAL : 1;
        $regexStatus  = defined('ABJ404_STATUS_REGEX')  ? (int)ABJ404_STATUS_REGEX  : 6;
        $typeExternal = defined('ABJ404_TYPE_EXTERNAL') ? (int)ABJ404_TYPE_EXTERNAL : 4;
        $typeHome     = defined('ABJ404_TYPE_HOME')     ? (int)ABJ404_TYPE_HOME     : 5;

        $queryResult = $dbCore->queryAndGetResults(
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

            if ($code === 410 || $code === 451) {
                $dest = $source;
            } elseif (!empty($row['cached_url'])) {
                $dest = (string)$row['cached_url'];
            } elseif ($type === $typeExternal) {
                $dest = $finalDest;
            } elseif ($type === $typeHome) {
                $dest = function_exists('home_url') ? home_url('/') : '/';
            } elseif (is_numeric($finalDest) && (int)$finalDest > 0) {
                if (function_exists('get_permalink')) {
                    $url = get_permalink((int)$finalDest);
                    $dest = ($url !== false && is_string($url)) ? $url : ('/?p=' . $finalDest);
                } else {
                    $dest = '/?p=' . $finalDest;
                }
            } elseif ($finalDest !== '') {
                $dest = $finalDest;
            } else {
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

            $pattern = ltrim($source, '/');

            if (!$r['is_regex']) {
                $pattern = preg_quote($pattern, '/');
                $pattern = $pattern . '/?';
            }

            if ($code === 410 || $code === 451) {
                // Apache [G] flag sends a 410 Gone response; it is the closest equivalent for 451.
                $lines[] = 'RewriteRule ^' . $pattern . '$ - [G,L]';
            } elseif ($code === 0) {
                // Meta Refresh requires serving an HTML response, not representable as a RewriteRule.
                $lines[] = '# Meta Refresh: ' . $source . ' to ' . $dest . ' (serve HTML; not representable as a RewriteRule)';
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
                $lines[] = '# Meta Refresh: ' . $source . ' to ' . $dest . ' (serve HTML; not representable as a return directive)';
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
            $jsonFlags  = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
            $sourceJson = json_encode($r['source'], $jsonFlags);
            $destJson   = json_encode($r['dest'], $jsonFlags);
            if ($sourceJson === false) {
                $sourceJson = json_encode(mb_convert_encoding($r['source'], 'UTF-8', 'UTF-8'), $jsonFlags);
            }
            if ($destJson === false) {
                $destJson = json_encode(mb_convert_encoding($r['dest'], 'UTF-8', 'UTF-8'), $jsonFlags);
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
}
