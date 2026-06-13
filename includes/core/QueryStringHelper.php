<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * URL query-string and JSON-in-querystring transformation service.
 *
 * Responsibilities:
 *  - sortQueryString: produce a canonical, alphabetized rebuild of a parsed
 *    URL's `query` part so identical requests with reordered parameters
 *    hash to the same storage key.
 *  - removePageIDFromQueryString: strip `p=N` from a query string so a
 *    redirect destination does not re-trigger a 404 via WordPress's
 *    page-id parameter.
 *  - decodeComplicatedData: urldecode then json_decode an encoded request
 *    payload (with JSON.stringify single-quote unescape), used by the
 *    AJAX update-options path that ships form data as a single
 *    URL-encoded JSON blob.
 *
 * Extracted from ABJ_404_Solution_Functions per design-audit-2026-06-02
 * M201 (Functions.php grab-bag split, parent task i802). The three
 * methods share the responsibility of normalizing query-string-shaped
 * input/output across the dispatch and admin-AJAX surfaces; they are
 * distinct from percent-encoding (UrlEncoder), control-byte
 * sanitization (Sanitizer), and string primitives (MbStringAdapter).
 *
 * Depends on Sanitizer for sanitizeUrlComponent / normalizeUrlString
 * (sortQueryString and removePageIDFromQueryString already routed
 * through the sibling Sanitizer service) and on Logging for the JSON
 * decode error path.
 */
class ABJ_404_Solution_QueryStringHelper {

    /** @var ABJ_404_Solution_Sanitizer */
    private $sanitizer;

    /** @var ABJ_404_Solution_Logging|null */
    private $logger;

    /**
     * @param ABJ_404_Solution_Sanitizer $sanitizer
     * @param ABJ_404_Solution_Logging|null $logger Optional: when omitted,
     *   decodeComplicatedData() lazy-resolves through the service container
     *   so early-boot and direct test instantiation still get logging.
     */
    public function __construct(ABJ_404_Solution_Sanitizer $sanitizer, $logger = null) {
        $this->sanitizer = $sanitizer;
        $this->logger    = $logger;
    }

    /**
     * Sort the QUERY parts of the requested URL.
     * This is in place because these are stored as part of the URL in the database and used for forwarding to another page.
     * This is done because sometimes different query parts result in a completely different page. Therefore we have to
     * take into account the query part of the URL (?query=part) when looking for a page to redirect to.
     *
     * Here we sort the query parts so that the same request will always look the same.
     *
     * @param array<string, string> $urlParts
     * @return string
     */
    public function sortQueryString(array $urlParts): string {
        if (!array_key_exists('query', $urlParts) || $urlParts['query'] == '') {
            return '';
        }

        $queryParts = array();
        parse_str($urlParts['query'], $queryParts);

        ksort($queryParts);

        $sanitized = $this->sanitizer->sanitizeUrlComponent($queryParts);
        $queryParts = is_array($sanitized) ? $sanitized : $queryParts;
        $built = http_build_query($queryParts, '', '&', PHP_QUERY_RFC3986);
        $decoded = rawurldecode($built);
        return $this->sanitizer->normalizeUrlString($decoded, array('decode' => false));
    }

    /**
     * We have to remove any 'p=##' because it will cause a 404 otherwise.
     *
     * @param string $queryString
     * @return string
     */
    public function removePageIDFromQueryString($queryString) {
        $queryParts = array();
        parse_str($queryString, $queryParts);

        if (array_key_exists('p', $queryParts)) {
            unset($queryParts['p']);
        }

        $sanitized = $this->sanitizer->sanitizeUrlComponent($queryParts);
        $queryParts = is_array($sanitized) ? $sanitized : $queryParts;
        $built = http_build_query($queryParts, '', '&', PHP_QUERY_RFC3986);
        $decoded = rawurldecode($built);
        return $this->sanitizer->normalizeUrlString($decoded, array('decode' => false));
    }

    /**
     * First urldecode then json_decode the data, then return it.
     * All of this encoding and decoding is so that [] characters are supported.
     *
     * @param string $data
     * @return mixed
     */
    public function decodeComplicatedData($data) {
        $dataDecoded = urldecode((string)$data);

        // JSON.stringify escapes single quotes and json_decode does not want them to be escaped.
        $dataStripped = str_replace("\'", "'", $dataDecoded);
        $fixedData = json_decode($dataStripped, true);

        $jsonErrorNumber = json_last_error();
        if ($jsonErrorNumber != 0) {
            $errorMsg = json_last_error_msg();
            $lastMessagePart = ", Decoded: " . $dataDecoded;
            if ($dataStripped != null && mb_strlen($dataStripped) > 1) {
                $lastMessagePart = ", Stripped: " . $dataStripped;
            }

            $logger = $this->resolveLogger();
            if ($logger !== null) {
                $logger->errorMessage("Error " . $jsonErrorNumber . " parsing JSON in "
                    . __CLASS__ . "->" . __FUNCTION__ . "(). Error message: " . $errorMsg . $lastMessagePart);
            }
        }

        return $fixedData;
    }

    /** @return ABJ_404_Solution_Logging|null */
    private function resolveLogger() {
        if ($this->logger !== null) {
            return $this->logger;
        }
        if (function_exists('abj_service_optional')) {
            $resolved = abj_service_optional('logging');
            if ($resolved instanceof ABJ_404_Solution_Logging) {
                return $resolved;
            }
        }
        return null;
    }
}
