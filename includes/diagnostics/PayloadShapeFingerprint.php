<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The structural shape of a response payload, measured BEFORE anything tries
 * to encode it: maximum nesting depth, element count, and total string bytes.
 *
 * Recorded on the `json_encode_start` checkpoint so that a stall, a fatal or a
 * memory exhaustion INSIDE json_encode() is attributable to a payload shape
 * rather than vanishing into an absence. Without it, the trace of a request
 * that died mid-encode ends at a boundary that says nothing about what it was
 * handed.
 *
 * Bounded on purpose, in two directions. A diagnostic that walks an
 * unboundedly deep or unboundedly wide structure becomes the next unmeasured
 * hang, which is exactly the failure class this instrumentation exists to
 * catch; so the walk stops at a depth and an element count and reports
 * `truncated` rather than pretending it saw everything. `truncated` is a
 * finding in its own right: it says the payload was pathological enough to
 * outrun the measurement.
 *
 * Pure: no I/O, no globals, no WordPress. It lives here rather than inside
 * ABJ_404_Solution_AjaxResponseEmitter because "how big and how deep is this
 * value" has nothing to do with headers, echo, connection detach or exit, and
 * as a private pair inside the emitter it could not be driven with a
 * pathological payload without staging an entire response emission.
 *
 * @since 4.3.5
 */
final class ABJ_404_Solution_PayloadShapeFingerprint {

    /**
     * Maximum recursion depth the walk will descend to.
     * See the class docblock: the bound is what keeps this diagnostic from
     * becoming the hang it was written to explain.
     */
    const MAX_DEPTH = 32;

    /** Maximum number of array/object elements the walk will visit. */
    const MAX_ELEMENTS = 5000;

    /**
     * Measure one value.
     *
     * @param mixed $payload
     * @return array{depth: int, element_count: int, string_byte_total: int, truncated: bool}
     */
    public static function measure($payload): array {
        $stats = array('depth' => 0, 'element_count' => 0, 'string_byte_total' => 0, 'truncated' => false);
        self::walk($payload, 0, $stats);
        return $stats;
    }

    /**
     * @param mixed $value
     * @param array{depth: int, element_count: int, string_byte_total: int, truncated: bool} $stats
     */
    private static function walk($value, int $currentDepth, array &$stats): void {
        if ($stats['truncated']) {
            return;
        }
        $stats['depth'] = max($stats['depth'], $currentDepth);
        if ($currentDepth >= self::MAX_DEPTH) {
            $stats['truncated'] = true;
            return;
        }
        if (is_string($value)) {
            $stats['string_byte_total'] += strlen($value);
            return;
        }
        $children = null;
        if (is_array($value)) {
            $children = $value;
        } else if (is_object($value)) {
            $children = get_object_vars($value);
        }
        if ($children === null) {
            return;
        }
        foreach ($children as $child) {
            $stats['element_count']++;
            if ($stats['element_count'] >= self::MAX_ELEMENTS) {
                $stats['truncated'] = true;
                return;
            }
            self::walk($child, $currentDepth + 1, $stats);
        }
    }
}
