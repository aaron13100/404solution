<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/DecisiveRecordContractEvaluator.php';
require_once __DIR__ . '/DecisiveRecordDiscriminatorContract.php';
require_once __DIR__ . '/DecisiveRecordAuthorizationFamilies.php';

/**
 * The canonical catalog of decisive tracer records, and the single source the
 * completeness gates and the support-reservation policy derive from. (Bruno
 * timeout cause matrix, gap-hunt iteration 6, convergence of gaps O1 + O2.)
 * Three durability consumers -- both completeness gates and
 * ABJ_404_Solution_RequiredCheckpointEvidence -- used to hardcode their own
 * required and reserved record types. Omissions stayed invisible until the
 * next gap hunt (GF/c444, c473, c479, c489), allowing a failing session
 * to drop the record carrying its discriminator (the G1 shape).
 * The fix mirrors ABJ_404_Solution_DiagnosticModuleManifest: coverage is
 * DERIVED, not maintained by memory. This class is the one list; the two gates
 * and the reservation policy read their expectations from it, and
 * DecisiveRecordManifestTest source-scans every *Tracer in includes/diagnostics
 * and FAILS if any record type a tracer can emit is absent here. A diagnostics
 * tracer that gains a record next month cannot ship un-gated and un-reserved:
 * the meta-test forces its enrollment before the completeness gate can pass.
 *
 * Each family declares:
 *  - emitter: the tracer class whose source the meta-test scans for the events.
 *  - events:  the journal `event` names in the family.
 *  - presence:
 *      PRESENCE_ALWAYS       the record (or, for start/end pairs, the pair) is
 *                            emitted for every canonical ordinary table request,
 *                            so both completeness gates must require it.
 *      PRESENCE_CONDITIONAL  the record only appears under a specific condition
 *                            (a persistent object cache, external work inside a
 *                            row, a callback registered on an option hook). Its
 *                            absence is not a hole because an always-present
 *                            SENTINEL record states the condition's outcome
 *                            (see `sentinel`), so the gates must not require it.
 *  - reserve: a {start,end} event pair whose START must survive bounded support
 *             ranking even when its END never reached disk (the hung operation
 *             whose completion the worker died before writing). Null when the
 *             family has no unmatched-start hazard.
 */
final class ABJ_404_Solution_DecisiveRecordManifest {

    /** Bumped when the catalog's shape changes, so an old reader stays valid. */
    const SCHEMA_VERSION = 4;

    const PRESENCE_ALWAYS = 'always-present';
    const PRESENCE_CONDITIONAL = 'conditional-with-sentinel';

