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
            return array('status' => 'unavailable', 'reason' => 'invalid_path', 'path' => '');
        }
        $path = rtrim($path, '/\\');
        if (!is_dir($path)) {
            return array('status' => 'unavailable', 'reason' => 'not_directory', 'path' => $path);
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
        if (!is_writable($path)) {
            return self::unavailable('directory_not_writable');
        }
        $sentinel = @tempnam($path, 'abj404-pressure-');
        if (!is_string($sentinel)) {
            return self::unavailable('create_failed');
        }
        $targetDirectory = realpath($path);
        $createdDirectory = realpath(dirname($sentinel));
        if ($targetDirectory === false || $createdDirectory !== $targetDirectory) {
            @unlink($sentinel);
            return self::unavailable('create_fell_back_outside_target');
        }
        $handle = @fopen($sentinel, 'wb');
        if ($handle === false) {
            @unlink($sentinel);
            return self::unavailable('open_failed');
        }
        $bytesWritten = @fwrite($handle, 'x');
        $closed = @fclose($handle);
        $removed = @unlink($sentinel);
        if ($bytesWritten !== 1) {
            return self::unavailable('write_failed');
        }
        if (!$closed) {
            return self::unavailable('close_failed');
        }
        if (!$removed) {
            return self::unavailable('cleanup_failed');
        }
        return array('status' => 'available', 'bytes_written' => 1);
    }

    /** @return array{status: string, reason: string} */
    private static function unavailable(string $reason): array {
        return array('status' => 'unavailable', 'reason' => $reason);
    }

    private static function reportFailure(string $message): void {
        if (function_exists('abj404_logPhpFallback')) {
            abj404_logPhpFallback('host-pressure', $message);
        }
    }
}
