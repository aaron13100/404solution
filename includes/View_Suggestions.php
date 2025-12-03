<?php

class ABJ_404_Solution_View_Suggestions {

	private static $instance = null;

	/** @var ABJ_404_Solution_Functions */
	private $f;

	/**
	 * Constructor with dependency injection.
	 *
	 * @param ABJ_404_Solution_Functions|null $functions String utilities
	 */
	public function __construct($functions = null) {
		$this->f = $functions !== null ? $functions : ABJ_404_Solution_Functions::getInstance();
	}

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
        $selectedSuggestMinscoreEnabled = "";
        if (isset($options['suggest_minscore_enabled']) && $options['suggest_minscore_enabled'] == '1') {
        	$selectedSuggestMinscoreEnabled = " checked";
        }


        // read the html content.
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/viewSuggestions.html");
        // do special replacements
        $html = $this->f->str_replace('{SELECTED_SUGGEST_CATS}', $selectedSuggestCats, $html);
        $html = $this->f->str_replace('{SELECTED_SUGGEST_TAGS}', $selectedSuggestTags, $html);
        $html = $this->f->str_replace('{SELECTED_SUGGEST_MINSCORE_ENABLED}', $selectedSuggestMinscoreEnabled, $html);
        $html = $this->f->str_replace('{SELECTED_SUGGEST_URL}', $selectedSuggestURL, $html);
        $html = $this->f->str_replace('{SUGGEST_MAX_SUGGESTIONS}', esc_attr($options['suggest_max']), $html);
        $html = $this->f->str_replace('{SUGGEST_USER_TITLE}', esc_attr($options['suggest_title']), $html);
        $html = $this->f->str_replace('{SUGGEST_USER_BEFORE}', esc_attr($options['suggest_before']), $html);
        $html = $this->f->str_replace('{SUGGEST_USER_AFTER}', esc_attr($options['suggest_after']), $html);
        $html = $this->f->str_replace('{SUGGEST_USER_ENTRY_BEFORE}', esc_attr($options['suggest_entrybefore']), $html);
        $html = $this->f->str_replace('{SUGGEST_USER_ENTRY_AFTER}', esc_attr($options['suggest_entryafter']), $html);
        $html = $this->f->str_replace('{SUGGEST_USER_NO_RESULTS}', esc_attr($options['suggest_noresults']), $html);
        // constants and translations.
        $html = $this->f->doNormalReplacements($html);
        
        return $html;
    }
    
}