    /**
     * The authoritative catalog, keyed by family name.
     *
     * Order is not significant. New records added by later gap-hunt iterations
     * (translation prelude, excluded-callback breadcrumbs, sort-write option
     * records) enroll here, and the meta-test forces their coverage so the
     * gap-hunt loop converges.
     *
     * @var array<string, array{
     *   emitter: string,
     *   events: array<int, string>,
     *   presence: string,
     *   reserve: array{start: string, end: string}|null,
     *   sentinel: string|null
     * }>
     */
    private const RECORDS = array(
        // Registry lookup, traversal, reflection, mutation, and restoration
        // happen before callback and table-operation boundaries can identify
        // themselves. The lifecycle start is therefore written first and
        // advanced with the same operation id before each callback position.
        // An unmatched start distinguishes instrumentation self-interference
        // from a foreign callback or the surrounding table operation. The same
        // start/end pair also brackets every atomic add/remove action/filter a
        // diagnostic runs to register or remove its own hook entry (phase
        // registration / removal), so a stall inside WordPress's registry
        // mutation path is reserved and attributed just like a traversal.
        'hook_instrumentation_lifecycle' => array(
            'emitter' => 'ABJ_404_Solution_HookInstrumentationLifecycleTracer',
            'events' => array(
                'hook_instrumentation_lifecycle_start',
                'hook_instrumentation_lifecycle_end',
            ),
            'presence' => self::PRESENCE_ALWAYS,
            'reserve' => array(
                'start' => 'hook_instrumentation_lifecycle_start',
                'end' => 'hook_instrumentation_lifecycle_end',
            ),
            'sentinel' => null,
        ),
        // The medium-high Redis / Object-Cache-Pro-during-rate-limiter
        // discriminator. backend_selection runs on EVERY table request via
        // Ajax_Php::consumeRateLimit, so the start/end pair is always present;
        // the three cache commands only run when a persistent object cache is
        // selected, and when it is not, the always-present backend_selection
        // record's result field reads 'database_fallback' -- the sentinel that
        // makes their absence a stated fact rather than a hole.
        'rate_limit_operation' => array(
            'emitter' => 'ABJ_404_Solution_RateLimitOperationTracer',
            'events' => array('rate_limit_operation_start', 'rate_limit_operation_end'),
            'presence' => self::PRESENCE_ALWAYS,
            'reserve' => array(
                'start' => 'rate_limit_operation_start',
                'end' => 'rate_limit_operation_end',
            ),
            'sentinel' => 'backend_selection result=database_fallback when no persistent object cache; '
                . 'cache_add_initial/cache_increment/cache_add_fallback are the conditional commands',
        ),
        // Diagnostics-owned cache capability, metrics, and counter reads.
        // Conditional because there is no third-party boundary when WordPress
        // has no object-cache object; row-loop activity's cache_src=none is the
        // sentinel. A third-party method or magic property that never returns
        // leaves its start unmatched and must survive support ranking.
        'cache_metrics_probe' => array(
            'emitter' => 'ABJ_404_Solution_CacheMetricsProbeTracer',
            'events' => array('cache_metrics_probe_start', 'cache_metrics_probe_end'),
            'presence' => self::PRESENCE_CONDITIONAL,
            'reserve' => array(
                'start' => 'cache_metrics_probe_start',
                'end' => 'cache_metrics_probe_end',
            ),
            'sentinel' => 'row_loop_progress/row_loop_end.cache_src',
        ),
        // The matrix-cause-37 foreign-option-callback census. Emitted for every
        // option storage write (even the hook-registry-unavailable branch
        // writes the record), so it is always present and both gates require it.
        'option_hook_instrumentation' => array(
            'emitter' => 'ABJ_404_Solution_OptionPersistenceTracer',
            'events' => array('option_hook_instrumentation'),
            'presence' => self::PRESENCE_ALWAYS,
            'reserve' => null,
            'sentinel' => null,
        ),
        // The fine-grained option-persistence boundaries (normalization, read,
        // storage write, cache refresh). Always emitted while the tracer is
        // active during ajaxUpdatePaginationLinks. A boundary that hangs leaves
        // its start unmatched, so the start is reserved.
        'option_operation' => array(
            'emitter' => 'ABJ_404_Solution_OptionPersistenceTracer',
            'events' => array('option_operation_start', 'option_operation_end'),
            'presence' => self::PRESENCE_ALWAYS,
            'reserve' => array(
                'start' => 'option_operation_start',
                'end' => 'option_operation_end',
            ),
            'sentinel' => null,
        ),
        // Each foreign callback registered on an option lifecycle hook, wrapped
        // for per-callback attribution. Conditional on such callbacks existing;
        // the always-present option_hook_instrumentation record's
        // callbacks_attributed count is the census that states how many there were.
        // A foreign callback that hangs (the literal matrix-cause-37 symptom)
        // leaves its start unmatched, so the start is reserved.
        'option_hook_callback' => array(
            'emitter' => 'ABJ_404_Solution_OptionPersistenceTracer',
            'events' => array('option_hook_callback_start', 'option_hook_callback_end'),
            'presence' => self::PRESENCE_CONDITIONAL,
            'reserve' => array(
                'start' => 'option_hook_callback_start',
                'end' => 'option_hook_callback_end',
            ),
            'sentinel' => 'option_hook_instrumentation.callbacks_attributed',
        ),
        // External work (a cache call or a hook callback) inside a rendered
        // table row. Conditional on the row performing such work; a row that
        // never returns leaves its start unmatched, so the start is reserved.
        'row_operation' => array(
            'emitter' => 'ABJ_404_Solution_RowRenderOperationTracer',
            'events' => array('row_operation_start', 'row_operation_end'),
            'presence' => self::PRESENCE_CONDITIONAL,
            'reserve' => array(
                'start' => 'row_operation_start',
                'end' => 'row_operation_end',
            ),
            'sentinel' => 'row_operation_instrumentation.status',
        ),
        // The census that row-render attribution was installed. Always emitted
        // when the row-render tracer runs for a table request.
        'row_operation_instrumentation' => array(
            'emitter' => 'ABJ_404_Solution_RowRenderOperationTracer',
            'events' => array('row_operation_instrumentation'),
            'presence' => self::PRESENCE_ALWAYS,
            'reserve' => null,
            'sentinel' => null,
        ),
        // The record budget for row operations was exhausted. Conditional.
        'row_operation_capped' => array(
            'emitter' => 'ABJ_404_Solution_RowRenderOperationTracer',
            'events' => array('row_operation_capped'),
            'presence' => self::PRESENCE_CONDITIONAL,
            'reserve' => null,
            'sentinel' => 'row_operation_instrumentation.status',
        ),
        // A malformed hook registry entry could not be attributed. Conditional;
        // reference signatures are attributed by markers and do not use this.
        'row_operation_unavailable' => array(
            'emitter' => 'ABJ_404_Solution_RowRenderOperationTracer',
            'events' => array('row_operation_unavailable'),
            'presence' => self::PRESENCE_CONDITIONAL,
            'reserve' => null,
            'sentinel' => 'row_operation_instrumentation.status',
        ),
        // Every canonical table render resolves this fixed pre-row operation
        // list. A load or resolver stall leaves its start unmatched.
        'table_prelude_operation' => array(
            'emitter' => 'ABJ_404_Solution_TableRendererPreludeTracer',
            'events' => array('table_prelude_operation_start', 'table_prelude_operation_end'),
            'presence' => self::PRESENCE_ALWAYS,
            'reserve' => array(
                'start' => 'table_prelude_operation_start',
                'end' => 'table_prelude_operation_end',
            ),
            'sentinel' => null,
        ),
        // The callback census is always emitted even when the hook registry
        // or the WordPress JIT translation loader is unavailable.
        'table_prelude_instrumentation' => array(
            'emitter' => 'ABJ_404_Solution_TableRendererPreludeTracer',
            'events' => array('table_prelude_instrumentation'),
            'presence' => self::PRESENCE_ALWAYS,
            'reserve' => null,
            'sentinel' => null,
        ),
        // Locale/translation callbacks only exist when another component has
        // registered them; the census states how many were wrapped.
        'table_prelude_hook_callback' => array(
            'emitter' => 'ABJ_404_Solution_TableRendererPreludeTracer',
            'events' => array(
                'table_prelude_hook_callback_start',
                'table_prelude_hook_callback_end',
            ),
            'presence' => self::PRESENCE_CONDITIONAL,
            'reserve' => array(
                'start' => 'table_prelude_hook_callback_start',
                'end' => 'table_prelude_hook_callback_end',
            ),
            'sentinel' => 'table_prelude_instrumentation.callbacks_attributed',
        ),
        'table_prelude_hook_callback_capped' => array(
            'emitter' => 'ABJ_404_Solution_TableRendererPreludeTracer',
            'events' => array('table_prelude_hook_callback_capped'),
            'presence' => self::PRESENCE_CONDITIONAL,
            'reserve' => null,
            'sentinel' => 'table_prelude_instrumentation.max_callback_records',
        ),
        // The response-control filter dispatches on the instrumented
        // response tail (gap-hunt iterations 8 and 9).
        // getAndClearAjaxBufferedOutput()
        // dispatches abj404_should_manage_output_buffer and
        // sendJsonResponseAndExit() dispatches abj404_should_exit before the
        // flush; AjaxRequestLedger::resolveDetachAbMode() dispatches
        // abj404_should_run_detach_ab_diagnostic after it; WordPress
        // status_header() dispatches its named filter before core header
        // emission. All four run on every ajaxUpdatePaginationLinks response,
        // so the bracket pair is always
        // present. The start is written before any registry access, so a worker
        // killed inside a foreign callback still names the boundary -- the start
        // is reserved. The end carries the callbacks_attributed census that is
        // the sentinel for the conditional per-callback family below.
        'response_control_filter_dispatch' => array(
            'emitter' => 'ABJ_404_Solution_ResponseControlFilterTracer',
            'events' => array(
                'response_control_filter_dispatch_start',
                'response_control_filter_dispatch_end',
            ),
            'presence' => self::PRESENCE_ALWAYS,
            'reserve' => array(
                'start' => 'response_control_filter_dispatch_start',
                'end' => 'response_control_filter_dispatch_end',
            ),
            'sentinel' => null,
        ),
        // Each foreign callback registered on a response-control filter or on
        // WordPress's global `all` hook, wrapped for per-callback attribution.
        // Conditional on such callbacks existing; the always-present
        // response_control_filter_dispatch_end record's callbacks_attributed
        // count is the census stating how many there were. A callback that hangs
        // (the literal gap symptom) leaves its start unmatched, so it is reserved.
        'response_control_filter_callback' => array(
            'emitter' => 'ABJ_404_Solution_ResponseControlFilterTracer',
            'events' => array(
                'response_control_filter_callback_start',
                'response_control_filter_callback_end',
            ),
            'presence' => self::PRESENCE_CONDITIONAL,
            'reserve' => array(
                'start' => 'response_control_filter_callback_start',
                'end' => 'response_control_filter_callback_end',
            ),
            'sentinel' => 'response_control_filter_dispatch_end.callbacks_attributed',
        ),
        // The full detach A/B resolution after echo_end and before
        // finish_request. Every instrumented response resolves a mode, including
        // stable/disabled and no-session requests, so this pair is always
        // present. A killed filter callback or transient operation leaves the
        // outer start unmatched and support-reserved.
        'detach_ab_resolution' => array(
            'emitter' => 'ABJ_404_Solution_DetachAbResolutionTracer',
            'events' => array(
                'detach_ab_resolution_start',
                'detach_ab_resolution_end',
            ),
            'presence' => self::PRESENCE_ALWAYS,
            'reserve' => array(
                'start' => 'detach_ab_resolution_start',
                'end' => 'detach_ab_resolution_end',
            ),
            'sentinel' => null,
        ),
        // The transient read/write only run after enablement with a usable
        // session and API. detach_ab_resolution_end.counter_status states why
        // they are absent on disabled, no-session, or unavailable-API paths.
        'detach_ab_operation' => array(
            'emitter' => 'ABJ_404_Solution_DetachAbResolutionTracer',
            'events' => array(
                'detach_ab_operation_start',
                'detach_ab_operation_end',
            ),
            'presence' => self::PRESENCE_CONDITIONAL,
            'reserve' => array(
                'start' => 'detach_ab_operation_start',
                'end' => 'detach_ab_operation_end',
            ),
            'sentinel' => 'detach_ab_resolution_end.counter_status',
        ),
    );

