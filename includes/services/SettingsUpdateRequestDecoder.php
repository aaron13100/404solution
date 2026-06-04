<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decodes and admits settings-save POST requests before any option mutation.
 *
 * This service owns the request-boundary concerns for the settings update
 * workflow: the encoded AJAX payload, malformed payload logging, nonce
 * verification, and admin-context gate.
 */
class ABJ_404_Solution_SettingsUpdateRequestDecoder {

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var callable|null */
    private $decoder;

    /**
     * @param ABJ_404_Solution_Logging $logger
     * @param callable|null $decoder Optional fn(string): mixed decoder for tests.
     */
    public function __construct($logger, $decoder = null) {
        $this->logger = $logger;
        $this->decoder = is_callable($decoder) ? $decoder : null;
    }

    /**
     * Decode a settings POST array into the actual form payload.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed> Either ['success' => true, 'postData' => array]
     *                              or a structured failure result compatible
     *                              with updateOptionsFromPOST().
     */
    public function decode(array $post): array {
        if (!isset($post['encodedData'])) {
            $this->logger->errorMessage('Missing encodedData in POST');
            return $this->failure(400, 'Missing form data');
        }

        $encodedData = $post['encodedData'];
        $encodedData = is_scalar($encodedData) ? (string)$encodedData : '';

        try {
            $postData = call_user_func($this->decoder(), $encodedData);
        } catch (\Throwable $e) {
            $this->logger->errorMessage('Invalid JSON encodedData in POST: ' . $e->getMessage());
            return $this->failure(400, 'Missing form data');
        }

        if (!is_array($postData)) {
            $this->logger->errorMessage('Invalid JSON encodedData in POST');
            return $this->failure(400, 'Missing form data');
        }

        $nonce = isset($postData['nonce']) && is_scalar($postData['nonce']) ? (string)$postData['nonce'] : '';
        if (!wp_verify_nonce($nonce, 'abj404UpdateOptions') || !is_admin()) {
            return $this->failure(403, 'Invalid security token');
        }

        return array(
            'success' => true,
            'postData' => $postData,
        );
    }

    /** @return callable */
    private function decoder() {
        if ($this->decoder !== null) {
            return $this->decoder;
        }
        $queryStringHelper = abj_service('query_string_helper');
        return array($queryStringHelper, 'decodeComplicatedData');
    }

    /**
     * @param int $status
     * @param string $message
     * @return array<string, mixed>
     */
    private function failure(int $status, string $message): array {
        return array(
            'success' => false,
            'status' => $status,
            'message' => $message,
        );
    }
}
