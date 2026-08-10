<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What each active-operation boundary is allowed to persist, and what it must
 * carry to be worth reserving as evidence.
 *
 * Two questions, one catalog, because they are answered from the same row and
 * would drift apart if they were not:
 *
 * - `fields` is a default-deny privacy allowlist. A tracer hands over whatever
 *   it happens to know about the operation it is inside, including translated
 *   strings, callback arguments and captured paths; only the scalar identity
 *   fields named here reach a sink. Anything not listed is dropped, so adding a
 *   boundary without listing a field is safe and listing one is the deliberate
 *   act.
 * - `required_evidence_fields` is the minimum discriminator identity a record
 *   must carry before support collection reserves it. A record that cannot say
 *   WHICH operation it names is not evidence, and reserving it would spend a
 *   bounded slot on something a reader cannot act on.
 *
 * Deliberately separate from ABJ_404_Solution_ActiveOperationBreadcrumbs, which
 * owns the file the redacted records land in. Two consumers need this contract
 * and never touch that file: ABJ_404_Solution_RequiredCheckpointEvidence derives
 * the evidence gate's active boundaries from it, and
 * ABJ_404_Solution_DurableOperationRecorder redacts through it before a field
 * reaches ANY sink, the journal included.
 *
 * @see ABJ_404_Solution_ActiveOperationBreadcrumbs for the bounded persistence.
 */
final class ABJ_404_Solution_ActiveOperationBoundaryManifest {

    /**
     * @var array<string, array{
     *   fields: array<int, string>,
     *   required_evidence_fields: array<int, string>
     * }>
     */
    private const BOUNDARIES = array(
        'query' => array(
            'fields' => array(
                'q', 'stage', 'src', 'sql_id', 'sql_len', 'timeout_s', 'preflight_id',
            ),
            'required_evidence_fields' => array('q', 'src', 'sql_id'),
        ),
        'row_operation' => array(
            'fields' => array(
                'operation_id', 'kind', 'operation', 'key', 'group', 'hook', 'callback', 'source',
            ),
            'required_evidence_fields' => array('operation_id', 'kind'),
        ),
        'table_prelude_hook_callback' => array(
            'fields' => array('operation_id', 'hook', 'callback', 'source', 'locale'),
            'required_evidence_fields' => array(
                'operation_id', 'hook', 'callback', 'source', 'locale',
            ),
        ),
        'render_translation_callback' => array(
            'fields' => array(
                'operation_id', 'phase', 'hook', 'callback', 'source', 'locale',
                'message_set_hash',
            ),
            'required_evidence_fields' => array(
                'operation_id', 'phase', 'hook', 'callback', 'source', 'locale',
                'message_set_hash',
            ),
        ),
        'query_filter_callback' => array(
            'fields' => array(
                'operation_id', 'q', 'sql_id', 'registered_hook', 'hook',
                'callback', 'source', 'priority', 'callback_ordinal',
            ),
            'required_evidence_fields' => array(
                'operation_id', 'q', 'sql_id', 'hook', 'callback', 'source',
            ),
        ),
        'status_count_operation' => array(
            'fields' => array(
                'operation_id', 'operation', 'parent_operation_id', 'scope',
                'family', 'kind', 'hook', 'callback', 'source', 'priority',
                'cache_key', 'cache_group',
            ),
            'required_evidence_fields' => array('operation_id', 'operation'),
        ),
        'render_option_io' => array(
            'fields' => array(
                'operation_id', 'phase', 'operation', 'cache_key', 'cache_group',
                'key_family', 'group_family', 'backend', 'backend_class', 'query_id',
            ),
            'required_evidence_fields' => array(
                'operation_id', 'phase', 'operation',
            ),
        ),
        'request_phase' => array(
            'fields' => array('operation_id', 'operation', 'phase', 'threshold_ms'),
            'required_evidence_fields' => array('operation_id', 'operation', 'phase'),
        ),
        'shutdown_callback' => array(
            'fields' => array(
                'operation_id', 'hook', 'callback', 'source', 'priority',
                'callback_ordinal', 'has_reference',
            ),
            'required_evidence_fields' => array(
                'operation_id', 'hook', 'callback', 'source', 'priority',
                'callback_ordinal',
            ),
        ),
        // Template I/O past its per-request journal budget. It is the one
        // family whose volume scales with the rendered row count, so on a big
        // table most reads arrive here rather than in the journal; a blocked
        // read is then an operation that went active and never completed.
        // template_id is already a basename hash, never a path.
        'template_file_operation' => array(
            'fields' => array(
                'operation_id', 'operation', 'template_id', 'status', 'elapsed_ms', 'bytes',
            ),
            'required_evidence_fields' => array(
                'operation_id', 'operation', 'template_id',
            ),
        ),
    );

    /**
     * The canonical allowed/reserved active-operation boundary contract.
     *
     * @return array<string, array{
     *   fields: array<int, string>,
     *   required_evidence_fields: array<int, string>
     * }>
     */
    public static function boundaries(): array {
        return self::BOUNDARIES;
    }

    /**
     * Is this a boundary the catalog knows? Asked instead of handing out the
     * whole catalog for a key check, so a validator cannot come to depend on
     * the catalog's shape when all it needs is the answer.
     */
    public static function hasBoundary(string $boundary): bool {
        return array_key_exists($boundary, self::BOUNDARIES);
    }

    /**
     * Keep only the scalar, non-sensitive identity fields one boundary allows.
     *
     * Default-deny: an unknown boundary keeps nothing, and a listed field whose
     * value is not scalar (an object, an array, a resource a tracer picked up
     * by accident) is dropped rather than serialized.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function selectFields(string $boundary, array $fields): array {
        $allowed = self::BOUNDARIES[$boundary]['fields'] ?? array();
        $safe = array();
        foreach ($allowed as $field) {
            if (array_key_exists($field, $fields)
                    && (is_scalar($fields[$field]) || $fields[$field] === null)) {
                $safe[$field] = $fields[$field];
            }
        }
        return $safe;
    }
}
