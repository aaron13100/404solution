<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @param array<string, mixed> $value
 * @return array<string, mixed>
 */
return function (array $value): array {
    $defaults = ABJ_404_Solution_StorageOptionContracts::defaultUninstallPreferences();
    $value = array_merge($defaults, $value);

    if ($value['followup_details'] === '' && is_string($value['feedback_details'])) {
        $value['followup_details'] = $value['feedback_details'];
    }

    $value['_schemaVersion'] = 2;
    return $value;
};