    /**
     * The full catalog.
     *
     * @return array<string, array{emitter: string, events: array<int, string>, presence: string, reserve: array{start: string, end: string}|null, sentinel: string|null}>
     */
    public static function records(): array {
        return self::recordsCatalog();
    }

    /**
     * Every journal `event` name enrolled, across all families.
     *
     * @return array<int, string>
     */
    public static function allEvents(): array {
        $events = array();
        foreach (self::recordsCatalog() as $family) {
            foreach ($family['events'] as $event) {
                $events[$event] = true;
            }
        }
        return array_keys($events);
    }
    /**
     * Events belonging to always-present families. Both completeness gates must
     * require every one of these; a build that stops emitting one can come back
     * evidence-free for that discriminator, which is the whole failure mode.
     *
     * @return array<int, string>
     */
    public static function alwaysPresentEvents(): array {
        return self::eventsWithPresence(self::PRESENCE_ALWAYS);
    }

    /**
     * Events belonging to conditional families. Gates must NOT require these;
     * their always-present sentinel records the condition's outcome instead.
     *
     * @return array<int, string>
     */
    public static function conditionalEvents(): array {
        return self::eventsWithPresence(self::PRESENCE_CONDITIONAL);
    }

    /**
     * The start/end pairs whose unmatched start the support-reservation policy
     * must preserve. Keyed matching is by request_id + operation_id, the field
     * every enrolled tracer stamps onto both records of a pair.
     *
     * @return array<int, array{start: string, end: string}>
     */
    public static function reservedOperationPairs(): array {
        $pairs = array();
        foreach (self::recordsCatalog() as $family) {
            if (is_array($family['reserve'])) {
                $pairs[] = $family['reserve'];
            }
        }
        return $pairs;
    }

