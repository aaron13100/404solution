<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decides whether a redirect may be written at all.
 *
 * Two admission rules, both about the redirect itself rather than about the
 * SQL that would store it:
 *
 *   1. A regex redirect's source must be a pattern the matching engine can
 *      actually compile, or every frontend request pays for a pattern that
 *      can never match.
 *   2. An automatic redirect's destination must resolve to something
 *      published. Auto redirects are created unattended (slug changes,
 *      spell-check promotion), so a destination that is trashed, draft or
 *      simply absent would otherwise be written silently and then send
 *      visitors to a 404 of its own.
 *
 * Kept apart from the write service because it is a decision, not a write:
 * it holds no SQL and touches no cache, so the create paths that do not go
 * through RedirectWriteService::setupRedirect (REST, WP-CLI) can apply the
 * same rules without dragging a writer along.
 */
class ABJ_404_Solution_RedirectWriteAdmissionPolicy {

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_Functions $functions
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct($functions, $logger) {
        $this->f = $functions;
        $this->logger = $logger;
    }

    /**
     * Whether a regex redirect's source pattern is usable. Logs the specific
     * validation failure, since the admin only sees that the redirect was
     * rejected.
     *
     * @param string $source
     * @return bool
     */
    public function regexSourceIsValid(string $source): bool {
        $validator = new ABJ_404_Solution_RegexSourcePatternValidator($this->f);
        $validation = $validator->validate($source);
        if ($validation['valid']) {
            return true;
        }

        $detail = $validation['detail'] !== '' ? ' ' . $validation['detail'] : '';
        $this->logger->warn('Invalid regex source pattern.' . $detail);
        return false;
    }

    /**
     * Whether an automatic redirect's destination points at something that is
     * actually published and reachable.
     *
     * Returns true when the WordPress lookup function is unavailable rather
     * than rejecting: this runs during frontend request handling on hosts
     * where a plugin conflict can leave the API unloaded, and refusing every
     * automatic redirect there would be a worse failure than admitting one
     * that a later request will re-validate.
     *
     * @param int $type One of the ABJ404_TYPE_* constants.
     * @param mixed $finalDest Destination id (post, category or tag).
     * @return bool
     */
    public function isValidAutomaticRedirectDestination($type, $finalDest): bool {
        $destId = self::exactDestinationId($finalDest);

        if ($type === ABJ404_TYPE_POST) {
            if ($destId <= 0) {
                return false;
            }
            if (!function_exists('get_post')) {
                return true;
            }
            $ref = ABJ_404_Solution_PostRef::fromWpPost(get_post($destId));
            if ($ref === null) {
                return false;
            }
            return $ref->isPublished();
        }

        if ($type === ABJ404_TYPE_CAT || $type === ABJ404_TYPE_TAG) {
            if ($destId <= 0) {
                return false;
            }
            if (!function_exists('get_term')) {
                return true;
            }
            $taxonomy = ($type === ABJ404_TYPE_CAT) ? 'category' : 'post_tag';
            $term = get_term($destId, $taxonomy);
            if ($term === null || is_wp_error($term)) {
                return false;
            }
            return is_object($term);
        }

        if ($type === ABJ404_TYPE_HOME) {
            return true;
        }

        return false;
    }

    /**
     * The destination id exactly as the write will store it, or 0 when the
     * value is not one.
     *
     * absint() answers a different question than the write path asks: it maps
     * -3 to 3 and '12abc' to 12. final_dest is stored verbatim (it is a string
     * column, because an external redirect keeps its URL there), so a coerced
     * id would validate one destination and then write another. Checking a
     * value the write will not use is how an automatic redirect to an
     * unreachable page passes the one gate that exists to stop it -- so the
     * value is parsed rather than coerced, and anything that is not already an
     * id is refused.
     *
     * @param mixed $finalDest
     * @return int Positive id, or 0 when the value is not usable as one.
     */
    private static function exactDestinationId($finalDest): int {
        if (is_int($finalDest)) {
            return $finalDest > 0 ? $finalDest : 0;
        }
        if (is_string($finalDest) && preg_match('/^[0-9]+$/', $finalDest) === 1) {
            return (int)$finalDest;
        }
        return 0;
    }
}
