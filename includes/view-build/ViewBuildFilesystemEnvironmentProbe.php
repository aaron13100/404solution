<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Filesystem-side host-environment probe for the staged view-build pipeline.
 *
 * Detects open_basedir, temp directory free-space, and temp write constraints
 * that can degrade staged view builds on hardened shared hosts.
 */
class ABJ_404_Solution_ViewBuildFilesystemEnvironmentProbe extends ABJ_404_Solution_ViewBuildCollaborator {

    /** @var ABJ_404_Solution_ViewBuildHostEnvironmentNoticePolicy */
    private $noticePolicy;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var array<string,mixed>|null */
    private $filesystemEnvironmentProbeCache = null;

    public function __construct(ABJ_404_Solution_ViewBuildCollaborationContext $host) {
        parent::__construct($host);
        $this->noticePolicy = new ABJ_404_Solution_ViewBuildHostEnvironmentNoticePolicy($host);
        $this->logger = $host->dataBoundary()->logger();
    }

    /** @return string */
    public function filesystemEnvironmentProbeOptionName(): string {
        return 'abj404_view_build_fs_env_probe';
    }

    /**
     * Probe filesystem-side host constraints that can silently degrade or
     * abort the staged view-build pipeline.
     *
     * @return array<string,mixed>
     */
    public function probeFilesystemEnvironmentForBuild(): array {
        if (is_array($this->filesystemEnvironmentProbeCache)) {
            return $this->filesystemEnvironmentProbeCache;
        }

        $rawOpenBasedir = (string)ini_get('open_basedir');
        $rawUploadTmpDir = (string)ini_get('upload_tmp_dir');
        $sysTmpDir = function_exists('sys_get_temp_dir') ? (string)sys_get_temp_dir() : '';

        $pluginTmpCandidates = array_filter(array(
            $sysTmpDir,
            $rawUploadTmpDir,
        ), function ($p) { return $p !== ''; });

        $openBasedirPaths = $this->splitOpenBasedirPaths($rawOpenBasedir);

        $tmpOutsideOpenBasedir = false;
        $uploadTmpOutsideOpenBasedir = false;
        if (!empty($openBasedirPaths)) {
            foreach ($pluginTmpCandidates as $candidate) {
                if (!$this->pathFallsWithinAny($candidate, $openBasedirPaths)) {
                    $tmpOutsideOpenBasedir = true;
                    break;
                }
            }
            if ($rawUploadTmpDir !== ''
                && !$this->pathFallsWithinAny($rawUploadTmpDir, $openBasedirPaths)) {
                $uploadTmpOutsideOpenBasedir = true;
            }
        }

        $tmpDirForCheck = $rawUploadTmpDir !== '' ? $rawUploadTmpDir : $sysTmpDir;
        $tmpFreeBytes = $this->probeTmpFreeBytes($tmpDirForCheck, $openBasedirPaths);
        $tmpDiskLow = ($tmpFreeBytes >= 0 && $tmpFreeBytes < ABJ_404_Solution_ViewBuildConfig::PHP_TMPDIR_FREE_FLOOR_BYTES);
        $tmpWriteProbe = $this->probeTmpWritable($tmpDirForCheck, $openBasedirPaths);

        $result = array(
            'open_basedir_raw'                 => $rawOpenBasedir,
            'open_basedir_paths'               => $openBasedirPaths,
            'upload_tmp_dir_raw'               => $rawUploadTmpDir,
            'sys_tmp_dir'                      => $sysTmpDir,
            'tmp_outside_open_basedir'         => $tmpOutsideOpenBasedir,
            'upload_tmp_outside_open_basedir'  => $uploadTmpOutsideOpenBasedir,
            'tmp_free_bytes'                   => $tmpFreeBytes,
            'tmp_disk_low'                     => $tmpDiskLow,
            'tmp_disk_floor_bytes'             => ABJ_404_Solution_ViewBuildConfig::PHP_TMPDIR_FREE_FLOOR_BYTES,
            'tmp_writable'                     => $tmpWriteProbe['writable'],
            'tmp_write_error'                  => $tmpWriteProbe['error'],
        );

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('abj404_filesystem_env_probe', $result);
            if (is_array($filtered)) {
                $result = array_merge($result, $filtered);
            }
        }