    /**
     * The start events whose unmatched instance is reserved.
     *
     * @return array<int, string>
     */
    public static function reservedStartEvents(): array {
        return array_map(
            static fn(array $pair): string => $pair['start'],
            self::reservedOperationPairs()
        );
    }

    /**
     * The tracer classes that emit enrolled records. The meta-test scans each
     * of these plus any *Tracer file it finds on disk.
     *
     * @return array<int, string>
     */
    public static function emitterClasses(): array {
        $classes = array();
        foreach (self::recordsCatalog() as $family) {
            $classes[$family['emitter']] = true;
        }
        return array_keys($classes);
    }

    /**
     * Events attributed to a given emitter class.
     *
     * @return array<int, string>
     */
    public static function eventsForEmitter(string $emitter): array {
        $events = array();
        foreach (self::recordsCatalog() as $family) {
            if ($family['emitter'] === $emitter) {
                foreach ($family['events'] as $event) {
                    $events[$event] = true;
                }
            }
        }
        return array_keys($events);
    }

    /**
     * Discriminator-field and conditional-presence contracts used by every
     * principal beta-cut gate.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function discriminatorContracts(): array {
        return ABJ_404_Solution_DecisiveRecordDiscriminatorContract::contracts();
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @param array<int, string> $profiles
     * @param array<string, mixed> $facts
     * @return array<int, string>
     */
    public static function contractViolations(
        array $records,
        array $profiles,
        array $facts = array()
    ): array {
        return ABJ_404_Solution_DecisiveRecordContractEvaluator::violations(
            self::discriminatorContracts(),
            $records,
            $profiles,
            $facts
        );
    }

