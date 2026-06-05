<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classifies PHP error types for shutdown fatal handling.
 */
class ABJ_404_Solution_ErrorTypeClassifier {

    /**
     * @param int $type PHP error type value.
     * @return bool
     */
    public function isFatalType(int $type): bool {
        $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR);
        return in_array($type, $fatalTypes, true);
    }
}
