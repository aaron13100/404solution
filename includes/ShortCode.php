<?php

// turn on debug for localhost etc
if (in_array($_SERVER['SERVER_NAME'], $GLOBALS['abj404_whitelist'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/* Functions in this class should only be for plugging into WordPress listeners (filters, actions, etc).  */

class ABJ_404_Solution_ShortCode {
    
    /** 
     * @param array $atts
     */
    static function shortcodePageSuggestions( $atts ) {
        $abj404logic = new ABJ_404_Solution_PluginLogic();
        $abj404spellChecker = new ABJ_404_Solution_SpellChecker();
        $f = ABJ_404_Solution_Functions::getInstance();
        
        // Attributes
        $atts = shortcode_atts(
                array(
                    ),
                $atts
            );

        $options = $abj404logic->getOptions();
        
        $content = "\n<!-- " . ABJ404_PP . " - Begin 404 suggestions. -->\n";

        // get the slug that caused the 404 from the session.
        $urlRequest = '';
        $cookieName = ABJ404_PP . '_REQUEST_URI';
        if (isset($_COOKIE[$cookieName]) && !empty($_COOKIE[$cookieName])) {
            $urlRequest = esc_url($f->regexReplace('\?.*', '', esc_url($_COOKIE[$cookieName])));
            // delete the cookie because the request was a one-time thing.
            // we use javascript to delete the cookie because the headers have already been sent.
            $content .= "<script> \n" .
                    "   var d = new Date(); \n" . 
                    "   d.setTime(d.getTime() - (60 * 5)); \n" .
                    '   var expires = "expires="+ d.toUTCString(); ' . "\n" . 
                    '   document.cookie = "' . $cookieName . '=;" + expires + ";path=/"; ' . "\n" .
                    "</script> \n";
        }
        if (array_key_exists(ABJ404_PP, $_REQUEST) && isset($_REQUEST[ABJ404_PP]) && 
                array_key_exists($cookieName, $_REQUEST[ABJ404_PP]) && isset($_REQUEST[ABJ404_PP][$cookieName])) {
            $urlRequest = $_REQUEST[ABJ404_PP][$cookieName];
        }
        
        if ($urlRequest == '') {
            // if no 404 was detected then we don't offer any suggestions
            return "<!-- " . ABJ404_PP . " - No 404 was detected. No suggestions to offer. -->\n";
        }
        
        $urlSlugOnly = $abj404logic->removeHomeDirectory($urlRequest);
        $permalinkSuggestionsPacket = $abj404spellChecker->findMatchingPosts($urlSlugOnly, 
                @$options['suggest_cats'], @$options['suggest_tags']);
        $permalinkSuggestions = $permalinkSuggestionsPacket[0];
        $rowType = $permalinkSuggestionsPacket[1];

        // allow some HTML.
        $content .= '<div class="suggest-404s">' . "\n";
        $content .= wp_kses_post($options['suggest_title']) . "\n";
        
        $currentSlug = $abj404logic->removeHomeDirectory(
                $f->regexReplace('\?.*', '', urldecode($_SERVER['REQUEST_URI'])));
        $displayed = 0;

        foreach ($permalinkSuggestions as $idAndType => $linkScore) {
            $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($idAndType, $linkScore, $rowType);

            // only display the suggestion if the score is high enough 
            // and if we're not currently on the page we're about to suggest.
            if ($permalink['score'] >= $options['suggest_minscore'] &&
                    basename($permalink['link']) != $currentSlug) {
                if ($displayed == 0) {
                    // <ol>
                    $content .= wp_kses_post($options['suggest_before']);
                }

                // <li>
                $content .= wp_kses_post($options['suggest_entrybefore']);
                
                $content .= "<a href=\"" . esc_url($permalink['link']) . "\" title=\"" . esc_attr($permalink['title']) . "\">" . esc_attr($permalink['title']) . "</a>";
                
                // display the score after the page link
                if (is_user_logged_in() && current_user_can('manage_options')) {
                    $content .= " (" . esc_html($permalink['score']) . ")";
                }
                
                // </li>
                $content .= wp_kses_post(@$options['suggest_entryafter']) . "\n";
                $displayed++;
                if ($displayed >= $options['suggest_max']) {
                    break;
                }
            } else {
                break;
            }
        }
        if ($displayed >= 1) {
            // </ol>
            $content .= wp_kses_post($options['suggest_after']) . "\n";
            
        } else {
            $content .= wp_kses_post($options['suggest_noresults']);
        }

        $content .= "\n</div>";
        $content .= "\n<!-- " . ABJ404_PP . " - End 404 suggestions for slug " . esc_html($urlSlugOnly) . " -->\n";

        return $content;
    }

}
add_shortcode('abj404_solution_page_suggestions', 'ABJ_404_Solution_ShortCode::shortcodePageSuggestions');
