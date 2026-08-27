<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * How many bytes this site's admin-table response encodes, measured by
 * building one right now.
 *
 * The third member of the response-size family, and the only one that does not
 * read a journal. ABJ_404_Solution_EncodedTableResponseSize says how many bytes
 * the server RECORDED encoding for a session's last table response;
 * ABJ_404_Solution_DeliveredTableResponseSize says how many the browser
 * reported receiving. Both are recoveries of a number some earlier request
 * left behind. This one produces the number itself, from the same builder the
 * table endpoint uses, so a site that recorded nothing still has a real size.
 *
 * Which is also why it does NOT live beside those two in includes/diagnostics/.
 * They record evidence; this one RENDERS an admin table response and then
 * measures it, and rendering is the presentation layer's job -- deptrac's
 * Diagnostics layer forbids exactly this dependency, and it is right to. The
 * family is split by what each member does, not by the question they share.
 *
 * That gap is why it exists. Support report 2026-08-27 (beadoctor.metaapply.io,
 * Azure App Service, plugin 4.3.4) ran the full ten-step canary ladder and came
 * back with `encoded_size {bytes: null, source: "unavailable"}`, so the size
 * probes fell back to the client's default and `sizeOrDeliveryCausal` was left
 * UNTESTED on the one report it was built to answer. The recorded number was
 * not lost, mis-keyed or rotated away: it was never written.
 * ABJ_404_Solution_AjaxResponseEmitter scopes its post-encode size record to
 * ABJ_404_Solution_AjaxDiagnosticRequestPolicy::diagnosticRequestId(), and that
 * returns '' for `ajaxUpdatePaginationLinks` unless `debug_mode` is on -- which
 * is the 4.3.3 performance gate working as designed, and which the ladder's own
 * self-arming cannot undo retroactively for a request that has already failed.
 * A number recovered from journals can therefore only ever exist on sites that
 * were already instrumented before the failure; a number measured here exists
 * on every site that runs the ladder.
 *
 * It is a MEASUREMENT of this site's table, not a reconstruction of the failed
 * response, and it never pretends otherwise: the source string says `measured`,
 * the caller keeps the recorded absence alongside it, and a build that cannot
 * run returns a named absence rather than a plausible number. Two honest
 * differences from the response the endpoint emits, both immaterial at the KB
 * granularity the size ladder works at, both stated rather than hidden:
 * `part=table` only (no counts or pagination block), and without the
 * `requestId`/`retryCount` scalars the handler appends.
 *
 * The build is the ordinary foreground one, so it warms the same caches an
 * admin table load warms and mutates nothing a user owns: it renders ONE page
 * of rows (bounded by the stored per-page option, not by the table's size),
 * writes no options, and returns HTML that is measured and dropped.
 *
 * Cost is deliberate and bounded. This is one real table build, on a request
 * the browser fires at most once an hour and only after a real table request
 * has already failed (view_updater_canary_cooldown.js), inside a step whose
 * own client timeout is 15 seconds. On a site where the table build is itself
 * the stall, that timeout expires and the ladder's trace carries the build's
 * own stage record showing where it stopped -- which is a decisive finding, not
 * a cost.
 */
final class ABJ_404_Solution_MeasuredTableResponseSize {

    /** A real table build ran and its encoded size is the number returned. */
    const SOURCE_MEASURED = 'measured_table_build';

    /** The subpage has no table renderer, so there is no table to measure. */
    const SOURCE_SUBPAGE_UNSUPPORTED = 'measure_subpage_unsupported';

    /** The view or view-read service is not resolvable on this request. */
    const SOURCE_SERVICES_UNAVAILABLE = 'measure_services_unavailable';

    /** The build threw. The size is unknown; that the build fails is the finding. */
    const SOURCE_BUILD_FAILED = 'measure_build_failed';

    /** The build returned something json_encode() could not turn into bytes. */
    const SOURCE_ENCODE_FAILED = 'measure_encode_failed';

    /**
     * Build this subpage's table response part and return its encoded size.
     *
     * Runs OUTSIDE any caller-opened stage on purpose. The builder opens its
     * own stage (`table_redirects` / `table_captured` / `table_logs`) and
     * ABJ_404_Solution_AjaxRequestTrace stages are flat, so wrapping this in
     * another stage would only mark the caller's stage `superseded`. Left
     * unwrapped, the ladder's own trace gains a real table-build stage that is
     * directly comparable, stage for stage, against the failing request's --
     * which is what the ladder's trace exists for.
     *
     * @param array<string, mixed> $context Mutated in place by the build's stages.
     * @return array{bytes: int|null, source: string}
     */
    public static function forSubpage(string $subpage, array &$context): array {
        if (!ABJ_404_Solution_AdminTableResponseParts::rendersTablePart($subpage)) {
            return self::noSize(self::SOURCE_SUBPAGE_UNSUPPORTED);
        }
        try {
            $view = function_exists('abj_service_optional') ? abj_service_optional('view') : null;
            $viewReadService = function_exists('abj_service_optional')
                ? abj_service_optional('view_read_service') : null;
            if (!is_object($view) || !is_object($viewReadService)) {
                return self::noSize(self::SOURCE_SERVICES_UNAVAILABLE);
            }
            $data = ABJ_404_Solution_AdminTableResponseParts::build(
                'table', $subpage, $view, $viewReadService, $context);
            $json = json_encode($data);
            if (!is_string($json) || strlen($json) === 0) {
                return self::noSize(self::SOURCE_ENCODE_FAILED);
            }
            return array('bytes' => strlen($json), 'source' => self::SOURCE_MEASURED);
        } catch (Throwable $e) {
            // Unconditional; abj404_logPhpFallback() is defined at plugin
            // entry, before any class here can be autoloaded.
            abj404_logPhpFallback(
                'ajax-checkpoint', 'Table response-size measurement failed: ' . $e->getMessage());
            return self::noSize(self::SOURCE_BUILD_FAILED);
        }
    }

    /**
     * The no-size answer, carrying the reason it is the answer.
     *
     * @return array{bytes: int|null, source: string}
     */
    private static function noSize(string $reason): array {
        return array('bytes' => null, 'source' => $reason);
    }
}
