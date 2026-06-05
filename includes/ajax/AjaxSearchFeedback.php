<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds shared autocomplete filtering and feedback sentinel rows.
 */
class ABJ_404_Solution_Ajax_SearchFeedback {

    /**
     * @param string $message
     * @return array<int, array<string, string>>
     */
    public static function autocompleteErrorItem($message) {
        return array(
            array(
                'label' => '',
                'category' => (string)$message,
                'value' => '',
                'data_overflow_item' => 'true',
            ),
        );
    }

    /**
     * @param array<int, array<string, string>> $suggestions
     * @param string $term
     * @return array<int, array<string, string>>
     */
    public function provideSearchFeedback($suggestions, $term) {
        $category = '';

        if (empty($suggestions)) {
            if ($this->stringLength(trim($term)) == 0) {
                $category = sprintf(__("(No matching results found.)", '404-solution'));
            } else {
                $category = sprintf(__("(No matching results found for \"%s.\")", '404-solution'), $term);
            }
        } else if (count($suggestions) > ABJ404_MAX_AJAX_DROPDOWN_SIZE) {
            $suggestions = array_slice($suggestions, 0, ABJ404_MAX_AJAX_DROPDOWN_SIZE);
            if ($this->stringLength(trim($term)) == 0) {
                $category = sprintf(__("(Data truncated. Too many results!)", '404-solution'));
            } else {
                $category = sprintf(__("(Data truncated. Too many results for \"%s!\".)", '404-solution'), $term);
            }
        } else {
            if ($this->stringLength(trim($term)) == 0) {
                $category = sprintf(__("(All results displayed.)", '404-solution'));
            } else {
                $category = sprintf(__("(All results displayed for \"%s.\")", '404-solution'), $term);
            }
        }

        $suggestion = array();
        $suggestion['label'] = '';
        $suggestion['category'] = $category;
        $suggestion['value'] = '';
        $suggestion['data_overflow_item'] = 'true';
        $suggestions[] = $suggestion;

        return $suggestions;
    }

    /**
     * @param array<int, array<string, string>> $pagesToFilter
     * @param string $searchTerm
     * @return array<int, array<string, string>>
     */
    public function filterPages($pagesToFilter, $searchTerm) {
        if ($searchTerm == "") {
            return $pagesToFilter;
        }

        $newPagesList = array();

        foreach ($pagesToFilter as $page) {
            $haystack = $this->lowercase($page['label']);
            $haystack2 = $this->lowercase($page['category']);
            $needle = $this->lowercase($searchTerm);
            if ($this->contains($haystack, $needle)) {
                $newPagesList[] = $page;
            } else if ($this->contains($haystack2, $needle)) {
                $newPagesList[] = $page;
            }
        }

        return $newPagesList;
    }

    /** @return mixed */
    private function functionsService() {
        return ABJ_404_Solution_Ajax_ServiceResolver::required('functions');
    }

    private function stringLength(string $value): int {
        $service = $this->functionsService();
        $callback = is_object($service) ? array($service, 'strlen') : null;
        if (is_callable($callback)) {
            $result = call_user_func($callback, $value);
            return is_numeric($result) ? intval($result) : strlen($value);
        }
        return strlen($value);
    }

    private function lowercase(string $value): string {
        $service = $this->functionsService();
        $callback = is_object($service) ? array($service, 'strtolower') : null;
        if (is_callable($callback)) {
            $result = call_user_func($callback, $value);
            return is_scalar($result) ? (string)$result : strtolower($value);
        }
        return strtolower($value);
    }

    private function contains(string $haystack, string $needle): bool {
        $service = $this->functionsService();
        $callback = is_object($service) ? array($service, 'strpos') : null;
        if (is_callable($callback)) {
            return call_user_func($callback, $haystack, $needle) !== false;
        }
        return strpos($haystack, $needle) !== false;
    }
}
