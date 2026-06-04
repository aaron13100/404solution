<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Converts stored redirect matching engine class names into admin-facing labels.
 */
class ABJ_404_Solution_RedirectEngineLabeler {

    /**
     * Convert a raw engine class name to a human-readable label.
     *
     * @param string $rawName
     * @return string
     */
    public function humanize(string $rawName): string {
        $name = preg_replace('/^ABJ_404_Solution_/', '', $rawName);
        if (!is_string($name)) {
            $name = $rawName;
        }

        $name = (string)preg_replace('/MatchingEngine$/', ' Matching', $name);
        $name = (string)preg_replace('/Engine$/', '', $name);
        $name = (string)preg_replace('/(?<=[a-z])([A-Z])/', ' $1', $name);
        $name = trim($name);
        $name = str_replace(array('Url ', 'Url'), array('URL ', 'URL'), $name);
        $name = str_replace('Category Tag', 'Category/Tag', $name);

        return $name !== '' ? $name : $rawName;
    }
}
