<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ask this response's transport not to compress or transform it, and report
 * whether each request actually took effect.
 *
 * Two mechanisms, because two different parties compress. `Cache-Control:
 * no-transform` asks an intermediary (LiteSpeed, Cloudflare, a caching reverse
 * proxy) to leave the bytes alone; `zlib.output_compression` is PHP's own
 * output handler. Suppressing one and not the other still yields a compressed
 * response, so the two outcomes are reported separately and `applied` is the
 * conjunction.
 *
 * BOTH are refusable at runtime, and neither refusal raises anything a caller
 * would notice:
 *
 * - Once any byte of output has been emitted, headers_sent() is true, the
 *   header cannot be set, and ini_set() returns false for this directive with
 *   "Cannot change zlib.output_compression - headers already sent".
 * - A host with ini_set in disable_functions refuses the second half outright
 *   (see ABJ_404_Solution_PhpRuntimeCapabilityAdapter), which is a live user
 *   environment, not a hypothetical one.
 *
 * The outcome is returned rather than discarded because the caller is a
 * diagnostic instrument. A canary rung that suppressed nothing but reports
 * itself as the suppressed rung does not merely lose a probe: it turns the
 * ladder's central comparison -- adjacent rungs differing by exactly one
 * variable -- into a comparison of a step against itself, while every consumer
 * still reads it as evidence about compression.
 */
final class ABJ_404_Solution_ResponseCompressionSuppression {

    /**
     * Request suppression from both parties and report what each one did.
     *
     * @return array{noTransformHeader: bool, zlibOutputCompression: bool, applied: bool}
     *   `noTransformHeader`: the no-transform header was set on this response.
     *   `zlibOutputCompression`: PHP's output compression is now off (a
     *   directive that was already off still counts -- the state is what the
     *   probe depends on, not whether this call changed it).
     *   `applied`: both, so the response is genuinely unsuppressed-by-nobody.
     */
    public static function apply(): array {
        $noTransformHeader = false;
        if (!headers_sent()) {
            header('Cache-Control: no-transform');
            $noTransformHeader = true;
        }
        // setIni() returns the PREVIOUS value on success and false on refusal,
        // so `!== false` is the test: an empty-string previous value means the
        // directive was already off, which is success, while a loose truthy
        // check would read it as failure.
        $zlibDisabled = ABJ_404_Solution_PhpRuntimeCapabilityAdapter::setIni(
            array('directive' => 'zlib.output_compression', 'value' => '0')) !== false;

        return array(
            'noTransformHeader' => $noTransformHeader,
            'zlibOutputCompression' => $zlibDisabled,
            'applied' => $noTransformHeader && $zlibDisabled,
        );
    }
}
