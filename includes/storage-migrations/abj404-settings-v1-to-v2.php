<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @param array<string, mixed> $value
 * @return array<string, mixed>
 */
return function (array $value): array {
    if (!array_key_exists('admin_notification_frequency', $value) || !is_string($value['admin_notification_frequency'])) {
        $value['admin_notification_frequency'] = 'instant';
    }

    if (!array_key_exists('admin_notification_digest_limit', $value)) {
        $value['admin_notification_digest_limit'] = '10';
    }

    if (!array_key_exists('dest404_behavior', $value) || !is_string($value['dest404_behavior'])) {
        $value['dest404_behavior'] = 'theme_default';
    }

    $value['_schemaVersion'] = 2;
    return $value;
};
