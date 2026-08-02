<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Storage for the set of PHP requests this site currently has in flight.
 *
 * ONE ROW PER REQUEST, written and deleted by that request alone. A single
 * shared counter incremented at request start and decremented at shutdown is
 * corrupted by exactly the event the census exists to investigate: a request
 * killed mid-flight never runs its decrement, so the counter drifts upward
 * forever and every later reading is fabricated. A row that only its own
 * request ever writes has no read-modify-write and therefore no interleaving
 * to lose, and a row whose request died is simply an old row -- something a
 * reader can recognise and delete, which a corrupted integer is not.
 *
 * This class owns the row and nothing else: how its name is minted, how its
 * value is encoded and decoded, and the statements that write, read and remove
 * it. What counts as "old", which requests are allowed to register, and what a
 * reading reports belong to ABJ_404_Solution_SameSiteRequestCensus. The same
 * split this subsystem already uses for
 * ABJ_404_Solution_AjaxCheckpointLogger and its journal writer.
 */
final class ABJ_404_Solution_SameSiteRequestRegistry {

    /**
     * Option-name prefix for one in-flight request. Alphanumeric on purpose:
     * it is used as a LIKE prefix, and `_` is a single-character wildcard in
     * LIKE, so an underscore here would silently widen the match to rows this
     * class never wrote.
     */
    const OPTION_PREFIX = 'abj404inflight';

    /**
     * Rows read at once. A real reading is a handful; the ceiling exists so a
     * pathological leak degrades into a truncated (and self-announcing)
     * reading rather than an unbounded SELECT on the path being measured.
     */
    const MAX_ENTRIES_READ = 200;

    /** Rows removed per call. Repeated readings converge; one never stalls on cleanup. */
    const MAX_REMOVED_PER_CALL = 50;

    /**
     * Register one in-flight request.
     *
     * INSERT IGNORE rather than an upsert: this row belongs to this request
     * alone and nothing else may ever write it, which is the property that
     * makes the whole registry race-free.
     *
     * @param string $phase the segment the request is in at registration. The
     *   caller names it: which segments exist, and what they mean, belongs to
     *   ABJ_404_Solution_SameSiteRequestCensus. Written with the row rather
     *   than by a follow-up update so registration still costs one query.
     * @return string the option name the request was registered under, or ''
     *   when it could not be registered at all.
     */
    public static function add(int $startedAtMs, string $channel, string $action, int $pid,
            string $phase = ''): string {
        $dbCore = self::dbCore();
        if ($dbCore === null) {
            return '';
        }
        $optionName = self::OPTION_PREFIX . dechex($pid)
            . preg_replace('/[^a-f0-9]/', '', uniqid('', true));
        $result = $dbCore->queryAndGetResults(
            "INSERT IGNORE INTO {wp_options} (option_name, option_value, autoload) "
            . "VALUES (%s, %s, 'no')",
            array('query_params' => array($optionName,
                self::encode($startedAtMs, $channel, $action, $pid, $phase)))
        );
        if (!empty($result['last_error'])) {
            // queryAndGetResults already logged it (CLAUDE.md: it is the
            // centralized error handler). A registry that cannot register is a
            // missing diagnostic, never a reason to affect the request.
            return '';
        }
        return $optionName;
    }

    /**
     * Record which segment of its own lifecycle this request has entered.
     *
     * A plain UPDATE of one row by primary key, and the single-writer property
     * that makes the registry race-free is what makes it safe: the row belongs
     * to this request alone, so there is no read-modify-write and nothing to
     * interleave with. The other fields are rewritten from the caller's own
     * values rather than read back and merged, for the same reason.
     *
     * ALWAYS CALLED BEFORE ENTERING THE SEGMENT IT NAMES, never after. A
     * request that dies inside a segment cannot write anything afterwards, so
     * a phase recorded on the way out would be exactly the one missing from
     * every row worth reading. Recorded on the way in, an abandoned row's
     * phase names the segment the worker was inside when it stopped -- which
     * is the entire question a stranded worker poses.
     *
     * @return bool whether the row was updated.
     */
    public static function advance(string $optionName, int $startedAtMs, string $channel,
            string $action, int $pid, string $phase): bool {
        $dbCore = self::dbCore();
        if ($dbCore === null || $optionName === '') {
            return false;
        }
        $result = $dbCore->queryAndGetResults(
            "UPDATE {wp_options} SET option_value = %s WHERE option_name = %s",
            array('query_params' => array(
                self::encode($startedAtMs, $channel, $action, $pid, $phase), $optionName))
        );
        // queryAndGetResults is the centralized error handler (CLAUDE.md #11).
        // A phase that cannot be recorded is a coarser reading, never a reason
        // to affect the request being measured.
        return empty($result['last_error']);
    }

