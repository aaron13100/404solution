<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Selects checkpoint records that must survive bounded support ranking.
 *
 * Ordinary request-group ranking may omit an early census, a row-progress
 * sample, or a completed browser report even though each is the only record
 * carrying its discriminator fields. This policy reserves one complete record
 * for every such evidence class before the remaining byte budget is ranked.
 */
final class ABJ_404_Solution_RequiredCheckpointEvidence {

    /**
     * Latest complete record for every required evidence identity.
     *
     * @param array<int, string> $lines JSONL lines, oldest first.
     * @return array<int, string>
     */
    public static function select(array $lines): array {
        if (class_exists('ABJ_404_Solution_ActiveOperationBreadcrumbs')) {
            $lines = ABJ_404_Solution_ActiveOperationBreadcrumbs::compactSupportLines($lines);
        }
        $operationState = array();
        foreach (array_reverse($lines) as $line) {
            $record = json_decode($line, true);
            if (!is_array($record)) {
                continue;
            }
            $operationKey = self::activeOperationKey($record);
            if ($operationKey !== '' && !isset($operationState[$operationKey])) {
                $operationState[$operationKey] = array('line' => $line, 'record' => $record);
            }
        }
        $required = array_merge(
            ABJ_404_Solution_MalformedCheckpointEvidence::select($lines),
            ABJ_404_Solution_RequiredCheckpointIdentityEvidence::select($lines)
        );
        foreach ($operationState as $latest) {
            $record = $latest['record'];
            $line = $latest['line'];
            if (self::isReservedActiveOperation($record)) {
                $required[] = $line;
            }
        }
        foreach (self::reservedDurableOperationLines($lines) as $line) {
            $required[] = $line;
        }
        foreach (self::unmatchedOperationLines($lines) as $line) {
            $required[] = $line;
        }
        foreach (ABJ_404_Solution_CheckpointIntentCorrelation::unmatchedIntentLines($lines)
            as $line) {
            $required[] = $line;
        }
        foreach (self::lastFullBoundaryLinesForIncompleteRequests($lines) as $line) {
            $required[] = $line;
        }
        return array_values(array_unique($required));
    }

