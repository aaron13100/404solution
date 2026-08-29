<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The one definition of the shape a failed admin-AJAX response takes on the wire.
 *
 * WHY THIS EXISTS. The shape is a contract with shipped JavaScript:
 * view_updater_pagination_error_notice.js reads `responseJson.data.message`, so
 * every producer has to agree on it byte for byte. It had three definitions --
 * an array built in AjaxAdminEndpointSupport, a second array hand-built in
 * AjaxFatalErrorResponder, and a hand-written JSON literal in
 * JsonResponseEncoder -- and they drifted. The literal said `errorText`, so the
 * one useful fact on the whole degraded path (the real json_last_error cause)
 * landed in a field nothing renders and the admin saw the browser's generic
 * AJAX failure instead. Each side had a test; each asserted its own spelling in
 * isolation, which is exactly how three copies of one contract stay green while
 * disagreeing.
 *
 * Kept out of AjaxAdminEndpointSupport because JsonResponseEncoder needs the
 * shape and may not reach the presentation layer: this is the third module both
 * sides depend on rather than a cycle between them.
 *
 * TWO REPRESENTATIONS, ONE SHAPE. build() is for the ordinary path, where the
 * envelope is a payload like any other and the encoder will serialize it.
 * encodeSafely() is for the last-resort path, where json_encode() on the real
 * payload is the thing that just failed and the reply still has to be bytes.
 * The second is defined in terms of the first, so there is nothing for a copy
 * to drift away from.
 *
 * @since 4.3.5
 */
final class ABJ_404_Solution_AjaxErrorEnvelope {

    /**
     * Ceiling on the embedded reason, in bytes.
     *
     * Applied to the REDUCED message and before encoding, never after: escaping
     * first and truncating second can cut a two-character escape in half and
     * leave the body ending in a lone backslash, which escapes the closing quote
     * and produces the malformed response this envelope exists to replace.
     * (Reproduced at exactly 199 printable characters followed by a backslash.)
     */
    const MAX_MESSAGE_BYTES = 200;

    /**
     * The body used if json_encode() ever refused a bounded printable-ASCII
     * payload, which it cannot: no invalid UTF-8, no recursion, no resource, no
     * INF/NAN, and a fixed depth of two. The branch exists only because the
     * return type admits false, and this literal is pinned to build() by
     * AjaxErrorEnvelopeTest so it cannot become a fourth definition.
     */
    const UNENCODABLE_FALLBACK = '{"success":false,"data":{"message":"unknown encoding error"}}';

    /** Shown when the real reason reduces to nothing printable. */
    const UNKNOWN_REASON = 'unknown encoding error';

    /**
     * The envelope as a payload array.
     *
     * @param string $message Shown to the admin. Must be a complete sentence:
     *   it is the whole of what the browser renders.
     * @param array<string, mixed>|null $details Diagnostic detail. Withheld
     *   from anyone who is not a plugin admin, since it carries query shapes,
     *   buffered output and context.
     * @param bool $isPluginAdmin
     * @return array<string, mixed>
     */
    public static function build($message, $details, $isPluginAdmin) {
        $data = array(
            'message' => $message,
        );
        if ($isPluginAdmin && $details !== null) {
            $data['details'] = $details;
        }
        return array(
            'success' => false,
            'data' => $data,
        );
    }

    /**
     * The envelope as JSON bytes that cannot themselves fail to encode.
     *
     * Three steps, in this order and for this reason:
     *
     *   1. Reduce to printable ASCII. This is what makes the encode below
     *      total: the likeliest caller message on this path is a
     *      json_last_error cause or a Throwable message, either of which can
     *      carry the very invalid UTF-8 that put us here.
     *   2. Cap. On the reduced text, so no escape can straddle the boundary.
     *   3. Encode. json_encode() does the escaping, which is the point --
     *      the previous hand-rolled sprintf() template offered its callers no
     *      escaping at all, so one quote in a caller-supplied message produced
     *      `{"success":false,"data":{"message":"he said "boom""}}`.
     */
    public static function encodeSafely(string $message): string {
        $reduced = (string)preg_replace('/[^\x20-\x7E]/', '', $message);
        if (strlen($reduced) > self::MAX_MESSAGE_BYTES) {
            $reduced = substr($reduced, 0, self::MAX_MESSAGE_BYTES);
        }
        if (trim($reduced) === '') {
            $reduced = self::UNKNOWN_REASON;
        }
        $json = json_encode(self::build($reduced, null, false));
        return is_string($json) ? $json : self::UNENCODABLE_FALLBACK;
    }
}
