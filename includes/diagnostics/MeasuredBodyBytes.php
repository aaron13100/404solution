<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A body-byte count somebody actually measured, and the name of who measured
 * it.
 *
 * Both halves of the emitted-against-delivered comparison are byte counts, and
 * both have to agree on one question before they can be compared at all: which
 * reported values are real measurements and which are a refusal to answer. The
 * rule and the vocabulary lived in two classes before this one existed --
 * ABJ_404_Solution_ResponseBodyDeliveryEvidence for the emitted half and
 * ABJ_404_Solution_DeliveredTableResponseSize for the delivered half -- as
 * byte-identical copies, down to the same explanatory sentence. Two copies of
 * the rule that decides whether a comparison is possible is one copy too many:
 * if either had ever been "fixed" alone, the pair would have started comparing
 * a measurement against a refusal and reporting the difference as a rewritten
 * body, which is the exact false finding the comparison exists to avoid.
 *
 * Pure vocabulary and one pure rule. No I/O, no journal knowledge, no
 * formatting.
 */
final class ABJ_404_Solution_MeasuredBodyBytes {

    /** Nobody produced a usable count for this half of the comparison. */
    const SOURCE_UNAVAILABLE = 'unavailable';

    /**
     * Delivered = Resource Timing `decodedBodySize`, the only octet count the
     * browser measures on the wire rather than through the JSON parser.
     */
    const SOURCE_RESOURCE_TIMING = 'resource_timing_decoded_body';

    /** Emitted = the encoded JSON only. */
    const SOURCE_ENCODE = 'json_encode';

    /**
     * Emitted = the encoded JSON plus the `stream` step's leading whitespace
     * block, which is echoed outside json_encode() and so is absent from the
     * encoded count.
     */
    const SOURCE_ENCODE_PLUS_STREAM = 'json_encode_plus_stream_whitespace';

    /**
     * The count as a positive integer, or null when the reporter declined to
     * give one.
     *
     * Zero and the client's own -1 sentinel are both unknown, not empty.
     * Resource Timing reports 0 for an entry it will not disclose (an opaque
     * or timing-restricted response) exactly as it would for a genuinely empty
     * body, and nothing downstream can tell those apart. Calling either one
     * "0 bytes" would invent a measurement out of a browser's refusal to
     * answer, and a fabricated zero on either half of the comparison reads as
     * the largest possible discrepancy.
     *
     * @param mixed $value Whatever the journal record carried.
     */
    public static function disclosed($value): ?int {
        return is_numeric($value) && (int)$value > 0 ? (int)$value : null;
    }
}
