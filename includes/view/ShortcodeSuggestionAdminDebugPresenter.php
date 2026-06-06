<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Presents admin-only debug data for frontend shortcode suggestions.
 */
class ABJ_404_Solution_ShortcodeSuggestionAdminDebugPresenter {

    /**
     * @param float $score
     * @return string
     */
    public function renderScoreLink(float $score): string {
        return $this->fillTemplate('shortcodeAdminDebugScoreLink.html', array(
            'title' => esc_attr__('Click to view debug data for all suggestions', '404-solution'),
            'score' => number_format($score, 2),
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $adminDebugData
     * @return string
     */
    public function renderScript(array $adminDebugData): string {
        $allSuggestionsJson = wp_json_encode($adminDebugData);
        if ($allSuggestionsJson === false) {
            $allSuggestionsJson = '[]';
        }
        $jsContent = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/js/suggestion-debug-modal.js');
        return $this->fillTemplate('shortcodeAdminDebugScript.html', array(
            'json' => $allSuggestionsJson,
            'modal_script' => $jsContent,
        ));
    }

    /**
     * @param array<int|string, mixed> $permalinkSuggestions
     * @param mixed $viewReadService
     * @param mixed $functions
     * @return array<string, array<string, mixed>>
     */
    public function collectExtraData(array $permalinkSuggestions, $viewReadService, $functions): array {
        $extraDataById = [];
        $postIDs = array_keys($permalinkSuggestions);
        if (empty($postIDs) || !is_object($viewReadService) || !method_exists($viewReadService, 'getExtraDataToPermalinkSuggestions')) {
            return $extraDataById;
        }
        foreach ($postIDs as $index => $id) {
            $idStr = is_string($id) ? $id : (string)$id;
            $pipePos = is_object($functions) && method_exists($functions, 'strpos') ? $functions->strpos($idStr, '|') : strpos($idStr, '|');
            $postIDs[$index] = is_object($functions) && method_exists($functions, 'substr')
                ? $functions->substr($idStr, 0, $pipePos !== false ? $pipePos : null)
                : substr($idStr, 0, $pipePos !== false ? $pipePos : null);
        }

        $rawExtraData = $viewReadService->getExtraDataToPermalinkSuggestions($postIDs);
        foreach ($rawExtraData as $dataItem) {
            if (!is_array($dataItem)) {
                continue;
            }
            $postIdRaw = isset($dataItem['post_id']) ? $dataItem['post_id'] : '';
            $termIdRaw = isset($dataItem['term_id']) ? $dataItem['term_id'] : '';
            $postIdVal = is_scalar($postIdRaw) ? (string)$postIdRaw : '';
            $termIdVal = is_scalar($termIdRaw) ? (string)$termIdRaw : '';
            $extraDataById['post_id_' . $postIdVal] = $dataItem;
            $extraDataById['term_id_' . $termIdVal] = $dataItem;
        }
        return $extraDataById;
    }

    /**
     * @param string $idAndTypeStr
     * @param string $permTitle
     * @param float $permScore
     * @param string $permLink
     * @param array<string, array<string, mixed>> $extraDataById
     * @return array<string, mixed>
     */
    public function buildItemData(string $idAndTypeStr, string $permTitle, float $permScore,
            string $permLink, array $extraDataById): array {
        $currentSuggestionData = [
            'Title' => $permTitle,
            'Link' => $permLink,
            'Score' => number_format($permScore, 2),
            'ID_Type_Code' => $idAndTypeStr,
        ];

        $idParts = explode('|', $idAndTypeStr);
        $currentId = isset($idParts[0]) ? $idParts[0] : null;
        $typeCode = isset($idParts[1]) ? $idParts[1] : null;

        if ($typeCode == '1') {
            $extraKey = 'post_id_' . $currentId;
            if (isset($extraDataById[$extraKey])) {
                $currentSuggestionData = $currentSuggestionData + $extraDataById[$extraKey];
            }
        } else {
            $extraKey = 'term_id_' . $currentId;
            if (isset($extraDataById[$extraKey])) {
                $currentSuggestionData = $currentSuggestionData + $extraDataById[$extraKey];
            }
        }

        return $currentSuggestionData;
    }

    /**
     * @param string $name
     * @param array<string, string> $vars
     * @return string
     */
    private function fillTemplate(string $name, array $vars): string {
        $template = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/' . $name, false);
        $search = [];
        $replace = [];
        foreach ($vars as $key => $value) {
            $search[] = '{' . $key . '}';
            $replace[] = $value;
        }
        return str_replace($search, $replace, $template);
    }
}