    /**
     * @return array<int, string>
     */
    private static function eventsWithPresence(string $presence): array {
        $events = array();
        foreach (self::recordsCatalog() as $family) {
            if ($family['presence'] !== $presence) {
                continue;
            }
            foreach ($family['events'] as $event) {
                $events[$event] = true;
            }
        }
        return array_keys($events);
    }

    /**
     * @return array<string, array{emitter: string, events: array<int, string>, presence: string, reserve: array{start: string, end: string}|null, sentinel: string|null}>
     */
    private static function recordsCatalog(): array {
        return array_merge(
            self::RECORDS,
            ABJ_404_Solution_DecisiveRecordAuthorizationFamilies::records(self::PRESENCE_ALWAYS),
            ABJ_404_Solution_DecisiveRecordRenderFamilies::records(
                self::PRESENCE_ALWAYS, self::PRESENCE_CONDITIONAL),
            ABJ_404_Solution_DecisiveRecordRenderOptionIoFamilies::records(
                self::PRESENCE_ALWAYS, self::PRESENCE_CONDITIONAL),
            ABJ_404_Solution_DecisiveRecordStatusCountFamilies::records(
                self::PRESENCE_CONDITIONAL),
            ABJ_404_Solution_DecisiveRecordQueryFilterFamilies::records(
                self::PRESENCE_ALWAYS, self::PRESENCE_CONDITIONAL),
            ABJ_404_Solution_DecisiveRecordShutdownFamilies::records(self::PRESENCE_ALWAYS,
                self::PRESENCE_CONDITIONAL),
            ABJ_404_Solution_DecisiveRecordFailureFamilies::records(self::PRESENCE_CONDITIONAL)
        );
    }
}
