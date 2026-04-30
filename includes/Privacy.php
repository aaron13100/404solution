<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WordPress privacy integration (export/erase + privacy policy content).
 *
 * WordPress's privacy tools are keyed on email address, so we map email -> WP user -> user_login,
 * then export/anonymize plugin log rows associated with that username (via lookup table).
 */
class ABJ_404_Solution_Privacy {

    const EXPORTER_ID = 'abj404-solution';
    const ERASER_ID = 'abj404-solution';

    /** @return void */
    public static function init(): void {
        // Add privacy policy content in wp-admin.
        add_action('admin_init', array(__CLASS__, 'addPrivacyPolicyContent'));

        // Register exporter/eraser with WP privacy tools.
        add_filter('wp_privacy_personal_data_exporters', array(__CLASS__, 'registerExporter'));
        add_filter('wp_privacy_personal_data_erasers', array(__CLASS__, 'registerEraser'));
    }

    /** @return void */
    public static function addPrivacyPolicyContent(): void {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $content = '<p>' . esc_html__(
            '404 Solution logs 404 hits and redirects to help you diagnose broken links and configure redirects.',
            '404-solution'
        ) . '</p>';

        $content .= '<p>' . esc_html__(
            'Log entries may include requested URLs, referrers, timestamps, and an IP address. By default, IP addresses are anonymized; you can enable raw IP logging in the plugin settings.',
            '404-solution'
        ) . '</p>';

        $content .= '<p>' . esc_html__(
            'You can export or erase personal data via WordPress Tools > Export/Erase Personal Data.',
            '404-solution'
        ) . '</p>';

        wp_add_privacy_policy_content('404 Solution', wp_kses_post($content));
    }

    /**
     * @param array<string, mixed> $exporters
     * @return array<string, mixed>
     */
    public static function registerExporter(array $exporters): array {
        $exporters[self::EXPORTER_ID] = array(
            'exporter_friendly_name' => __('404 Solution Logs', '404-solution'),
            'callback' => array(__CLASS__, 'exporter'),
        );
        return $exporters;
    }

    /**
     * @param array<string, mixed> $erasers
     * @return array<string, mixed>
     */
    public static function registerEraser(array $erasers): array {
        $erasers[self::ERASER_ID] = array(
            'eraser_friendly_name' => __('404 Solution Logs', '404-solution'),
            'callback' => array(__CLASS__, 'eraser'),
        );
        return $erasers;
    }

    /** @return ABJ_404_Solution_DataAccess */
    private static function resolveDao() {
        if (function_exists('abj_service') && class_exists('ABJ_404_Solution_ServiceContainer')) {
            try {
                $c = ABJ_404_Solution_ServiceContainer::getInstance();
                if (is_object($c) && method_exists($c, 'has') && $c->has('data_access')) {
                    $svc = $c->get('data_access');
                    if ($svc instanceof ABJ_404_Solution_DataAccess) {
                        return $svc;
                    }
                }
            } catch (Throwable $e) {
                // fall back
            }
        }
        return abj_service('data_access');
    }

    /**
     * @param mixed $email_address
     * @return string|null
     */
    private static function getUsernameFromEmail($email_address) {
        if (!function_exists('get_user_by')) {
            return null;
        }
        $email_address = is_string($email_address) ? trim($email_address) : '';
        if ($email_address === '') {
            return null;
        }

        $user = get_user_by('email', $email_address);
        if (!is_object($user) || !isset($user->user_login)) {
            return null;
        }
        $username = (string)$user->user_login;
        return $username !== '' ? $username : null;
    }

    /**
     * @param string $email_address
     * @param int $page
     * @return array{data: array<int, mixed>, done: bool}
     */
    public static function exporter(string $email_address, int $page = 1): array {
        $username = self::getUsernameFromEmail($email_address);
        if ($username === null) {
            return array(
                'data' => array(),
                'done' => true,
            );
        }

        $dao = self::resolveDao();
        $perPage = 50;
        $page = max(1, absint($page));

        $rows = method_exists($dao, 'getLogsv2RowsForLookupValue')
            ? $dao->getLogsv2RowsForLookupValue($username, $page, $perPage)
            : array();

        $data = array();
        foreach ($rows as $row) {
            $rowIdRaw = isset($row['id']) ? $row['id'] : null;
            $rowId = (is_scalar($rowIdRaw) ? (string)$rowIdRaw : uniqid('', true));
            $tsRaw = isset($row['timestamp']) ? $row['timestamp'] : '';
            $urlRaw = isset($row['requested_url']) ? $row['requested_url'] : '';
            $destRaw = isset($row['dest_url']) ? $row['dest_url'] : '';
            $refRaw = isset($row['referrer']) ? $row['referrer'] : '';
            $ipRaw = isset($row['user_ip']) ? $row['user_ip'] : '';
            $data[] = array(
                'group_id' => 'abj404_solution_logs',
                'group_label' => __('404 Solution Logs', '404-solution'),
                'item_id' => 'abj404_log_' . $rowId,
                'data' => array(
                    array(
                        'name' => __('Timestamp', '404-solution'),
                        'value' => is_scalar($tsRaw) ? (string)$tsRaw : '',
                    ),
                    array(
                        'name' => __('Requested URL', '404-solution'),
                        'value' => is_scalar($urlRaw) ? (string)$urlRaw : '',
                    ),
                    array(
                        'name' => __('Destination URL', '404-solution'),
                        'value' => is_scalar($destRaw) ? (string)$destRaw : '',
                    ),
                    array(
                        'name' => __('Referrer', '404-solution'),
                        'value' => is_scalar($refRaw) ? (string)$refRaw : '',
                    ),
                    array(
                        'name' => __('IP Address', '404-solution'),
                        'value' => is_scalar($ipRaw) ? (string)$ipRaw : '',
                    ),
                ),
            );
        }

        $done = count((array)$rows) < $perPage;

        return array(
            'data' => $data,
            'done' => $done,
        );
    }

    /**
     * @param string $email_address
     * @param int $page
     * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
     */
    public static function eraser(string $email_address, int $page = 1): array {
        $username = self::getUsernameFromEmail($email_address);
        if ($username === null) {
            return array(
                'items_removed' => false,
                'items_retained' => false,
                'messages' => array(),
                'done' => true,
            );
        }

        $dao = self::resolveDao();
        $perPage = 100;
        $page = max(1, absint($page));

        $ids = method_exists($dao, 'getLogsv2IdsForLookupValue')
            ? $dao->getLogsv2IdsForLookupValue($username, $page, $perPage)
            : array();

        if (empty($ids)) {
            return array(
                'items_removed' => false,
                'items_retained' => false,
                'messages' => array(),
                'done' => true,
            );
        }

        $ok = method_exists($dao, 'anonymizeLogsv2RowsByIds')
            ? $dao->anonymizeLogsv2RowsByIds($ids)
            : false;

        $removed = $ok ? true : false;
        $retained = $ok ? false : true;

        return array(
            'items_removed' => $removed,
            'items_retained' => $retained,
            'messages' => $ok ? array() : array(__('Some items could not be anonymized.', '404-solution')),
            'done' => count($ids) < $perPage,
        );
    }
}

