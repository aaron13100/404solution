<?php


if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/AjaxRequestContractValidator.php';

/**
 * AJAX adapter for saving plugin settings.
 */
class ABJ_404_Solution_Ajax_UpdateOptions {

    /** @return object */
    private static function getQueryStringHelperService() {
        $service = ABJ_404_Solution_Ajax_ServiceResolver::optional('query_string_helper');
        if (is_object($service) && is_callable(array($service, 'decodeComplicatedData'))) {
            return $service;
        }

        $fallback = abj_service('query_string_helper');
        if (is_object($fallback) && is_callable(array($fallback, 'decodeComplicatedData'))) {
            return $fallback;
        }

        throw new \RuntimeException('404 Solution query_string_helper service is unavailable.');
    }

    /**
     * @param object $service
     * @return mixed
     */
    private static function decodeComplicatedDataWithService($service, string $encodedData) {
        $callback = array($service, 'decodeComplicatedData');
        if (!is_callable($callback)) {
            throw new \RuntimeException('404 Solution query_string_helper service cannot decode request payloads.');
        }
        return call_user_func($callback, $encodedData);
    }

    /** @return array<mixed, mixed>|null */
    private static function decodeUpdateOptionsPayload() {
        if (!isset($_POST['encodedData']) || !is_scalar($_POST['encodedData'])) {
            ABJ_404_Solution_AjaxRequestContractValidator::requireValidPayload(
                'ajax-update-options',
                array()
            );
            return null;
        }

        // wp_magic_quotes() backslash-escapes the payload before any plugin
        // code sees it. general.js builds the field with encodeURI(), which
        // percent-encodes " and \\ but leaves ' literal, so every apostrophe a
        // user typed into a settings field arrives as \\'. Unslash at the
        // boundary rather than letting the decoder guess.
        $postData = self::decodeComplicatedDataWithService(
            self::getQueryStringHelperService(),
            ABJ_404_Solution_RequestInputNormalizer::normalizeScalar($_POST['encodedData'])
        );
        if (!is_array($postData) ||
                !ABJ_404_Solution_AjaxRequestContractValidator::requireValidLivePayload(
                    'ajax-update-options',
                    $postData
                )) {
            return null;
        }

        return $postData;
    }

    /**
     * @param array<mixed, mixed> $postData
     * @return string
     */
    private static function nonceFromDecodedPostData(array $postData) {
        $nonce = $postData['nonce'] ?? '';
        return is_string($nonce) ? $nonce : '';
    }

    /** @return void */
    public static function updateOptions() {
        $postData = self::decodeUpdateOptionsPayload();
        if ($postData === null) {
            return;
        }

        /** @var ABJ_404_Solution_PluginLogic $abj404logic */
        $abj404logic = ABJ_404_Solution_Ajax_ServiceResolver::required('plugin_logic');

        abj_service('ajax_security_gate')->requireAdminWithNonce(
            'abj404UpdateOptions',
            'nonce',
            array('nonce_value' => self::nonceFromDecodedPostData($postData))
        );

        $result = $abj404logic->settingsUpdate()->updateOptionsFromPOST();
        if (!is_array($result) || !array_key_exists('success', $result)) {
            $keys = implode(',', array_keys($result));
            wp_send_json_error(array('message' => 'Server error (handler result array missing "success" key; keys present: ' . $keys . ')'), 500);
            return; // @phpstan-ignore deadCode.unreachable
        }

        if (!$result['success']) {
            $statusRaw = array_key_exists('status', $result) ? $result['status'] : 400;
            $status = is_scalar($statusRaw) ? intval($statusRaw) : 400;
            $messageRaw = array_key_exists('message', $result) ? $result['message'] : 'Server error';
            $message = is_scalar($messageRaw) ? (string)$messageRaw : 'Server error';
            wp_send_json_error(array('message' => $message), $status);
            return; // @phpstan-ignore deadCode.unreachable
        }

        $data = array_key_exists('data', $result) ? $result['data'] : array();
        wp_send_json_success($data, 200);
    }
}
