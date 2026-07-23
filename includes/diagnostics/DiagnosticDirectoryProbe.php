<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Proof that the diagnostics directory is genuinely usable for THIS request.
 *
 * A resolved directory path is not evidence of anything. Beta.1 ended with
 * journals that "came back empty", and an empty journal has several
 * indistinguishable causes: a path that resolved but is not writable, a host
 * that writes to a per-worker filesystem, an open_basedir restriction, a quota,
 * a directory that exists for the writing worker and not for the reading one.
 * Only an actual round trip can separate those, and only if it is performed by
 * the request being diagnosed rather than by a health check at some other time.
 *
 * So this walks the whole file lifecycle -- create, append, flush, stat, glob,
 * read back, delete -- against a request-specific file in that directory, and
 * journals the outcome of every step. The step name in a failed record IS the
 * finding: "stat" says the bytes did not land, "glob" says the directory cannot
 * be enumerated (which is exactly what the support collector later has to do),
 * "delete" says the plugin is accumulating files it cannot clean up.
 *
 * The results go through ABJ_404_Solution_AjaxCheckpointLogger, the immediate
 * append-only channel, rather than through the staged trace journal this is
 * partly probing on behalf of: a defect in the component under investigation
 * must not be able to erase the evidence about it. That makes this class a
 * PRODUCER of checkpoint records, like every other instrumented boundary, and
 * the recorder knows nothing about it.
 */
final class ABJ_404_Solution_DiagnosticDirectoryProbe {

    /** Prefix for the probe file, also the glob the enumeration step tests. */
    const FILE_PREFIX = 'abj404_checkpoint_selftest_';

    /**
     * Run the whole round trip and journal each step's outcome. Never throws:
     * a probe that cannot complete is a finding about the host, and it must not
     * become the reason the request it was proving fails.
     */
    public static function run(string $requestId): void {
        try {
            $directory = ABJ_404_Solution_AjaxCheckpointLogger::resolveDirectory();
            if ($directory === '') {
                self::recordStep($requestId, false, 'resolve_directory');
                return;
            }
            $path = $directory . self::FILE_PREFIX . $requestId . '_' . getmypid() . '.tmp';
            $payload = 'abj404-selftest-' . $requestId;

            $handle = @fopen($path, 'wb');
            if ($handle === false) {
                self::recordStep($requestId, false, 'create');
                return;
            }
            $written = @fwrite($handle, $payload);
            $flushed = @fflush($handle);
            @fclose($handle);
            if ($written === false || !$flushed) {
                self::recordStep($requestId, false, 'append_flush');
                return;
            }

            $size = @filesize($path);
            if (!is_int($size) || $size !== strlen($payload)) {
                self::recordStep($requestId, false, 'stat', array('size' => $size));
                return;
            }

            $globMatches = @glob($directory . self::FILE_PREFIX . '*');
            $globCount = is_array($globMatches) ? count($globMatches) : 0;
            if ($globCount < 1) {
                self::recordStep($requestId, false, 'glob', array('glob_count' => $globCount));
                return;
            }

            $readBack = @file_get_contents($path);
            if ($readBack !== $payload) {
                self::recordStep($requestId, false, 'read');
                return;
            }

            if (!@unlink($path)) {
                self::recordStep($requestId, false, 'delete');
                return;
            }

            self::recordStep($requestId, true, 'complete', array('glob_count' => $globCount));
        } catch (Throwable $e) {
            // Reported to the debug log as well as journaled: an exception here
            // is a host condition the plugin cannot act on, and the journal is
            // read only when an admin sends a support request.
            abj404_logPhpFallback('ajax-checkpoint',
                'AJAX checkpoint self-test failed: ' . $e->getMessage());
            self::recordStep($requestId, false, 'exception',
                array('message' => substr($e->getMessage(), 0, 200)));
        }
    }

    /**
     * One step outcome, under the event name the evidence catalogue and both
     * acceptance gates already know this probe by.
     *
     * @param array<string, mixed> $extra
     */
    private static function recordStep(string $requestId, bool $ok, string $step,
            array $extra = array()): void {
        ABJ_404_Solution_AjaxCheckpointLogger::record($requestId, 'selftest',
            array_merge(array('ok' => $ok, 'step' => $step), $extra));
    }
}
