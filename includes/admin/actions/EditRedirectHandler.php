<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles the Edit Redirect form: $_POST['action']=='editRedirect'.
 *
 * Triggered by the admin's edit form on the Redirects and Captured-404 tabs.
 * Verifies the abj404editRedirect link nonce, then writes through to
 * redirectsRepo->updateRedirect()/saveRedirectConditions() via the
 * shared RedirectFormResolver. On success attempts a PRG redirect to the
 * caller's source page (so the post-update view does not re-render the edit
 * form), and rewrites $sub/$action by reference as a defense-in-depth
 * in-request route when headers are already sent.
 *
 * Extracted from PluginLogicAdminActions::handleActionEdit() +
 * updateRedirectData() (148 lines) (M201, design-audit-2026-06-02). Called
 * from View.php's admin-page render and from PluginLogicAdminActions's
 * thin compat shims (used by tests).
 */
class ABJ_404_Solution_EditRedirectHandler {

    /** @var ABJ_404_Solution_PluginLogicAdminActions */
    private $parent;

    /** @var ABJ_404_Solution_RedirectFormResolver */
    private $resolver;

    public function __construct(
        ABJ_404_Solution_PluginLogicAdminActions $parent,
        ABJ_404_Solution_RedirectFormResolver $resolver
    ) {
        $this->parent = $parent;
        $this->resolver = $resolver;
    }

    /**
     * Process the editRedirect POST. Returns a human-readable message and
     * rewrites $sub/$action by reference on success (defense-in-depth route
     * when wp_safe_redirect() can no longer fire).
     *
     * @param string $sub admin subpage tab key (by ref)
     * @param string $action admin action verb (by ref)
     * @return string
     */
    public function handle(&$sub, &$action): string {
        $message = "";

        if (!array_key_exists('action', $_POST) || $_POST['action'] != "editRedirect") {
            return $message;
        }

        $f = $this->parent->getFunctions();
        $id = $f->getPostOrGetSanitize('id');
        $ids = $f->getPostOrGetSanitize('ids_multiple');
        if ($id === '' && $ids === '') {
            return $message;
        }
        if (!$f->regexMatch('[0-9]+', '' . $id) && !$f->regexMatch('[0-9]+', '' . $ids)) {
            return $message;
        }
        if (!is_admin() || !$this->parent->verifyLinkNonce('abj404editRedirect')) {
            return $message;
        }

        $message = $this->updateRedirectData();
        if ($message != "") {
            return $message . __('Error: Unable to update redirect data.', '404-solution');
        }

        $redirect = $this->buildPostEditRedirect();

        if (!headers_sent()) {
            wp_safe_redirect(admin_url($this->getMenuParentScript() . $redirect['redirect_url']));
        }

        $sub = $redirect['source_page'];
        $action = '';
        return __('Redirect Information Updated Successfully!', '404-solution');
    }

