<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What the Edit Redirect screen shows when there is nothing to edit.
 *
 * Three ways the screen can arrive with no form to render -- no usable id at
 * all, ids whose rows no longer exist, and a selection larger than the plugin
 * will carry -- and one contract shared by all of them:
 *
 *   1. a stock WordPress notice, never a bare echoed string;
 *   2. a link back to the list the admin came from, so the dead end is
 *      recoverable in one click;
 *   3. a log line BELOW error level.
 *
 * Point 3 is the one with history. DebugLogReader::getLatestErrorLine() keys on
 * the (ERROR) token to decide whether to mail the maintainer an error report,
 * and none of these three is a plugin failure: a well-formed id that no longer
 * resolves is a normal request condition (another admin deletes the row, this
 * admin trashes it in a second tab, deleteOldRedirectsCron removes it on its
 * own schedule) while an already-rendered list page still carries the Edit
 * link. The plugin keeps working, which by defensive-coding rule #8 makes it a
 * non-error. Logging it at error level mailed a bug report for a stale link
 * (production report 349, plugin 4.3.4).
 *
 * A genuine database failure behind the same empty result is not hidden by
 * this: DatabaseQueryExecutor::queryAndGetResults() is the centralized error
 * handler and has already logged it via sqlErrorReporter.
 *
 * Split out of ABJ_404_Solution_View_Redirects, which owns the screen's happy
 * path (request -> context -> record content -> form). These live together
 * instead because the contract above is what must not drift between them, and
 * it had no single home: each dead end carried its own copy of the reasoning
 * and a third was about to carry a third copy.
 */
class ABJ_404_Solution_RedirectEditDeadEndRenderer {

    /** @var ABJ_404_Solution_RedirectEditFormPresenter */
    private $presenter;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_RedirectEditFormPresenter $presenter Builds the notice markup.
     * @param ABJ_404_Solution_Logging $logger Receives the below-error-level line.
     */
    public function __construct($presenter, $logger) {
        $this->presenter = $presenter;
        $this->logger = $logger;
    }

    /**
     * The requested ids no longer have a row -- or the request named no usable
     * id at all, which is a different message: naming an empty id list would
     * print "Redirects  were not found."
     *
     * Takes the page context rather than resolving the destination again, so
     * the notice's way back can never disagree with the way back the edit form
     * itself would have offered.
     *
     * @param array{backUrl: string, backLabel: string} $context From editRedirectPageContext().
     * @param array<int, int> $ids The redirect ids the request asked for. Empty
     *     when the request carried no usable id at all.
     * @return null Always null, so a caller can `return $renderer->missingRedirects(...)`.
     */
    public function missingRedirects(array $context, array $ids) {
        $backUrl = $context['backUrl'];
        $backLabel = $context['backLabel'];

        if (empty($ids)) {
            echo $this->presenter->buildNoRedirectIdsNoticeHtml($backUrl, $backLabel);
            $this->logger->debugMessage('Edit redirect page: request carried no usable redirect id.');
            return null;
        }

        echo $this->presenter->buildMissingRedirectsNoticeHtml($ids, $backUrl, $backLabel);
        $this->logger->debugMessage('Edit redirect page: no redirect row exists for requested id(s): ' .
                esc_html(implode(', ', array_map('strval', $ids))));

        return null;
    }

    /**
     * The selection is larger than one edit screen may carry, so it is refused.
     *
     * RedirectEditRequest's cap bounds the work one request can cause, but a
     * bound the admin cannot see is a silent partial edit: they selected N, the
     * screen would render MAX_SELECTED_IDS of them with nothing marking the
     * boundary, and the save that follows applies to exactly that subset.
     *
     * @param array{backUrl: string, backLabel: string} $context From editRedirectPageContext().
     * @param int $requestedCount How many redirects the request actually named.
     * @return null Always null, so a caller can `return $renderer->tooManySelected(...)`.
     */
    public function tooManySelected(array $context, int $requestedCount) {
        $maximum = ABJ_404_Solution_RedirectEditRequest::MAX_SELECTED_IDS;
        echo $this->presenter->buildTooManySelectedNoticeHtml(
                $requestedCount, $maximum, $context['backUrl'], $context['backLabel']);
        $this->logger->debugMessage('Edit redirect page: refused a selection of ' . $requestedCount
                . ' redirects; the maximum is ' . $maximum . '.');

        return null;
    }
}
