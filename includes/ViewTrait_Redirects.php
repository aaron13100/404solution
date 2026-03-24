<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Edit redirect page and destination option helpers.
 */
trait ViewTrait_Redirects {

    /** @return void */
    function echoAdminEditRedirectPage() {

        $options = $this->getOptionsWithDefaults();

        // Compute source page early so we can use it in the back link
        $source_page = $this->dao->getPostOrGetSanitize('source_page');
        if ($source_page === '') {
            $source_page = $this->dao->getPostOrGetSanitize('subpage');
        }
        if ($source_page === '' || $source_page == 'abj404_edit') {
            $source_page = 'abj404_redirects';
        }
        $backUrl = '?page=' . ABJ404_PP . '&subpage=' . esc_attr($source_page);

        // Modern page container
        echo '<div class="abj404-edit-page">';
        echo '<div class="abj404-edit-container">';

        // Header row: title + back link
        echo '<div class="abj404-edit-page-header">';
        echo '<h2>' . esc_html__('Edit Redirect', '404-solution') . '</h2>';
        echo '<a href="' . esc_url($backUrl) . '" class="abj404-back-link">&#8592; ' . esc_html__('Back to Redirects', '404-solution') . '</a>';
        echo '</div>';

        $link = wp_nonce_url("?page=" . ABJ404_PP . "&subpage=abj404_edit", "abj404editRedirect");

        echo '<form method="POST" name="admin-edit-redirect" action="' . esc_attr($link) . '" onsubmit="return validateAddManualRedirectForm(event);">';
        echo "<input type=\"hidden\" name=\"action\" value=\"editRedirect\">";

        // Preserve source page for return navigation
        echo "<input type=\"hidden\" name=\"source_page\" value=\"" . esc_attr($source_page) . "\">";

        // Preserve table options so we can return to the exact same view
        $filter = $this->dao->getPostOrGetSanitize('filter');
        if ($filter !== '') {
            echo "<input type=\"hidden\" name=\"source_filter\" value=\"" . esc_attr($filter) . "\">";
        }
        $orderby = $this->dao->getPostOrGetSanitize('orderby');
        if ($orderby !== '') {
            echo "<input type=\"hidden\" name=\"source_orderby\" value=\"" . esc_attr($orderby) . "\">";
        }
        $order = $this->dao->getPostOrGetSanitize('order');
        if ($order !== '') {
            echo "<input type=\"hidden\" name=\"source_order\" value=\"" . esc_attr($order) . "\">";
        }
        $paged = $this->dao->getPostOrGetSanitize('paged');
        if ($paged !== '') {
            echo "<input type=\"hidden\" name=\"source_paged\" value=\"" . esc_attr($paged) . "\">";
        }

        $recnum = null;
        $recnums_multiple = null;
        $startDate = '';
        $endDate = '';
        if (isset($_GET['id']) && $this->f->regexMatch('[0-9]+', $_GET['id'])) {
            $this->logger->debugMessage("Edit redirect page. GET ID: " .
                    wp_kses_post((string)json_encode($_GET['id'])));
            $recnum = absint($_GET['id']);

        } else if (isset($_POST['id']) && $this->f->regexMatch('[0-9]+', $_POST['id'])) {
            $this->logger->debugMessage("Edit redirect page. POST ID: " . 
                    wp_kses_post((string)json_encode($_POST['id'])));
            $recnum = absint($_POST['id']);
            
        } else if ($this->dao->getPostOrGetSanitize('idnum') !== '') {
            $recnums_multiple = array_map('absint', (array)$this->dao->getPostOrGetSanitize('idnum'));
            $this->logger->debugMessage("Edit redirect page. ids_multiple: " . 
                    wp_kses_post((string)json_encode($recnums_multiple)));

        } else {
            echo __('Error: No ID(s) found for edit request.', '404-solution');
            $this->logger->debugMessage("No ID(s) found in GET or POST data for edit request.");
            return;
        }
        
        // Decide whether we're editing one or multiple redirects.
        // If we're editing only one then set the ID to that one value.
        if ($recnum != null) {
            $recnumAsArray = array();
            $recnumAsArray[] = $recnum;
            $redirects_multiple = $this->dao->getRedirectsByIDs($recnumAsArray);
            
            if (empty($redirects_multiple)) {
                echo "Error: Invalid ID Number! (id: " . esc_html((string)$recnum) . ")";
                $this->logger->errorMessage("Error: Invalid ID Number! (id: " . esc_html((string)$recnum) . ")");
                return;
            }
            
            /** @var array<string, mixed> $redirect */
            $redirect = reset($redirects_multiple);
            $isRegexChecked = '';
            if (($redirect['status'] ?? '') == ABJ404_STATUS_REGEX) {
                $isRegexChecked = ' checked ';
            }

            $redirectId = is_scalar($redirect['id'] ?? '') ? (string)($redirect['id'] ?? '') : '';
            $redirectUrl = is_string($redirect['url'] ?? '') ? (string)($redirect['url'] ?? '') : '';
            echo '<input type="hidden" name="id" value="' . esc_attr($redirectId) . '">';

            // URL field (with optional "Matched by" note for auto-created redirects)
            echo '<div class="abj404-form-group">';
            echo '<label class="abj404-form-label" for="url">' . esc_html__('URL', '404-solution') . ' *</label>';
            echo '<input type="text" id="url" name="url" class="abj404-form-input" value="' . esc_attr($redirectUrl) . '" required>';
            $redirectEngine = is_string($redirect['engine'] ?? '') ? trim((string)($redirect['engine'] ?? '')) : '';
            if ($redirectEngine !== '') {
                $humanEngine = $this->humanizeEngineName($redirectEngine);
                echo '<p class="abj404-form-help abj404-matched-by">' . esc_html__('Auto-matched by:', '404-solution') . ' ' . esc_html($humanEngine) . '</p>';
            }
            echo '</div>';

            // Regex checkbox
            echo '<div class="abj404-form-group">';
            echo '<div class="abj404-checkbox-group">';
            echo '<input type="checkbox" name="is_regex_url" id="is_regex_url" class="abj404-checkbox-input" value="1" ' . $isRegexChecked . '>';
            echo '<label for="is_regex_url" class="abj404-checkbox-label">' . esc_html__('Treat this URL as a regular expression', '404-solution') . '</label>';
            echo ' <a href="#" class="abj404-regex-toggle" onclick="abj404ToggleRegexInfo(event)">' . esc_html__('(Explain)', '404-solution') . '</a>';
            echo '</div>';
            echo '<div class="abj404-regex-info" style="display: none;">';
            echo '<p>' . esc_html__('When checked, the text is treated as a regular expression. Note that including a bad regular expression or one that takes too long will break your website. So please use caution and test them elsewhere before trying them here. If you don\'t know what you\'re doing please don\'t use this option (as it\'s not necessary for the functioning of the plugin).', '404-solution') . '</p>';
            echo '<p><strong>' . esc_html__('Example:', '404-solution') . '</strong> <code>/events/(.+)</code></p>';
            echo '<p>' . esc_html__('/events/(.+) will match any URL that begins with /events/ and redirect to the specified page. Since a capture group is used, you can use a $1 replacement in the destination string of an external URL.', '404-solution') . '</p>';
            echo '</div>';
            echo '</div>';

            // Scheduled redirect dates (rendered inside Advanced Options in echoEditRedirect)
            $startTs = isset($redirect['start_ts']) && is_numeric($redirect['start_ts']) ? (int)$redirect['start_ts'] : 0;
            $endTs = isset($redirect['end_ts']) && is_numeric($redirect['end_ts']) ? (int)$redirect['end_ts'] : 0;
            $startDate = $startTs > 0 ? date('Y-m-d', $startTs) : '';
            $endDate = $endTs > 0 ? date('Y-m-d', $endTs) : '';

        } else if ($recnums_multiple != null) {
            $redirects_multiple = $this->dao->getRedirectsByIDs($recnums_multiple);
            if ($redirects_multiple == null) {
                echo "Error: Invalid ID Numbers! (ids: " . esc_html(implode(',', $recnums_multiple)) . ")";
                $this->logger->debugMessage("Error: Invalid ID Numbers! (ids: " . 
                        esc_html(implode(',', $recnums_multiple)) . ")");
                return;
            }

            echo '<input type="hidden" name="ids_multiple" value="' . esc_attr(implode(',', $recnums_multiple)) . '">';

            // Bulk URL list
            echo '<div class="abj404-form-group">';
            echo '<label class="abj404-form-label">' . esc_html__('URLs to redirect', '404-solution') . ' (' . count($redirects_multiple) . ')</label>';
            echo '<div class="abj404-url-list">';
            echo '<ul>';
            foreach ($redirects_multiple as $bulkRedirect) {
                /** @var array<string, mixed> $bulkRedirect */
                $bulkUrl = is_string($bulkRedirect['url'] ?? '') ? (string)($bulkRedirect['url'] ?? '') : '';
                echo '<li><code>' . esc_html($bulkUrl) . '</code></li>';
            }
            echo '</ul>';
            echo '</div>';
            echo '</div>';
            
            // here we set the variable to the first value returned because it's used to set default values
            // in the form data.
            $redirect = reset($redirects_multiple);
            
        } else {
            $idsText = '';
            echo "Error: Invalid ID Number(s) specified! (id: " . esc_html((string)$recnum) . ", ids: " . esc_html($idsText) . ")";
            $this->logger->debugMessage("Error: Invalid ID Number(s) specified! (id: " . esc_html((string)$recnum) .
                    ", ids: " . esc_html($idsText) . ")");
            return;
        }
        
        $final = "";
        $pageIDAndType = "";
        $redirectType = $redirect['type'] ?? null;
        $redirectFinalDestRaw = $redirect['final_dest'] ?? 0;
        $redirectFinalDest = is_scalar($redirectFinalDestRaw) ? (string)$redirectFinalDestRaw : '0';
        if ($redirectType == ABJ404_TYPE_EXTERNAL) {
            $final = $redirectFinalDest;
            $pageIDAndType = ABJ404_TYPE_EXTERNAL . "|" . ABJ404_TYPE_EXTERNAL;
            
        } else if ($redirectFinalDest != 0) {
            // if a destination has been specified then let's fill it in.
            $pageIDAndType = $redirectFinalDest . "|" . $redirectType;
            
        } else if ($redirectType == ABJ404_TYPE_404_DISPLAYED) {
        	$pageIDAndType = ABJ404_TYPE_404_DISPLAYED . "|" . ABJ404_TYPE_404_DISPLAYED;
        }
        
        $rawCode = $redirect['code'] ?? '';
        if ($rawCode == "") {
            $rawDefault = $options['default_redirect'] ?? '301';
            $codeSelected = is_string($rawDefault) ? $rawDefault : '301';
        } else {
            $codeSelected = is_string($rawCode) ? $rawCode : '301';
        }
        
        $pageTitle = $this->logic->getPageTitleFromIDAndType($pageIDAndType, $redirectFinalDest);
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ .
                "/html/addManualRedirectPageSearchDropdown.html");
        $html = $this->f->str_replace('{redirect_to_label}', __('Redirect to', '404-solution'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_EMPTY}',
                __('(Type a page name or an external URL)', '404-solution'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_PAGE}',
                __('(A page has been selected.)', '404-solution'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_CUSTOM_STRING}',
        	__('(A custom string has been entered.)', '404-solution'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_URL}',
                __('(An external URL will be used.)', '404-solution'), $html);
        $html = $this->f->str_replace('{REDIRECT_TO_USER_FIELD_WARNING}', '', $html);
        $html = $this->f->str_replace('{redirectPageTitle}', esc_attr($pageTitle), $html);
        $html = $this->f->str_replace('{pageIDAndType}', esc_attr($pageIDAndType), $html);
        $html = $this->f->str_replace('{data-url}',
                "admin-ajax.php?action=echoRedirectToPages&includeDefault404Page=true&includeSpecial=true&nonce=" . wp_create_nonce('abj404_ajax'), $html);
        $html = $this->f->doNormalReplacements($html);
        echo '<div class="abj404-form-group abj404-autocomplete-wrapper">';
        echo $html;
        echo '</div>';
        
