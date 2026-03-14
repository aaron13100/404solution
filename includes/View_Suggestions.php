<?php


if (!defined('ABSPATH')) {
    exit;
}

class ABJ_404_Solution_View_Suggestions {

	/** @var self|null */
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

	/** @return self */
	public static function getInstance() {
		if (self::$instance == null) {
			self::$instance = new ABJ_404_Solution_View_Suggestions();
		}

		return self::$instance;
	}

	/**
     * @param array<string, mixed> $options
     * @return string
     */
    function getAdminOptionsPage404Suggestions($options) {
        $options = array_merge(array(
            'suggest_cats' => '0',
            'suggest_tags' => '0',
            'update_suggest_url' => '0',
            'suggest_max' => '5',
            'suggest_title' => '',
            'suggest_before' => '',
            'suggest_after' => '',
            'suggest_entrybefore' => '',
            'suggest_entryafter' => '',
            'suggest_noresults' => '',
        ), $options);
        
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
        $sugMax = $options['suggest_max'];
        $sugTitle = $options['suggest_title'];
        $sugBefore = $options['suggest_before'];
        $sugAfter = $options['suggest_after'];
        $sugEntryBefore = $options['suggest_entrybefore'];
        $sugEntryAfter = $options['suggest_entryafter'];
        $sugNoResults = $options['suggest_noresults'];
        $html = $this->f->str_replace('{SUGGEST_MAX_SUGGESTIONS}', esc_attr(is_scalar($sugMax) ? (string)$sugMax : ''), $html);
        $html = $this->f->str_replace('{SUGGEST_USER_TITLE}', esc_attr(is_scalar($sugTitle) ? (string)$sugTitle : ''), $html);
        $html = $this->f->str_replace('{SUGGEST_USER_BEFORE}', esc_attr(is_scalar($sugBefore) ? (string)$sugBefore : ''), $html);
        $html = $this->f->str_replace('{SUGGEST_USER_AFTER}', esc_attr(is_scalar($sugAfter) ? (string)$sugAfter : ''), $html);
        $html = $this->f->str_replace('{SUGGEST_USER_ENTRY_BEFORE}', esc_attr(is_scalar($sugEntryBefore) ? (string)$sugEntryBefore : ''), $html);
        $html = $this->f->str_replace('{SUGGEST_USER_ENTRY_AFTER}', esc_attr(is_scalar($sugEntryAfter) ? (string)$sugEntryAfter : ''), $html);
        $html = $this->f->str_replace('{SUGGEST_USER_NO_RESULTS}', esc_attr(is_scalar($sugNoResults) ? (string)$sugNoResults : ''), $html);
        // constants and translations.
        $html = $this->f->doNormalReplacements($html);
        
        return $html;
    }
    
}
