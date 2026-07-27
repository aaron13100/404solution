<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Writes lightweight checkpoint records and caches their resolved request path.
 *
 * The cache lets callbacks executing inside wpdb reuse the path resolved by
 * the pre-query probe. Re-entering wp_upload_dir() or WordPress filters from a
 * query callback can issue nested SQL and recursively dispatch the callback
 * being measured.
 */
final class ABJ_404_Solution_AjaxFrequentCheckpointWriter {

    /** @var string */
    private static $resolvedRequestId = '';

    /** @var string */
    private static $resolvedDirectory = '';

    /** @var int */
    private static $checkpointSequence = 0;

    public static function rememberResolvedDirectory(string $requestId, string $directory): void {
        if ($requestId === '') {
            return;
        }
        self::$resolvedRequestId = $requestId;
        self::$resolvedDirectory = $directory;
    }

    public static function resolvedDirectoryForRequest(string $requestId): string {
        return $requestId !== '' && $requestId === self::$resolvedRequestId
            ? self::$resolvedDirectory
            : '';
    }

    /**
     * Whether directory resolution already ran for this request.
     *
     * Kept separate from resolvedDirectoryForRequest() because an empty
     * directory is a valid cached failure. Re-running WordPress filters after
     * that failure can recurse into the same database boundary being traced.
     */
    public static function hasResolvedDirectoryForRequest(string $requestId): bool {
        return $requestId !== '' && $requestId === self::$resolvedRequestId;
    }

    public static function resetForTests(): void {
        self::$resolvedRequestId = '';
        self::$resolvedDirectory = '';
        self::$checkpointSequence = 0;
    }

    /**
     * @param array<string, mixed> $fields
     */
    public static function append(
        string $requestId,
        string $event,
        array $fields,
        string $directory,
        bool $directoryWasResolved,
        string $checkpointId = ''
    ): void {
        if ($checkpointId === '') {
            $checkpointId = self::checkpointId();
            ABJ_404_Solution_CheckpointIntentStore::append(
                ABJ_404_Solution_CheckpointRecordFactory::intent(array(
                    'request_id' => $requestId,
                    'event' => $event,
                    'checkpoint_id' => $checkpointId,
                    'hrtime_ns' => function_exists('hrtime') ? (int)hrtime(true) : null,
                    'pid' => getmypid(),
                ))
            );
        }
        if ($directory === '') {
            return;
        }
        if (!$directoryWasResolved
                && (!class_exists('ABJ_404_Solution_FileSystemService')
                    || !ABJ_404_Solution_FileSystemService::createDirectoryWithErrorMessages($directory))) {
            return;
        }
        ABJ_404_Solution_CheckpointJournalWriter::append($directory, array_merge(
            $fields,
            ABJ_404_Solution_CheckpointRecordFactory::frequent(array(
                'ts' => self::nowFloat(),
                'hrtime_ns' => function_exists('hrtime') ? (int)hrtime(true) : null,
                'request_id' => $requestId,
                'event' => $event,
                'checkpoint_id' => $checkpointId,
                'pid' => getmypid(),
            ))
        ));
    }

    private static function checkpointId(): string {
        self::$checkpointSequence++;
        $pid = getmypid();
        $startedNs = function_exists('hrtime') ? (int)hrtime(true) : 0;
        return self::alphabeticHex(is_int($pid) ? $pid : 0) . '-'
            . self::alphabeticHex($startedNs) . '-f'
            . self::alphabeticHex(self::$checkpointSequence);
    }

    private static function alphabeticHex(int $value): string {
        return strtr(dechex($value), '0123456789abcdef', 'ghijklmnopqrstuv');
    }

    private static function nowFloat(): ?float {
        if (function_exists('abj_clock')) {
            return abj_clock()->nowFloat();
        }
        if (class_exists('ABJ_404_Solution_SystemClock')) {
            return (new ABJ_404_Solution_SystemClock())->nowFloat();
        }
        return null;
    }
}