        $warnings = $this->classifyFilesystemWarnings($result, $tmpDirForCheck);
        $result['warnings'] = $warnings;

        foreach ($warnings as $w) {
            $this->logger->warn('[staged] ' . $w);
        }
        if (!empty($warnings)) {
            $this->noticePolicy->setFilesystemEnvAdminNotice($result);
        }

        if (function_exists('update_option')) {
            update_option($this->filesystemEnvironmentProbeOptionName(), $result, false);
        }

        $this->filesystemEnvironmentProbeCache = $result;
        return $result;
    }

    /** @return void */
    public function clearFilesystemEnvironmentProbeCache(): void {
        $this->filesystemEnvironmentProbeCache = null;
        if (function_exists('delete_option')) {
            delete_option($this->filesystemEnvironmentProbeOptionName());
        }
    }

    /**
     * @param array<int,string> $openBasedirPaths
     * @return bool
     */
    private function tmpPathIsProbeable(string $path, array $openBasedirPaths): bool {
        return $path !== ''
            && (empty($openBasedirPaths) || $this->pathFallsWithinAny($path, $openBasedirPaths));
    }

    /**
     * @param array<int,string> $openBasedirPaths
     * @return int
     */
    private function probeTmpFreeBytes(string $tmpDirForCheck, array $openBasedirPaths): int {
        if (!function_exists('disk_free_space')
            || !$this->tmpPathIsProbeable($tmpDirForCheck, $openBasedirPaths)) {
            return -1;
        }
        $prev = function_exists('error_reporting') ? error_reporting(0) : 0;
        try {
            $bytes = @disk_free_space($tmpDirForCheck);
            return ($bytes === false) ? -1 : (int)$bytes;
        } catch (\Throwable $e) {
            $this->logger->warn('[staged] temp free-space probe failed: '
                . get_class($e) . ': ' . $e->getMessage());
            return -1;
        } finally {
            if (function_exists('error_reporting')) {
                error_reporting($prev);
            }
        }
    }

    /**
     * @param array<int,string> $openBasedirPaths
     * @return array{writable:bool,error:string}
     */
    private function probeTmpWritable(string $tmpDirForCheck, array $openBasedirPaths): array {
        if ($tmpDirForCheck === '') {
            return array('writable' => false, 'error' => 'No temp directory configured');
        }
        if (!$this->tmpPathIsProbeable($tmpDirForCheck, $openBasedirPaths)) {
            return array('writable' => true, 'error' => '');
        }

        $prev = function_exists('error_reporting') ? error_reporting(0) : 0;
        $probeFile = false;
        try {
            $probeFile = function_exists('tempnam') ? @tempnam($tmpDirForCheck, 'abj404_probe_') : false;
            if (!is_string($probeFile)) {
                return array('writable' => false, 'error' => 'tempnam returned false');
            }
            $written = function_exists('file_put_contents') ? @file_put_contents($probeFile, '1') : false;
            if ($written === false) {
                return array('writable' => false, 'error' => 'file_put_contents returned false');
            }
            return array('writable' => true, 'error' => '');
        } catch (\Throwable $e) {
            return array('writable' => false, 'error' => get_class($e) . ': ' . $e->getMessage());
        } finally {
            if (is_string($probeFile) && function_exists('unlink')) {
                @unlink($probeFile);
            }
            if (function_exists('error_reporting')) {
                error_reporting($prev);
            }
        }
    }

    /**
     * @param array<string,mixed> $result
     * @return array<int,string>
     */
    private function classifyFilesystemWarnings(array $result, string $tmpDirForCheck): array {
        $warnings = array();
        $resultOpenBasedirRaw = isset($result['open_basedir_raw']) && is_scalar($result['open_basedir_raw'])
            ? (string)$result['open_basedir_raw'] : '';
        if (!empty($result['tmp_outside_open_basedir'])) {
            $resultSysTmpDir = isset($result['sys_tmp_dir']) && is_scalar($result['sys_tmp_dir'])
                ? (string)$result['sys_tmp_dir'] : '';
            $warnings[] = sprintf(
                'open_basedir (%s) does not include the system temp directory (%s); '
                . 'PHP-side temp file work may fail.',
                $resultOpenBasedirRaw, $resultSysTmpDir
            );
        }
        if (!empty($result['upload_tmp_outside_open_basedir'])) {
            $resultUploadTmpDirRaw = isset($result['upload_tmp_dir_raw']) && is_scalar($result['upload_tmp_dir_raw'])
                ? (string)$result['upload_tmp_dir_raw'] : '';
            $warnings[] = sprintf(
                'upload_tmp_dir (%s) is outside open_basedir (%s); ini upload paths cannot be probed.',
                $resultUploadTmpDirRaw, $resultOpenBasedirRaw
            );
        }
        if (!empty($result['tmp_disk_low'])) {
            $resultTmpFreeBytes = isset($result['tmp_free_bytes']) && is_numeric($result['tmp_free_bytes'])
                ? (int)$result['tmp_free_bytes'] : -1;
            $warnings[] = sprintf(
                'temp directory (%s) has %d bytes free (< %d MB floor); the S9 hits '
                . 'aggregate or any MySQL temp materialization may fail with "No space left on device".',
                $tmpDirForCheck,
                $resultTmpFreeBytes,
                (int)(ABJ_404_Solution_ViewBuildConfig::PHP_TMPDIR_FREE_FLOOR_BYTES / 1048576)
            );
        }
        if (isset($result['tmp_writable']) && empty($result['tmp_writable'])) {
            $resultTmpWriteError = isset($result['tmp_write_error']) && is_scalar($result['tmp_write_error'])
                ? (string)$result['tmp_write_error'] : 'unknown write failure';
            $warnings[] = sprintf(
                'temp directory (%s) is not writable (%s); staged view-build temp-file work may fail.',
                $tmpDirForCheck,
                $resultTmpWriteError
            );
        }
        return $warnings;
    }

    /**
     * Split a raw open_basedir value into a trimmed list of path prefixes.
     *
     * @param string $raw
     * @return array<int,string>
     */
    public function splitOpenBasedirPaths(string $raw): array {
        $raw = trim($raw);
        if ($raw === '') { return array(); }
        $sep = defined('PATH_SEPARATOR') ? PATH_SEPARATOR : ':';
        $parts = array_map('trim', explode($sep, $raw));
        $paths = array();
        foreach ($parts as $part) {
            if ($part !== '') {
                $paths[] = $part;
            }
        }
        return $paths;
    }

    /**
     * @param string             $candidate
     * @param array<int,string>  $allowed
     * @return bool
     */
    public function pathFallsWithinAny(string $candidate, array $allowed): bool {
        if ($candidate === '' || empty($allowed)) { return true; }
        $normCandidate = $this->normalizePathPrefix($candidate);
        foreach ($allowed as $a) {
            $normA = $this->normalizePathPrefix($a);
            if ($normA === '') { continue; }
            if (strncmp($normCandidate, $normA, strlen($normA)) === 0) {
                return true;
            }
        }
        return false;
    }

    /** @return string */
    public function normalizePathPrefix(string $path): string {
        $path = trim($path);
        if ($path === '') { return ''; }
        if (function_exists('realpath')) {
            $real = @realpath($path);
            if (is_string($real)) {
                return rtrim($real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            }
        }
        return rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }
}
