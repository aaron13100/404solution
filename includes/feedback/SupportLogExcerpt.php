<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The sanitized debug-log tail for a report, or a statement of why there is
 * none.
 *
 * Three collectors (the support request, its preview, and the uninstall
 * feedback) each used to run the same fifteen lines: resolve the optional
 * logging service, call getSanitizedLogExcerptForSupport(), bound it, and
 * return '' from every branch where any of that did not work out. That empty
 * string is the same defect the collection manifest exists to end, one layer
 * up: a developer holding the report cannot tell "this site has no log" from
 * "the logging service was not registered" from "reading the log threw".
 * ABJ_404_Solution_DebugLogReader already answers in words when the FILE is
 * missing or unreadable ("No log file available"); everything above it threw
 * that discipline away.
 *
 * So the resolution lives here once, and every branch that yields no excerpt
 * yields a sentence instead. Three copies of a silent path could each be fixed
 * three times; one shared path can only be fixed once.
 */
final class ABJ_404_Solution_SupportLogExcerpt {

    /** Prefix every stated reason carries, so a reader can grep for them. */
    const REASON_PREFIX = '[404 Solution] Sanitized debug log unavailable: ';

    /** Bytes of an underlying exception message carried into the report. */
    const MAX_REASON_DETAIL_LENGTH = 200;

    /**
     * The excerpt, tail-bounded, or a one-line reason.
     *
     * @param string $context Which collector is asking, for the warning log.
     * @param int $maxBytes Tail bound for the excerpt itself; 0 for unbounded.
     * @return string Never empty.
     */
    public static function resolve(string $context, int $maxBytes = 0): string {
        if (!function_exists('abj_service_optional')) {
            return self::REASON_PREFIX . 'the plugin service container is not loaded in this request.';
        }
        $logger = abj_service_optional('logging');
        if (!is_object($logger) || !method_exists($logger, 'getSanitizedLogExcerptForSupport')) {
            return self::REASON_PREFIX . 'the logging service is not available on this install.';
        }
        try {
            $excerpt = $logger->getSanitizedLogExcerptForSupport();
            if (!is_string($excerpt)) {
                return self::REASON_PREFIX . 'the logging service returned no readable excerpt.';
            }
            if ($excerpt === '') {
                return self::REASON_PREFIX . 'the log excerpt came back empty.';
            }
            return $maxBytes > 0 && strlen($excerpt) > $maxBytes
                ? substr($excerpt, -$maxBytes) : $excerpt;
        } catch (\Throwable $e) {
            ABJ_404_Solution_FeedbackTransportLog::log(
                'warn',
                $context . ' debug-log excerpt unavailable: ' . $e->getMessage()
            );
            // The underlying message is carried, not just logged: the site that
            // hit this is the one site whose log we cannot read, so the debug
            // log is exactly where this explanation would NOT reach anyone.
            return self::REASON_PREFIX . 'reading it failed ('
                . substr($e->getMessage(), 0, self::MAX_REASON_DETAIL_LENGTH) . ').';
        }
    }
}
