<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/CrashBeacon.php';

/**
 * Persists at most one pending ABJ_404_Solution_CrashBeacon to a small JSON file
 * in the plugin's uploads dir.
 *
 * Why a file (not an option or a transient): a ~200-byte file_put_contents
 * allocates far less than update_option (which can load the whole options table)
 * during an OOM, AND a file in wp-content/uploads survives a plugin update (the
 * plugin directory is replaced; uploads is not), which is what lets an
 * every-request-OOM site be reported after it recovers.
 *
 * The fatal-handler (write) path must use ONLY a precomputed primitive path; it
 * must not call wp_upload_dir()/options/the container during shutdown. Callers
 * in the write path therefore construct this with the path cached at healthy
 * boot in $GLOBALS['abj404_crash_beacon_path']. The healthy drain path may use
 * forCurrentSite(), which resolves the path through WP normally.
 */
class ABJ_404_Solution_CrashBeaconStore {

    /** Predictable filename; the file contents are PII-redacted at rest, so a
     *  predictable path in a possibly-public uploads dir discloses nothing
     *  sensitive (consistent with the sibling abj404_debug.zip). */
    const FILE_NAME = 'abj404_crash_beacon.json';

    /** Refuse to read a file larger than this. A predictable-path file could be
     *  bloated by a corrupted write or an unrelated process; a real beacon is a
     *  few hundred bytes. */
    const MAX_READ_BYTES = 8192;

    /** @var string absolute path to the beacon file. */
    private $filePath;

    /**
     * @param string $filePath absolute path to the beacon JSON file.
     */
    public function __construct(string $filePath) {
        $this->filePath = $filePath;
    }

    /**
     * Resolve the store for the current site on a HEALTHY request. Prefers the
     * path cached at boot; falls back to resolving through WP. Do NOT use this in
     * the fatal handler (it may call wp_upload_dir()).
     *
     * @return self
     */
    public static function forCurrentSite(): self {
        $cached = isset($GLOBALS['abj404_crash_beacon_path']) && is_string($GLOBALS['abj404_crash_beacon_path'])
            ? $GLOBALS['abj404_crash_beacon_path'] : '';
        if ($cached !== '') {
            return new self($cached);
        }
        $dir = function_exists('abj404_getUploadsDir') ? abj404_getUploadsDir() : '';
        return new self(rtrim((string)$dir, '/\\') . DIRECTORY_SEPARATOR . self::FILE_NAME);
    }

    /** @return string */
    public function filePath(): string {
        return $this->filePath;
    }

    /** @return bool */
    public function exists(): bool {
        return @is_file($this->filePath);
    }

    /**
     * Last-modified epoch seconds, or null if the file is absent/unstatable.
     * Used by the drain to age-gate the discard of a corrupt/partial file so a
     * file caught mid-write is not deleted before its writer finishes.
     *
     * @return int|null
     */
    public function modifiedAt(): ?int {
        $m = @filemtime($this->filePath);
        return $m === false ? null : (int)$m;
    }

    /**
     * Write the beacon ONLY if no beacon file already exists. Uses fopen('xb'),
     * an atomic create-only open, so two concurrent crashing requests cannot
     * both write (first crash wins, race-safe) and so we never read+allocate an
     * existing file during an OOM. All I/O is error-suppressed because this runs
     * in the fatal handler where a warning must not escalate.
     *
     * @param ABJ_404_Solution_CrashBeacon $beacon
     * @return bool true iff a new file was written.
     */
    public function recordIfAbsent(ABJ_404_Solution_CrashBeacon $beacon): bool {
        $json = json_encode($beacon->toArray(), JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return false;
        }
        $handle = @fopen($this->filePath, 'xb');
        if ($handle === false) {
            return false;
        }
        $written = @fwrite($handle, $json);
        @fflush($handle);
        @fclose($handle);
        return $written !== false;
    }

    /**
     * Read the pending beacon. Runs only on a healthy request, so failures are
     * surfaced via status rather than silently suppressed.
     *
     * Status values:
     *   'absent'     no file.
     *   'oversized'  file exceeds MAX_READ_BYTES (refused).
     *   'unreadable' file present but could not be read or was empty (possibly mid-write).
     *   'future'     a newer beacon_schema_version: LEAVE IT (a compatible version drains it).
     *   'corrupt'    present, readable, but not a valid known-version beacon.
     *   'ok'         parsed.
     *
     * @return array{beacon: ABJ_404_Solution_CrashBeacon|null, status: string}
     */
    public function read(): array {
        if (!@is_file($this->filePath)) {
            return array('beacon' => null, 'status' => 'absent');
        }
        $size = @filesize($this->filePath);
        if ($size === false || $size > self::MAX_READ_BYTES) {
            return array('beacon' => null, 'status' => 'oversized');
        }
        $raw = @file_get_contents($this->filePath);
        if (!is_string($raw) || $raw === '') {
            return array('beacon' => null, 'status' => 'unreadable');
        }
        $decoded = json_decode($raw, true);
        $beacon = ABJ_404_Solution_CrashBeacon::fromArray($decoded);
        if ($beacon instanceof ABJ_404_Solution_CrashBeacon) {
            return array('beacon' => $beacon, 'status' => 'ok');
        }
        if (is_array($decoded) && isset($decoded['beacon_schema_version'])
                && is_scalar($decoded['beacon_schema_version'])
                && (int)$decoded['beacon_schema_version'] > ABJ_404_Solution_CrashBeacon::SCHEMA_VERSION) {
            return array('beacon' => null, 'status' => 'future');
        }
        return array('beacon' => null, 'status' => 'corrupt');
    }

    /** Remove the pending beacon file (idempotent). @return void */
    public function clear(): void {
        if (@is_file($this->filePath)) {
            @unlink($this->filePath);
        }
    }
}
