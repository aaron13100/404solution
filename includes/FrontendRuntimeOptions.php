<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads frontend runtime options and normalizes malformed provider responses.
 */
class ABJ_404_Solution_FrontendRuntimeOptions {

    /**
     * @param bool $skipDbCheck
     * @return array<string, mixed>
     */
    function get(bool $skipDbCheck = false): array {
        $options = abj_service('options_repository')->getOptions($skipDbCheck);
        return is_array($options) ? $options : array();
    }
}
