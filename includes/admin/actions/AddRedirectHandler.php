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

            $newRedirectId = $redirectsRepo->setupRedirect(ABJ_404_Solution_RedirectSpec::fromArray(array(
                'fromURL' => $manualURL,
                'status' => (string)$statusType,
                'type' => $tdType2,
                'finalDest' => $tdDest2,
                'code' => sanitize_text_field($code),
                'disabled' => 0,
            )));
            if ($autoPromoteAdd['autoPromoted']) {
                $this->resolver->saveRegexAutoPromoteNotice((int)$newRedirectId, $originalManualURL, $manualURL, $autoPromoteAdd['urlRewritten']);
            }

        } else {
            $message .= __('Error: Data not formatted properly.', '404-solution') . "<BR/>";
            $logger->errorMessage("Add redirect data issue. Type: " . esc_html($tdType2) . ", dest: " .
                    esc_html($tdDest2));
        }

        return $message;
    }
}
