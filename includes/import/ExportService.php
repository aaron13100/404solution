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

    /** @var mixed Logger-like object supplied by production or legacy tests. */
    private $logger;

    /** @var ABJ_404_Solution_RedirectsRepositoryInterface|null */
    private $redirectsRepository;

    /**
     * Constructor supports three signatures for backward compatibility:
     *   (1) New: (ViewReadService, Logging, RedirectsRepository)
     *   (2) Alternate injected order: (ViewReadService, RedirectsRepository, Logging)
     *   (3) Legacy: (DataAccess, Logging) -- DataAccess implements ViewReadService methods
     *
     * @param mixed $viewReadServiceOrDataAccess
     * @param mixed $loggingOrRedirectsRepository
     * @param mixed $redirectsRepositoryOrLogging
     */
    function __construct($viewReadServiceOrDataAccess, $loggingOrRedirectsRepository, $redirectsRepositoryOrLogging = null) {
        /** @var ABJ_404_Solution_ViewReadServiceInterface $viewReadServiceOrDataAccess */
        $this->viewReadService = $viewReadServiceOrDataAccess;
        $this->logger = $loggingOrRedirectsRepository;
        $this->redirectsRepository = null;

        if ($loggingOrRedirectsRepository instanceof ABJ_404_Solution_RedirectsRepositoryInterface) {
            $this->redirectsRepository = $loggingOrRedirectsRepository;
            /** @var ABJ_404_Solution_Logging $redirectsRepositoryOrLogging */
            $this->logger = $redirectsRepositoryOrLogging;
        } elseif ($redirectsRepositoryOrLogging instanceof ABJ_404_Solution_RedirectsRepositoryInterface) {
            $this->redirectsRepository = $redirectsRepositoryOrLogging;
        } elseif ($viewReadServiceOrDataAccess instanceof ABJ_404_Solution_RedirectsRepositoryInterface) {
            $this->redirectsRepository = $viewReadServiceOrDataAccess;
        } elseif (is_object($viewReadServiceOrDataAccess) && method_exists($viewReadServiceOrDataAccess, 'getRedirectsRepo')) {
            $candidate = $viewReadServiceOrDataAccess->getRedirectsRepo();
            if ($candidate instanceof ABJ_404_Solution_RedirectsRepositoryInterface) {
                $this->redirectsRepository = $candidate;
            }
        }
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

    /**
     * Registry of server-level / edge formats served directly (no temp file).
     *
     * Each entry pairs the format key with (a) the generator method, (b) the
     * filename presented to the browser, and (c) the Content-Type header.
     * doExport() and doServerFormatExport() both consume this map; adding a
     * new server-format is one row here, not two coupled edits in the
     * whitelist + switch.
     *
     * @return array<string, array{method: string, filename: string, mime: string}>
     */
    private function serverFormatRegistry() {
        return array(
            'htaccess'   => array('method' => 'generateHtaccessRules',          'filename' => 'redirects.htaccess',    'mime' => 'text/plain; charset=utf-8'),
            'nginx'      => array('method' => 'generateNginxRules',             'filename' => 'redirects-nginx.conf',  'mime' => 'text/plain; charset=utf-8'),
            'cloudflare' => array('method' => 'generateCloudflareWorkerScript', 'filename' => 'redirects-worker.js',   'mime' => 'application/javascript; charset=utf-8'),
            'netlify'    => array('method' => 'generateNetlifyRedirects',       'filename' => '_redirects',            'mime' => 'text/plain; charset=utf-8'),
            'vercel'     => array('method' => 'generateVercelRedirects',        'filename' => 'vercel-redirects.json', 'mime' => 'application/json; charset=utf-8'),
        );
    }

    /** @return void */
    function doExport() {
        $format = isset($_REQUEST['export_format']) ? sanitize_text_field((string)$_REQUEST['export_format']) : 'native';

        if (array_key_exists($format, $this->serverFormatRegistry())) {
            $this->doServerFormatExport($format);
            return;
        }

        $tempFile = $this->getExportFilename($format);

        if ($format === 'redirection') {
            $nativeExportFile = $this->getExportFilename('native');
            $this->viewReadService->doRedirectsExport($nativeExportFile);
            $error = $this->convertExportCsvToRedirectionFormat($nativeExportFile, $tempFile);
            if ($error !== '') {
                $this->loggerWarn($error);
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

        $this->loggerInfo("I don't see any data to export.");
    }

    /**
     * Serve a server-level or edge/CDN format export directly (no temp file needed).
     *
     * @param string $format One of: htaccess, nginx, cloudflare, netlify, vercel.
     * @return void
     */
    private function doServerFormatExport($format) {
        $registry = $this->serverFormatRegistry();
        if (!array_key_exists($format, $registry)) {
            $this->loggerWarn('Unknown server export format: ' . $format);
            return;
        }

        $entry   = $registry[$format];
        $content = $this->{$entry['method']}();

        header('Content-Description: File Transfer');
        header('Content-Disposition: attachment; filename=' . $entry['filename']);
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . strlen($content));
        header('Content-Type: ' . $entry['mime']);
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
        if (!$this->redirectsRepository instanceof ABJ_404_Solution_RedirectsRepositoryInterface) {
            $this->loggerWarn('Exportable redirects repository is not available for server-format export.');
            return array();
        }

        return $this->redirectsRepository->getExportableRedirects();
    }

    /**
     * @param string $message
     * @return void
     */
    private function loggerWarn(string $message): void {
        if (is_object($this->logger) && method_exists($this->logger, 'warn')) {
            $this->logger->warn($message);
            return;
        }

        $logger = function_exists('abj_service_optional') ? abj_service_optional('logging') : null;
        if (is_object($logger) && method_exists($logger, 'warn')) {
            $logger->warn($message);
            return;
        }

        abj404_logPhpFallback('service-resolution-fallback', $message);
    }

    /**
     * @param string $message
     * @return void
     */
    private function loggerInfo(string $message): void {
        if (is_object($this->logger) && method_exists($this->logger, 'infoMessage')) {
            $this->logger->infoMessage($message);
            return;
        }

        $logger = function_exists('abj_service_optional') ? abj_service_optional('logging') : null;
        if (is_object($logger) && method_exists($logger, 'infoMessage')) {
            $logger->infoMessage($message);
            return;
        }

        abj404_logPhpFallback('service-resolution-fallback', $message);
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
