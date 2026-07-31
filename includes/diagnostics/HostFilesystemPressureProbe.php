<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Measures filesystem capacity and create/write/cleanup ability for host diagnostics.
 */
final class ABJ_404_Solution_HostFilesystemPressureProbe {

    /** @return array<string, mixed> */
    public static function capture(): array {
        $paths = self::defaultProbePaths();
        if (function_exists('apply_filters')) {
            try {
                $filtered = apply_filters('abj404_host_pressure_probe_paths', $paths);
                $paths = is_array($filtered) ? $filtered : array('configuration' => null);
            } catch (Throwable $e) {
                self::reportFailure('filesystem probe-path filter failed: ' . get_class($e) . ' code=' .
                    $e->getCode() . ' message=' . $e->getMessage());
                return array(
                    'configuration' => array(
                        'status' => 'unavailable',
                        'reason' => 'path_filter_failed',
                        'path' => '',
                        'attempted_paths' => array(
                            'apply_filters(abj404_host_pressure_probe_paths)' =>
                                'exception:' . get_class($e),
                        ),
                    ),
                );
            }
        }

        static $requestCache = array();
        $cacheKey = hash('sha256', serialize($paths));
        if (isset($requestCache[$cacheKey])) {
            return $requestCache[$cacheKey];
        }

        $probes = array();
        foreach ($paths as $label => $path) {
            $probeLabel = is_string($label) && $label !== '' ? $label : 'path_' . count($probes);
            $probes[$probeLabel] = self::probe($path);
        }
        $requestCache[$cacheKey] = $probes;
        return $probes;
    }

    /** @return array<string, string> */
    private static function defaultProbePaths(): array {
        $paths = array();
        $contentDirectory = defined('WP_CONTENT_DIR')
            ? rtrim((string)WP_CONTENT_DIR, '/\\')
            : (defined('ABSPATH') ? rtrim((string)ABSPATH, '/\\') . '/wp-content' : '');
        if ($contentDirectory !== '') {
            $paths['wordpress_uploads'] = $contentDirectory . '/uploads';
            if (is_dir($contentDirectory . '/cache')) {
                $paths['wordpress_cache'] = $contentDirectory . '/cache';
            }
        }
        if (function_exists('abj404_getUploadsDir')) {
            try {
                $pluginUploads = abj404_getUploadsDir();
                if (is_string($pluginUploads) && $pluginUploads !== '') {
                    $paths['plugin_diagnostics'] = $pluginUploads;
                }
            } catch (Throwable $e) {
                self::reportFailure('plugin diagnostics path lookup failed: ' . get_class($e) . ' code=' .
                    $e->getCode() . ' message=' . $e->getMessage());
            }
        }
        return $paths;
    }

    /**
     * @param mixed $path
     * @return array<string, mixed>
     */
    private static function probe($path): array {
        if (!is_string($path) || $path === '') {
            return self::unavailable('invalid_path', array(
                'configured_path' => !is_string($path) ? 'not_string' : 'empty',
            ), '');
        }
        $path = rtrim($path, '/\\');
        if (!is_dir($path)) {
            return self::unavailable('not_directory', array(
                'is_dir(' . $path . ')' => 'not_directory',
            ), $path);
        }
        $freeBytes = @disk_free_space($path);
        $totalBytes = @disk_total_space($path);

        return array(
            'status' => 'available',
            'path' => $path,
            'free_bytes' => is_numeric($freeBytes) ? (int)$freeBytes : null,
            'total_bytes' => is_numeric($totalBytes) ? (int)$totalBytes : null,
            'create_write_probe' => self::createWriteProbe($path),
        );
    }

    /** @return array<string, mixed> */
    private static function createWriteProbe(string $path): array {
        $attemptedPaths = array();
        if (!is_writable($path)) {
            return self::unavailable('directory_not_writable', array(
                'is_writable(' . $path . ')' => 'false',
            ));
        }
        $attemptedPaths['is_writable(target_directory)'] = 'true';
        $sentinel = @tempnam($path, 'abj404-pressure-');
        if (!is_string($sentinel)) {
            $attemptedPaths['tempnam(target_directory)'] = 'failed';
            return self::unavailable('create_failed', $attemptedPaths);
        }
        $attemptedPaths['tempnam(target_directory)'] = 'created';
        $targetDirectory = realpath($path);
        $createdDirectory = realpath(dirname($sentinel));
        if ($targetDirectory === false || $createdDirectory !== $targetDirectory) {
            @unlink($sentinel);
            $attemptedPaths['realpath(created_directory)'] = 'outside_target';
            return self::unavailable('create_fell_back_outside_target', $attemptedPaths);
        }
        $attemptedPaths['realpath(created_directory)'] = 'target_match';
        $handle = @fopen($sentinel, 'wb');
        if ($handle === false) {
            @unlink($sentinel);
            $attemptedPaths['fopen(sentinel)'] = 'failed';
            return self::unavailable('open_failed', $attemptedPaths);
        }
        $attemptedPaths['fopen(sentinel)'] = 'opened';
        $bytesWritten = @fwrite($handle, 'x');
        $closed = @fclose($handle);
        $removed = @unlink($sentinel);
        $attemptedPaths['fwrite(sentinel)'] = 'bytes_written:' . (string)$bytesWritten;
        $attemptedPaths['fclose(sentinel)'] = $closed ? 'true' : 'false';
        $attemptedPaths['unlink(sentinel)'] = $removed ? 'true' : 'false';
        if ($bytesWritten !== 1) {
            return self::unavailable('write_failed', $attemptedPaths);
        }
        if (!$closed) {
            return self::unavailable('close_failed', $attemptedPaths);
        }
        if (!$removed) {
            return self::unavailable('cleanup_failed', $attemptedPaths);
        }
        return array('status' => 'available', 'bytes_written' => 1);
    }

    /**
     * @param array<string, string> $attemptedPaths
     * @return array<string, mixed>
     */
    private static function unavailable(
        string $reason,
        array $attemptedPaths,
        ?string $path = null
    ): array {
        $reading = array(
            'status' => 'unavailable',
            'reason' => $reason,
            'attempted_paths' => $attemptedPaths,
        );
        if ($path !== null) {
            $reading['path'] = $path;
        }
        return $reading;
    }

    private static function reportFailure(string $message): void {
        if (function_exists('abj404_logPhpFallback')) {
            abj404_logPhpFallback('host-pressure', $message);
        }
    }
}
