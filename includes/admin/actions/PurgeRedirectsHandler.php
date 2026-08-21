<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles action 'purgeRedirects': parses the admin form request, delegates
 * the data mutation to the redirects repository, then renders the admin
 * result message.
 */
class ABJ_404_Solution_PurgeRedirectsHandler implements ABJ_404_Solution_AdminActionHandlerInterface {

    /** @var ABJ_404_Solution_PluginLogicAdminActions */
    private $parent;

    public function __construct(ABJ_404_Solution_PluginLogicAdminActions $parent) {
        $this->parent = $parent;
    }

    public function nonceAction(): string {
        return 'abj404_purgeRedirects';
    }

    public function nonceArg(): string {
        return '_wpnonce';
    }

    public function useCheckAdminReferer(): bool {
        return true;
    }

    public function handle(string $action, string &$sub): string {
        $request = $this->parseRequest();
        if ($request['status'] !== 'ok') {
            return $this->messageForStatus($request['status'], 0);
        }

        $result = $this->parent->getRedirectsRepo()->deleteSpecifiedRedirects(
            $request['types'],
            $request['purge_type']
        );

        $status = isset($result['status']) && is_string($result['status']) ? $result['status'] : 'unknown';
        $rowsAffected = isset($result['rows_affected']) && is_scalar($result['rows_affected'])
            ? (int)$result['rows_affected']
            : 0;

        return $this->messageForStatus($status, $rowsAffected);
    }

    /**
     * Parse and validate the purge form POST payload.
     *
     * @return array{status: string, types: array<int, int|string>, purge_type: string}
     */
    private function parseRequest(): array {
        if (!array_key_exists('sanity_purge', $_POST) || $_POST['sanity_purge'] != "1") {
            return array('status' => 'missing_sanity_purge', 'types' => array(), 'purge_type' => '');
        }

        if (!isset($_POST['types']) || $_POST['types'] == '') {
            return array('status' => 'missing_types', 'types' => array(), 'purge_type' => '');
        }

        $rawTypes = $_POST['types'];
        if (!is_array($rawTypes)) {
            return array('status' => 'unknown', 'types' => array(), 'purge_type' => '');
        }

        $types = array_map(static function($value): string {
            return sanitize_text_field(is_scalar($value) ? (string)$value : '');
        }, $rawTypes);
        $purgeType = ABJ_404_Solution_RequestInputNormalizer::readText(
            $_POST, array('name' => 'purgetype'));

        if ($purgeType != 'abj404_logs' && $purgeType != 'abj404_redirects') {
            $this->parent->getLogger()->debugMessage("Error: An invalid purge type was selected. Type: " .
                    wp_kses_post((string)json_encode($purgeType)));
            return array('status' => 'invalid_purge_type', 'types' => array(), 'purge_type' => '');
        }

        return array('status' => 'ok', 'types' => $types, 'purge_type' => $purgeType);
    }

    private function messageForStatus(string $status, int $rowsAffected): string {
        switch ($status) {
            case 'missing_sanity_purge':
                return __('Error: You didn\'t check the I understand checkbox. No purging of records for you!', '404-solution');
            case 'missing_types':
                return __('Error: No redirect types were selected. No purges will be done.', '404-solution');
            case 'unknown':
                return __('An unknown error has occurred.', '404-solution');
            case 'invalid_purge_type':
                return __('Error: An invalid purge type was selected. Exiting.', '404-solution');
            case 'no_valid_types':
                return __('Error: No valid redirect types were selected. Exiting.', '404-solution');
            case 'redirects_purged':
                return sprintf(_n('%s redirect entry was moved to the trash.',
                        '%s redirect entries were moved to the trash.', $rowsAffected, '404-solution'), $rowsAffected);
            case 'logs_only':
            case 'noop':
                return '';
        }

        return sprintf(__('Error: Unknown purge result: %s', '404-solution'), esc_html($status));
    }
}
