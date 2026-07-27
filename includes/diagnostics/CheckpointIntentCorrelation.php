<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Correlates fixed-sink checkpoint intents with ordinary terminal records.
 *
 * The fixed and ordinary journals are merged only during support collection.
 * This policy keeps exact checkpoint-ID matching in one place so required
 * evidence selection and ranked-journal compaction cannot disagree.
 */
final class ABJ_404_Solution_CheckpointIntentCorrelation {

    /**
     * @param array<int, string> $lines
     * @return array<string, bool>
     */
    public static function closedCheckpointIds(array $lines): array {
        $closed = array();
        foreach ($lines as $line) {
            $record = json_decode($line, true);
            if (!is_array($record) || self::isIntent($record)) {
                continue;
            }
            $checkpointId = self::checkpointId($record);
            if ($checkpointId !== '') {
                $closed[$checkpointId] = true;
            }
        }
        return $closed;
    }

    /**
     * @param array<int, string> $lines
     * @param array<string, bool> $closed
     * @return array<int, string>
     */
    public static function withoutClosedIntents(array $lines, array $closed): array {
        return array_values(array_filter($lines, static function (string $line) use ($closed): bool {
            $record = json_decode($line, true);
            if (!is_array($record) || !self::isIntent($record)) {
                return true;
            }
            $checkpointId = self::checkpointId($record);
            return $checkpointId === '' || !isset($closed[$checkpointId]);
        }));
    }

    /**
     * Fixed-sink intents whose exact ordinary checkpoint never reached disk.
     *
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    public static function unmatchedIntentLines(array $lines): array {
        $closed = self::closedCheckpointIds($lines);
        return array_values(array_filter($lines, static function (string $line) use ($closed): bool {
            $record = json_decode($line, true);
            if (!is_array($record) || !self::isIntent($record)) {
                return false;
            }
            $checkpointId = self::checkpointId($record);
            return $checkpointId !== '' && !isset($closed[$checkpointId]);
        }));
    }

    /**
     * Remove keyed intents after RequiredCheckpointEvidence reserved them.
     *
     * Malformed or unkeyed intents remain rankable because they cannot be
     * correlated safely.
     *
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    public static function withoutKeyedIntents(array $lines): array {
        return array_values(array_filter($lines, static function (string $line): bool {
            $record = json_decode($line, true);
            return !is_array($record)
                || !self::isIntent($record)
                || self::checkpointId($record) === '';
        }));
    }

    /** @param array<mixed, mixed> $record Decoded JSON at the untrusted journal boundary. */
    private static function isIntent(array $record): bool {
        return ($record['envelope'] ?? '')
            === ABJ_404_Solution_CheckpointRecordFactory::ENVELOPE_INTENT;
    }

    /** @param array<mixed, mixed> $record Decoded JSON at the untrusted journal boundary. */
    private static function checkpointId(array $record): string {
        return is_string($record['checkpoint_id'] ?? null)
            ? $record['checkpoint_id']
            : '';
    }
}
