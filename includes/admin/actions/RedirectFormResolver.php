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

    /** @var ABJ_404_Solution_RegexDestinationTemplateValidator */
    private $regexDestinationValidator;

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
    public function __construct(
        $f,
        $logger,
        $urlNormalization,
        ?ABJ_404_Solution_RegexDestinationTemplateValidator $regexDestinationValidator = null
    ) {
        $this->f = $f;
        $this->logger = $logger;
        $this->urlNormalization = $urlNormalization;
        $this->regexDestinationValidator = $regexDestinationValidator !== null
            ? $regexDestinationValidator
            : new ABJ_404_Solution_RegexDestinationTemplateValidator($f);
    }

    /**
     * Parse the redirect destination field from $_POST.
     *
     * @param array{isRegex?: bool, sourcePattern?: string} $context
     * @return array<string, mixed> {type: string, dest: string, message: string}
     */
    public function getRedirectTypeAndDest(array $context = array()): array {

        $response = array();
        $response['type'] = "";
        $response['dest'] = "";
        $response['message'] = "";
        $isRegex = isset($context['isRegex']) && $context['isRegex'] === true;
        $sourcePattern = isset($context['sourcePattern']) && is_string($context['sourcePattern'])
            ? $context['sourcePattern'] : '';

        if ($isRegex && $sourcePattern !== '') {
            $sourceValidation = $this->regexDestinationValidator->validateSourcePattern($sourcePattern);
            if (!$sourceValidation['valid']) {
                $response['message'] = $this->regexValidationMessage($sourceValidation);
                return $response;
            }
        }

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
            $externalDestination = $this->resolveExternalDestination($isRegex, $sourcePattern);
            $response['type'] = ABJ404_TYPE_EXTERNAL;
            $response['dest'] = $externalDestination['dest'];
            $response['message'] = $externalDestination['message'];
            return $response;
        }

        $info = explode("|", sanitize_text_field($_POST['redirect_to_data_field_id']));
        if (count($info) == 2) {
            $response['dest'] = absint($info[0]);
            $response['type'] = $info[1];
        } else {
            $infoJson = json_encode($info);
            $this->logger->errorMessage("Unexpected info while updating redirect: " .
                    wp_kses_post(is_string($infoJson) ? $infoJson : ''));
        }

        return $response;
    }

    /**
     * @return array{dest: string, message: string}
     */
    private function resolveExternalDestination(bool $isRegex, string $sourcePattern): array {
        $rawPostedDestination = isset($_POST['redirect_to_user_field'])
            ? ABJ_404_Solution_RequestInputNormalizer::normalizeScalar($_POST['redirect_to_user_field'])
            : '';
        $rawEnteredURLResult = ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitizeUrl(
            'redirect_to_user_field'
        );
        $rawEnteredURL = is_string($rawEnteredURLResult) ? $rawEnteredURLResult : null;
        $normalizedDestination = $this->urlNormalization->normalizeExternalDestinationUrl($rawEnteredURL);
        $isRelativeRegexDestination = $isRegex
            && isset($rawPostedDestination[0])
            && $rawPostedDestination[0] === '/';

        if ($isRelativeRegexDestination) {
            $validation = $this->regexDestinationValidator->validate($sourcePattern, $rawPostedDestination);
            return array(
                'dest' => $normalizedDestination,
                'message' => $this->regexValidationMessage($validation),
            );
        }

        $absoluteDestination = $this->validateAbsoluteDestination($normalizedDestination);
        if ($absoluteDestination['message'] !== '' || !$isRegex) {
            return $absoluteDestination;
        }

        $validation = $this->regexDestinationValidator->validateReplacement(
            $sourcePattern,
            $rawPostedDestination
        );
        $absoluteDestination['message'] = $this->regexValidationMessage($validation);
        return $absoluteDestination;
    }

    /**
     * @return array{dest: string, message: string}
     */
    private function validateAbsoluteDestination(string $destination): array {
        $destination = esc_url($destination, array('http', 'https'));
        if ($destination === '') {
            return array(
                'dest' => '',
                'message' => __('Error: You selected external URL but did not enter a URL.', '404-solution') . "<BR/>",
            );
        }
        if ($this->f->strlen($destination) < 8) {
            return array(
                'dest' => $destination,
                'message' => __('Error: External URL is too short.', '404-solution') . "<BR/>",
            );
        }
        if ($this->f->strpos($destination, "://") === false) {
            return array(
                'dest' => $destination,
                'message' => __("Error: External URL doesn't contain ://", '404-solution') . "<BR/>",
            );
        }

        $parsedUrl = parse_url($destination);
        if (!is_array($parsedUrl) || !isset($parsedUrl['scheme'])
                || !in_array(strtolower($parsedUrl['scheme']), array('http', 'https'), true)) {
            return array(
                'dest' => $destination,
                'message' => __('Error: External URL must use http:// or https:// protocol only.', '404-solution') . "<BR/>",
            );
        }

        $validatedUrl = apply_filters('abj404_validate_external_redirect', $destination);
        if ($validatedUrl === false) {
            return array(
                'dest' => $destination,
                'message' => __('Error: External redirect URL failed validation.', '404-solution') . "<BR/>",
            );
        }

        return array('dest' => (string)$validatedUrl, 'message' => '');
    }

    /**
     * @param array{valid: bool, message: string, detail: string} $validation
     */
    private function regexValidationMessage(array $validation): string {
        if ($validation['valid']) {
            return '';
        }
        if ($validation['detail'] !== '') {
            $this->logger->warn('Regex redirect validation failed: ' . $validation['detail']);
        }
        return $validation['message'] . "<BR/>";
    }

    /**
     * Sanitize, classify, and normalize a redirect source without allowing
     * ordinary path normalization to alter regex syntax.
     *
     * @param int $statusTypeIn
     * @param string $fromURL
     * @return array{statusType: int, url: string, autoPromoted: bool, urlRewritten: bool}
     */
    public function resolveSource($statusTypeIn, $fromURL): array {
        $source = $this->urlNormalization->sanitizeRedirectSource($fromURL);
        $result = $this->maybeAutoPromoteRegex($statusTypeIn, $source);
        $result['url'] = $this->urlNormalization->normalizeRedirectSourceForStatus(
            $result['url'],
            $result['statusType']
        );
        return $result;
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
