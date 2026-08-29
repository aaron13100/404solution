<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What the Edit Redirect screen was asked to do.
 *
 * Reads the incoming request and answers two questions the edit screen needs
 * before it can render anything: which redirect ids were named, and which list
 * page the admin arrived from. Pure request interpretation -- no HTML, no
 * user-facing copy, no database access, and no logging -- so the answers can be
 * asserted directly instead of by scraping rendered markup, and so a getter
 * here never writes anything (enforced by scripts/lint/lint-hidden-write-getters).
 *
 * The two questions live together because they are read from the same request
 * in the same breath and every consumer needs both: the edit form needs the
 * ids to load and the source page for its "back" link and hidden inputs, and
 * the missing-row notice needs the ids to name and the same source page to
 * send the admin back to.
 *
 * This is also the single answer to "which subpage is a real one". The write
 * side used to keep its own tab list in EditRedirectHandler while this class
 * validated only by excluding the edit tab, so the same question had two
 * answers that could drift apart -- and the weaker of the two fed a link the
 * admin clicks.
 */
class ABJ_404_Solution_RedirectEditRequest {

    /**
     * Every subpage the edit screen can legitimately have been opened from,
     * and the only values getSourcePage() will return.
     *
     * Deliberately NOT including 'abj404_edit': the edit screen must not offer
     * to send the admin back to the screen they are already on.
     *
     * @var array<int, string>
     */
    const LIST_SUBPAGES = array('abj404_redirects', 'abj404_captured', 'abj404_logs',
            'abj404_stats', 'abj404_tools', 'abj404_options');

    /** Where the edit screen returns to when the request names nothing usable. */
    const DEFAULT_SUBPAGE = 'abj404_redirects';

    /** Request named a single redirect through the GET id parameter. */
    const SOURCE_GET_ID = 'get_id';

    /** Request named a single redirect through the POST id parameter. */
    const SOURCE_POST_ID = 'post_id';

    /** Request named a set of redirects through the idnum parameter. */
    const SOURCE_IDNUM = 'idnum';

    /**
     * Most redirects one request may name at once.
     *
     * The set arrives as a repeatable form field, so its size is chosen by the
     * client, and every consumer scales with it: one `IN (...)` lookup over the
     * whole set, then a hidden input and a rendered table row per id. Without a
     * ceiling the request decides how much work the server does.
     *
     * Set well above any real bulk selection -- the admin tables page in the
     * dozens, and the REST list endpoint caps a page at 100 -- so an admin who
     * selects everything on a very large page is unaffected, and only a set no
     * screen produces is bounded.
     */
    const MAX_SELECTED_IDS = 2000;

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /**
     * @param ABJ_404_Solution_Functions $f Provides regexMatch.
     */
    public function __construct($f) {
        $this->f = $f;
    }

    /**
     * Which list page the edit screen was opened from.
     *
     * Falls back to the Redirects list when the request names nothing usable,
     * which includes the edit screen itself -- so a reload of the edit URL
     * cannot make the screen offer to send the admin back to itself.
     *
     * Returns a member of LIST_SUBPAGES or nothing at all: the answer is a
     * subpage key, so a value that is not one is not an answer to narrow later.
     * Returning the raw sanitized string made this a half-parse, and the raw
     * string went straight into the back link as
     * '?page=...&subpage=' . esc_attr($sourcePage) -- esc_attr escapes HTML,
     * not URL components, so an '&' rode through and appended parameters of
     * the caller's choosing to a link the admin is invited to click.
     *
     * @return string One of self::LIST_SUBPAGES.
     */
    public function getSourcePage(): string {
        $sourcePage = $this->sanitizedScalar('source_page');
        if ($sourcePage === '') {
            $sourcePage = $this->sanitizedScalar('subpage');
        }
        return self::isListSubpage($sourcePage) ? $sourcePage : self::DEFAULT_SUBPAGE;
    }