    /**
     * Parse the Edit Redirect POST form, validate, and write through to
     * redirectsRepo. Returns the error message (or '' on success).
     *
     * Public so the legacy PluginLogicAdminActions::updateRedirectData()
     * shim and existing tests can call it directly.
     *
     * @return string
     */
    public function updateRedirectData(): string {
        $message = "";
        $fromURL = "";
        $ids_multiple = "";
        $f = $this->parent->getFunctions();
        $redirectsRepo = $this->parent->getRedirectsRepo();
        $logger = $this->parent->getLogger();
        $viewBuild = $this->parent->getViewBuild();

        if (
            (!array_key_exists('url', $_POST) || $_POST['url'] == "") &&
            (array_key_exists('ids_multiple', $_POST) && $_POST['ids_multiple'] != "")) {
            $ids_multiple = array_map('absint', explode(',', $_POST['ids_multiple']));

        } else if (array_key_exists('url', $_POST) && $_POST['url'] != "" &&
            (!array_key_exists('ids_multiple', $_POST) || $_POST['ids_multiple'] == "")) {

            $fromURL = stripslashes($_POST['url']);
        } else {
            $message .= __('Error: URL is a required field.', '404-solution') . "<BR/>";
        }

        if ($fromURL != "" && $f->substr($_POST['url'], 0, 1) != "/") {
            $message .= __('Error: URL must start with /', '404-solution') . "<BR/>";
        }

        $typeAndDest = $this->resolver->getRedirectTypeAndDest();

        $typeAndDestMessage = is_string($typeAndDest['message']) ? $typeAndDest['message'] : '';
        if ($typeAndDestMessage != "") {
            return $typeAndDestMessage;
        }

        $tdTypeRaw = is_scalar($typeAndDest['type']) ? (string)$typeAndDest['type'] : '';
        $tdType = ($tdTypeRaw !== '') ? (int)$tdTypeRaw : -1;
        $tdDest = is_scalar($typeAndDest['dest']) ? (string)$typeAndDest['dest'] : '';
        $postedCodeForCheck = isset($_POST['code']) && is_scalar($_POST['code']) ? (string)$_POST['code'] : '';
        $isCode410 = $postedCodeForCheck === '410' || $postedCodeForCheck === '451';
        if ($tdTypeRaw !== '' && ($tdDest !== "" || $isCode410)) {
            $statusType = ABJ404_STATUS_MANUAL;
            if (isset($_POST['is_regex_url']) &&
                $_POST['is_regex_url'] != '0') {

                $statusType = ABJ404_STATUS_REGEX;
            }

            $startDateRaw = isset($_POST['redirect_start_date']) && is_string($_POST['redirect_start_date']) ? trim($_POST['redirect_start_date']) : '';
            $endDateRaw = isset($_POST['redirect_end_date']) && is_string($_POST['redirect_end_date']) ? trim($_POST['redirect_end_date']) : '';
            $startTs = ($startDateRaw !== '') ? strtotime($startDateRaw . ' 00:00:00') : null;
            $endTs = ($endDateRaw !== '') ? strtotime($endDateRaw . ' 23:59:59') : null;
            if ($startTs === false) { $startTs = null; }
            if ($endTs === false) { $endTs = null; }

            $sanitizedConditions = $this->sanitizeRedirectConditions();

            if ($fromURL != "") {
                $id = isset($_POST['id']) && is_scalar($_POST['id']) ? (int)$_POST['id'] : 0;
                $code = isset($_POST['code']) && is_string($_POST['code']) ? $_POST['code'] : '';
                $originalFromURL = $fromURL;
                $autoPromote = $this->resolver->maybeAutoPromoteRegex($statusType, $fromURL);
                $statusType = $autoPromote['statusType'];
                $fromURL = $autoPromote['url'];
                $redirectsRepo->updateRedirect(ABJ_404_Solution_RedirectUpdate::create(
                    $id,
                    (int)$tdType,
                    (string)$fromURL,
                    (string)$tdDest,
                    (string)$code,
                    (string)$statusType,
                    $startTs,
                    $endTs
                ));
                if ($autoPromote['autoPromoted']) {
                    $this->resolver->saveRegexAutoPromoteNotice($id, $originalFromURL, $fromURL, $autoPromote['urlRewritten']);
                }

                if ($id > 0) {
                    $redirectsRepo->saveRedirectConditions($id, $sanitizedConditions);
                }
                $viewBuild->invalidateViewDoneAndScheduleRebuild();

            } else if ($ids_multiple != "") {
                $redirects_multiple = $redirectsRepo->getRedirectsByIDs($ids_multiple);
                $code = isset($_POST['code']) && is_string($_POST['code']) ? $_POST['code'] : '';
                foreach ($redirects_multiple as $redirect) {
                    $redirectUrl = is_string($redirect['url']) ? $redirect['url'] : '';
                    $redirectId = is_scalar($redirect['id']) ? (int)$redirect['id'] : 0;
                    $redirectsRepo->updateRedirect(ABJ_404_Solution_RedirectUpdate::create(
                        $redirectId,
                        (int)$tdType,
                        (string)$redirectUrl,
                        (string)$tdDest,
                        (string)$code,
                        (string)$statusType
                    ));
                }
                $viewBuild->invalidateViewDoneAndScheduleRebuild();

            } else {
                $logger->errorMessage("Issue determining which redirect(s) to update. " .
                    "fromURL: " . $fromURL . ", ids_multiple: " . $ids_multiple);
            }

        } else {
            $message .= __('Error: Data not formatted properly.', '404-solution') . "<BR/>";
            $logger->errorMessage("Update redirect data issue. Type: " . esc_html((string)$tdType) .
                    ", dest: " . esc_html($tdDest));
        }

        return $message;
    }

