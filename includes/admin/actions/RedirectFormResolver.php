<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Owns parsing of the admin redirect form's destination field and the
 * regex auto-promote policy. Used by both AddRedirectHandler (handler
 * for the Add Redirect form) and EditRedirectHandler (handler for the
 * Edit Redirect form). Extracted from PluginLogicAdminActions as part
 * of M201 split (design-audit-2026-06-02).
 *
 * Two responsibilities, both genuinely shared:
 *
 *   - getRedirectTypeAndDest(): translate the form's
 *     redirect_to_data_field_id POST value into a {type, dest, message}
 *     triple. Handles the external-URL branch (parse, validate scheme,
 *     filter through abj404_validate_external_redirect) and the
 *     internal post/page branch (split on '|').
 *
 *   - maybeAutoPromoteRegex() + saveRegexAutoPromoteNotice(): if a
 *     manually entered URL pattern looks like an unambiguous regex,
 *     promote its status to ABJ404_STATUS_REGEX and apply a glob
 *     rewrite. The transient notice the admin sees afterwards is
 *     persisted by saveRegexAutoPromoteNotice().
 *
 * Neither belongs uniquely to Add or Edit; both must apply both. A
 * shared resolver collapses the duplicated wiring previously inlined
 * in addAdminRedirect() and updateRedirectData() into one place.
 */
class ABJ_404_Solution_RedirectFormResolver {

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_PluginLogicUrlNormalization */
    private $urlNormalization;

    /**
     * Parameters are intentionally untyped: the legacy DI sites that flow
     * through PluginLogicAdminActions pass test doubles that don't extend the
     * canonical classes (anonymous-class logger spies, custom Functions
     * subclasses). The @param docblocks document the intent for static
     * analysis. Matches the pattern in AdminActionsDependencies.
     *
     * @param ABJ_404_Solution_Functions $f
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_PluginLogicUrlNormalization $urlNormalization
     */
    public function __construct($f, $logger, $urlNormalization) {
        $this->f = $f;
        $this->logger = $logger;
        $this->urlNormalization = $urlNormalization;
    }

    /**
     * Parse the redirect destination field from $_POST.
     *
     * @return array<string, mixed> {type: string, dest: string, message: string}
     */
    public function getRedirectTypeAndDest(): array {

        $response = array();
        $response['type'] = "";
        $response['dest'] = "";
        $response['message'] = "";
        $userEnteredURL = '';

        $postedCode = isset($_POST['code']) && is_scalar($_POST['code']) ? (string)$_POST['code'] : '';
        if ($postedCode === '410' || $postedCode === '451') {
            $response['type'] = (string)ABJ404_TYPE_HOME;
            $response['dest'] = '';
            return $response;
        }

        if (!isset($_POST['redirect_to_data_field_id']) || $_POST['redirect_to_data_field_id'] === '') {
            $response['message'] = __('Error: Redirect destination is required.', '404-solution') . "<BR/>";
            return $response;
        }

        if ($_POST['redirect_to_data_field_id'] == ABJ404_TYPE_EXTERNAL . '|' . ABJ404_TYPE_EXTERNAL) {
            $rawEnteredURLResult = $this->f->getPostOrGetSanitizeUrl('redirect_to_user_field');
            $rawEnteredURL = is_string($rawEnteredURLResult) ? $rawEnteredURLResult : null;
            $userEnteredURL = $this->urlNormalization->normalizeExternalDestinationUrl($rawEnteredURL);
            $userEnteredURL = esc_url($userEnteredURL, array('http', 'https'));
            if ($userEnteredURL == "") {
                $response['message'] = __('Error: You selected external URL but did not enter a URL.', '404-solution') . "<BR/>";

            } else if ($this->f->strlen($userEnteredURL) < 8) {
                $response['message'] = __('Error: External URL is too short.', '404-solution') . "<BR/>";

            } else if ($this->f->strpos($userEnteredURL, "://") === false) {
                $response['message'] = __("Error: External URL doesn't contain ://", '404-solution') . "<BR/>";

            } else {
                $parsed_url = parse_url($userEnteredURL);
                if (!is_array($parsed_url) || !isset($parsed_url['scheme']) || !in_array(strtolower($parsed_url['scheme']), array('http', 'https'))) {
                    $response['message'] = __('Error: External URL must use http:// or https:// protocol only.', '404-solution') . "<BR/>";
                }

                $validated_url = apply_filters('abj404_validate_external_redirect', $userEnteredURL);
                if ($validated_url === false) {
                    $response['message'] = __('Error: External redirect URL failed validation.', '404-solution') . "<BR/>";
                } else {
                    $userEnteredURL = $validated_url;
                }
            }
        }

        if ($response['message'] != "") {
            return $response;
        }
        $info = explode("|", sanitize_text_field($_POST['redirect_to_data_field_id']));

        if ($_POST['redirect_to_data_field_id'] == ABJ404_TYPE_EXTERNAL . '|' . ABJ404_TYPE_EXTERNAL) {
            $response['type'] = ABJ404_TYPE_EXTERNAL;
            $response['dest'] = $userEnteredURL;
        } else {
            if (count($info) == 2) {
                $response['dest'] = absint($info[0]);
                $response['type'] = $info[1];
            } else {
                $infoJson = json_encode($info);
                $this->logger->errorMessage("Unexpected info while updating redirect: " .
                        wp_kses_post(is_string($infoJson) ? $infoJson : ''));
            }
        }

        return $response;
    }

    /**
     * Decide whether a manually entered URL pattern should be auto-promoted
     * to ABJ404_STATUS_REGEX and apply a glob rewrite when so.
     *
     * @param int $statusTypeIn
     * @param string $fromURL
     * @return array{statusType: int, url: string, autoPromoted: bool, urlRewritten: bool}
     */
    public function maybeAutoPromoteRegex($statusTypeIn, $fromURL): array {
        $result = array(
            'statusType' => (int)$statusTypeIn,
            'url' => is_string($fromURL) ? $fromURL : '',
            'autoPromoted' => false,
            'urlRewritten' => false,
        );

        if ((int)$statusTypeIn === ABJ404_STATUS_REGEX) {
            return $result;
        }
        if (!ABJ_404_Solution_RegexAutoPromote::looksLikeUnambiguousRegex($result['url'])) {
            return $result;
        }

        $result['statusType'] = ABJ404_STATUS_REGEX;
        $result['autoPromoted'] = true;
        $glob = ABJ_404_Solution_RegexAutoPromote::applyGlobFixup($result['url']);
        $result['url'] = $glob['url'];
        $result['urlRewritten'] = $glob['changed'];

        return $result;
    }

    /**
     * Persist the transient notice the admin sees after a regex auto-promotion
     * (so they can undo it).
     *
     * @param int $redirectId
     * @param string $originalURL
     * @param string $newURL
     * @param bool $urlRewritten
     * @return void
     */
    public function saveRegexAutoPromoteNotice($redirectId, $originalURL, $newURL, $urlRewritten): void {
        ABJ_404_Solution_RegexAutoPromote::saveNotice($redirectId, $originalURL, $newURL, $urlRewritten);
    }
}