    /**
     * The final full-envelope boundary reached by each unterminated request.
     *
     * High-frequency query and row records can follow the last lifecycle
     * boundary before a worker stalls. Raw head/tail byte trimming therefore
     * treats that boundary as middle content and can discard the exact
     * pre-stall location when host-pressure counters widen the full envelope.
     * Reserve one boundary per incomplete request so variable environment
     * values cannot change whether the failure remains attributable.
     *
     * A request with only frequent records has no boundary to invent. A
     * request with any terminal event is complete and stays in ordinary
     * request-group ranking.
     *
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private static function lastFullBoundaryLinesForIncompleteRequests(array $lines): array {
        $requests = array();
        foreach ($lines as $line) {
            $record = json_decode($line, true);
            if (!is_array($record) || !is_scalar($record['request_id'] ?? null)
                    || !is_scalar($record['event'] ?? null)) {
                continue;
            }
            $requestId = (string)$record['request_id'];
            $event = (string)$record['event'];
            if ($requestId === '' || $event === '') {
                continue;
            }
            if (!isset($requests[$requestId])) {
                $requests[$requestId] = array(
                    'terminal' => false,
                    'boundary' => '',
                    'boundary_count' => 0,
                );
            }
            if (in_array($event, ABJ_404_Solution_DiagnosticEvidencePriority::TERMINAL_EVENTS, true)) {
                $requests[$requestId]['terminal'] = true;
            }
            if (($record['envelope'] ?? '')
                    === ABJ_404_Solution_CheckpointRecordFactory::ENVELOPE_FULL) {
                $requests[$requestId]['boundary'] = $line;
                $requests[$requestId]['boundary_count']++;
            }
        }

        $selected = array();
        foreach ($requests as $request) {
            // A lone full record is already both the head and tail of its
            // request group, so raw head/tail ranking cannot hide it. Requiring
            // a preceding boundary also keeps a standalone partial evidence
            // sample from being promoted merely because it lacks a terminal.
            if ($request['terminal'] === false && $request['boundary_count'] > 1) {
                $selected[] = $request['boundary'];
            }
        }
        return $selected;
    }

    /**
     * Select the latest unresolved fixed-sink state for each operation.
     *
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private static function reservedDurableOperationLines(array $lines): array {
        $latestByOperation = array();
        foreach ($lines as $line) {
            $record = json_decode($line, true);
            $operationKey = is_array($record) ? self::durableOperationKey($record) : '';
            if ($operationKey !== '') {
                $latestByOperation[$operationKey] = array('line' => $line, 'record' => $record);
            }
        }
        $selected = array();
        foreach ($latestByOperation as $latest) {
            $record = $latest['record'] ?? null;
            $line = $latest['line'] ?? null;
            if (is_array($record) && is_string($line)
                    && self::isReservedDurableOperation($record)) {
                $selected[] = $line;
            }
        }
        return $selected;
    }

    /**
     * Starts whose matching completion never reached disk.
     *
     * The start/end pairs to reserve are DERIVED from the decisive-record
     * manifest, not hardcoded here: every operation family the manifest marks
     * `reserve` (row-render, rate-limit backend/cache, option persistence,
     * option-hook callback) is preserved by the same request_id + operation_id
     * matching. Enrolling a new decisive record in the manifest extends this
     * reservation automatically, which is the structural fix for the recurring
     * "emitted but un-reserved" gap. The query timeline keeps its own start /
     * clear semantics (a summary with open_query === null cancels the reserve).
     *
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private static function unmatchedOperationLines(array $lines): array {
        return array_merge(
            self::unmatchedReservedStartLines($lines),
            self::unmatchedQueryLines($lines)
        );
    }

    /**
     * Reserved operation starts (from the manifest) whose matching end never
     * reached disk. Each family's start/end pair is matched by request_id +
     * operation_id; an end removes its start, so what remains is the hung set.
     *
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private static function unmatchedReservedStartLines(array $lines): array {
        $startEvents = array();
        $endToStart = array();
        foreach (ABJ_404_Solution_DecisiveRecordManifest::reservedOperationPairs() as $pair) {
            $startEvents[$pair['start']] = true;
            $endToStart[$pair['end']] = $pair['start'];
        }

        $openStarts = array();
        foreach ($lines as $line) {
            $record = json_decode($line, true);
            if (!is_array($record)) {
                continue;
            }
            $event = is_scalar($record['event'] ?? null) ? (string)$record['event'] : '';
            $isStart = isset($startEvents[$event]);
            if (!$isStart && !isset($endToStart[$event])) {
                continue;
            }
            $key = self::operationKey($record, $isStart ? $event : $endToStart[$event]);
            if ($key === '') {
                continue;
            }
            if ($isStart) {
                $openStarts[$key] = $line;
            } else {
                unset($openStarts[$key]);
            }
        }
        return array_values($openStarts);
    }

    /**
     * The reservation key for a start/end record: its start-event namespace
     * plus request_id and operation_id. Empty when either identifier is absent,
     * so an unattributable record is never reserved.
     *
     * @param array<mixed, mixed> $record
     */
    private static function operationKey(array $record, string $startEvent): string {
        $requestId = is_scalar($record['request_id'] ?? null) ? (string)$record['request_id'] : '';
        $operationId = is_scalar($record['operation_id'] ?? null) ? (string)$record['operation_id'] : '';
        if ($requestId === '' || $operationId === '') {
            return '';
        }
        return $startEvent . '|' . $requestId . '|' . $operationId;
    }