    /**
     * Build the post-edit PRG redirect querystring + the source page that
     * the in-request render should target.
     *
     * @return array{source_page: string, redirect_url: string}
     */
    private function buildPostEditRedirect(): array {
        $f = $this->parent->getFunctions();
        $valid_tabs = array('abj404_redirects', 'abj404_captured', 'abj404_logs',
                          'abj404_stats', 'abj404_tools', 'abj404_options');
        $source_page = $f->getPostOrGetSanitize('source_page');
        if ($source_page === '' || !in_array($source_page, $valid_tabs)) {
            $source_page = 'abj404_redirects';
        }

        $redirect_url = "?page=" . ABJ404_PP . "&subpage=" . $source_page . "&updated=1";

        $source_filter = $f->getPostOrGetSanitize('source_filter', '');
        if ($source_filter !== '' && $source_filter !== '0') {
            $redirect_url .= "&filter=" . urlencode($source_filter);
        }

        $source_orderby = $f->getPostOrGetSanitize('source_orderby', '');
        $source_order = $f->getPostOrGetSanitize('source_order', '');
        if ($source_orderby !== '' && $source_order !== ''
                && !($source_orderby === "url" && $source_order === "ASC")) {
            $redirect_url .= "&orderby=" . urlencode($source_orderby);
            $redirect_url .= "&order=" . urlencode($source_order);
        }

        $source_paged = $f->getPostOrGetSanitize('source_paged', '');
        if ($source_paged !== '' && (int)$source_paged > 1) {
            $redirect_url .= "&paged=" . urlencode($source_paged);
        }

        return array('source_page' => $source_page, 'redirect_url' => $redirect_url);
    }

    /**
     * Resolve the admin parent script the plugin's menu page is registered
     * under. Used to build correct admin_url() after a successful edit.
     *
     * @return string
     */
    private function getMenuParentScript(): string {
        $options = abj_service('options_repository')->getOptions(true);
        $menuLocation = 'underSettings';
        if (is_array($options) && isset($options['menuLocation']) && is_string($options['menuLocation'])) {
            $menuLocation = $options['menuLocation'];
        }
        return $menuLocation === 'settingsLevel' ? 'admin.php' : 'options-general.php';
    }

    /**
     * Sanitize the conditions[] POST payload into the shape redirectsRepo
     * accepts. Whitelists condition types and operators; coerces logic to
     * AND/OR.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeRedirectConditions(): array {
        $rawConditions = (isset($_POST['conditions']) && is_array($_POST['conditions']))
            ? $_POST['conditions'] : [];
        $sanitizedConditions = [];
        $allowedConditionTypes = [
            'login_status', 'user_role', 'referrer',
            'user_agent', 'ip_range', 'http_header',
        ];
        $allowedOperators = [
            'equals', 'not_equals', 'contains',
            'not_contains', 'regex', 'cidr',
        ];
        foreach ($rawConditions as $rawCond) {
            if (!is_array($rawCond)) {
                continue;
            }
            $condType = isset($rawCond['condition_type']) && is_string($rawCond['condition_type'])
                ? sanitize_text_field($rawCond['condition_type']) : '';
            if (!in_array($condType, $allowedConditionTypes, true)) {
                continue;
            }
            $condLogic = (isset($rawCond['logic']) && strtoupper((string)$rawCond['logic']) === 'OR') ? 'OR' : 'AND';
            $condOperator = isset($rawCond['operator']) && is_string($rawCond['operator'])
                ? sanitize_text_field($rawCond['operator']) : 'equals';
            if (!in_array($condOperator, $allowedOperators, true)) {
                $condOperator = 'equals';
            }
            $condValue = isset($rawCond['value']) && is_string($rawCond['value'])
                ? sanitize_text_field(wp_unslash($rawCond['value'])) : '';
            $condSortOrder = isset($rawCond['sort_order']) ? absint($rawCond['sort_order']) : 0;

            $sanitizedConditions[] = [
                'logic'          => $condLogic,
                'condition_type' => $condType,
                'operator'       => $condOperator,
                'value'          => $condValue,
                'sort_order'     => $condSortOrder,
            ];
        }
        return $sanitizedConditions;
    }
}
