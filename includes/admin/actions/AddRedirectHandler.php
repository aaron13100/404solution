<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles action 'addRedirect': processes the Add Redirect form POST.
 *
 * Validates the manually entered URL, normalizes it, resolves the destination
 * via the shared RedirectFormResolver, applies regex auto-promotion, then
 * writes through to redirectsRepo->setupRedirect(). Returns the framed
 * success/error message to the dispatcher.
 *
 * The form-validation logic previously lived on
 * PluginLogicAdminActions::addAdminRedirect() (M201, design-audit-2026-06-02).
 * That parent method is now a thin shim that calls addAdminRedirect() here
 * so existing tests/callers do not break.
 */
class ABJ_404_Solution_AddRedirectHandler implements ABJ_404_Solution_AdminActionHandlerInterface {

    /** @var ABJ_404_Solution_PluginLogicAdminActions */
    private $parent;

    /** @var ABJ_404_Solution_RedirectFormResolver */
    private $resolver;

    public function __construct(
        ABJ_404_Solution_PluginLogicAdminActions $parent,
        ?ABJ_404_Solution_RedirectFormResolver $resolver = null
    ) {
        $this->parent = $parent;
        $this->resolver = $resolver !== null ? $resolver : $parent->redirectFormResolver();
    }

    public function nonceAction(): string {
        return 'abj404addRedirect';
    }

    public function nonceArg(): string {
        return '_wpnonce';
    }

    public function useCheckAdminReferer(): bool {
        return true;
    }

    public function handle(string $action, string &$sub): string {
        $message = $this->addAdminRedirect();
        if ($message == '') {
            return __('New Redirect Added Successfully!', '404-solution');
        }
        return $message . __('Error: unable to add new redirect.', '404-solution');
    }

    /**
     * Run the add-redirect work. Public so the legacy
     * PluginLogicAdminActions::addAdminRedirect() shim and existing tests can
     * call it directly.
     *
     * @return string error message, '' on success
     */
    public function addAdminRedirect(): string {
        $message = "";
        $f = $this->parent->getFunctions();
        $urlNormalization = $this->parent->getUrlNormalization();
        $redirectsRepo = $this->parent->getRedirectsRepo();
        $logger = $this->parent->getLogger();
        $viewBuild = $this->parent->getViewBuild();

        if (!isset($_POST['manual_redirect_url']) || $_POST['manual_redirect_url'] == "") {
            $message .= __('Error: URL is a required field.', '404-solution') . "<BR/>";
            return $message;
        }

        $manualURL = isset($_POST['manual_redirect_url']) ? wp_unslash($_POST['manual_redirect_url']) : '';
        $manualURL = $urlNormalization->normalizeUserProvidedPath($manualURL);
        if ($f->substr($manualURL, 0, 1) != "/") {
            $message .= __('Error: URL must start with /', '404-solution') . "<BR/>";
            return $message;
        }

        $typeAndDest = $this->resolver->getRedirectTypeAndDest();

        $tdMsg = is_string($typeAndDest['message']) ? $typeAndDest['message'] : '';
        if ($tdMsg != "") {
            return $tdMsg;
        }

        $tdType2 = is_scalar($typeAndDest['type']) ? (string)$typeAndDest['type'] : '';
        $tdDest2 = is_scalar($typeAndDest['dest']) ? (string)$typeAndDest['dest'] : '';
        $postedCodeForCheck2 = isset($_POST['code']) && is_scalar($_POST['code']) ? (string)$_POST['code'] : '';
        $code410 = $postedCodeForCheck2 === '410' || $postedCodeForCheck2 === '451';
        if ($tdType2 != "" && ($tdDest2 !== "" || $code410)) {
            $statusType = ABJ404_STATUS_MANUAL;
            if (isset($_POST['is_regex_url']) &&
                $_POST['is_regex_url'] != '0') {

                $statusType = ABJ404_STATUS_REGEX;
            }

            $code = isset($_POST['code']) && is_scalar($_POST['code']) && (string)$_POST['code'] !== '' ? (string)$_POST['code'] : '301';

            $originalManualURL = $manualURL;
            $autoPromoteAdd = $this->resolver->maybeAutoPromoteRegex($statusType, $manualURL);
            $statusType = $autoPromoteAdd['statusType'];
            $manualURL = $autoPromoteAdd['url'];

            $newRedirectId = $redirectsRepo->setupRedirect(ABJ_404_Solution_RedirectSpec::create(
                    $manualURL, (string)$statusType,
                    $tdType2, $tdDest2,
                    sanitize_text_field($code), 0
            ));
            if ($autoPromoteAdd['autoPromoted']) {
                $this->resolver->saveRegexAutoPromoteNotice((int)$newRedirectId, $originalManualURL, $manualURL, $autoPromoteAdd['urlRewritten']);
            }
            $viewBuild->invalidateViewDoneAndScheduleRebuild();
            // Run the staged rebuild inline so the post-add admin navigation
            // (often a filterText lookup for the just-added URL) reads fresh
            // view_done data instead of the pre-add snapshot. Without this
            // the cron-scheduled rebuild can lose the race against the
            // user's next request and the new row stays invisible to
            // filtered queries until the background tick lands. Same shape
            // as 119cfbda (CSV import) -- the CSV fix's sibling-search
            // labelled this path "safe via PRG to unfiltered", but the
            // modal-add flow stays on a filterable list and the user
            // (and the e2e suite) immediately filters by the new URL.
            // Safe to call inline: the build pipeline yields per-stage
            // on time pressure and the lock is non-blocking, so a
            // concurrent worker just returns control here without
            // doubling work.
            $viewBuild->rebuildViewDoneInBackground();
            // Write-through cache reconciliation. The staged pipeline
            // yields between stages so a single inline rebuildInBackground
            // call rarely completes S1..S11 in the same request -- the
            // new redirect's row stays missing from view_done until the
            // next cron tick lands and the S11 swap publishes the buffer.
            // syncViewDoneWithSource() closes the visibility gap surgically
            // (INSERT IGNORE + DELETE LEFT JOIN + UPDATE INNER JOIN against
            // the live source table). The next S11 swap atomically replaces
            // view_done with the fully-derived buffer.
            $viewBuild->syncViewDoneWithSource();

        } else {
            $message .= __('Error: Data not formatted properly.', '404-solution') . "<BR/>";
            $logger->errorMessage("Add redirect data issue. Type: " . esc_html($tdType2) . ", dest: " .
                    esc_html($tdDest2));
        }

        return $message;
    }
}
