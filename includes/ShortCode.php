<?php

/* Functions in this class should only be for plugging into WordPress listeners (filters, actions, etc).  */

class ABJ_404_Solution_ShortCode {
    
	private static $instance = null;
	
	public static function getInstance() {
		if (self::$instance == null) {
			self::$instance = new ABJ_404_Solution_ShortCode();
		}
		
		return self::$instance;
	}
	
	/** If we're currently redirecting to a custom 404 page and we are about to show page
	 * suggestions then update the URL displayed to the user. */
	static function updateURLbarIfNecessary() {
		$abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
		$f = ABJ_404_Solution_Functions::getInstance();
		$abj404logging = ABJ_404_Solution_Logging::getInstance();
		$debugMessage = '';
        $options = $abj404logic->getOptions();
		
		$shouldUpdateURL = true;
		// if we're not supposed to update the URL then don't.
		if (!array_key_exists('update_suggest_url', $options) ||
				!isset($options['update_suggest_url']) ||
				$options['update_suggest_url'] != 1) {
			$shouldUpdateURL = false;
			$debugMessage .= "do not update (update_suggest_url is off), ";
		}

		// if the cookie we need isn't set then give up.
		$updateURLCookieName = ABJ404_PP . '_REQUEST_URI';
		$updateURLCookieName .= '_UPDATE_URL';
		if (!isset($_REQUEST[$updateURLCookieName]) || empty($_REQUEST[$updateURLCookieName])) {
			$shouldUpdateURL = false;
			$debugMessage .= "do not update (no cookie found), ";
		}

		$dest404page = (array_key_exists('dest404page', $options) && isset($options['dest404page']) ?
			$options['dest404page'] :
			ABJ404_TYPE_404_DISPLAYED . '|' . ABJ404_TYPE_404_DISPLAYED);
		
		// if we're not currently loading the custom 404 page then don't change the URL.
		if ($abj404logic->thereIsAUserSpecified404Page($dest404page)) {
			
			// get the user specified 404 page.
			$permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($dest404page, 0,
				null, $options);
			
			// if the last part of the URL does not match the custom 404 page then
			// don't update the URL.
			if (!$f->endsWithCaseSensitive($permalink['link'], $_SERVER['REQUEST_URI']) &&
					$permalink['status'] != 'trash') {
						
				$shouldUpdateURL = false;
				$debugMessage .= "do not update (not on custom 404 page (" .
					$permalink['link'] . ")), ";
				
			} else {
				$debugMessage .= "ok to update (displaying custom 404 page (" . 
					$permalink['link'] . ")), ";
			}
		} else {
			// the 404 page is the default 404 page. so we shouldn't change the URL.
			$shouldUpdateURL = false;
			$debugMessage .= "do not update (no custom 404 page specified), ";
		}
		
		$content = '';
		
		if ($shouldUpdateURL) {
			// replace the current URL with the user's actual requested URL.
			$requestedURL = $_REQUEST[$updateURLCookieName];
			$userFriendlyURL = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ?
				"https" : "http") . "://" . $_SERVER['HTTP_HOST'] . $requestedURL;
			
			$content .= "window.history.replaceState({}, null, '" .
				$userFriendlyURL . "');\n";
			
			$debugMessage .= "Updating the URL from " . $_SERVER['REQUEST_URI'] .
				" to " . $userFriendlyURL . ", ";
		}
		
		if ($content != '') {
			$content = '<script language="JavaScript">' . "\n" . 
				$content .
				"\n</script>\n\n";
			echo $content;
		}
		
		$debugMessage .= "is404: " . is_404() . ", " . 
			esc_html('auto_redirects: ' . $options['auto_redirects'] . ', auto_score: ' .
			$options['auto_score'] . ', template_redirect_priority: ' . $options['template_redirect_priority'] .
            ', auto_cats: ' . $options['auto_cats'] . ', auto_tags: ' .
			$options['auto_tags'] . ', dest404page: ' . $options['dest404page']) . ", ";
		
		$debugMessage .= "is_single(): " . is_single() . " | " . "is_page(): " . is_page() .
			" | is_feed(): " . is_feed() . " | is_trackback(): " . is_trackback() . " | is_preview(): " .
			is_preview();
		
