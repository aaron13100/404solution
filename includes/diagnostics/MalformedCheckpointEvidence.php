<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Selects bounded malformed fixed-sink lines for support evidence.
 *
 * Corrupt records cannot participate in request ranking because they have no
 * readable request identity. They still prove that the durable sink was
 * reached, so a small newest-first reserve keeps them without letting one
 * unbounded line consume the support payload.
 */
final class ABJ_404_Solution_MalformedCheckpointEvidence {

    /** Keep the newest bounded corruption samples outside ordinary ranking. */
    private const MAX_RESERVED_LINES = 4;

    /** A corrupt line must never consume the bounded support payload by itself. */
    private const MAX_RESERVED_LINE_BYTES = 1024;

    private const TRUNCATION_SUFFIX = '...[malformed line truncated]';

    /**
     * @param array<int, string> $lines JSONL lines, oldest first.
     * @return array<int, string>
     */
    public static function select(array $lines): array {
        $selected = array();
        foreach ($lines as $line) {
            if (is_array(json_decode($line, true))) {
                continue;
            }
            if (strlen($line) > self::MAX_RESERVED_LINE_BYTES) {
                $prefixBytes = self::MAX_RESERVED_LINE_BYTES
                    - strlen(self::TRUNCATION_SUFFIX);
                $line = substr($line, 0, max(0, $prefixBytes))
                    . self::TRUNCATION_SUFFIX;
            }
            $selected[] = $line;
            if (count($selected) > self::MAX_RESERVED_LINES) {
                array_shift($selected);
            }
        }
        return $selected;
    }
}