        $this->echoEditRedirect($final, $codeSelected, __('Update Redirect', '404-solution'), $source_page, $filter, $orderby, $order, $startDate, $endDate);

        echo '</form>';
        echo '</div>'; // end abj404-edit-container
        echo '</div>'; // end abj404-edit-page
    }
    
    /**
     * @param string $dest
     * @param array<int, object> $rows
     * @return string
     */
    function echoRedirectDestinationOptionsOthers($dest, $rows) {
        $content = array();

        $rowCounter = 0;
        $currentPostType = '';

        foreach ($rows as $row) {
            $rowCounter++;
            /** @var object{id: int, post_type: string, depth?: int} $row */
            $id = $row->id;
            $theTitle = get_the_title($id);
            $thisval = $id . "|" . ABJ404_TYPE_POST;

            $selected = "";
            if ($thisval == $dest) {
                $selected = " selected";
            }
            
            $_REQUEST[ABJ404_PP]['debug_info'] = 'Before row: ' . $rowCounter . ', Title: ' . $theTitle . 
                    ', Post type: ' . $row->post_type;
            
            if ($row->post_type != $currentPostType) {
                if ($currentPostType != '') {
                    $content[] = "\n" . '</optgroup>' . "\n";
                }
                
                $content[] = "\n" . '<optgroup label="' . __(ucwords($row->post_type), '404-solution') . '">' . "\n";
                $currentPostType = $row->post_type;
            }

            // this is split in this ridiculous way to help me figure out how to resolve a memory issue.
            // (https://wordpress.org/support/topic/options-tab-is-not-loading/)
            $content[] = "\n <option value=\"";
            $content[] = esc_attr($thisval);
            $content[] = "\"";
            $content[] = $selected;
            $content[] = ">";
            
            // insert some spaces for child pages.
            $depth = property_exists($row, 'depth') ? intval($row->depth) : 0;
            for ($i = 0; $i < $depth; $i++) {
                $content[] = "&nbsp;&nbsp;&nbsp;";
            }
            
            $content[] = __(ucwords($row->post_type), '404-solution');
            $content[] = ": ";
            $content[] = esc_html($theTitle);
            $content[] = "</option>";
            
            $_REQUEST[ABJ404_PP]['debug_info'] = 'After row: ' . $rowCounter . ', Title: ' . $theTitle . 
                    ', Post type: ' . $row->post_type;
        }
        
        $content[] = "\n" . '</optgroup>' . "\n";
        

        $_REQUEST[ABJ404_PP]['debug_info'] = 'Cleared after building redirect destination page list.';
        
        return implode('', $content);
    }

    /**
     * @param string $dest
     * @return string
     */
    function echoRedirectDestinationOptionsCatsTags($dest) {
        $content = "";
        $content .= "\n" . '<optgroup label="Categories">' . "\n";
        
        $customTagsEtc = array();

        // categories ---------------------------------------------
        $cats = $this->dao->getPublishedCategories();
        foreach ($cats as $cat) {
            /** @var \WP_Term $cat */
            $taxonomy = $cat->taxonomy;
            if ($taxonomy != 'category') {
                continue;
            }
            
            $id = $cat->term_id;
            $theTitle = $cat->name;
            $thisval = $id . "|" . ABJ404_TYPE_CAT;

            $selected = "";
            if ($thisval == $dest) {
                $selected = " selected";
            }
            $content .= "\n<option value=\"" . esc_attr($thisval) . "\"" . $selected . ">" . __('Category', '404-solution') . ": " . $theTitle . "</option>";
        }
        $content .= "\n" . '</optgroup>' . "\n";
        /** @var array<int, object{taxonomy: string, name?: string}> $cats */
        $customTagsEtc = $this->logic->getMapOfCustomCategories($cats);

        // tags ---------------------------------------------
        $content .= "\n" . '<optgroup label="Tags">' . "\n";
        $tags = $this->dao->getPublishedTags();
        foreach ($tags as $tag) {
            /** @var \WP_Term $tag */
            $id = $tag->term_id;
            $theTitle = $tag->name;
            $thisval = $id . "|" . ABJ404_TYPE_TAG;

            $selected = "";
            if ($thisval == $dest) {
                $selected = " selected";
            }
            $content .= "\n<option value=\"" . esc_attr($thisval) . "\"" . $selected . ">" . __('Tag', '404-solution') . ": " . $theTitle . "</option>";
        }
        $content .= "\n" . '</optgroup>' . "\n";
        
        // custom ---------------------------------------------
        foreach ($customTagsEtc as $taxonomy => $catRow) {
            $content .= "\n" . '<optgroup label="' . esc_html($taxonomy) . '">' . "\n";
            
            foreach ($catRow as $cat) {
                /** @var \WP_Term $cat */
                $id = $cat->term_id;
                $theTitle = $cat->name;
                $thisval = $id . "|" . ABJ404_TYPE_CAT;

                $selected = "";
                if ($thisval == $dest) {
                    $selected = " selected";
                }
                $content .= "\n<option value=\"" . esc_attr($thisval) . "\"" . $selected . ">" . __('Custom', '404-solution') . ": " . $theTitle . "</option>";
            }
            
            $content .= "\n" . '</optgroup>' . "\n";
        }
        
        return $content;
    }
    
    /**
     * Convert a raw engine class name to a human-readable label.
     *
     * Examples:
     *   TitleMatchingEngine        → "Title Matching"
     *   SpellingMatchingEngine     → "Spelling Matching"
     *   CategoryTagMatchingEngine  → "Category/Tag Matching"
     *   UrlFixEngine               → "URL Fix"
     *   ArchiveFallbackEngine      → "Archive Fallback"
     *
     * @param string $rawName
     * @return string
     */
    private function humanizeEngineName(string $rawName): string {
        // Strip full namespace prefix if stored with it.
        $name = preg_replace('/^ABJ_404_Solution_/', '', $rawName);
        if (!is_string($name)) {
            $name = $rawName;
        }
        // Strip "MatchingEngine" or bare "Engine" suffix.
        $name = (string)preg_replace('/MatchingEngine$/', ' Matching', $name);
        $name = (string)preg_replace('/Engine$/', '', $name);
        // Insert a space before each upper-case letter that follows a lower-case letter
        // (e.g. CategoryTag → Category Tag).
        $name = (string)preg_replace('/(?<=[a-z])([A-Z])/', ' $1', $name);
        $name = trim($name);
        // Fix known abbreviations.
        $name = str_replace(array('Url ', 'Url'), array('URL ', 'URL'), $name);
        // Fix Category/Tag — appears as "Category Tag Matching", make the separator a slash.
        $name = str_replace('Category Tag', 'Category/Tag', $name);
        return $name !== '' ? $name : $rawName;
    }

    /**
     * @return void
     */


}
