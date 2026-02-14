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

    public static function init() {
        // Add privacy policy content in wp-admin.
        add_action('admin_init', array(__CLASS__, 'addPrivacyPolicyContent'));

        // Register exporter/eraser with WP privacy tools.
        add_filter('wp_privacy_personal_data_exporters', array(__CLASS__, 'registerExporter'));
        add_filter('wp_privacy_personal_data_erasers', array(__CLASS__, 'registerEraser'));
    }

    public static function addPrivacyPolicyContent() {
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

    public static function registerExporter($exporters) {
        $exporters[self::EXPORTER_ID] = array(
            'exporter_friendly_name' => __('404 Solution Logs', '404-solution'),
            'callback' => array(__CLASS__, 'exporter'),
        );
        return $exporters;
    }

    public static function registerEraser($erasers) {
        $erasers[self::ERASER_ID] = array(
            'eraser_friendly_name' => __('404 Solution Logs', '404-solution'),
            'callback' => array(__CLASS__, 'eraser'),
        );
        return $erasers;
    }

    private static function resolveDao() {
        if (function_exists('abj_service') && class_exists('ABJ_404_Solution_ServiceContainer')) {
            try {
                $c = ABJ_404_Solution_ServiceContainer::getInstance();
                if (is_object($c) && method_exists($c, 'has') && $c->has('data_access')) {
                    return $c->get('data_access');
                }
            } catch (Throwable $e) {
                // fall back
            }
        }
        return ABJ_404_Solution_DataAccess::getInstance();
    }

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

    public static function exporter($email_address, $page = 1) {
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
        foreach ((array)$rows as $row) {
            $data[] = array(
                'group_id' => 'abj404_solution_logs',
                'group_label' => __('404 Solution Logs', '404-solution'),
                'item_id' => 'abj404_log_' . (isset($row['id']) ? $row['id'] : uniqid('', true)),
                'data' => array(
                    array(
                        'name' => __('Timestamp', '404-solution'),
                        'value' => isset($row['timestamp']) ? (string)$row['timestamp'] : '',
                    ),
                    array(
                        'name' => __('Requested URL', '404-solution'),
                        'value' => isset($row['requested_url']) ? (string)$row['requested_url'] : '',
                    ),
                    array(
                        'name' => __('Destination URL', '404-solution'),
                        'value' => isset($row['dest_url']) ? (string)$row['dest_url'] : '',
                    ),
                    array(
                        'name' => __('Referrer', '404-solution'),
                        'value' => isset($row['referrer']) ? (string)$row['referrer'] : '',
                    ),
                    array(
                        'name' => __('IP Address', '404-solution'),
                        'value' => isset($row['user_ip']) ? (string)$row['user_ip'] : '',
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

    public static function eraser($email_address, $page = 1) {
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

