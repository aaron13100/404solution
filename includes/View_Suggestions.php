<?php

// turn on debug for localhost etc
if (in_array($_SERVER['SERVER_NAME'], $GLOBALS['abj404_whitelist'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

class ABJ_404_Solution_View_Suggestions {

    /** 
     * @param array $options
     * @return string
     */
    function getAdminOptionsPage404Suggestions($options) {
        $f = ABJ_404_Solution_Functions::getInstance();
        
        // Suggested Alternatives Options
        $selectedSuggestCats = "";
        if ($options['suggest_cats'] == '1') {
            $selectedSuggestCats = " checked";
        }
        $selectedSuggestTags = "";
        if ($options['suggest_tags'] == '1') {
            $selectedSuggestTags = " checked";
        }
        
        // read the html content.
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/viewSuggestions.html");
        // do special replacements
        $html = str_replace('{SELECTED_SUGGEST_CATS}', $selectedSuggestCats, $html);
        $html = str_replace('{SELECTED_SUGGEST_TAGS}', $selectedSuggestTags, $html);
        $html = str_replace('{SUGGEST_MIN_SCORE}', esc_attr($options['suggest_minscore']), $html);
        $html = str_replace('{SUGGEST_MAX_SUGGESTIONS}', esc_attr($options['suggest_max']), $html);
        $html = str_replace('{SUGGEST_USER_TITLE}', esc_attr($options['suggest_title']), $html);
        $html = str_replace('{SUGGEST_USER_BEFORE}', esc_attr($options['suggest_before']), $html);
        $html = str_replace('{SUGGEST_USER_AFTER}', esc_attr($options['suggest_after']), $html);
        $html = str_replace('{SUGGEST_USER_ENTRY_BEFORE}', esc_attr($options['suggest_entrybefore']), $html);
        $html = str_replace('{SUGGEST_USER_ENTRY_AFTER}', esc_attr($options['suggest_entryafter']), $html);
        $html = str_replace('{SUGGEST_USER_NO_RESULTS}', esc_attr($options['suggest_noresults']), $html);
        // constants and translations.
        $html = $f->doNormalReplacements($html);
        
        return $html;
    }
    
}