		$abj404logging->debugMessage("updateURLbarIfNecessary: " . $debugMessage);
	}
	
	/** 
     * @param array $atts
     */
    static function shortcodePageSuggestions( $atts ) {
        $abj404logic = ABJ_404_Solution_PluginLogic::getInstance();
        $abj404spellChecker = ABJ_404_Solution_SpellChecker::getInstance();
        $f = ABJ_404_Solution_Functions::getInstance();
        $abj404dao = ABJ_404_Solution_DataAccess::getInstance();
        
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
        
        // we delete the UPDATE_URL cookie here, where the shortcode is used so that it won't
        // get deleted too early if multiple redirects happen.
        $updateURLCookieName = ABJ404_PP . '_REQUEST_URI';
        $updateURLCookieName .= '_UPDATE_URL';
        if (isset($_COOKIE[$updateURLCookieName]) && !empty($_COOKIE[$updateURLCookieName])) {
        	// delete the cookie since we're done with it. it's a one-time use thing.
        	$content .= "<script> \n" .	
         	"   var d = new Date(); /* delete the cookie */\n" .
         	"   d.setTime(d.getTime() - (60 * 5)); \n" .
         	'   var expires = "expires="+ d.toUTCString(); ' . "\n" .
         	'   document.cookie = "' . $updateURLCookieName . '=;" + expires + ";path=/"; ' .
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

        $showExtraAdminData = (is_user_logged_in() && $abj404logic->userIsPluginAdmin());
        $extraData = null;
        $extraDataById = []; // <--- New: Array to hold extra data indexed by ID
        $adminDebugData = []; // <--- New: Array to collect data for JS
        
        if ($showExtraAdminData) {
            // add extra information to the permalinkSuggestionsPacket. for each permalink,
            // retrieve the post_type, taxonomy, post_author (this is an id not a name), 
            // post_date, post_name (this is the slug), 
            $postIDs = array_keys($permalinkSuggestions);
            if (!empty($postIDs)) {
                // for each id remove the part after '|' using substring
                foreach ($postIDs as $index => $id) {
                    $postIDs[$index] = $f->substr($id, 0, $f->strpos($id, '|'));
                }
                
                $rawExtraData = $abj404dao->getExtraDataToPermalinkSuggestions($postIDs); 
                foreach ($rawExtraData as $dataItem) {
                    $extraDataById['post_id_' . $dataItem['post_id']] = $dataItem;
                    $extraDataById['term_id_' . $dataItem['term_id']] = $dataItem;
                }
            }
        }

        // allow some HTML.
        $content .= '<div class="suggest-404s">' . "\n";
        $content .= wp_kses_post($options['suggest_title']) . "\n";
        
        $currentSlug = $abj404logic->removeHomeDirectory(
                $f->regexReplace('\?.*', '', urldecode($_SERVER['REQUEST_URI'])));
        $displayed = 0;
        $commentPartAndQueryPart = $abj404logic->getCommentPartAndQueryPartOfRequest();

        foreach ($permalinkSuggestions as $idAndType => $linkScore) {
            $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($idAndType, $linkScore, 
            	$rowType, $options);

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
                
                $content .= "<a href=\"" . esc_url($permalink['link']) . $commentPartAndQueryPart .
                	"\" title=\"" . esc_attr($permalink['title']) . "\">" . 
                	esc_attr($permalink['title']) . "</a>";
                
                // display the score after the page link
                	
                if ($showExtraAdminData) {
                    $idParts = explode('|', $idAndType);
                    $currentId = isset($idParts[0]) ? (int)$idParts[0] : null;
                    $typeCode  = isset($idParts[1]) ? $idParts[1] : null;

                    $currentSuggestionData = [
                        'Title' => $permalink['title'],
                        'Link' => $permalink['link'],
                        'Score' => number_format($permalink['score'], 2),
                        'ID_Type_Code' => $idAndType, // e.g., "123|1" or "94|2"
                    ];

                    // Extract ID for lookup
                    $idParts = explode('|', $idAndType);
                    $currentId = isset($idParts[0]) ? $idParts[0] : null;

                    if ($typeCode == '1') { // It's a Post
                        $currentSuggestionData = $currentSuggestionData + $extraDataById['post_id_' . $currentId]; 
                    } else { // It's a Term
                        $currentSuggestionData = $currentSuggestionData + $extraDataById['term_id_' . $currentId]; 
                    }

                    // Add this suggestion's data to the array for JS
                    $adminDebugData[] = $currentSuggestionData;
                    
                    // Make the score clickable
                    $content .= ' (<a href="#" onclick="show404AdminDebugData(); return false;" title="' . 
                                esc_attr__('Click to view debug data for all suggestions', '404-solution') . 
                                '">' . number_format($permalink['score'], 2) . // Format score
                                '</a>)'; 
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

        if ($showExtraAdminData && !empty($adminDebugData)) {
            // Ensure the JSON is properly encoded and escaped for JavaScript
            $allSuggestionsJson = wp_json_encode($adminDebugData);
            if ($allSuggestionsJson === false) {
                // Handle encoding error
                $allSuggestionsJson = '[]';
            }

            $content .= "<script type=\"text/javascript\">\n";
            $content .= "var abj404_suggestionData = " . $allSuggestionsJson . ";\n";
            $content .= "function show404AdminDebugData() {\n";
            $content .= "    var debugText = 'Suggestion Debug Data:\\n====================\\n\\n';\n";
            $content .= "    if (typeof abj404_suggestionData !== 'undefined' && abj404_suggestionData.length > 0) {\n";
            $content .= "        for (var i = 0; i < abj404_suggestionData.length; i++) {\n";
            $content .= "            var item = abj404_suggestionData[i];\n";
            $content .= "            debugText += 'Suggestion #' + (i + 1) + ':\\n';\n";
            $content .= "            for (var key in item) {\n";
            $content .= "                if (item.hasOwnProperty(key) && item[key]) {\n";
            $content .= "                    // Only include properties that have values\n";
            $content .= "                    // Format the key for display (capitalize first letter)\n";
            $content .= "                    var displayKey = key;\n";
            $content .= "                    // Escape any potentially harmful content using text nodes\n";
            $content .= "                    debugText += '  ' + displayKey + ': ' + String(item[key]).replace(/</g, '&lt;').replace(/>/g, '&gt;') + '\\n';\n";
            $content .= "                }\n";
            $content .= "            }\n";
            $content .= "            debugText += '--------------------\\n';\n";
            $content .= "        }\n";
            $content .= "    } else {\n";
            $content .= "        debugText += 'No suggestion data collected.';\n";
            $content .= "    }\n";
            $content .= "    \n";
            $content .= "    // Create a modal dialog with copyable text\n";
            $content .= "    var modalOverlay = document.createElement('div');\n";
            $content .= "    modalOverlay.style.position = 'fixed';\n";
            $content .= "    modalOverlay.style.top = '0';\n";
            $content .= "    modalOverlay.style.left = '0';\n";
            $content .= "    modalOverlay.style.width = '100%';\n";
            $content .= "    modalOverlay.style.height = '100%';\n";
            $content .= "    modalOverlay.style.backgroundColor = 'rgba(0,0,0,0.5)';\n";
            $content .= "    modalOverlay.style.zIndex = '9999';\n";
            $content .= "    \n";
            $content .= "    var modalContent = document.createElement('div');\n";
            $content .= "    modalContent.style.position = 'absolute';\n";
            $content .= "    modalContent.style.top = '50%';\n";
            $content .= "    modalContent.style.left = '50%';\n";
            $content .= "    modalContent.style.transform = 'translate(-50%, -50%)';\n";
            $content .= "    modalContent.style.backgroundColor = 'white';\n";
            $content .= "    modalContent.style.padding = '20px';\n";
            $content .= "    modalContent.style.borderRadius = '5px';\n";
            $content .= "    modalContent.style.maxWidth = '80%';\n";
            $content .= "    modalContent.style.maxHeight = '80%';\n";
            $content .= "    modalContent.style.overflow = 'auto';\n";
            $content .= "    \n";
            $content .= "    var textArea = document.createElement('textarea');\n";
            $content .= "    textArea.style.width = '100%';\n";
            $content .= "    textArea.style.height = '300px';\n";
            $content .= "    textArea.style.marginBottom = '10px';\n";
            $content .= "    // Set value safely using textContent\n"; 
            $content .= "    textArea.value = debugText;\n";
            $content .= "    textArea.readOnly = true;\n";
            $content .= "    \n";
            $content .= "    var copyButton = document.createElement('button');\n";
            $content .= "    // Using textContent instead of innerHTML\n";
            $content .= "    copyButton.textContent = 'Copy to Clipboard';\n";
            $content .= "    copyButton.style.marginRight = '10px';\n";
            $content .= "    copyButton.onclick = function() {\n";
            $content .= "        textArea.select();\n";
            $content .= "        document.execCommand('copy');\n";
            $content .= "    };\n";
            $content .= "    \n";
            $content .= "    var closeButton = document.createElement('button');\n";
            $content .= "    // Using textContent instead of innerHTML\n";
            $content .= "    closeButton.textContent = 'Close';\n";
            $content .= "    closeButton.onclick = function() {\n";
            $content .= "        document.body.removeChild(modalOverlay);\n";
            $content .= "    };\n";
            $content .= "    \n";
            $content .= "    modalContent.appendChild(textArea);\n";
            $content .= "    modalContent.appendChild(copyButton);\n";
            $content .= "    modalContent.appendChild(closeButton);\n";
            $content .= "    modalOverlay.appendChild(modalContent);\n";
            $content .= "    document.body.appendChild(modalOverlay);\n";
            $content .= "}\n";
            $content .= "</script>\n";
        }        

        $content .= "\n<!-- " . ABJ404_PP . " - End 404 suggestions for slug " . esc_html($urlSlugOnly) . " -->\n";

        return $content;
    }

}
add_shortcode('abj404_solution_page_suggestions', 'ABJ_404_Solution_ShortCode::shortcodePageSuggestions');
