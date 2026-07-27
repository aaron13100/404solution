<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Evaluates the canonical discriminator contracts against durable records.
 *
 * The discriminator contract catalog is separate. This class only interprets
 * its serializable activation, matching, field, and unmatched-operation rules
 * so PHP and browser-backed gates use the same declarations.
 */
final class ABJ_404_Solution_DecisiveRecordContractEvaluator {

    /**
     * @param array<string, array<string, mixed>> $contracts
     * @param array<int, array<string, mixed>> $records
     * @param array<int, string> $profiles
     * @param array<string, mixed> $facts
     * @return array<int, string>
     */
    public static function violations(
        array $contracts,
        array $records,
        array $profiles,
        array $facts
    ): array {
        $violations = array();
        foreach ($contracts as $family => $contract) {
            if (array_intersect(
                $profiles,
                self::strings($contract['profiles'] ?? array())
            ) === array()) {
                continue;
            }
            foreach (self::requirements($contract) as $requirement) {
                $violations = array_merge(
                    $violations,
                    self::requirementViolations(
                        is_string($family) ? $family : '',
                        $requirement,
                        $records,
                        $facts
                    )
                );
            }
        }
        return array_values(array_unique($violations));
    }

    /**
     * @param array<string, mixed> $requirement
     * @param array<int, array<string, mixed>> $records
     * @param array<string, mixed> $facts
     * @return array<int, string>
     */
    private static function requirementViolations(
        string $family,
        array $requirement,
        array $records,
        array $facts
    ): array {
        if (!self::predicateMatches(
            self::stringKeyedArray($requirement['activation'] ?? null),
            $records,
            $facts
        )) {
            return array();
        }
        $id = is_string($requirement['id'] ?? null)
            ? $requirement['id'] : $family;
        $candidates = self::matchingRecords(
            $records,
            is_string($requirement['event'] ?? null) ? $requirement['event'] : '',
            self::stringKeyedArray($requirement['match'] ?? null)
        );
        if ($candidates === array()) {
            return array($id . ': missing required record');
        }
        $valid = array_values(array_filter(
            $candidates,
            static fn(array $record): bool => self::recordSatisfies($record, $requirement)
        ));
        if ($valid === array()) {
            return array($id . ': no matching record satisfies its discriminator fields');
        }
        $violations = array();
        if (($requirement['all_matches'] ?? false) === true
                && count($valid) !== count($candidates)) {
            $violations[] = $id . ': at least one matching record lost a discriminator field';
        }
        $endEvent = $requirement['unmatched_end_event'] ?? null;
        if (is_string($endEvent)
                && !self::hasUnmatchedStart($valid, $records, $endEvent)) {
            $violations[] = $id . ': no unmatched start remains';
        }
        return $violations;
    }

