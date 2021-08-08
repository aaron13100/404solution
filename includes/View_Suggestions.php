<?php

class ABJ_404_Solution_View_Suggestions {

	private static $instance = null;
	
	public static function getInstance() {
		if (self::$instance == null) {
			self::$instance = new ABJ_404_Solution_View_Suggestions();
		}
		
		return self::$instance;
	}
	
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
        $selectedSuggestURL = "";
        if ($options['update_suggest_url'] == '1') {
        	$selectedSuggestURL = " checked";
        }
        
        
        // read the html content.
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/viewSuggestions.html");
        // do special replacements
        $html = $f->str_replace('{SELECTED_SUGGEST_CATS}', $selectedSuggestCats, $html);
        $html = $f->str_replace('{SELECTED_SUGGEST_TAGS}', $selectedSuggestTags, $html);
        $html = $f->str_replace('{SELECTED_SUGGEST_URL}', $selectedSuggestURL, $html);
        $html = $f->str_replace('{SUGGEST_MIN_SCORE}', esc_attr($options['suggest_minscore']), $html);
        $html = $f->str_replace('{SUGGEST_MAX_SUGGESTIONS}', esc_attr($options['suggest_max']), $html);
        $html = $f->str_replace('{SUGGEST_USER_TITLE}', esc_attr($options['suggest_title']), $html);
        $html = $f->str_replace('{SUGGEST_USER_BEFORE}', esc_attr($options['suggest_before']), $html);
        $html = $f->str_replace('{SUGGEST_USER_AFTER}', esc_attr($options['suggest_after']), $html);
        $html = $f->str_replace('{SUGGEST_USER_ENTRY_BEFORE}', esc_attr($options['suggest_entrybefore']), $html);
        $html = $f->str_replace('{SUGGEST_USER_ENTRY_AFTER}', esc_attr($options['suggest_entryafter']), $html);
        $html = $f->str_replace('{SUGGEST_USER_NO_RESULTS}', esc_attr($options['suggest_noresults']), $html);
        // constants and translations.
        $html = $f->doNormalReplacements($html);
        
        return $html;
    }
    
}
