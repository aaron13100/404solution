<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Encodes an AJAX response body to JSON, and always produces bytes.
 *
 * WHY THIS EXISTS. `json_encode()` returns `false` for a payload it cannot
 * represent -- most reachably a string that is not valid UTF-8, which any
 * co-plugin hooked on `gettext`, `the_title` or any other WordPress filter our
 * admin table HTML passes through can introduce. `echo false` writes zero
 * bytes. The response then leaves the server as HTTP 200 with
 * `Content-type: application/json` and an empty body: the browser calls that
 * `parsererror`, the admin table never renders, and nothing anywhere says why.
 * That is a silent error path, and this class is its removal.
 *
 * THE LADDER, in the order the self-healing philosophy asks for -- try,
 * recover, retry, then notify:
 *
 *   1. Encode as-is. The overwhelming majority of responses stop here and pay
 *      nothing for the rest of this class.
 *   2. Malformed UTF-8: re-encode with JSON_INVALID_UTF8_SUBSTITUTE. The user
 *      gets their table; the offending bytes read as U+FFFD. Recovery the admin
 *      never has to know about.
 *   3. Anything else (a resource, a recursive structure, INF/NAN, depth):
 *      re-encode with JSON_PARTIAL_OUTPUT_ON_ERROR so the branches that CAN be
 *      represented still arrive.
 *   4. Only if all three fail -- including when step 3 returns bytes that are
 *      not parseable JSON, which it does for a payload nested past the depth
 *      limit -- a hand-built envelope carrying the real json_last_error_msg(),
 *      so the admin screen shows an actionable message rather than a dead
 *      table. Built from ASCII literals and an integer, so it cannot itself
 *      hit the failure it is reporting.
 *
 * Every step after the first is checked by PARSING what it produced, not by
 * testing it against false. A body can be a non-empty string and still be
 * unreadable, and that is the same dead table by another route.
 *
 * Fixing the CONSUMER, not the input (defensive philosophy #10): the plugin
 * cannot stop a foreign filter handing it bad bytes, and constraining every
 * upstream producer would be an endless game. Making the one encoder tolerant
 * ends the class in a single place.
 *
 * @since 4.3.5
 */
final class ABJ_404_Solution_JsonResponseEncoder {

    /**
     * The error-envelope shape, matching what
     * ABJ_404_Solution_AjaxAdminEndpointSupport::buildAjaxErrorResponse()
     * produces, so the browser's existing error notice renders it with no
     * client change. Written as a literal template rather than encoded from an
     * array: step 4 runs precisely when json_encode() is the thing that failed.
     *
     * `data.message`, because that is the field the consumer actually reads
     * (view_updater_pagination_error_notice.js:52). This template previously
     * said `errorText` while its docblock claimed to match
     * buildAjaxErrorResponse(), which produces `data.message` -- so the one
     * useful fact on the whole degraded path, the real json_last_error cause,
     * landed in a field nothing renders and the admin saw a generic AJAX
     * failure. A diagnostic nobody can read is a silent error path.
     * JsonResponseEncoderTest pins this against the notice source itself, since
     * asserting each side in isolation is exactly what let the two drift.
     */
    const ERROR_ENVELOPE_TEMPLATE = '{"success":false,"data":{"message":"%s"}}';

    /**
     * Encode a response payload, degrading rather than failing.
     *
     * @param mixed $payload
     * @return ABJ_404_Solution_EncodedJsonResponse Never carries a false json().
     */
    public static function encode($payload): ABJ_404_Solution_EncodedJsonResponse {
        $json = json_encode($payload);
        if (is_string($json)) {
            return new ABJ_404_Solution_EncodedJsonResponse(
                $json, ABJ_404_Solution_EncodedJsonResponse::STRATEGY_DIRECT);
        }

        // Captured from the FIRST failure. Every later attempt overwrites
        // json_last_error(), and the first one is the diagnosis: UTF8 means a
        // producer handed us bad bytes, RECURSION or DEPTH means the payload
        // shape is wrong, INF_OR_NAN means an arithmetic bug upstream.
        $errorCode = json_last_error();
        $errorMessage = json_last_error_msg();

        if ($errorCode === JSON_ERROR_UTF8) {
            // JSON_INVALID_UTF8_SUBSTITUTE has existed since PHP 7.2 and the
            // plugin's floor is 7.4, so this needs no function_exists dance.
            $substituted = self::parseableOrNull(json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE));
            if ($substituted !== null) {
                return new ABJ_404_Solution_EncodedJsonResponse(
                    $substituted,
                    ABJ_404_Solution_EncodedJsonResponse::STRATEGY_UTF8_SUBSTITUTED,
                    $errorCode,
                    $errorMessage
                );
            }
        }

        $partial = self::parseableOrNull(
            json_encode($payload, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        if ($partial !== null) {
            return new ABJ_404_Solution_EncodedJsonResponse(
                $partial,
                ABJ_404_Solution_EncodedJsonResponse::STRATEGY_PARTIAL_OUTPUT,
                $errorCode,
                $errorMessage
            );
        }

        return new ABJ_404_Solution_EncodedJsonResponse(
            self::errorEnvelope($errorCode, $errorMessage),
            ABJ_404_Solution_EncodedJsonResponse::STRATEGY_ERROR_ENVELOPE,
            $errorCode,
            $errorMessage
        );
    }

    /**
     * A candidate body, but only if a JSON parser can actually read it.
     *
     * `is_string()` is not enough, and assuming it was is how this class nearly
     * shipped the defect it was written to remove. JSON_PARTIAL_OUTPUT_ON_ERROR
     * does NOT always produce parseable output: hand json_encode() a structure
     * nested past the 512-level depth limit and it returns a string of 512 open
     * brackets and nothing else. That is a non-false, non-empty body a browser
     * still reports as `parsererror` -- the same dead admin table, reached
     * through the fallback instead of through the bug.
     *
     * Only ever called on the degraded path, so an ordinary response pays
     * nothing for the second parse.
     *
     * @param string|false $candidate
     */
    private static function parseableOrNull($candidate): ?string {
        if (!is_string($candidate) || $candidate === '') {
            return null;
        }
        json_decode($candidate);
        return json_last_error() === JSON_ERROR_NONE ? $candidate : null;
    }

    /**
     * A JSON envelope naming why the real payload could not be encoded.
     *
     * The message is reduced to printable ASCII and length-capped before it is
     * embedded: json_last_error_msg() is a PHP constant string today, but this
     * envelope is the last line of defence and must not be able to produce the
     * malformed body it exists to replace.
     */
    private static function errorEnvelope(int $errorCode, string $errorMessage): string {
        $safeMessage = preg_replace('/[^\x20-\x7E]/', '', $errorMessage);
        $safeMessage = str_replace(array('\\', '"'), array('\\\\', '\\"'), (string)$safeMessage);
        if (strlen($safeMessage) > 200) {
            $safeMessage = substr($safeMessage, 0, 200);
        }
        if ($safeMessage === '') {
            $safeMessage = 'unknown encoding error';
        }
        return sprintf(
            self::ERROR_ENVELOPE_TEMPLATE,
            'The plugin could not encode this response (JSON error ' . $errorCode . ': ' . $safeMessage . ').'
        );
    }
}