    /**
     * The last query probe per request whose timeline never closed (no summary
     * with open_query === null). A killed worker mid-query leaves exactly this.
     *
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private static function unmatchedQueryLines(array $lines): array {
        $lastQueries = array();
        foreach ($lines as $line) {
            $record = json_decode($line, true);
            if (!is_array($record)) {
                continue;
            }
            $requestId = is_scalar($record['request_id'] ?? null)
                ? (string)$record['request_id'] : '';
            $event = is_scalar($record['event'] ?? null) ? (string)$record['event'] : '';
            if ($event === 'query_probe' && $requestId !== '') {
                $lastQueries[$requestId] = $line;
            } elseif ($event === 'query_timeline_summary' && $requestId !== ''
                    && ($record['open_query'] ?? null) === null) {
                unset($lastQueries[$requestId]);
            }
        }
        return array_values($lastQueries);
    }

    /** @param array<mixed, mixed> $record */
    private static function activeOperationKey(array $record): string {
        if (($record['event'] ?? '') !== 'active_operation_breadcrumb') {
            return '';
        }
        $requestId = is_scalar($record['request_id'] ?? null)
            ? (string)$record['request_id'] : '';
        $boundary = is_scalar($record['boundary'] ?? null)
            ? (string)$record['boundary'] : '';
        $state = is_scalar($record['state'] ?? null) ? (string)$record['state'] : '';
        $manifest = self::activeBoundaryManifest();
        if ($requestId === ''
                || !array_key_exists($boundary, $manifest)
                || !in_array($state, array('active', 'complete'), true)) {
            return '';
        }
        return $requestId . '|' . $boundary;
    }

    /** @param array<mixed, mixed> $record */
    private static function durableOperationKey(array $record): string {
        if (($record['event'] ?? '') !== 'durable_operation_state') {
            return '';
        }
        $requestId = is_scalar($record['request_id'] ?? null)
            ? (string)$record['request_id'] : '';
        $checkpointId = is_scalar($record['operation_checkpoint_id'] ?? null)
            ? (string)$record['operation_checkpoint_id'] : '';
        return $requestId === '' || $checkpointId === ''
            ? ''
            : $requestId . '|' . $checkpointId;
    }

    /** @param array<mixed, mixed> $record */
    private static function isReservedActiveOperation(array $record): bool {
        if (($record['event'] ?? '') !== 'active_operation_breadcrumb'
                || ($record['state'] ?? '') !== 'active') {
            return false;
        }
        $boundary = is_scalar($record['boundary'] ?? null)
            ? (string)$record['boundary'] : '';
        $manifest = self::activeBoundaryManifest();
        $requiredFields = $manifest[$boundary]['required_evidence_fields'] ?? array();
        return $requiredFields !== array()
            && self::hasNonEmptyScalarKeys($record, $requiredFields);
    }

    /** @param array<mixed, mixed> $record */
    private static function isReservedDurableOperation(array $record): bool {
        if (($record['event'] ?? '') !== 'durable_operation_state'
                || !in_array($record['operation_state'] ?? '', array('intent', 'armed'), true)) {
            return false;
        }
        $operationEvent = is_scalar($record['operation_event'] ?? null)
            ? (string)$record['operation_event'] : '';
        if ($operationEvent === 'cache_metrics_probe_start') {
            return self::hasNonEmptyScalarKeys(
                $record,
                array('operation_id', 'source', 'phase', 'operation_checkpoint_id')
            );
        }
        if ($operationEvent !== 'active_operation_breadcrumb'
                || ($record['state'] ?? '') !== 'active') {
            return false;
        }
        $boundary = is_scalar($record['boundary'] ?? null)
            ? (string)$record['boundary'] : '';
        $requiredFields = self::activeBoundaryManifest()[$boundary]['required_evidence_fields']
            ?? array();
        return $requiredFields !== array()
            && self::hasNonEmptyScalarKeys($record, $requiredFields)
            && self::hasNonEmptyScalarKeys($record, array('operation_checkpoint_id'));
    }

    /**
     * Missing diagnostics files must degrade to no reserved active evidence
     * instead of breaking the support-request path on a corrupt install.
     *
     * @return array<string, array{
     *   fields: array<int, string>,
     *   required_evidence_fields: array<int, string>
     * }>
     */
    private static function activeBoundaryManifest(): array {
        return class_exists('ABJ_404_Solution_ActiveOperationBoundaryManifest')
            ? ABJ_404_Solution_ActiveOperationBoundaryManifest::boundaries()
            : array();
    }

    /**
     * @param array<mixed, mixed> $record
     * @param array<int, string> $fields
     */
    private static function hasNonEmptyScalarKeys(array $record, array $fields): bool {
        foreach ($fields as $field) {
            $value = $record[$field] ?? null;
            if (!is_scalar($value) || (string)$value === '') {
                return false;
            }
        }
        return true;
    }
}