    /**
     * Whether a value names a real plugin list page.
     *
     * Public so the write side resolves the same question through the same
     * list rather than keeping a second copy of it.
     *
     * @param mixed $subpage
     */
    public static function isListSubpage($subpage): bool {
        return is_string($subpage) && in_array($subpage, self::LIST_SUBPAGES, true);
    }

    /**
     * Which redirect ids the request named.
     *
     * Returns null when the request named no usable redirect at all: no id
     * parameter, or an idnum parameter that sanitizes down to nothing (empty
     * array, all zeros, non-numeric). Callers treat null as "nothing was
     * selected", which is a different message from "these ids have no row" --
     * the latter would print an empty id list.
     *
     * The `source` key reports which parameter the answer came from, so the
     * caller can log the same GET / POST / ids_multiple distinction the edit
     * page has always recorded without this method doing the logging itself.
     *
     * `truncated` reports that the request named more ids than
     * MAX_SELECTED_IDS, and `requestedCount` how many it named. The caller
     * REFUSES on that flag rather than proceeding with the remainder: editing
     * a subset of what the admin selected is worse than the unbounded work the
     * cap exists to prevent, and a partial answer they cannot see the edge of
     * is worse still -- they would have no way to tell which of their
     * selections had been dropped. `requestedCount` survives the slice so the
     * refusal can name the real number back to them.
     *
     * @return array{recnum: int|null, recnumsMultiple: array<int, int>, source: string,
     *   truncated: bool, requestedCount: int}|null
     */
    public function getRequestedIds(): ?array {
        if (isset($_GET['id']) && is_scalar($_GET['id']) && $this->f->regexMatch('[0-9]+', (string)$_GET['id'])) {
            return array(
                'recnum' => absint($_GET['id']),
                'recnumsMultiple' => array(),
                'source' => self::SOURCE_GET_ID,
                'truncated' => false,
                'requestedCount' => 1,
            );
        }

        if (isset($_POST['id']) && is_scalar($_POST['id']) && $this->f->regexMatch('[0-9]+', (string)$_POST['id'])) {
            return array(
                'recnum' => absint($_POST['id']),
                'recnumsMultiple' => array(),
                'source' => self::SOURCE_POST_ID,
                'truncated' => false,
                'requestedCount' => 1,
            );
        }

        if ($this->sanitizedScalar('idnum') === '' && !isset($_GET['idnum']) && !isset($_POST['idnum'])) {
            return null;
        }

        $rawIdnum = isset($_GET['idnum']) ? $_GET['idnum']
                : (isset($_POST['idnum']) ? $_POST['idnum'] : $this->sanitizedScalar('idnum'));
        // Bounded BEFORE the map, not after: the cap is there to limit the work
        // this request can cause, and sanitizing a million ids to then keep two
        // thousand of them has already done the work.
        $rawIds = (array)$rawIdnum;
        $requestedCount = count($rawIds);
        $truncated = $requestedCount > self::MAX_SELECTED_IDS;
        if ($truncated) {
            $rawIds = array_slice($rawIds, 0, self::MAX_SELECTED_IDS);
        }
        $recnumsMultiple = array_values(array_filter(array_map(
                function ($v): int { return is_scalar($v) ? absint($v) : 0; },
                $rawIds), function (int $v): bool { return $v > 0; }));
        if (empty($recnumsMultiple)) {
            return null;
        }
        return array(
            'recnum' => null,
            'recnumsMultiple' => $recnumsMultiple,
            'source' => self::SOURCE_IDNUM,
            'truncated' => $truncated,
            'requestedCount' => $requestedCount,
        );
    }

    /**
     * One sanitized GET/POST scalar as a string.
     *
     * Reads through RequestInputNormalizer directly rather than through
     * View_Shared's wrapper of it: this class answers a question about the
     * request, so it must not depend on the presentation layer to do so.
     *
     * @param string $name
     * @return string '' when absent or non-scalar.
     */
    private function sanitizedScalar(string $name): string {
        $result = ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize($name);
        return is_string($result) ? $result : (is_scalar($result) ? (string)$result : '');
    }
}
