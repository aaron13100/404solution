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

    /**
     * Cardinality cap on the conditions[] POST payload. Every accepted
     * condition becomes its own INSERT statement inside
     * RedirectConditionsRepository::saveRedirectConditions()'s replacement
     * transaction, so this bounds the SQL workload (and in-memory statement
     * array) a single edit request can generate. The admin UI's "+ Add
     * Condition" button has no client-side cap, but a redirect with dozens
     * of conditions is already an edge case -- 50 is comfortably above any
     * realistic legitimate use while still rejecting a forged or accidental
     * oversized request (e.g. 50,000 conditions).
     */
    const MAX_REDIRECT_CONDITIONS = 50;

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
        $id = ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize('id');
        $ids = ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize('ids_multiple');
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
        $target = $this->resolveUpdateTarget();
        $message = $target['message'];
        $logger = $this->parent->getLogger();
        if ($message !== '') {
            return $message;
        }

        $statusTypeForValidation = ABJ404_STATUS_MANUAL;
        if (isset($_POST['is_regex_url']) && $_POST['is_regex_url'] != '0') {
            $statusTypeForValidation = ABJ404_STATUS_REGEX;
        }
        $sourceResolution = array(
            'statusType' => $statusTypeForValidation,
            'url' => $target['fromURL'],
            'autoPromoted' => false,
            'urlRewritten' => false,
        );
        $originalFromURL = $target['fromURL'];
        if ($target['fromURL'] !== '') {
            $sourceResolution = $this->resolver->resolveSource(
                $statusTypeForValidation,
                $target['fromURL']
            );
            $statusTypeForValidation = $sourceResolution['statusType'];
            $target['fromURL'] = $sourceResolution['url'];
        }

        $typeAndDest = $this->resolver->getRedirectTypeAndDest(array(
            'isRegex' => $statusTypeForValidation === ABJ404_STATUS_REGEX,
            'sourcePattern' => $target['fromURL'],
        ));
        $typeAndDestMessage = is_string($typeAndDest['message']) ? $typeAndDest['message'] : '';
        if ($typeAndDestMessage != "") {
            return $typeAndDestMessage;
        }

        $context = $this->buildUpdateContext($typeAndDest, $statusTypeForValidation);
        if (!$this->contextHasDestination($context)) {
            $message .= __('Error: Data not formatted properly.', '404-solution') . "<BR/>";
            $logger->errorMessage("Update redirect data issue. Type: " . esc_html((string)$context['tdType']) .
                    ", dest: " . esc_html($context['tdDest']));
            return $message;
        }

        if ($target['fromURL'] != "") {
            return $message . $this->updateSingleRedirect(
                $target['fromURL'],
                $context,
                $sourceResolution,
                $originalFromURL
            );
        }

        if (!empty($target['ids_multiple'])) {
            return $message . $this->updateMultipleRedirects($target['ids_multiple'], $context);
        }

        $logger->errorMessage("Issue determining which redirect(s) to update. " .
            "fromURL: " . $target['fromURL'] . ", ids_multiple: " . implode(',', $target['ids_multiple']));
        return $message;
    }

    /**
     * @return array{fromURL: string, ids_multiple: array<int, int>, message: string}
     */
    private function resolveUpdateTarget(): array {
        $message = "";
        $fromURL = "";
        $idsMultiple = array();

        if (
            (!array_key_exists('url', $_POST) || $_POST['url'] == "") &&
            (array_key_exists('ids_multiple', $_POST) && $_POST['ids_multiple'] != "")) {
            $idsMultiple = array_map('absint', explode(',', (string)$_POST['ids_multiple']));

        } else if (array_key_exists('url', $_POST) && $_POST['url'] != "" &&
            (!array_key_exists('ids_multiple', $_POST) || $_POST['ids_multiple'] == "")) {

            $fromURL = stripslashes((string)$_POST['url']);
        } else {
            $message .= __('Error: URL is a required field.', '404-solution') . "<BR/>";
        }

        return array('fromURL' => $fromURL, 'ids_multiple' => $idsMultiple, 'message' => $message);
    }

    /**
     * @param array<string, mixed> $typeAndDest
     * @return array{tdTypeRaw: string, tdType: int, tdDest: string, code: string, statusType: int, startTs: int|null, endTs: int|null}
     */
    private function buildUpdateContext(array $typeAndDest, int $statusType): array {
        $tdTypeRaw = is_scalar($typeAndDest['type']) ? (string)$typeAndDest['type'] : '';
        $tdType = ($tdTypeRaw !== '') ? (int)$tdTypeRaw : -1;
        $tdDest = is_scalar($typeAndDest['dest']) ? (string)$typeAndDest['dest'] : '';
        $code = isset($_POST['code']) && is_string($_POST['code']) ? $_POST['code'] : '';

        $startDateRaw = isset($_POST['redirect_start_date']) && is_string($_POST['redirect_start_date']) ? trim($_POST['redirect_start_date']) : '';
        $endDateRaw = isset($_POST['redirect_end_date']) && is_string($_POST['redirect_end_date']) ? trim($_POST['redirect_end_date']) : '';
        $startTs = ABJ_404_Solution_RedirectScheduleTimezone::toEpoch($startDateRaw, '00:00:00');
        $endTs = ABJ_404_Solution_RedirectScheduleTimezone::toEpoch($endDateRaw, '23:59:59');

        return array(
            'tdTypeRaw' => $tdTypeRaw,
            'tdType' => $tdType,
            'tdDest' => $tdDest,
            'code' => $code,
            'statusType' => $statusType,
            'startTs' => $startTs,
            'endTs' => $endTs,
        );
    }

    /**
     * @param array{tdTypeRaw: string, tdType: int, tdDest: string, code: string, statusType: int, startTs: int|null, endTs: int|null} $context
     */
    private function contextHasDestination(array $context): bool {
        $isGoneCode = $context['code'] === '410' || $context['code'] === '451';
        return $context['tdTypeRaw'] !== '' && ($context['tdDest'] !== "" || $isGoneCode);
    }

    /**
     * @param array{tdTypeRaw: string, tdType: int, tdDest: string, code: string, statusType: int, startTs: int|null, endTs: int|null} $context
     * @param array{statusType: int, url: string, autoPromoted: bool, urlRewritten: bool} $sourceResolution
     */
    private function updateSingleRedirect(
        string $fromURL,
        array $context,
        array $sourceResolution,
        string $originalFromURL
    ): string {
        $redirectsRepo = $this->parent->getRedirectsRepo();
        $id = isset($_POST['id']) && is_scalar($_POST['id']) ? (int)$_POST['id'] : 0;
        $updateError = $redirectsRepo->updateRedirect(ABJ_404_Solution_RedirectUpdate::fromArray(array(
            'id' => $id,
            'type' => $context['tdType'],
            'fromUrl' => (string)$fromURL,
            'destination' => $context['tdDest'],
            'code' => $context['code'],
            'statusType' => (string)$context['statusType'],
            'startTs' => $context['startTs'],
            'endTs' => $context['endTs'],
        )));
        $errorCode = is_scalar($updateError) ? (string)$updateError : '';
        if ($errorCode !== '') {
            return $this->formatUpdateRedirectError($errorCode) . "<BR/>";
        }
        if ($sourceResolution['autoPromoted']) {
            $this->resolver->saveRegexAutoPromoteNotice(
                $id,
                $originalFromURL,
                $fromURL,
                $sourceResolution['urlRewritten']
            );
        }

        if ($id > 0) {
            $conditionsError = $redirectsRepo->saveRedirectConditions($id, $this->sanitizeRedirectConditions());
            if ($conditionsError !== '') {
                return $this->formatSaveConditionsError($conditionsError) . "<BR/>";
            }
        }
        return '';
    }

    /**
     * @param array<int, int> $idsMultiple
     * @param array{tdTypeRaw: string, tdType: int, tdDest: string, code: string, statusType: int, startTs: int|null, endTs: int|null} $context
     */
    private function updateMultipleRedirects(array $idsMultiple, array $context): string {
        $message = "";
        $redirectsRepo = $this->parent->getRedirectsRepo();
        $redirectsMultiple = $redirectsRepo->getRedirectsByIDs($idsMultiple);
        foreach ($redirectsMultiple as $redirect) {
            $redirectUrl = is_string($redirect['url']) ? $redirect['url'] : '';
            $redirectId = is_scalar($redirect['id']) ? (int)$redirect['id'] : 0;
            $updateError = $redirectsRepo->updateRedirect(ABJ_404_Solution_RedirectUpdate::fromArray(array(
                'id' => $redirectId,
                'type' => $context['tdType'],
                'fromUrl' => (string)$redirectUrl,
                'destination' => $context['tdDest'],
                'code' => $context['code'],
                'statusType' => (string)$context['statusType'],
            )));
            $errorCode = is_scalar($updateError) ? (string)$updateError : '';
            if ($errorCode !== '') {
                $message .= $this->formatUpdateRedirectError($errorCode) . "<BR/>";
                continue;
            }
        }
        return $message;
    }

    private function formatUpdateRedirectError(string $errorCode): string {
        if ($errorCode === 'bad_update_request') {
            return __('Error: Bad data passed for update redirect request.', '404-solution');
        }

        return sprintf(
            __('Error: Unable to update redirect data. Repository result: %s', '404-solution'),
            esc_html($errorCode)
        );
    }

    /**
     * @param string $errorMessage Underlying DB/transaction error text (per
     *     the Error visibility philosophy, surfaced rather than genericized).
     */
    private function formatSaveConditionsError(string $errorMessage): string {
        return sprintf(
            __('Error: Unable to save redirect conditions. Repository result: %s', '404-solution'),
            esc_html($errorMessage)
        );
    }

    /**
     * Build the post-edit PRG redirect querystring + the source page that
     * the in-request render should target.
     *
     * @return array{source_page: string, redirect_url: string}
     */
    private function buildPostEditRedirect(): array {
        $valid_tabs = array('abj404_redirects', 'abj404_captured', 'abj404_logs',
                          'abj404_stats', 'abj404_tools', 'abj404_options');
        $source_page = ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize('source_page');
        if ($source_page === '' || !in_array($source_page, $valid_tabs)) {
            $source_page = 'abj404_redirects';
        }

        $redirect_url = "?page=" . ABJ404_PP . "&subpage=" . $source_page . "&updated=1";

        $source_filter = ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize('source_filter', '');
        if ($source_filter !== '' && $source_filter !== '0') {
            $redirect_url .= "&filter=" . urlencode($source_filter);
        }

        $source_orderby = ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize('source_orderby', '');
        $source_order = ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize('source_order', '');
        if ($source_orderby !== '' && $source_order !== ''
                && !($source_orderby === "url" && $source_order === "ASC")) {
            $redirect_url .= "&orderby=" . urlencode($source_orderby);
            $redirect_url .= "&order=" . urlencode($source_order);
        }

        $source_paged = ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize('source_paged', '');
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
        // Enforce the domain maximum before sanitizing/inserting: mirrors the
        // loop's existing "skip silently, never fail the whole request" shape
        // (see the condition-type/operator handling below) rather than
        // rejecting the entire edit over an oversized payload.
        if (count($rawConditions) > self::MAX_REDIRECT_CONDITIONS) {
            $rawConditions = array_slice($rawConditions, 0, self::MAX_REDIRECT_CONDITIONS);
        }
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