    /**
     * Every registered request, decoded, oldest option name first.
     *
     * `truncated` says the read ceiling was reached, so a reader can tell a
     * bounded reading from a complete one instead of quietly believing the
     * smaller number.
     *
     * @return array{status: string, reason: string, entries: array<int, array{option_name: string, started_at_ms: int, channel: string, action: string, pid: int, phase: string}>, truncated: bool}
     */
    public static function readAll(): array {
        $dbCore = self::dbCore();
        if ($dbCore === null) {
            return self::unreadable('dao_unavailable');
        }
        $result = $dbCore->queryAndGetResults(
            "SELECT option_name, option_value FROM {wp_options} "
            . "WHERE option_name LIKE %s ORDER BY option_name LIMIT " . (self::MAX_ENTRIES_READ + 1),
            array('query_params' => array(self::OPTION_PREFIX . '%'))
        );
        if (!empty($result['last_error'])) {
            return self::unreadable('read_failed');
        }
        $rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();
        $truncated = count($rows) > self::MAX_ENTRIES_READ;
        if ($truncated) {
            $rows = array_slice($rows, 0, self::MAX_ENTRIES_READ);
        }
        $entries = array();
        foreach ($rows as $row) {
            $entry = self::decode($row);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }
        return array('status' => 'available', 'reason' => '', 'entries' => $entries,
            'truncated' => $truncated);
    }

    /**
     * Remove registrations by option name. Idempotent, bounded per call, and
     * safe when two readers remove the same name at once.
     *
     * @param array<int, string> $optionNames
     * @return int how many rows the delete claimed.
     */
    public static function remove(array $optionNames): int {
        $optionNames = array_values($optionNames);
        $dbCore = self::dbCore();
        if ($dbCore === null || $optionNames === array()) {
            return 0;
        }
        $optionNames = array_slice($optionNames, 0, self::MAX_REMOVED_PER_CALL);
        $placeholders = implode(', ', array_fill(0, count($optionNames), '%s'));
        $result = $dbCore->queryAndGetResults(
            "DELETE FROM {wp_options} WHERE option_name IN (" . $placeholders . ")",
            array('query_params' => $optionNames)
        );
        if (!empty($result['last_error'])) {
            return 0;
        }
        return isset($result['rows_affected']) && is_numeric($result['rows_affected'])
            ? (int)$result['rows_affected'] : count($optionNames);
    }

    /**
     * The stored value: start time, channel, WordPress action, PID, phase.
     *
     * A flat delimited string rather than JSON because every field is a
     * bounded scalar and the row is written on a path whose cost is being
     * measured; the decoder below is the only reader.
     *
     * Phase is last so a row written by an older build -- four fields, no
     * trailing delimiter -- still decodes completely, with an empty phase
     * rather than a rejected row. The delimiter is stripped from the phase
     * for the same reason the decoder bounds every field: a value that could
     * introduce a sixth part would shift the meaning of the parts after it.
     */
    private static function encode(int $startedAtMs, string $channel, string $action, int $pid,
            string $phase = ''): string {
        return $startedAtMs . '|' . $channel . '|' . $action . '|' . $pid
            . '|' . str_replace('|', '', $phase);
    }

    /**
     * One row as a structured entry, or null when the row is not one this
     * class wrote in a format it understands.
     *
     * @param mixed $row
     * @return array{option_name: string, started_at_ms: int, channel: string, action: string, pid: int, phase: string}|null
     */
    private static function decode($row): ?array {
        if (!is_array($row)) {
            return null;
        }
        // Case-insensitive: MySQL drivers vary the case of returned column
        // names, and a registry that silently reads nothing is the failure
        // mode the whole census exists to avoid.
        $row = array_change_key_case($row, CASE_LOWER);
        $name = isset($row['option_name']) && is_scalar($row['option_name'])
            ? (string)$row['option_name'] : '';
        $raw = isset($row['option_value']) && is_scalar($row['option_value'])
            ? (string)$row['option_value'] : '';
        if ($name === '' || $raw === '') {
            return null;
        }
        $parts = explode('|', $raw, 5);
        if (!isset($parts[0]) || !ctype_digit($parts[0])) {
            return null;
        }
        return array(
            'option_name' => $name,
            'started_at_ms' => (int)$parts[0],
            'channel' => isset($parts[1]) ? substr($parts[1], 0, 16) : '',
            'action' => isset($parts[2]) ? substr($parts[2], 0, 64) : '',
            'pid' => isset($parts[3]) && ctype_digit($parts[3]) ? (int)$parts[3] : 0,
            // A row from a build that predates phases has four parts. Reported
            // as an empty phase, which the census names explicitly, rather than
            // being confused with a request that reached no phase at all.
            'phase' => isset($parts[4]) ? substr($parts[4], 0, 32) : '',
        );
    }

    /**
     * @return array{status: string, reason: string, entries: array<int, array{option_name: string, started_at_ms: int, channel: string, action: string, pid: int, phase: string}>, truncated: bool}
     */
    private static function unreadable(string $reason): array {
        return array('status' => 'unavailable', 'reason' => $reason, 'entries' => array(),
            'truncated' => false);
    }

    /**
     * Read straight from the container rather than through
     * ABJ_404_Solution_Ajax_ServiceResolver, which is a two-line pass-through
     * to this same call that exists to serve the AJAX endpoint adapters. The
     * census runs on every in-scope request, not only AJAX ones, so borrowing
     * the endpoint layer's accessor was both an indirection with nothing in it
     * and a dependency pointing the wrong way: instrumentation must not need
     * the presentation surface to be loaded in order to read a service.
     *
     * @return ABJ_404_Solution_DatabaseQueryInterface|null
     */
    private static function dbCore() {
        if (!class_exists('ABJ_404_Solution_ServiceContainer')) {
            return null;
        }
        $dbCore = ABJ_404_Solution_ServiceContainer::safeGet('db_core');
        return ($dbCore instanceof ABJ_404_Solution_DatabaseQueryInterface) ? $dbCore : null;
    }
}