    /**
     * @param array<int, array<string, mixed>> $starts
     * @param array<int, array<string, mixed>> $records
     */
    private static function hasUnmatchedStart(
        array $starts,
        array $records,
        string $endEvent
    ): bool {
        foreach ($starts as $start) {
            $operationId = $start['operation_id'] ?? null;
            if (is_scalar($operationId)
                    && self::matchingRecords(
                        $records,
                        $endEvent,
                        array('operation_id' => $operationId)
                    ) === array()) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $predicate
     * @param array<int, array<string, mixed>> $records
     * @param array<string, mixed> $facts
     */
    private static function predicateMatches(array $predicate, array $records, array $facts): bool {
        if (($predicate['always'] ?? false) === true) {
            return true;
        }
        if (is_array($predicate['all'] ?? null)) {
            return self::allPredicatesMatch($predicate['all'], $records, $facts);
        }
        if (is_array($predicate['any'] ?? null)) {
            return self::anyPredicateMatches($predicate['any'], $records, $facts);
        }
        if (is_string($predicate['fact'] ?? null)) {
            return array_key_exists($predicate['fact'], $facts)
                && ($facts[$predicate['fact']] === ($predicate['equals'] ?? true));
        }
        return self::eventPredicateMatches($predicate, $records);
    }

    /**
     * @param array<string, mixed> $predicate
     * @param array<int, array<string, mixed>> $records
     */
    private static function eventPredicateMatches(array $predicate, array $records): bool {
        if (!is_string($predicate['event'] ?? null)) {
            return false;
        }
        $matches = self::matchingRecords(
            $records,
            $predicate['event'],
            is_array($predicate['match'] ?? null) ? $predicate['match'] : array()
        );
        if (!array_key_exists('field', $predicate)) {
            return $matches !== array();
        }
        $field = is_string($predicate['field']) ? $predicate['field'] : '';
        foreach ($matches as $record) {
            if (!array_key_exists($field, $record)) {
                continue;
            }
            if (self::compare(
                $record[$field],
                is_string($predicate['operator'] ?? null) ? $predicate['operator'] : 'equals',
                $predicate['value'] ?? null
            )) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int|string, mixed> $predicates
     * @param array<int, array<string, mixed>> $records
     * @param array<string, mixed> $facts
     */
    private static function allPredicatesMatch(
        array $predicates,
        array $records,
        array $facts
    ): bool {
        foreach ($predicates as $candidate) {
            if (!is_array($candidate)
                    || !self::predicateMatches(
                        self::stringKeyedArray($candidate),
                        $records,
                        $facts
                    )) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<int|string, mixed> $predicates
     * @param array<int, array<string, mixed>> $records
     * @param array<string, mixed> $facts
     */
    private static function anyPredicateMatches(
        array $predicates,
        array $records,
        array $facts
    ): bool {
        foreach ($predicates as $candidate) {
            if (is_array($candidate)
                    && self::predicateMatches(
                        self::stringKeyedArray($candidate),
                        $records,
                        $facts
                    )) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $requirement
     */
    private static function recordSatisfies(array $record, array $requirement): bool {
        foreach (self::strings($requirement['required_fields'] ?? array()) as $field) {
            if (!array_key_exists($field, $record)) {
                return false;
            }
        }
        foreach (self::strings($requirement['non_empty_fields'] ?? array()) as $field) {
            if (!is_scalar($record[$field] ?? null) || (string)$record[$field] === '') {
                return false;
            }
        }
        foreach (is_array($requirement['field_types'] ?? null)
            ? $requirement['field_types'] : array() as $field => $type) {
            if (!is_string($field)) {
                return false;
            }
            if ($type === 'integer' && !is_int($record[$field] ?? null)) {
                return false;
            }
            if ($type === 'positive_integer'
                    && (!is_int($record[$field] ?? null) || $record[$field] < 1)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @param array<string, mixed> $match
     * @return array<int, array<string, mixed>>
     */
    private static function matchingRecords(array $records, string $event, array $match): array {
        return array_values(array_filter($records, static function (array $record) use ($event, $match): bool {
            $recordEvent = ($record['event'] ?? '') === 'durable_operation_state'
                ? ($record['operation_event'] ?? '') : ($record['event'] ?? '');
            if ($recordEvent !== $event) {
                return false;
            }
            foreach ($match as $field => $value) {
                if (($record[$field] ?? null) !== $value) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * @param mixed $left
     * @param mixed $right
     */
    private static function compare($left, string $operator, $right): bool {
        if ($operator === 'greater_than') {
            return is_numeric($left) && is_numeric($right) && (float)$left > (float)$right;
        }
        if ($operator === 'not_equals') {
            return $left !== $right;
        }
        return $left === $right;
    }

    /**
     * @param mixed $values
     * @return array<int, string>
     */
    private static function strings($values): array {
        return array_values(array_filter(
            is_array($values) ? $values : array(),
            static fn($value): bool => is_string($value) && $value !== ''
        ));
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private static function stringKeyedArray($value): array {
        if (!is_array($value)) {
            return array();
        }
        $result = array();
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<int, array<string, mixed>>
     */
    private static function requirements(array $contract): array {
        $requirements = $contract['requirements'] ?? null;
        if (!is_array($requirements)) {
            return array();
        }
        return array_values(array_map(
            static fn(array $requirement): array => self::stringKeyedArray($requirement),
            array_filter($requirements, 'is_array')
        ));
    }
}
