<?php

/* Turns data into an html display and vice versa.
 * Houses all displayed pages. Logs, options page, captured 404s, stats, etc. */

class ABJ_404_Solution_View {

	private static $instance = null;

	/** @var ABJ_404_Solution_Functions */
	private $f;

	/** @var ABJ_404_Solution_PluginLogic */
	private $logic;

	/** @var ABJ_404_Solution_DataAccess */
	private $dao;

	/** @var ABJ_404_Solution_Logging */
	private $logger;

	/**
	 * Constructor with dependency injection.
	 * Dependencies are now explicit and visible.
	 *
	 * @param ABJ_404_Solution_Functions|null $functions String manipulation utilities
	 * @param ABJ_404_Solution_PluginLogic|null $pluginLogic Business logic service
	 * @param ABJ_404_Solution_DataAccess|null $dataAccess Data access layer
	 * @param ABJ_404_Solution_Logging|null $logging Logging service
	 */
	public function __construct($functions = null, $pluginLogic = null, $dataAccess = null, $logging = null) {
		// Use injected dependencies or fall back to getInstance() for backward compatibility
		$this->f = $functions !== null ? $functions : ABJ_404_Solution_Functions::getInstance();
		$this->logic = $pluginLogic !== null ? $pluginLogic : ABJ_404_Solution_PluginLogic::getInstance();
		$this->dao = $dataAccess !== null ? $dataAccess : ABJ_404_Solution_DataAccess::getInstance();
		$this->logger = $logging !== null ? $logging : ABJ_404_Solution_Logging::getInstance();
	}

	public static function getInstance() {
		if (self::$instance == null) {
			self::$instance = new ABJ_404_Solution_View();
		}

		return self::$instance;
	}

	/** Get the 'checked' attribute for a checkbox based on option value.
	 * @param array $options The options array
	 * @param string $key The option key to check
	 * @return string Returns ' checked' if option is '1', empty string otherwise
	 */
	private function getCheckedAttr($options, $key) {
		return (array_key_exists($key, $options) && $options[$key] == '1') ? " checked" : "";
	}

	/**
	 * Get the tooltip HTML for the Hits and Last Used columns.
	 *
	 * Includes warning that data may not be immediately updated, plus the last
	 * update time with a data-timestamp attribute for JS to update dynamically.
	 *
	 * @return string Tooltip HTML (not escaped - contains data attributes)
	 */
	private function getHitsColumnTooltip() {
		$tooltip = esc_html__('Data may not be immediately updated. Refresh to see latest changes.', '404-solution');

		$timestamp = $this->dao->getLogsHitsTableLastUpdated();
		if ($timestamp !== null) {
			$lastUpdated = $this->dao->getLogsHitsTableLastUpdatedHuman();
			// Wrap in span with data-timestamp for JS to update dynamically
			$timeHtml = '<span class="abj404-time-ago" data-timestamp="' . esc_attr($timestamp) . '">' . esc_html($lastUpdated) . '</span>';
			$tooltip .= ' ' . sprintf(__('Last updated: %s', '404-solution'), $timeHtml);
		}

		return $tooltip;
	}

	/**
	 * Build action links for table rows (edit, logs, trash, delete, etc.)
	 *
	 * @param array $row The data row from the database
	 * @param string $sub The subpage parameter value
	 * @param array $tableOptions Table options including filter, orderby, order
	 * @param bool $isCapturedPage True for captured URLs page, false for redirects page
	 * @return array Array of links and titles
	 */
	protected function buildTableActionLinks($row, $sub, $tableOptions, $isCapturedPage = false) {
		$result = [];

		// Sanitize $sub for safe use in URLs (prevents XSS via quote injection)
		$sub = rawurlencode($sub);

		// ID handling differs between pages
		if ($isCapturedPage) {
			// Captured page uses raw ID for most links
			$id = $row['id'];
			$logsId = $row['logsid'];
		} else {
			// Redirects page uses absint for all IDs
			$id = absint($row['id']);
			$logsId = absint($row['logsid']);
		}

		// Build base links
		$result['editlink'] = "?page=" . ABJ404_PP . "&subpage=abj404_edit&id=" . $id . "&source_page=" . $sub;
		$result['logslink'] = "?page=" . ABJ404_PP . "&subpage=abj404_logs&id=" . $logsId;

		if ($isCapturedPage) {
			// Captured page - use the dynamic $sub parameter only once
			$result['trashlink'] = "?page=" . ABJ404_PP . "&id=" . $id .
				"&subpage=" . $sub;
			$result['ajaxTrashLink'] = "admin-ajax.php?action=trashLink" . "&id=" . absint($row['id']) .
				"&subpage=" . $sub;
			$result['deletelink'] = "?page=" . ABJ404_PP . "&remove=1&id=" . $id .
				"&subpage=" . $sub;
		} else {
			// Redirects page does not have hardcoded subpage
			$result['trashlink'] = "?page=" . ABJ404_PP . "&id=" . $id .
				"&subpage=" . $sub;
			$result['ajaxTrashLink'] = "admin-ajax.php?action=trashLink" . "&id=" . $id .
				"&subpage=" . $sub;
			$result['deletelink'] = "?page=" . ABJ404_PP . "&remove=1&id=" . $id .
				"&subpage=" . $sub;
		}

		// Trash/Restore title and action
		if (is_array($tableOptions) && array_key_exists('filter', $tableOptions) && $tableOptions['filter'] == ABJ404_TRASH_FILTER) {
			$result['trashlink'] .= "&trash=0";
			$result['ajaxTrashLink'] .= "&trash=0";
			$result['trashtitle'] = __('Restore', '404-solution');
		} else {
			$result['trashlink'] .= "&trash=1";
			$result['ajaxTrashLink'] .= "&trash=1";
			$result['trashtitle'] = __('Trash', '404-solution');
		}

		// Captured page has ignore and later links
		if ($isCapturedPage) {
			$result['ignorelink'] = "?page=" . ABJ404_PP . "&id=" . $id .
				"&subpage=" . $sub;
			$result['laterlink'] = "?page=" . ABJ404_PP . "&id=" . $id .
				"&subpage=" . $sub;

			// Ignore title and action
			$result['ignoretitle'] = "";
			if (is_array($tableOptions) && array_key_exists('filter', $tableOptions) && $tableOptions['filter'] == ABJ404_STATUS_IGNORED) {
				$result['ignorelink'] .= "&ignore=0";
				$result['ignoretitle'] = __('Remove Ignore Status', '404-solution');
			} else {
				$result['ignorelink'] .= "&ignore=1";
				$result['ignoretitle'] = __('Ignore 404 Error', '404-solution');
			}

			// Later title and action
			$result['latertitle'] = '?Organize Later?';
			if (is_array($tableOptions) && array_key_exists('filter', $tableOptions) && $tableOptions['filter'] == ABJ404_STATUS_LATER) {
				$result['laterlink'] .= "&later=0";
				$result['latertitle'] = __('Remove Later Status', '404-solution');
			} else {
				$result['laterlink'] .= "&later=1";
				$result['latertitle'] = __('Organize Later', '404-solution');
			}
		}

		// Add orderby/order parameters if not default
		if (is_array($tableOptions) && array_key_exists('orderby', $tableOptions) && array_key_exists('order', $tableOptions)) {
			if (!($tableOptions['orderby'] == "url" && $tableOptions['order'] == "ASC")) {
				$result['trashlink'] .= "&orderby=" . sanitize_text_field($tableOptions['orderby']) . "&order=" . sanitize_text_field($tableOptions['order']);
				$result['deletelink'] .= "&orderby=" . sanitize_text_field($tableOptions['orderby']) . "&order=" . sanitize_text_field($tableOptions['order']);

				if ($isCapturedPage) {
					$result['ignorelink'] .= "&orderby=" . sanitize_text_field($tableOptions['orderby']) . "&order=" . sanitize_text_field($tableOptions['order']);
					$result['laterlink'] .= "&orderby=" . sanitize_text_field($tableOptions['orderby']) . "&order=" . sanitize_text_field($tableOptions['order']);
				}
			}
		}

		// Add filter parameter if not zero
		if (is_array($tableOptions) && array_key_exists('filter', $tableOptions) && $tableOptions['filter'] != 0) {
			$result['trashlink'] .= "&filter=" . $tableOptions['filter'];
			$result['deletelink'] .= "&filter=" . $tableOptions['filter'];
			$result['editlink'] .= "&filter=" . $tableOptions['filter'];

			if ($isCapturedPage) {
				$result['ignorelink'] .= "&filter=" . $tableOptions['filter'];
				$result['laterlink'] .= "&filter=" . $tableOptions['filter'];
			}
		}

		// Add orderby/order parameters to edit link
		if (is_array($tableOptions) && array_key_exists('orderby', $tableOptions) && array_key_exists('order', $tableOptions)) {
			if (!($tableOptions['orderby'] == "url" && $tableOptions['order'] == "ASC")) {
				$result['editlink'] .= "&orderby=" . sanitize_text_field($tableOptions['orderby']) . "&order=" . sanitize_text_field($tableOptions['order']);
			}
		}

		// Add paged parameter to edit link if present
		if (is_array($tableOptions) && array_key_exists('paged', $tableOptions) && $tableOptions['paged'] > 1) {
			$result['editlink'] .= "&paged=" . $tableOptions['paged'];
		}

		// Apply nonces
		$result['trashlink'] = wp_nonce_url($result['trashlink'], "abj404_trashRedirect");
		$result['ajaxTrashLink'] = wp_nonce_url($result['ajaxTrashLink'], "abj404_ajaxTrash");

		if (is_array($tableOptions) && array_key_exists('filter', $tableOptions) && $tableOptions['filter'] == ABJ404_TRASH_FILTER) {
			$result['deletelink'] = wp_nonce_url($result['deletelink'], "abj404_removeRedirect");
		}

		if ($isCapturedPage) {
			$result['ignorelink'] = wp_nonce_url($result['ignorelink'], "abj404_ignore404");
			$result['laterlink'] = wp_nonce_url($result['laterlink'], "abj404_organizeLater");
		}

		return $result;
	}

	/** Get the text to notify the user when some URLs have been captured and need attention. 
     * @param int $captured the number of captured URLs
     * @return string html
     */
    function getDashboardNotificationCaptured($captured) {
        /* Translators: %s is the number of captured 404 URLs. */
    	$capturedMessage = sprintf( _n( 'There is <a>%s captured 404 URL</a> that needs to be processed.',
                'There are <a>%s captured 404 URLs</a> to be processed.',
                $captured, '404-solution'), $captured);
        $capturedMessage = $this->f->str_replace("<a>",
                "<a href=\"options-general.php?page=" . ABJ404_PP . "&subpage=abj404_captured\" >",
                $capturedMessage);
        $capturedMessage = $this->f->str_replace("</a>", "</a>", $capturedMessage);

        return '<div class="notice notice-info"><p><strong>' . PLUGIN_NAME .
                ":</strong> " . $capturedMessage . "</p></div>";
    }

    /** Do an action like trash/delete/ignore/edit and display a page like stats/logs/redirects/options.
     * @global type $abj404view
     * @global type $abj404logic
     */
    static function handleMainAdminPageActionAndDisplay() {
        global $abj404view;
        $instance = self::getInstance();

        try {
            $action = $instance->dao->getPostOrGetSanitize('action');

            if (!is_admin() || !$instance->logic->userIsPluginAdmin()) {
                $instance->logger->logUserCapabilities("handleMainAdminPageActionAndDisplay (" .
                        esc_html($action == '' ? '(none)' : $action) . ")");
                return;
            }

            $sub = "";

            // --------------------------------------------------------------------
            // Handle Post Actions
            $instance->logger->debugMessage("Processing request for action: " .
                    esc_html($action == '' ? '(none)' : $action));

            // this should really not pass things by reference so it can be more object oriented (encapsulation etc).
            $message = "";
            $message .= $instance->logic->handlePluginAction($action, $sub);
            $message .= $instance->logic->hanldeTrashAction();
            $message .= $instance->logic->handleDeleteAction();
            $message .= $instance->logic->handleIgnoreAction();
            $message .= $instance->logic->handleLaterAction();
            $message .= $instance->logic->handleActionEdit($sub, $action);
            $message .= $instance->logic->handleActionImportRedirects();
            $message .= $instance->logic->handleActionChangeItemsPerRow();
            $message .= $instance->logic->handleActionImportFile();

            // --------------------------------------------------------------------
            // Output the correct page.
            $abj404view->echoChosenAdminTab($action, $sub, $message);

        } catch (Exception $e) {
            $instance->logger->errorMessage("Caught exception: " . stripcslashes(wp_kses_post(json_encode($e))));
            throw $e;
        }
    }
    
    /** Display the chosen admin page.
     * @global type $abj404view
     * @param string $sub
     * @param string $message
     */
    function echoChosenAdminTab($action, $sub, $message) {
        global $abj404view;

        // If globals are not set, use sensible defaults
        if ($abj404view === null) {
            $abj404view = $this;
        }

        // Deal With Page Tabs
        if ($sub == "") {
            $sub = $this->f->strtolower($this->dao->getPostOrGetSanitize('subpage'));
        }
        if ($sub == "") {
            $sub = 'abj404_redirects';
            $this->logger->debugMessage('No tab selected. Displaying the "redirects" tab.');
        }

        // Check if we're returning from a successful redirect update
        $updated = $this->dao->getPostOrGetSanitize('updated');
        if ($updated == '1') {
            $message .= __('Redirect Information Updated Successfully!', '404-solution');
        }

        $this->logger->debugMessage("Displaying sub page: " . esc_html($sub == '' ? '(none)' : $sub));

        $abj404view->outputAdminHeaderTabs($sub, $message);
        
        $abj404action = $this->dao->getPostOrGetSanitize('abj404action');
        if (($action == 'editRedirect') || ($abj404action == 'editRedirect') || ($sub == 'abj404_edit')) {
            $abj404view->echoAdminEditRedirectPage();
        } else if ($sub == 'abj404_redirects') {
            $abj404view->echoAdminRedirectsPage();
        } else if ($sub == 'abj404_captured') {
            $abj404view->echoAdminCapturedURLsPage();
        } else if ($sub == "abj404_options") {
            $abj404view->echoAdminOptionsPage();
        } else if ($sub == 'abj404_logs') {
            $abj404view->echoAdminLogsPage();
        } else if ($sub == 'abj404_stats') {
            $abj404view->outputAdminStatsPage();
        } else if ($sub == 'abj404_tools') {
            $abj404view->echoAdminToolsPage();
        } else if ($sub == 'abj404_debugfile') {
            $abj404view->echoAdminDebugFile();
        } else {
            $this->logger->debugMessage('No tab selected. Displaying the "redirects" tab.');
            $abj404view->echoAdminRedirectsPage();
        }
        
        $abj404view->echoAdminFooter();
    }
    
    /** Echo the text that appears at the bottom of each admin page. */
    function echoAdminFooter() {
        // read the html content.
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/adminFooter.html");
        $html = $this->f->str_replace('{JAPANESE_FLASHCARDS_URL}', ABJ404_FC_URL, $html);
        
        // constants and translations.
        $html = $this->f->doNormalReplacements($html);
        echo $html;
    }

    /** Output the tabs at the top of the plugin page.
     * @param string $sub
     * @param string $message
     */
    function outputAdminHeaderTabs($sub = 'list', $message = '') {
        ABJ_404_Solution_WPNotices::echoAdminNotices();

        echo "<div class=\"wrap\" style='z-index: 1;position: relative;'>";
        if ($message != "") {
            $allowed_tags = array(
                'br' => array(),
                'em' => array(),
                'strong' => array(),
            );
            
            if (($this->f->strlen($message) >= 6) && ($this->f->substr($this->f->strtolower($message), 0, 6) == 'error:')) {
                $cssClasses = 'notice notice-error';
            } else {
                $cssClasses = 'notice notice-success';
            }
            
            echo '<div class="' . $cssClasses . '"><p>' . wp_kses($message, $allowed_tags) . "</p></div>\n";
        }

        echo '<nav class="abj404-tab-navigation" role="tablist">';

        // Page Redirects tab
        $class = ($sub == 'abj404_redirects') ? "active" : "";
        echo '<a href="?page=' . ABJ404_PP . '&subpage=abj404_redirects" title="' . esc_attr__('Page Redirects', '404-solution') . '" class="abj404-tab ' . $class . '" role="tab">';
        echo '<span class="dashicons dashicons-randomize"></span>';
        echo '<span class="abj404-tab-text">' . esc_html__('Page Redirects', '404-solution') . '</span>';
        echo '</a>';

        // Captured 404 URLs tab
        $class = ($sub == 'abj404_captured') ? "active" : "";
        echo '<a href="?page=' . ABJ404_PP . '&subpage=abj404_captured" title="' . esc_attr__('Captured 404 URLs', '404-solution') . '" class="abj404-tab ' . $class . '" role="tab">';
        echo '<span class="dashicons dashicons-search"></span>';
        echo '<span class="abj404-tab-text">' . esc_html__('Captured 404s', '404-solution') . '</span>';
        echo '</a>';

        // Logs tab
        $class = ($sub == 'abj404_logs') ? "active" : "";
        echo '<a href="?page=' . ABJ404_PP . '&subpage=abj404_logs" title="' . esc_attr__('Redirect & Capture Logs', '404-solution') . '" class="abj404-tab ' . $class . '" role="tab">';
        echo '<span class="dashicons dashicons-list-view"></span>';
        echo '<span class="abj404-tab-text">' . esc_html__('Logs', '404-solution') . '</span>';
        echo '</a>';

        // Stats tab
        $class = ($sub == 'abj404_stats') ? "active" : "";
        echo '<a href="?page=' . ABJ404_PP . '&subpage=abj404_stats" title="' . esc_attr__('Stats', '404-solution') . '" class="abj404-tab ' . $class . '" role="tab">';
        echo '<span class="dashicons dashicons-chart-bar"></span>';
        echo '<span class="abj404-tab-text">' . esc_html__('Stats', '404-solution') . '</span>';
        echo '</a>';

        // Tools tab
        $class = ($sub == 'abj404_tools') ? "active" : "";
        echo '<a href="?page=' . ABJ404_PP . '&subpage=abj404_tools" title="' . esc_attr__('Tools', '404-solution') . '" class="abj404-tab ' . $class . '" role="tab">';
        echo '<span class="dashicons dashicons-admin-tools"></span>';
        echo '<span class="abj404-tab-text">' . esc_html__('Tools', '404-solution') . '</span>';
        echo '</a>';

        // Options tab
        $class = ($sub == "abj404_options") ? "active" : "";
        echo '<a href="?page=' . ABJ404_PP . '&subpage=abj404_options" title="' . esc_attr__('Options', '404-solution') . '" class="abj404-tab ' . $class . '" role="tab">';
        echo '<span class="dashicons dashicons-admin-generic"></span>';
        echo '<span class="abj404-tab-text">' . esc_html__('Options', '404-solution') . '</span>';
        echo '</a>';

        // Plugin branding - right side of tabs
        echo '<span class="abj404-tab-branding">' . esc_html(PLUGIN_NAME) . '</span>';

        echo '</nav>';
    }
    
    /** This outputs a box with a title and some content in it. 
     * It's used on the Stats, Options and Tools page (for example).
     * @param int $id
     * @param string $title
     * @param string $content
     */
    function echoPostBox($id, $title, $content) {
        echo "<div id=\"" . esc_attr($id) . "\" class=\"postbox\">";
        echo "<h3 class=\"\" ><span>" . esc_html($title) . "</span></h3>";
        echo "<div class=\"inside\">" . $content /* Can't escape here, as contains forms */ . "</div>";
        echo "</div>";
    }

    /**
     * Echo an accordion section using card-based layout
     * @param string $sectionId The section identifier
     * @param string $postboxId The ID for the postbox
     * @param string $title The title of the section
     * @param string $content The content to display
     * @param bool $initiallyVisible Whether the card starts expanded (default: false)
     * @param string $icon The SVG icon for the card header (optional)
     * @param string $badge Optional info badge text
     */
    function echoOptionsSection($sectionId, $postboxId, $title, $content, $initiallyVisible = false, $icon = '', $badge = '') {
        $expandedClass = $initiallyVisible ? ' expanded' : '';

        echo "<div class=\"abj404-card" . esc_attr($expandedClass) . "\" data-card=\"" . esc_attr($sectionId) . "\" id=\"" . esc_attr($postboxId) . "\">";
        echo "<div class=\"abj404-card-header\" onclick=\"abj404ToggleCard(this)\" role=\"button\" aria-expanded=\"" . ($initiallyVisible ? 'true' : 'false') . "\" tabindex=\"0\">";
        echo "<h2 class=\"abj404-card-title\">";
        if ($icon) {
            echo $icon; // Icon is pre-sanitized SVG
        }
        echo esc_html($title);
        if ($badge) {
            echo "<span class=\"abj404-info-badge\">" . esc_html($badge) . "</span>";
        }
        echo "</h2>";
        echo "<svg class=\"abj404-collapse-icon\" width=\"20\" height=\"20\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\">";
        echo "<path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M19 9l-7 7-7-7\"></path>";
        echo "</svg>";
        echo "</div>";
        echo "<div class=\"abj404-card-content\">";
        echo $content; /* Can't escape here, as contains forms */
        echo "</div>";
        echo "</div>";
    }

    /**
     * Get SVG icon for a section
     * @param string $iconName The icon name
     * @return string The SVG icon markup
     */
    function getCardIcon($iconName) {
        $icons = array(
            'lightning' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>',
            'gear' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>',
            'filter' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path></svg>',
            'document' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>',
            'sliders' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"></path></svg>',
            'lightbulb' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path></svg>',
            // Stats page icons
            'chart' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>',
            'warning' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>',
            'clock' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>',
            // Tools page icons
            'download' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>',
            'upload' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>',
            'trash' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>',
            'database' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path></svg>',
            'cog' => '<svg class="abj404-card-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>'
        );
        return isset($icons[$iconName]) ? $icons[$iconName] : '';
    }

    /**
     * Echo the quick links navigation bar
     */
    function echoQuickLinks() {
        $statsUrl = admin_url('admin.php?page=' . ABJ404_PP . '&subpage=abj404_stats');
        $capturedUrl = admin_url('admin.php?page=' . ABJ404_PP . '&subpage=abj404_captured');
        $redirectsUrl = admin_url('admin.php?page=' . ABJ404_PP . '&subpage=abj404_redirects');
        $docsUrl = 'https://ajexoop.com/404-solution/documentation/';

        echo '<div class="abj404-quick-links">';
        echo '<a href="' . esc_url($statsUrl) . '" class="abj404-quick-link">';
        echo '<span class="abj404-quick-link-icon">📊</span>';
        echo esc_html__('View Stats', '404-solution');
        echo '</a>';
        echo '<a href="' . esc_url($capturedUrl) . '" class="abj404-quick-link">';
        echo '<span class="abj404-quick-link-icon">📝</span>';
        echo esc_html__('Captured 404s', '404-solution');
        echo '</a>';
        echo '<a href="' . esc_url($redirectsUrl) . '" class="abj404-quick-link">';
        echo '<span class="abj404-quick-link-icon">🔄</span>';
        echo esc_html__('Page Redirects', '404-solution');
        echo '</a>';
        echo '<a href="' . esc_url($docsUrl) . '" class="abj404-quick-link" target="_blank" rel="noopener">';
        echo '<span class="abj404-quick-link-icon">📚</span>';
        echo esc_html__('Documentation', '404-solution');
        echo '</a>';
        echo '</div>';
    }

    /**
     * Echo the sticky save bar
     */
    function echoStickySaveBar() {
        $version = ABJ404_VERSION;
        echo '<div class="abj404-sticky-save-bar">';
        echo '<div class="abj404-save-bar-status">';
        /* translators: %s: plugin version number */
        echo esc_html(sprintf(__('Plugin v%s', '404-solution'), $version));
        echo '</div>';
        echo '<div class="abj404-save-bar-actions">';
        echo '<input type="submit" name="abj404-optionssub" id="abj404-optionssub" value="' . esc_attr__('Save Settings', '404-solution') . '" class="button button-primary abj404-btn abj404-btn-primary">';
        echo '</div>';
        echo '</div>';
    }

    /**
     * Echo the success toast notification container
     */
    function echoToastNotification() {
        echo '<div id="abj404-toast" class="abj404-toast">';
        echo '<span class="abj404-toast-icon">✓</span> ';
        echo '<span class="abj404-toast-message">' . esc_html__('Settings saved successfully!', '404-solution') . '</span>';
        echo '</div>';
    }


    /**
     * Echo the expand/collapse all button and save button
     * @param bool $showSuggestions Not used (kept for compatibility)
     */
    function echoExpandCollapseButton($showSuggestions = true) {
        ?>
        <div class="abj404-accordion-controls">
            <button type="button" id="abj404-expand-collapse-all" class="button">
                <?php echo esc_html__('Expand All', '404-solution'); ?>
            </button>
            <input type="submit" name="abj404-optionssub" id="abj404-optionssub" value="<?php echo esc_attr__('Save Settings', '404-solution'); ?>" class="button-primary">
        </div>
        <?php
    }

    /**
     * Echo the Simple/Advanced mode toggle control.
     * @param string $currentMode 'simple' or 'advanced'
     */
    function echoSettingsModeToggle($currentMode) {
        $simpleActive = ($currentMode === 'simple') ? 'active' : '';
        $advancedActive = ($currentMode === 'advanced') ? 'active' : '';
        $simplePressedState = ($currentMode === 'simple') ? 'true' : 'false';
        $advancedPressedState = ($currentMode === 'advanced') ? 'true' : 'false';

        if ($currentMode === 'simple') {
            $modeDescription = __('Simple Mode shows essential options only. Switch to Advanced Mode for full configuration.', '404-solution');
        } else {
            $modeDescription = __('Advanced Mode shows all options. Switch to Simple Mode for a streamlined view.', '404-solution');
        }

        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/settingsModeToggle.html");
        $html = $this->f->str_replace('{nonce}', wp_create_nonce('abj404_mode_toggle'), $html);
        $html = $this->f->str_replace('{simpleActive}', $simpleActive, $html);
        $html = $this->f->str_replace('{advancedActive}', $advancedActive, $html);
        $html = $this->f->str_replace('{simplePressedState}', $simplePressedState, $html);
        $html = $this->f->str_replace('{advancedPressedState}', $advancedPressedState, $html);
        $html = $this->f->str_replace('{Simple Mode}', __('Simple Mode', '404-solution'), $html);
        $html = $this->f->str_replace('{Advanced Mode}', __('Advanced Mode', '404-solution'), $html);
        $html = $this->f->str_replace('{mode_description}', $modeDescription, $html);

        echo $html;
    }

    /**
     * Echo an inline (compact) mode toggle for the header row.
     * This is a smaller version without the description text.
     */
    function echoInlineModeToggle() {
        $currentMode = $this->logic->getSettingsMode();
        $simpleActive = ($currentMode === 'simple') ? 'active' : '';
        $advancedActive = ($currentMode === 'advanced') ? 'active' : '';
        $simplePressedState = ($currentMode === 'simple') ? 'true' : 'false';
        $advancedPressedState = ($currentMode === 'advanced') ? 'true' : 'false';

        echo '<div class="abj404-mode-toggle abj404-mode-toggle-inline" data-nonce="' . esc_attr(wp_create_nonce('abj404_mode_toggle')) . '">';
        echo '<div class="abj404-mode-toggle-buttons">';
        echo '<button type="button" class="abj404-mode-btn ' . esc_attr($simpleActive) . '" data-mode="simple" aria-pressed="' . esc_attr($simplePressedState) . '">';
        echo '<span class="abj404-mode-btn-text">' . esc_html__('Simple', '404-solution') . '</span>';
        echo '</button>';
        echo '<button type="button" class="abj404-mode-btn ' . esc_attr($advancedActive) . '" data-mode="advanced" aria-pressed="' . esc_attr($advancedPressedState) . '">';
        echo '<span class="abj404-mode-btn-text">' . esc_html__('Advanced', '404-solution') . '</span>';
        echo '</button>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * Echo the Simple Mode options page.
     * @param array $options The plugin options
     */
    function echoSimpleModeOptions($options) {
        // Get dest404page dropdown HTML (reuse existing logic)
        $userSelectedDefault404Page = (array_key_exists('dest404page', $options) &&
                isset($options['dest404page']) ? $options['dest404page'] : null);
        $urlDestination = (array_key_exists('dest404pageURL', $options) &&
                isset($options['dest404pageURL']) ? $options['dest404pageURL'] : null);

        $pageTitle = $this->logic->getPageTitleFromIDAndType($userSelectedDefault404Page, $urlDestination);
        $pageMissingWarning = "";
        if ($userSelectedDefault404Page != null) {
            $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($userSelectedDefault404Page, 0);
            if (!in_array($permalink['status'], array('publish', 'published'))) {
                $pageMissingWarning = __("(The specified page doesn't exist. Please update this setting.)", '404-solution');
            }
        }

        // Build dest404page dropdown
        $dest404Dropdown = ABJ_404_Solution_Functions::readFileContents(__DIR__ .
                "/html/addManualRedirectPageSearchDropdown.html");
        $dest404Dropdown = $this->f->str_replace('{redirect_to_label}', '', $dest404Dropdown);
        $dest404Dropdown = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_EMPTY}',
                __('(Type a page name or an external URL)', '404-solution'), $dest404Dropdown);
        $dest404Dropdown = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_PAGE}',
                __('(A page has been selected.)', '404-solution'), $dest404Dropdown);
        $dest404Dropdown = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_CUSTOM_STRING}',
            __('(A custom string has been entered.)', '404-solution'), $dest404Dropdown);
        $dest404Dropdown = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_URL}',
                __('(An external URL will be used.)', '404-solution'), $dest404Dropdown);
        $dest404Dropdown = $this->f->str_replace('{REDIRECT_TO_USER_FIELD_WARNING}', $pageMissingWarning, $dest404Dropdown);
        $dest404Dropdown = $this->f->str_replace('{redirectPageTitle}', $pageTitle, $dest404Dropdown);
        $dest404Dropdown = $this->f->str_replace('{pageIDAndType}', $userSelectedDefault404Page, $dest404Dropdown);
        $dest404Dropdown = $this->f->str_replace('{data-url}',
                "admin-ajax.php?action=echoRedirectToPages&includeDefault404Page=true&includeSpecial=true&nonce=" . wp_create_nonce('abj404_ajax'), $dest404Dropdown);
        $dest404Dropdown = $this->f->doNormalReplacements($dest404Dropdown);

        // Build selected states for checkboxes and dropdowns
        $selectedAutoRedirects = $this->getCheckedAttr($options, 'auto_redirects');
        $selectedCapture404 = $this->getCheckedAttr($options, 'capture_404');
        $selectedDefaultRedirect301 = ($options['default_redirect'] == '301') ? 'selected' : '';
        $selectedDefaultRedirect302 = ($options['default_redirect'] == '302') ? 'selected' : '';

        // Theme selections
        $selectedThemeDefault = ($options['admin_theme'] == 'default') ? 'selected' : '';
        $selectedThemeCalm = ($options['admin_theme'] == 'calm') ? 'selected' : '';
        $selectedThemeMono = ($options['admin_theme'] == 'mono') ? 'selected' : '';
        $selectedThemeNeon = ($options['admin_theme'] == 'neon') ? 'selected' : '';
        $selectedThemeObsidian = ($options['admin_theme'] == 'obsidian') ? 'selected' : '';

        // Read and build the simple options template
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/adminOptionsSimple.html");

        // Replace dest404page dropdown
        $html = $this->f->str_replace('{dest404pageOptions}', $dest404Dropdown, $html);

        // Replace checkbox states
        $html = $this->f->str_replace('{selectedAutoRedirects}', $selectedAutoRedirects, $html);
        $html = $this->f->str_replace('{selectedCapture404}', $selectedCapture404, $html);
        $html = $this->f->str_replace('{selectedDefaultRedirect301}', $selectedDefaultRedirect301, $html);
        $html = $this->f->str_replace('{selectedDefaultRedirect302}', $selectedDefaultRedirect302, $html);

        // Replace values
        $html = $this->f->str_replace('{capture_deletion}', esc_attr($options['capture_deletion']), $html);
        $html = $this->f->str_replace('{admin_notification}', esc_attr($options['admin_notification']), $html);
        $html = $this->f->str_replace('{maximum_log_disk_usage}', esc_attr($options['maximum_log_disk_usage']), $html);

        // Theme selections
        $html = $this->f->str_replace('{selectedThemeDefault}', $selectedThemeDefault, $html);
        $html = $this->f->str_replace('{selectedThemeCalm}', $selectedThemeCalm, $html);
        $html = $this->f->str_replace('{selectedThemeMono}', $selectedThemeMono, $html);
        $html = $this->f->str_replace('{selectedThemeNeon}', $selectedThemeNeon, $html);
        $html = $this->f->str_replace('{selectedThemeObsidian}', $selectedThemeObsidian, $html);
        $html = $this->f->str_replace('{theme_default}', __('Default (Follows WordPress)', '404-solution'), $html);

        // Translate labels
        $html = $this->f->str_replace('{Core Settings}', __('Core Settings', '404-solution'), $html);
        $html = $this->f->str_replace('{404 Capture}', __('404 Capture', '404-solution'), $html);
        $html = $this->f->str_replace('{Maintenance}', __('Maintenance', '404-solution'), $html);
        $html = $this->f->str_replace('{Default 404 destination}', __('Default 404 destination', '404-solution'), $html);
        $html = $this->f->str_replace('{Where to send visitors when a 404 error occurs}', __('Where to send visitors when a 404 error occurs', '404-solution'), $html);
        $html = $this->f->str_replace('{Create automatic redirects}', __('Create automatic redirects', '404-solution'), $html);
        $html = $this->f->str_replace('{Automatically redirect 404s to similar pages when a good match is found}', __('Automatically redirect 404s to similar pages when a good match is found', '404-solution'), $html);
        $html = $this->f->str_replace('{Redirect type}', __('Redirect type', '404-solution'), $html);
        $html = $this->f->str_replace('{Permanent 301}', __('Permanent 301', '404-solution'), $html);
        $html = $this->f->str_replace('{Temporary 302}', __('Temporary 302', '404-solution'), $html);
        $html = $this->f->str_replace('{301 for SEO, 302 for temporary changes}', __('301 for SEO, 302 for temporary changes', '404-solution'), $html);
        $html = $this->f->str_replace('{Collect incoming 404 URLs}', __('Collect incoming 404 URLs', '404-solution'), $html);
        $html = $this->f->str_replace('{Log 404 errors so you can review and fix them}', __('Log 404 errors so you can review and fix them', '404-solution'), $html);
        $html = $this->f->str_replace('{Delete captured URLs after}', __('Delete captured URLs after', '404-solution'), $html);
        $html = $this->f->str_replace('{days}', __('days', '404-solution'), $html);
        $html = $this->f->str_replace('{Auto-remove old 404 records (0 to keep forever)}', __('Auto-remove old 404 records (0 to keep forever)', '404-solution'), $html);
        $html = $this->f->str_replace('{Notify me when captured URLs exceed}', __('Notify me when captured URLs exceed', '404-solution'), $html);
        $html = $this->f->str_replace('{URLs}', __('URLs', '404-solution'), $html);
        $html = $this->f->str_replace('{Show admin notice when 404 count gets high (0 to disable)}', __('Show admin notice when 404 count gets high (0 to disable)', '404-solution'), $html);
        $html = $this->f->str_replace('{Maximum log storage}', __('Maximum log storage', '404-solution'), $html);
        $html = $this->f->str_replace('{Oldest logs are deleted when this limit is reached}', __('Oldest logs are deleted when this limit is reached', '404-solution'), $html);
        $html = $this->f->str_replace('{Admin theme}', __('Admin theme', '404-solution'), $html);
        $html = $this->f->str_replace('{Calm Ops (Light)}', __('Calm Ops (Light)', '404-solution'), $html);
        $html = $this->f->str_replace('{Monochrome Minimal (Light)}', __('Monochrome Minimal (Light)', '404-solution'), $html);
        $html = $this->f->str_replace('{Neon Slate (Dark)}', __('Neon Slate (Dark)', '404-solution'), $html);
        $html = $this->f->str_replace('{Obsidian Blue (Dark)}', __('Obsidian Blue (Dark)', '404-solution'), $html);
        $html = $this->f->str_replace('{Save Settings}', __('Save Settings', '404-solution'), $html);
        $html = $this->f->str_replace('{Need more control?}', __('Need more control?', '404-solution'), $html);
        $html = $this->f->str_replace('{Switch to Advanced Mode}', __('Switch to Advanced Mode', '404-solution'), $html);
        $html = $this->f->str_replace('{for 30+ additional options.}', __('for 30+ additional options.', '404-solution'), $html);

        echo $html;
    }

    /** Output the stats page.
     * @global type $wpdb
     * @global type $abj404dao
     */
    function outputAdminStatsPage() {
        global $wpdb;
        global $abj404view;

        $redirects = $this->dao->doTableNameReplacements("{wp_abj404_redirects}");
        $logs = $this->dao->doTableNameReplacements("{wp_abj404_logsv2}");

        // Main container
        echo "<div class=\"abj404-container\">";
        echo "<div class=\"abj404-settings-content\">";

        // Header row with Expand All button
        echo "<div class=\"abj404-header-row\">";
        echo "<h2>" . esc_html__('Statistics', '404-solution') . "</h2>";
        echo "<div class=\"abj404-header-controls\">";
        echo '<button type="button" id="abj404-expand-collapse-all" class="button">';
        echo esc_html__('Expand All', '404-solution');
        echo '</button>';
        echo "</div>";
        echo "</div>";

        // Flow layout for stats cards
        echo "<div class=\"abj404-flow-layout\">";

        // Redirects Statistics Card
        $query = "select count(id) from $redirects where disabled = 0 and code = 301 and status = %d";
        $auto301 = $this->dao->getStatsCount($query, array(ABJ404_STATUS_AUTO));

        $query = "select count(id) from $redirects where disabled = 0 and code = 302 and status = %d";
        $auto302 = $this->dao->getStatsCount($query, array(ABJ404_STATUS_AUTO));

        $query = "select count(id) from $redirects where disabled = 0 and code = 301 and status = %d";
        $manual301 = $this->dao->getStatsCount($query, array(ABJ404_STATUS_MANUAL));

        $query = "select count(id) from $redirects where disabled = 0 and code = 302 and status = %d";
        $manual302 = $this->dao->getStatsCount($query, array(ABJ404_STATUS_MANUAL));

        $query = "select count(id) from $redirects where disabled = 1 and (status = %d or status = %d)";
        $trashed = $this->dao->getStatsCount($query, array(ABJ404_STATUS_AUTO, ABJ404_STATUS_MANUAL));

        $total = $auto301 + $auto302 + $manual301 + $manual302 + $trashed;

        $content = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/statsRedirectsBox.html");
        $content = $this->f->str_replace('{auto301}', esc_html($auto301), $content);
        $content = $this->f->str_replace('{auto302}', esc_html($auto302), $content);
        $content = $this->f->str_replace('{manual301}', esc_html($manual301), $content);
        $content = $this->f->str_replace('{manual302}', esc_html($manual302), $content);
        $content = $this->f->str_replace('{trashed}', esc_html($trashed), $content);
        $content = $this->f->str_replace('{total}', esc_html($total), $content);
        $content = $this->f->doNormalReplacements($content);
        $abj404view->echoOptionsSection('stats-redirects', 'abj404-redirectStats', __('Redirects', '404-solution'), $content, true, $abj404view->getCardIcon('chart'));

        // Captured URLs Statistics Card
        $query = "select count(id) from $redirects where disabled = 0 and status = %d";
        $captured = $this->dao->getStatsCount($query, array(ABJ404_STATUS_CAPTURED));

        $query = "select count(id) from $redirects where disabled = 0 and status in (%d, %d)";
        $ignored = $this->dao->getStatsCount($query, array(ABJ404_STATUS_IGNORED, ABJ404_STATUS_LATER));

        $query = "select count(id) from $redirects where disabled = 1 and (status in (%d, %d, %d) )";
        $trashed = $this->dao->getStatsCount($query, array(ABJ404_STATUS_CAPTURED, ABJ404_STATUS_IGNORED, ABJ404_STATUS_LATER));

        $total = $captured + $ignored + $trashed;

        $content = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/statsCapturedURLsBox.html");
        $content = $this->f->str_replace('{captured}', esc_html($captured), $content);
        $content = $this->f->str_replace('{ignored}', esc_html($ignored), $content);
        $content = $this->f->str_replace('{trashed}', esc_html($trashed), $content);
        $content = $this->f->str_replace('{total}', esc_html($total), $content);
        $content = $this->f->doNormalReplacements($content);
        $abj404view->echoOptionsSection('stats-captured', 'abj404-capturedStats', __('Captured URLs', '404-solution'), $content, true, $abj404view->getCardIcon('warning'));

        // Periodic Stats Cards
        $today = mktime(0, 0, 0, abs(intval(date('m'))), abs(intval(date('d'))), abs(intval(date('Y'))));
        $firstm = mktime(0, 0, 0, abs(intval(date('m'))), 1, abs(intval(date('Y'))));
        $firsty = mktime(0, 0, 0, 1, 1, abs(intval(date('Y'))));

        for ($x = 0; $x <= 3; $x++) {
            if ($x == 0) {
                $title = __("Today's Stats", '404-solution');
                $ts = $today;
            } else if ($x == 1) {
                $title = __("This Month", '404-solution');
                $ts = $firstm;
            } else if ($x == 2) {
                $title = __("This Year", '404-solution');
                $ts = $firsty;
            } else if ($x == 3) {
                $title = __("All Stats", '404-solution');
                $ts = 0;
            }

            $query = "select count(id) from $logs where timestamp >= $ts and dest_url = %s";
            $disp404 = $this->dao->getStatsCount($query, array("404"));

            $query = "select count(distinct requested_url) from $logs where timestamp >= $ts and dest_url = %s";
            $distinct404 = $this->dao->getStatsCount($query, array("404"));

            $query = "select count(distinct user_ip) from $logs where timestamp >= $ts and dest_url = %s";
            $visitors404 = $this->dao->getStatsCount($query, array("404"));

            $query = "select count(distinct referrer) from $logs where timestamp >= $ts and dest_url = %s";
            $refer404 = $this->dao->getStatsCount($query, array("404"));

            $query = "select count(id) from $logs where timestamp >= $ts and dest_url != %s";
            $redirected = $this->dao->getStatsCount($query, array("404"));

            $query = "select count(distinct requested_url) from $logs where timestamp >= $ts and dest_url != %s";
            $distinctredirected = $this->dao->getStatsCount($query, array("404"));

            $query = "select count(distinct user_ip) from $logs where timestamp >= $ts and dest_url != %s";
            $distinctvisitors = $this->dao->getStatsCount($query, array("404"));

            $query = "select count(distinct referrer) from $logs where timestamp >= $ts and dest_url != %s";
            $distinctrefer = $this->dao->getStatsCount($query, array("404"));

            $content = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/statsPeriodicBox.html");
            $content = $this->f->str_replace('{disp404}', esc_html($disp404), $content);
            $content = $this->f->str_replace('{distinct404}', esc_html($distinct404), $content);
            $content = $this->f->str_replace('{visitors404}', esc_html($visitors404), $content);
            $content = $this->f->str_replace('{refer404}', esc_html($refer404), $content);
            $content = $this->f->str_replace('{redirected}', esc_html($redirected), $content);
            $content = $this->f->str_replace('{distinctredirected}', esc_html($distinctredirected), $content);
            $content = $this->f->str_replace('{distinctvisitors}', esc_html($distinctvisitors), $content);
            $content = $this->f->str_replace('{distinctrefer}', esc_html($distinctrefer), $content);
            $content = $this->f->doNormalReplacements($content);
            $abj404view->echoOptionsSection('stats-periodic-' . $x, 'abj404-stats' . $x, $title, $content, ($x == 0), $abj404view->getCardIcon('clock'));
        }

        echo "</div>"; // Close flow layout
        echo "</div>"; // Close settings content
        echo "</div>"; // Close container
    }
    
    function echoAdminDebugFile() {
        if ($this->logic->userIsPluginAdmin()) {
        	$filesToEcho = array($this->logger->getDebugFilePath(), 
        			$this->logger->getDebugFilePathOld());
        	for ($i = 0; $i < count($filesToEcho); $i++) {
        		$currentFile = $filesToEcho[$i];
        		echo "<div style=\"clear: both;\">";
        		echo "<BR/>Contents of: " . $currentFile . ": <BR/><BR/>";
        		// read the file and replace new lines with <BR/>.
        		$this->echoFileContents($currentFile);
        		echo "</div>";
        	}
            
        } else {
        	echo "Non-admin request to view debug file.";
        	$current_user = wp_get_current_user();
        	$userInfo = "Login: " . $current_user->user_login . ", display name: " . 
         		$current_user->display_name . ", Email: " . $current_user->user_email . 
         		", UserID: " . $current_user->ID;
            $this->logger->infoMessage("Non-admin request to view debug file. User info: " .
            	$userInfo);
        }
    }
    
    function echoFileContents($fileName) {

    	if (file_exists($fileName)) {
    		$linesRead = 0;
    		$handle = null;
    		try {
    			if ($handle = fopen($fileName, "r")) {
    				// read the file one line at a time.
    				while (($line = fgets($handle)) !== false) {
    					$linesRead++;
    					echo nl2br(esc_html($line));
    					
    					if ($linesRead > 1000000) {
    						echo "<BR/><BR/>Read " . $linesRead . " lines. Download debug file to see more.";
    						break;
    					}
    				}
    			} else {
    				$this->logger->errorMessage("Error opening debug file.");
    			}
    			
    		} catch (Exception $e) {
    			$this->logger->errorMessage("Error while reading debug file.", $e);
    		}
    		
    		if ($handle != null) {
    			fclose($handle);
    		}
    		
    	} else {
    		echo nl2br(__('(The log file does not exist.)', '404-solution'));
    	}
    }

    /** Display the tools page.
     * @global type $abj404view
     */
    function echoAdminToolsPage() {
        global $abj404view;

        // Main container
        echo "<div class=\"abj404-container\">";
        echo "<div class=\"abj404-settings-content\">";

        // Header row with Expand All button
        echo "<div class=\"abj404-header-row\">";
        echo "<h2>" . esc_html__('Tools', '404-solution') . "</h2>";
        echo "<div class=\"abj404-header-controls\">";
        echo '<button type="button" id="abj404-expand-collapse-all" class="button">';
        echo esc_html__('Expand All', '404-solution');
        echo '</button>';
        echo "</div>";
        echo "</div>";

        // Export Card
        $link = wp_nonce_url("?page=" . ABJ404_PP . "&subpage=abj404_tools", "abj404_exportRedirects");
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/toolsExportForm.html");
        $html = $this->f->str_replace('{toolsExportRedirectsLink}', $link, $html);
        $html = $this->f->doNormalReplacements($html);
        $abj404view->echoOptionsSection('tools-export', 'abj404-exportRedirects', __('Export', '404-solution'), $html, true, $abj404view->getCardIcon('download'));

        // Import Card
        $link = wp_nonce_url("?page=" . ABJ404_PP . "&subpage=abj404_tools", "abj404_importRedirectsFile");
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/toolsImportForm.html");
        $html = $this->f->str_replace('{toolsImportRedirectsLink}', $link, $html);
        $html = $this->f->doNormalReplacements($html);
        $abj404view->echoOptionsSection('tools-import', 'abj404-importRedirects', __('Import', '404-solution'), $html, false, $abj404view->getCardIcon('upload'));

        // Purge Card
        $url = "?page=" . ABJ404_PP . "&subpage=abj404_tools";
        $link = wp_nonce_url($url, "abj404_purgeRedirects");
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/toolsPurgeForm.html");
        $html = $this->f->str_replace('{toolsPurgeFormActionLink}', $link, $html);
        $html = $this->f->doNormalReplacements($html);
        $abj404view->echoOptionsSection('tools-purge', 'abj404-purgeRedirects', __('Purge Options', '404-solution'), $html, false, $abj404view->getCardIcon('trash'));

        // Cache Management Card
        $ngramLink = wp_nonce_url("?page=" . ABJ404_PP . "&subpage=abj404_tools", "abj404_rebuildNgramCache");
        $spellingLink = wp_nonce_url("?page=" . ABJ404_PP . "&subpage=abj404_tools", "abj404_clearSpellingCache");
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/toolsCacheForm.html");
        $html = $this->f->str_replace('{toolsNgramCacheFormActionLink}', $ngramLink, $html);
        $html = $this->f->str_replace('{toolsSpellingCacheFormActionLink}', $spellingLink, $html);
        $html = $this->f->doNormalReplacements($html);
        $abj404view->echoOptionsSection('tools-cache', 'abj404-cacheTools', __('Cache Management', '404-solution'), $html, false, $abj404view->getCardIcon('database'));

        // Etcetera Card
        $link = wp_nonce_url("?page=" . ABJ404_PP . "&subpage=abj404_tools", "abj404_runMaintenance");
        $link .= '&manually_fired=true';
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/toolsEtcForm.html");
        $html = $this->f->str_replace('{toolsMaintenanceFormActionLink}', $link, $html);
        $html = $this->f->doNormalReplacements($html);
        $abj404view->echoOptionsSection('tools-etc', 'abj404-etcTools', __('Etcetera', '404-solution'), $html, false, $abj404view->getCardIcon('cog'));

        echo "</div>";
        echo "</div>";
    }
    
    function echoAdminOptionsPage() {
        global $abj404view;
        global $abj404viewSuggestions;

        // If globals are not set, use sensible defaults
        if ($abj404view === null) {
            $abj404view = $this;
        }

        $options = $this->logic->getOptions();
        if (!is_array($options)) {
            $options = [];
        }

        // Get the current user's settings mode preference
        $settingsMode = $this->logic->getSettingsMode();

        // if the current URL does not match the chosen menuLocation then redirect to the correct URL
        $helperFunctions = ABJ_404_Solution_Functions::getInstance();
        $urlParts = parse_url($helperFunctions->normalizeUrlString($_SERVER['REQUEST_URI']));
        $currentURL = $urlParts['path'];
        if (is_array($options) && isset($options['menuLocation']) &&
                $options['menuLocation'] == 'settingsLevel') {
            if ($this->f->strpos($currentURL, 'options-general.php') !== false) {
                // the option changed and we're at the wrong URL now, so we redirect to the correct one.
                $this->logic->forceRedirect(admin_url() . "admin.php?page=" .
                        ABJ404_PP . '&subpage=abj404_options');
            }
        } else if ($this->f->strpos($currentURL, 'admin.php') !== false) {
            // if the current URL has admin.php then the URLs don't match and we need to reload.
            $this->logic->forceRedirect(admin_url() . "options-general.php?page=" .
                    ABJ404_PP . '&subpage=abj404_options');
        }

        // Toast notification container
        $abj404view->echoToastNotification();

        // Options page header with mode toggle and expand button
        echo "\n<div class=\"abj404-header-row\">";
        echo "<h2>" . esc_html__('Options', '404-solution') . "</h2>";
        echo "<div class=\"abj404-header-controls\">";
        $this->echoInlineModeToggle();
        // Expand/Collapse All button for both Simple and Advanced modes
        echo '<button type="button" id="abj404-expand-collapse-all" class="button">';
        echo esc_html__('Expand All', '404-solution');
        echo '</button>';
        echo "</div>";
        echo "</div>";

        // Main container
        echo "<div class=\"abj404-container\">";
        echo "<div class=\"abj404-settings-content\">";

        $formBeginning = '<form method="POST" id="admin-options-page" ' .
        	'name="admin-options-page" action="#" data-url="{data-url}">' . "\n";
        $formBeginning .= '<input type="hidden" name="action" id="action" value="updateOptions">' . "\n";
        $formBeginning .= '<input type="hidden" name="nonce" id="nonce" value="' .
        	wp_create_nonce('abj404UpdateOptions') . '">' . "\n";
        $formBeginning = $this->f->str_replace('{data-url}',
        	"admin-ajax.php?action=updateOptions", $formBeginning);
        echo $formBeginning;

        // Add loading overlay for save operations
        echo '<div id="abj404-save-overlay" class="abj404-save-overlay" style="display: none;">';
        echo '<div class="abj404-save-overlay-content">';
        echo '<div class="abj404-spinner"></div>';
        echo '<p class="abj404-save-message">' . esc_html__('Saving settings...', '404-solution') . '</p>';
        echo '</div>';
        echo '</div>';

        if ($settingsMode === 'simple') {
            // Simple Mode: Show streamlined options with card layout
            $abj404view->echoSimpleModeOptions($options);
        } else {
            // Advanced Mode: Show all card sections with icons

            // Render each section with card structure and icons
            $contentAutomaticRedirects = $abj404view->getAdminOptionsPageAutoRedirects($options);
            $abj404view->echoOptionsSection(
                "abj404-autooptions",
                "abj404-autooptions",
                __('Automatic Redirects', '404-solution'),
                $contentAutomaticRedirects,
                true,
                $abj404view->getCardIcon('lightning')
            );

            $contentGeneralSettings = $abj404view->getAdminOptionsPageGeneralSettings($options);
            $abj404view->echoOptionsSection(
                "abj404-generaloptions",
                "abj404-generaloptions",
                __('General Settings', '404-solution'),
                $contentGeneralSettings,
                true,
                $abj404view->getCardIcon('gear')
            );

            $contentAdvancedContent = $abj404view->getAdminOptionsPageAdvancedContent($options);
            $abj404view->echoOptionsSection(
                "abj404-advanced-content",
                "abj404-advanced-content",
                __('Content & URL Filtering', '404-solution'),
                $contentAdvancedContent,
                true,
                $abj404view->getCardIcon('filter')
            );

            $contentAdvancedLogging = $abj404view->getAdminOptionsPageAdvancedLogging($options);
            $abj404view->echoOptionsSection(
                "abj404-advanced-logging",
                "abj404-advanced-logging",
                __('Logging & Privacy', '404-solution'),
                $contentAdvancedLogging,
                true,
                $abj404view->getCardIcon('document')
            );

            $contentAdvancedSystem = $abj404view->getAdminOptionsPageAdvancedSystem($options);
            $abj404view->echoOptionsSection(
                "abj404-advanced-system",
                "abj404-advanced-system",
                __('Advanced Configuration', '404-solution'),
                $contentAdvancedSystem,
                true,
                $abj404view->getCardIcon('sliders')
            );

            // Only render suggestions section if the suggestions view is available
            if ($abj404viewSuggestions !== null && method_exists($abj404viewSuggestions, 'getAdminOptionsPage404Suggestions')) {
                $content404PageSuggestions = $abj404viewSuggestions->getAdminOptionsPage404Suggestions($options);
                $abj404view->echoOptionsSection(
                    "abj404-suggestoptions",
                    "abj404-suggestoptions",
                    __('404 Page Suggestions', '404-solution'),
                    $content404PageSuggestions,
                    true,
                    $abj404view->getCardIcon('lightbulb')
                );
            }
        }

        // Sticky save bar
        $abj404view->echoStickySaveBar();

        echo "</form><!-- end in admin-options-page -->";

        echo "</div>"; // end abj404-settings-content
        echo "</div>"; // end abj404-container
    }
    
    /** 
     * @global type $abj404dao
     * @global type $abj404logic
     */
    function echoAdminEditRedirectPage() {

        $options = $this->logic->getOptions();

        // Modern page container
        echo '<div class="abj404-edit-page">';
        echo '<div class="abj404-edit-container">';
        echo '<h2>' . esc_html__('Edit Redirect', '404-solution') . '</h2>';

        $link = wp_nonce_url("?page=" . ABJ404_PP . "&subpage=abj404_edit", "abj404editRedirect");

        echo '<form method="POST" name="admin-edit-redirect" action="' . esc_attr($link) . '">';
        echo "<input type=\"hidden\" name=\"action\" value=\"editRedirect\">";

        // Capture source page and table options to return user to the same place after saving
        $source_page = $this->dao->getPostOrGetSanitize('source_page');
        if ($source_page === null) {
            $source_page = $this->dao->getPostOrGetSanitize('subpage');
        }
        // Default to redirects page if no source specified
        if ($source_page === null || $source_page == 'abj404_edit') {
            $source_page = 'abj404_redirects';
        }
        echo "<input type=\"hidden\" name=\"source_page\" value=\"" . esc_attr($source_page) . "\">";

        // Preserve table options so we can return to the exact same view
        $filter = $this->dao->getPostOrGetSanitize('filter');
        if ($filter !== null) {
            echo "<input type=\"hidden\" name=\"source_filter\" value=\"" . esc_attr($filter) . "\">";
        }
        $orderby = $this->dao->getPostOrGetSanitize('orderby');
        if ($orderby !== null) {
            echo "<input type=\"hidden\" name=\"source_orderby\" value=\"" . esc_attr($orderby) . "\">";
        }
        $order = $this->dao->getPostOrGetSanitize('order');
        if ($order !== null) {
            echo "<input type=\"hidden\" name=\"source_order\" value=\"" . esc_attr($order) . "\">";
        }
        $paged = $this->dao->getPostOrGetSanitize('paged');
        if ($paged !== null) {
            echo "<input type=\"hidden\" name=\"source_paged\" value=\"" . esc_attr($paged) . "\">";
        }

        $recnum = null;
        if (isset($_GET['id']) && $this->f->regexMatch('[0-9]+', $_GET['id'])) {
            $this->logger->debugMessage("Edit redirect page. GET ID: " .
                    wp_kses_post(json_encode($_GET['id'])));
            $recnum = absint($_GET['id']);

        } else if (isset($_POST['id']) && $this->f->regexMatch('[0-9]+', $_POST['id'])) {
            $this->logger->debugMessage("Edit redirect page. POST ID: " . 
                    wp_kses_post(json_encode($_POST['id'])));
            $recnum = absint($_POST['id']);
            
        } else if ($this->dao->getPostOrGetSanitize('idnum') !== null) {
            $recnums_multiple = array_map('absint', (array)$this->dao->getPostOrGetSanitize('idnum'));
            $this->logger->debugMessage("Edit redirect page. ids_multiple: " . 
                    wp_kses_post(json_encode($recnums_multiple)));

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
                echo "Error: Invalid ID Number! (id: " . esc_html($recnum) . ")";
                $this->logger->errorMessage("Error: Invalid ID Number! (id: " . esc_html($recnum) . ")");
                return;
            }
            
            $redirect = reset($redirects_multiple);
            $isRegexChecked = '';
            if ($redirect['status'] == ABJ404_STATUS_REGEX) {
                $isRegexChecked = ' checked ';
            }

            echo '<input type="hidden" name="id" value="' . esc_attr($redirect['id']) . '">';

            // URL field
            echo '<div class="abj404-form-group">';
            echo '<label class="abj404-form-label" for="url">' . esc_html__('URL', '404-solution') . ' *</label>';
            echo '<input type="text" id="url" name="url" class="abj404-form-input" value="' . esc_attr($redirect['url']) . '" required>';
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
            foreach ($redirects_multiple as $redirect) {
                echo '<li><code>' . esc_html($redirect['url']) . '</code></li>';
            }
            echo '</ul>';
            echo '</div>';
            echo '</div>';
            
            // here we set the variable to the first value returned because it's used to set default values
            // in the form data.
            $redirect = reset($redirects_multiple);
            
        } else {
            echo "Error: Invalid ID Number(s) specified! (id: " . $recnum . ", ids: " . $recnums_multiple . ")";
            $this->logger->debugMessage("Error: Invalid ID Number(s) specified! (id: " . $recnum . 
                    ", ids: " . $recnums_multiple . ")");
            return;
        }
        
        $final = "";
        $pageIDAndType = "";
        if ($redirect['type'] == ABJ404_TYPE_EXTERNAL) {
            $final = $redirect['final_dest'];
            $pageIDAndType = ABJ404_TYPE_EXTERNAL . "|" . ABJ404_TYPE_EXTERNAL;
            
        } else if ($redirect['final_dest'] != 0) {
            // if a destination has been specified then let's fill it in.
            $pageIDAndType = $redirect['final_dest'] . "|" . $redirect['type'];
            
        } else if ($redirect['type'] == ABJ404_TYPE_404_DISPLAYED) {
        	$pageIDAndType = ABJ404_TYPE_404_DISPLAYED . "|" . ABJ404_TYPE_404_DISPLAYED;
        }
        
        if ($redirect['code'] == "") {
            $codeSelected = $options['default_redirect'];
        } else {
            $codeSelected = $redirect['code'];
        }
        
        $pageTitle = $this->logic->getPageTitleFromIDAndType($pageIDAndType, $redirect['final_dest']);
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
        $html = $this->f->str_replace('{redirectPageTitle}', $pageTitle, $html);
        $html = $this->f->str_replace('{pageIDAndType}', $pageIDAndType, $html);
        $html = $this->f->str_replace('{data-url}',
                "admin-ajax.php?action=echoRedirectToPages&includeDefault404Page=true&includeSpecial=true&nonce=" . wp_create_nonce('abj404_ajax'), $html);
        $html = $this->f->doNormalReplacements($html);
        echo $html;
        
        $this->echoEditRedirect($final, $codeSelected, __('Update Redirect', '404-solution'), $source_page, $filter, $orderby, $order);

        echo '</form>';
        echo '</div>'; // end abj404-edit-container
        echo '</div>'; // end abj404-edit-page
    }
    
    function echoRedirectDestinationOptionsOthers($dest, $rows) {
        $content = array();
        
        $rowCounter = 0;
        $currentPostType = '';
        
        foreach ($rows as $row) {
            $rowCounter++;
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
            for ($i = 0; $i < $row->depth; $i++) {
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

    function echoRedirectDestinationOptionsCatsTags($dest) {
        $content = "";
        $content .= "\n" . '<optgroup label="Categories">' . "\n";
        
        $customTagsEtc = array();

        // categories ---------------------------------------------
        $cats = $this->dao->getPublishedCategories();
        foreach ($cats as $cat) {
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
        $customTagsEtc = $this->logic->getMapOfCustomCategories($cats);

        // tags ---------------------------------------------
        $content .= "\n" . '<optgroup label="Tags">' . "\n";
        $tags = $this->dao->getPublishedTags();
        foreach ($tags as $tag) {
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
     * @global type $abj404dao
     */
    function echoAdminCapturedURLsPage() {
        $sub = 'abj404_captured';

        $tableOptions = $this->logic->getTableOptions($sub);

        $timezone = get_option('timezone_string');
        if ('' == $timezone) {
            $timezone = 'UTC';
        }
        date_default_timezone_set($timezone);

        // Get counts for tabs
        $counts = $this->dao->getCapturedStatusCounts();

        // Modern page wrapper
        echo '<div class="abj404-table-page">';

        // Header with page title
        echo '<div class="abj404-table-header">';
        echo '<h2>' . __('Captured 404 URLs', '404-solution') . '</h2>';
        echo '</div>';

        // Content tabs (Captured, Ignored, Later, Trash)
        echo '<div class="abj404-content-tabs">';
        $baseUrl = "?page=" . ABJ404_PP . "&subpage=abj404_captured";
        $baseUrl .= "&orderby=" . sanitize_text_field($tableOptions['orderby']);
        $baseUrl .= "&order=" . sanitize_text_field($tableOptions['order']);

        // All tab
        $this->echoContentTab('abj404_captured', 0, __('All', '404-solution'), $counts['all'], $tableOptions);
        // Captured tab
        $this->echoContentTab('abj404_captured', ABJ404_STATUS_CAPTURED, __('Captured', '404-solution'), $counts['captured'], $tableOptions);
        // Ignored tab
        $this->echoContentTab('abj404_captured', ABJ404_STATUS_IGNORED, __('Ignored', '404-solution'), $counts['ignored'], $tableOptions);
        // Organize Later tab
        $this->echoContentTab('abj404_captured', ABJ404_STATUS_LATER, __('Later', '404-solution'), $counts['later'], $tableOptions);
        // Trash tab
        $this->echoContentTab('abj404_captured', ABJ404_TRASH_FILTER, __('Trash', '404-solution'), $counts['trash'], $tableOptions);
        echo '</div>';

        // Filter bar with server-side search
        $filterText = isset($tableOptions['filterText']) ? $tableOptions['filterText'] : '';
        $perPage = isset($tableOptions['perpage']) ? $tableOptions['perpage'] : 25;

        $paginationNonce = wp_create_nonce('abj404_updatePaginationLink');
        echo '<div class="abj404-filter-bar tablenav"'
                . ' data-pagination-ajax-url="' . esc_attr(admin_url('admin-ajax.php')) . '"'
                . ' data-pagination-ajax-action="ajaxUpdatePaginationLinks"'
                . ' data-pagination-ajax-subpage="' . esc_attr($sub) . '"'
                . ' data-pagination-ajax-nonce="' . esc_attr($paginationNonce) . '">';
        echo '<div class="abj404-search-box">';
        echo '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/></svg>';
        echo '<input type="search" name="searchFilter" placeholder="' . esc_attr__('Type to filter URLs... (press Enter)', '404-solution') . '" value="' . esc_attr($filterText) . '" data-lpignore="true">';
        echo '</div>';
        echo '<div class="abj404-rows-per-page">';
        echo '<span>' . esc_html__('Rows per page:', '404-solution') . '</span>';
        echo '<select class="abj404-filter-select perpage" name="perpage" onchange="paginationLinksChange(this);">';
        foreach ([10, 25, 50, 100, 200] as $opt) {
            $selected = ($perPage == $opt) ? ' selected' : '';
            echo '<option value="' . $opt . '"' . $selected . '>' . $opt . '</option>';
        }
        echo '</select>';
        echo '</div>';

        // Empty trash button
        if ($tableOptions['filter'] == ABJ404_TRASH_FILTER) {
            $eturl = "?page=" . ABJ404_PP . "&subpage=abj404_captured&filter=" . ABJ404_TRASH_FILTER;
            $eturl = wp_nonce_url($eturl, 'abj404_bulkProcess');
            echo '<a href="' . esc_url($eturl) . '&abj404action=emptyCapturedTrash" class="button abj404-empty-trash-btn" onclick="return confirm(\'' . esc_js(__('Are you sure you want to permanently delete all items in trash?', '404-solution')) . '\');">';
            echo esc_html__('Empty Trash', '404-solution');
            echo '</a>';
        }
        echo '</div>';

        // Bulk actions bar
        $url = $this->getBulkOperationsFormURL($sub, $tableOptions);
        echo '<div class="abj404-bulk-actions">';
        echo '<div class="abj404-selection-info"><strong>0</strong> ' . __('selected', '404-solution') . '</div>';
        echo '<div class="abj404-bulk-buttons">';

        // Bulk action buttons based on current filter
        if ($tableOptions['filter'] != ABJ404_STATUS_CAPTURED) {
            echo '<button type="submit" name="abj404action" value="bulkcaptured" form="bulk-action-form" class="button">' . __('Mark Captured', '404-solution') . '</button>';
        }
        if ($tableOptions['filter'] != ABJ404_STATUS_IGNORED) {
            echo '<button type="submit" name="abj404action" value="bulkignore" form="bulk-action-form" class="button">' . __('Mark Ignored', '404-solution') . '</button>';
        }
        if ($tableOptions['filter'] != ABJ404_STATUS_LATER) {
            echo '<button type="submit" name="abj404action" value="bulklater" form="bulk-action-form" class="button">' . __('Organize Later', '404-solution') . '</button>';
        }
        if ($tableOptions['filter'] != ABJ404_TRASH_FILTER) {
            echo '<button type="submit" name="abj404action" value="bulktrash" form="bulk-action-form" class="button">' . __('Move to Trash', '404-solution') . '</button>';
        }
        echo '<button type="submit" name="abj404action" value="editRedirect" form="bulk-action-form" class="button">' . __('Create Redirect', '404-solution') . '</button>';

        echo '</div>';
        echo '<button type="button" class="abj404-clear-selection" onclick="abj404ClearSelection()">' . __('Clear', '404-solution') . '</button>';
        echo '</div>';

        // Hidden form for bulk actions
        echo '<form id="bulk-action-form" method="POST" action="' . esc_url($url) . '">';
        wp_nonce_field('abj404_bulkProcess');

        // Table
        echo $this->getCapturedURLSPageTable($sub);

        // Pagination (using original AJAX-integrated pagination)
        echo $this->getPaginationLinks($sub, false);

        echo '</form>';
        echo '</div><!-- .abj404-table-page -->';
    }
    
    function getCapturedURLSPageTable($sub) {

        $tableOptions = $this->logic->getTableOptions($sub);

        // Build column headers with sorting
        $hitsTooltip = $this->getHitsColumnTooltip();
        $columns = array(
            'url' => array('title' => __('URL', '404-solution'), 'orderby' => 'url'),
            'status' => array('title' => __('Status', '404-solution'), 'orderby' => 'status'),
            'hits' => array('title' => __('Hits', '404-solution'), 'orderby' => 'logshits', 'title_attr_html' => $hitsTooltip),
            'timestamp' => array('title' => __('Created', '404-solution'), 'orderby' => 'timestamp', 'class' => 'hide-on-tablet'),
            'last_used' => array('title' => __('Last Used', '404-solution'), 'orderby' => 'last_used', 'title_attr_html' => $hitsTooltip),
        );

        $html = '<table class="abj404-table">';
        $html .= '<thead><tr>';
        $html .= '<th scope="col"><input type="checkbox" id="select-all-captured" aria-label="' . esc_attr__('Select all', '404-solution') . '"></th>';

        // Generate sortable column headers
        foreach ($columns as $key => $col) {
            $sortUrl = "?page=" . ABJ404_PP . "&subpage=abj404_captured&filter=" . $tableOptions['filter'];
            $sortUrl .= "&orderby=" . $col['orderby'];
            $newOrder = ($tableOptions['orderby'] == $col['orderby'] && $tableOptions['order'] == 'ASC') ? 'DESC' : 'ASC';
            $sortUrl .= "&order=" . $newOrder;

            $sortClass = '';
            $sortIndicator = '';
            $extraClass = isset($col['class']) ? ' ' . esc_attr($col['class']) : '';
            if ($tableOptions['orderby'] == $col['orderby']) {
                $sortClass = 'sorted ' . strtolower($tableOptions['order']) . $extraClass;
                $sortIndicator = $tableOptions['order'] == 'ASC' ? ' ↑' : ' ↓';
            } else {
                $sortClass = trim($extraClass);
            }

            // Add tooltip class if title_attr or title_attr_html exists
            $hasTooltip = (isset($col['title_attr']) && !empty($col['title_attr'])) ||
                          (isset($col['title_attr_html']) && !empty($col['title_attr_html']));
            if ($hasTooltip) {
                $sortClass .= ' lefty-tooltip';
            }
            $classAttr = $sortClass ? ' class="' . trim($sortClass) . '"' : '';

            // Build tooltip HTML if present
            $tooltipHtml = '';
            if (isset($col['title_attr_html']) && !empty($col['title_attr_html'])) {
                // Raw HTML (already escaped where needed)
                $tooltipHtml = '<span class="lefty-tooltiptext">' . $col['title_attr_html'] . '</span>';
            } elseif (isset($col['title_attr']) && !empty($col['title_attr'])) {
                // Plain text - escape it
                $tooltipHtml = '<span class="lefty-tooltiptext">' . esc_html($col['title_attr']) . '</span>';
            }

            $html .= '<th scope="col"' . $classAttr . '>' . $tooltipHtml . '<a href="' . esc_url($sortUrl) . '">' . esc_html($col['title']) . $sortIndicator . '</a></th>';
        }

        $html .= '</tr></thead>';
        $html .= '<tbody id="the-list">';

        $rows = $this->dao->getRedirectsForView($sub, $tableOptions);
        $displayed = 0;

        foreach ($rows as $row) {
            $displayed++;

            $hits = $row['logshits'];

            $last_used = $row['last_used'];
            $lastUsedClass = '';
            if ($last_used != 0) {
                $last = date("Y/m/d h:i:s A", abs(intval($last_used)));
            } else {
                $last = __('Never', '404-solution');
                $lastUsedClass = 'abj404-never-used';
            }

            // Build action links using helper method
            $links = $this->buildTableActionLinks($row, $sub, $tableOptions, true);
            extract($links);

            // Determine status badge
            $statusBadgeClass = 'abj404-badge-captured';
            $statusText = __('Captured', '404-solution');
            $statusTitle = __('Captured 404 URL', '404-solution');

            if ($row['status'] == ABJ404_STATUS_IGNORED) {
                $statusBadgeClass = 'abj404-badge-ignored';
                $statusText = __('Ignored', '404-solution');
                $statusTitle = __('Ignored URL - will not be suggested', '404-solution');
            } else if ($row['status'] == ABJ404_STATUS_LATER) {
                $statusBadgeClass = 'abj404-badge-later';
                $statusText = __('Later', '404-solution');
                $statusTitle = __('Organize Later', '404-solution');
            }

            // Build row action buttons
            $editBtnHTML = '';
            $logsBtnHTML = '';
            $trashBtnHTML = '';
            $deleteBtnHTML = '';
            $ignoreBtnHTML = '';
            $laterBtnHTML = '';

            if ($tableOptions['filter'] != ABJ404_TRASH_FILTER) {
                $editBtnHTML = '<a href="' . esc_url($editlink) . '" class="abj404-action-link" title="' . esc_attr__('Edit', '404-solution') . '">'
                    . '<svg viewBox="0 0 20 20" fill="currentColor"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/></svg> '
                    . esc_html__('Edit', '404-solution') . '</a>';
            }

            if ($row['logsid'] > 0) {
                $logsBtnHTML = '<a href="' . esc_url($logslink) . '" class="abj404-action-link" title="' . esc_attr__('View Logs', '404-solution') . '">'
                    . '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/></svg> '
                    . esc_html__('Logs', '404-solution') . '</a>';
            }

            if ($tableOptions['filter'] != ABJ404_TRASH_FILTER) {
                $trashBtnHTML = '<a href="' . esc_url($trashlink) . '" class="abj404-action-link danger" title="' . esc_attr($trashtitle) . '">'
                    . '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"/></svg> '
                    . esc_html__('Trash', '404-solution') . '</a>';
            }

            if ($tableOptions['filter'] == ABJ404_TRASH_FILTER) {
                // Show Restore button
                $trashBtnHTML = '<a href="' . esc_url($trashlink) . '" class="abj404-action-link" title="' . esc_attr__('Restore', '404-solution') . '">'
                    . '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd"/></svg> '
                    . esc_html__('Restore', '404-solution') . '</a>';
                $deleteBtnHTML = ' | <a href="' . esc_url($deletelink) . '" class="abj404-action-link danger" title="' . esc_attr__('Delete Permanently', '404-solution') . '" onclick="return confirm(\'' . esc_js(__('Are you sure you want to permanently delete this item?', '404-solution')) . '\');">'
                    . '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg> '
                    . esc_html__('Delete', '404-solution') . '</a>';
            } else {
                // Show Ignore and Later buttons (with separators)
                if ($row['status'] != ABJ404_STATUS_IGNORED) {
                    $ignoreBtnHTML = ' | <a href="' . esc_url($ignorelink) . '" class="abj404-action-link" title="' . esc_attr($ignoretitle) . '">'
                        . '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M13.477 14.89A6 6 0 015.11 6.524l8.367 8.368zm1.414-1.414L6.524 5.11a6 6 0 018.367 8.367zM18 10a8 8 0 11-16 0 8 8 0 0116 0z" clip-rule="evenodd"/></svg> '
                        . esc_html__('Ignore', '404-solution') . '</a>';
                }
                if ($row['status'] != ABJ404_STATUS_LATER) {
                    $laterBtnHTML = ' | <a href="' . esc_url($laterlink) . '" class="abj404-action-link" title="' . esc_attr($latertitle) . '">'
                        . '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/></svg> '
                        . esc_html__('Later', '404-solution') . '</a>';
                }
            }

            // Build full URL for visiting
            $fullVisitorURL = esc_url(home_url($row['url']));

            $tempHtml = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/tableRowCapturedURLs.html");
            $tempHtml = $this->f->str_replace('{rowid}', $row['id'], $tempHtml);
            $tempHtml = $this->f->str_replace('{rowClass}', '', $tempHtml);
            $tempHtml = $this->f->str_replace('{visitorURL}', $fullVisitorURL, $tempHtml);
            $tempHtml = $this->f->str_replace('{url}', esc_html($row['url']), $tempHtml);
            $tempHtml = $this->f->str_replace('{statusBadgeClass}', $statusBadgeClass, $tempHtml);
            $tempHtml = $this->f->str_replace('{statusTitle}', esc_attr($statusTitle), $tempHtml);
            $tempHtml = $this->f->str_replace('{status}', $statusText, $tempHtml);
            $tempHtml = $this->f->str_replace('{hits}', esc_html($hits), $tempHtml);
            $tempHtml = $this->f->str_replace('{created_date}',
                    esc_html(date("Y/m/d h:i:s A", abs(intval($row['timestamp'])))), $tempHtml);
            $tempHtml = $this->f->str_replace('{last_used_date}', esc_html($last), $tempHtml);
            $tempHtml = $this->f->str_replace('{lastUsedClass}', $lastUsedClass, $tempHtml);
            $tempHtml = $this->f->str_replace('{editBtnHTML}', $editBtnHTML, $tempHtml);
            $tempHtml = $this->f->str_replace('{logsBtnHTML}', $logsBtnHTML, $tempHtml);
            $tempHtml = $this->f->str_replace('{trashBtnHTML}', $trashBtnHTML, $tempHtml);
            $tempHtml = $this->f->str_replace('{deleteBtnHTML}', $deleteBtnHTML, $tempHtml);
            $tempHtml = $this->f->str_replace('{ignoreBtnHTML}', $ignoreBtnHTML, $tempHtml);
            $tempHtml = $this->f->str_replace('{laterBtnHTML}', $laterBtnHTML, $tempHtml);

            $tempHtml = $this->f->doNormalReplacements($tempHtml);
            $html .= $tempHtml;
        }

        if ($displayed == 0) {
            $html .= '<tr><td colspan="7" class="abj404-empty-message">' . __('No Captured 404 Records To Display', '404-solution') . '</td></tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }

    /** 
     * @global type $abj404dao
     * @global type $abj404logic
     */
    function echoAdminRedirectsPage() {

        $sub = 'abj404_redirects';

        $tableOptions = $this->logic->getTableOptions($sub);

        // Sanitizing unchecked table options
        $tableOptions = $this->logic->sanitizePostData($tableOptions);

        $timezone = get_option('timezone_string');
        if ('' == $timezone) {
            $timezone = 'UTC';
        }
        date_default_timezone_set($timezone);

        // Get counts for tabs
        $counts = $this->dao->getRedirectStatusCounts();

        // Modern table page wrapper
        echo '<div class="abj404-table-page">';

        // Page header with Add Redirect button
        echo '<div class="abj404-table-header">';
        echo '<h1>' . esc_html__('Page Redirects', '404-solution') . '</h1>';
        if ($tableOptions['filter'] != ABJ404_TRASH_FILTER) {
            echo '<button type="button" class="abj404-btn abj404-btn-primary" data-modal-open="abj404-add-redirect-modal">';
            echo '+ ' . esc_html__('Add Redirect', '404-solution');
            echo '</button>';
        }
        echo '</div>';

        // Content tabs
        echo '<div class="abj404-content-tabs">';
        $this->echoContentTab($sub, 0, __('All', '404-solution'), $counts['all'] ?? 0, $tableOptions);
        $this->echoContentTab($sub, ABJ404_STATUS_MANUAL, __('Manual', '404-solution'), $counts['manual'] ?? 0, $tableOptions);
        $this->echoContentTab($sub, ABJ404_STATUS_AUTO, __('Automatic', '404-solution'), $counts['auto'] ?? 0, $tableOptions);
        $this->echoContentTab($sub, ABJ404_TRASH_FILTER, __('Trash', '404-solution'), $counts['trash'] ?? 0, $tableOptions);
        echo '</div>';

        // Filter bar with server-side search
        $filterText = isset($tableOptions['filterText']) ? $tableOptions['filterText'] : '';
        $perPage = isset($tableOptions['perpage']) ? $tableOptions['perpage'] : 25;

        $paginationNonce = wp_create_nonce('abj404_updatePaginationLink');
        echo '<div class="abj404-filter-bar tablenav"'
                . ' data-pagination-ajax-url="' . esc_attr(admin_url('admin-ajax.php')) . '"'
                . ' data-pagination-ajax-action="ajaxUpdatePaginationLinks"'
                . ' data-pagination-ajax-subpage="' . esc_attr($sub) . '"'
                . ' data-pagination-ajax-nonce="' . esc_attr($paginationNonce) . '">';
        echo '<div class="abj404-search-box">';
        echo '<svg class="abj404-search-icon" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">';
        echo '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>';
        echo '</svg>';
        echo '<input type="search" name="searchFilter" placeholder="' . esc_attr__('Type to filter redirects... (press Enter)', '404-solution') . '" value="' . esc_attr($filterText) . '" data-lpignore="true">';
        echo '</div>';
        echo '<div class="abj404-rows-per-page">';
        echo '<span>' . esc_html__('Rows per page:', '404-solution') . '</span>';
        echo '<select class="abj404-filter-select perpage" name="perpage" onchange="paginationLinksChange(this);">';
        foreach ([10, 25, 50, 100, 200] as $opt) {
            $selected = ($perPage == $opt) ? ' selected' : '';
            echo '<option value="' . $opt . '"' . $selected . '>' . $opt . '</option>';
        }
        echo '</select>';
        echo '</div>';
        echo '</div>';

        // Bulk actions bar (hidden by default, shown when items selected)
        $url = $this->getBulkOperationsFormURL($sub, $tableOptions);

        // Form must wrap both bulk actions and table so dropdown values are submitted
        echo '<form method="POST" action="' . esc_url($url) . '">';

        echo '<div class="abj404-bulk-actions" id="abj404-bulk-actions">';
        echo '<span class="abj404-selection-info"><strong>0</strong> ' . esc_html__('redirects selected', '404-solution') . '</span>';
        echo '<select class="abj404-filter-select" name="abj404action">';
        echo '<option value="">' . esc_html__('Bulk Actions', '404-solution') . '</option>';
        if ($tableOptions['filter'] != ABJ404_STATUS_AUTO) {
            echo '<option value="editRedirect">' . esc_html__('Edit Redirects', '404-solution') . '</option>';
        }
        if ($tableOptions['filter'] != ABJ404_TRASH_FILTER) {
            echo '<option value="bulktrash">' . esc_html__('Move to Trash', '404-solution') . '</option>';
        }
        if ($tableOptions['filter'] == ABJ404_TRASH_FILTER) {
            echo '<option value="bulk_trash_restore">' . esc_html__('Restore Redirects', '404-solution') . '</option>';
            echo '<option value="bulk_trash_delete_permanently">' . esc_html__('Delete Permanently', '404-solution') . '</option>';
        }
        echo '</select>';
        echo '<button type="submit" class="abj404-btn abj404-btn-primary">' . esc_html__('Apply', '404-solution') . '</button>';
        echo '<button type="button" class="abj404-btn abj404-btn-secondary abj404-clear-selection" onclick="abj404ClearSelection()">' . esc_html__('Clear Selection', '404-solution') . '</button>';
        echo '</div>';

        // Table container
        echo '<div class="abj404-table-container">';
        echo $this->getAdminRedirectsPageTable($sub);
        echo '</div>';

        // Pagination (using original AJAX-integrated pagination)
        echo $this->getPaginationLinks($sub, false);

        echo '</form>';

        // Empty trash button (within page container but outside form)
        if ($tableOptions['filter'] == ABJ404_TRASH_FILTER) {
            $eturl = "?page=" . ABJ404_PP . "&filter=" . ABJ404_TRASH_FILTER . "&subpage=" . $sub;
            $eturl = wp_nonce_url($eturl, "abj404_bulkProcess");
            echo '<div style="padding: 0 32px 20px;">';
            echo '<form method="POST" action="' . esc_url($eturl) . '">';
            echo '<input type="hidden" name="action" value="emptyRedirectTrash">';
            echo '<button type="submit" class="abj404-btn abj404-btn-secondary" onclick="return confirm(\'' . esc_js(__('Are you sure you want to permanently delete all items in the trash?', '404-solution')) . '\')">';
            echo esc_html__('Empty Trash', '404-solution');
            echo '</button>';
            echo '</form>';
            echo '</div>';
        }

        echo '</div>'; // end abj404-table-page

        // Add redirect modal (outside the main container)
        if ($tableOptions['filter'] != ABJ404_TRASH_FILTER) {
            $this->echoAddRedirectModal($tableOptions);
        }
    }

    /**
     * Echo a content tab for the table pages
     */
    function echoContentTab($sub, $filter, $label, $count, $tableOptions) {
        $isActive = ($tableOptions['filter'] == $filter) ? 'active' : '';
        $url = "?page=" . ABJ404_PP . "&subpage=" . $sub;
        if ($filter != 0) {
            $url .= "&filter=" . $filter;
        }
        echo '<a href="' . esc_url($url) . '" class="abj404-content-tab ' . $isActive . '">';
        echo esc_html($label);
        echo '<span class="abj404-tab-count">' . esc_html($count) . '</span>';
        echo '</a>';
    }

    /**
     * Echo the modern Add Redirect modal
     */
    function echoAddRedirectModal($tableOptions) {
        $options = $this->logic->getOptions();
        $url = "?page=" . ABJ404_PP;
        if (!( $tableOptions['orderby'] == "url" && $tableOptions['order'] == "ASC" )) {
            $url .= "&orderby=" . sanitize_text_field($tableOptions['orderby']) . "&order=" . sanitize_text_field($tableOptions['order']);
        }
        if ($tableOptions['filter'] != 0) {
            $url .= "&filter=" . $tableOptions['filter'];
        }
        $link = wp_nonce_url($url, "abj404addRedirect");
        $urlPlaceholder = parse_url(get_home_url(), PHP_URL_PATH) . "/example";

        echo '<div class="abj404-modal" id="abj404-add-redirect-modal">';
        echo '<div class="abj404-modal-content">';
        echo '<div class="abj404-modal-header">';
        echo '<h2>' . esc_html__('Add Manual Redirect', '404-solution') . '</h2>';
        echo '<button type="button" class="abj404-modal-close" onclick="abj404CloseAddRedirectModal()">&times;</button>';
        echo '</div>';
        echo '<form method="POST" action="' . esc_url($link) . '">';
        echo '<input type="hidden" name="action" value="addRedirect">';
        echo '<div class="abj404-modal-body">';

        // URL field
        echo '<div class="abj404-form-group">';
        echo '<label class="abj404-form-label">' . esc_html__('URL', '404-solution') . ' *</label>';
        echo '<input type="text" name="manual_redirect_url" class="abj404-form-input" placeholder="' . esc_attr($urlPlaceholder) . '" required>';
        echo '<p class="abj404-form-help">' . esc_html__('The URL path that should be redirected (without domain)', '404-solution') . '</p>';
        echo '<div class="abj404-checkbox-group" style="margin-top: 12px;">';
        echo '<input type="checkbox" name="is_regex_url" id="modal_is_regex" class="abj404-checkbox-input" value="1">';
        echo '<label for="modal_is_regex" class="abj404-checkbox-label">' . esc_html__('Treat this URL as a regular expression', '404-solution') . '</label>';
        echo ' <a href="#" class="abj404-regex-toggle" onclick="abj404ToggleRegexInfo(event)">' . esc_html__('(Explain)', '404-solution') . '</a>';
        echo '</div>';
        echo '<div class="abj404-regex-info" style="display: none;">';
        echo '<p>' . esc_html__('When checked, the text is treated as a regular expression. Note that including a bad regular expression or one that takes too long will break your website. So please use caution and test them elsewhere before trying them here. If you don\'t know what you\'re doing please don\'t use this option (as it\'s not necessary for the functioning of the plugin).', '404-solution') . '</p>';
        echo '<p><strong>' . esc_html__('Example:', '404-solution') . '</strong> <code>/events/(.+)</code></p>';
        echo '<p>' . esc_html__('/events/(.+) will match any URL that begins with /events/ and redirect to the specified page. Since a capture group is used, you can use a $1 replacement in the destination string of an external URL.', '404-solution') . '</p>';
        echo '<p>' . esc_html__('First, all of the normal "exact match" URLs are checked, then all of the regular expression URLs are checked.', '404-solution') . '</p>';
        echo '</div>';
        echo '</div>';

        // Redirect to field - using the existing AJAX autocomplete template
        echo '<div class="abj404-form-group abj404-autocomplete-wrapper">';
        echo '<label class="abj404-form-label">' . esc_html__('Redirect to', '404-solution') . ' *</label>';

        // Load the autocomplete HTML template (includes wrapper and spinner)
        $redirectHtml = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/addManualRedirectPageSearchDropdown.html");
        $redirectHtml = $this->f->str_replace('{redirect_to_label}', '', $redirectHtml);
        $redirectHtml = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_EMPTY}',
            __('(Type a page name or an external URL)', '404-solution'), $redirectHtml);
        $redirectHtml = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_EMTPY}',
            __('(Type a page name or an external URL)', '404-solution'), $redirectHtml);
        $redirectHtml = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_PAGE}',
            __('(A page has been selected.)', '404-solution'), $redirectHtml);
        $redirectHtml = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_CUSTOM_STRING}',
            __('(A custom string has been entered.)', '404-solution'), $redirectHtml);
        $redirectHtml = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_URL}',
            __('(An external URL will be used.)', '404-solution'), $redirectHtml);
        $redirectHtml = $this->f->str_replace('{REDIRECT_TO_USER_FIELD_WARNING}', '', $redirectHtml);
        $redirectHtml = $this->f->str_replace('{redirectPageTitle}', '', $redirectHtml);
        $redirectHtml = $this->f->str_replace('{pageIDAndType}', '', $redirectHtml);
        $redirectHtml = $this->f->str_replace('{data-url}',
            "admin-ajax.php?action=echoRedirectToPages&includeDefault404Page=true&includeSpecial=true&nonce=" . wp_create_nonce('abj404_ajax'), $redirectHtml);
        $redirectHtml = $this->f->doNormalReplacements($redirectHtml);
        echo $redirectHtml;

        echo '</div>';

        // Redirect type
        echo '<div class="abj404-form-group">';
        echo '<label class="abj404-form-label">' . esc_html__('Redirect Type', '404-solution') . '</label>';
        echo '<select name="code" class="abj404-form-select">';
        $selected301 = ($options['default_redirect'] == '301') ? ' selected' : '';
        $selected302 = ($options['default_redirect'] == '302') ? ' selected' : '';
        echo '<option value="301"' . $selected301 . '>301 - ' . esc_html__('Permanent Redirect (Recommended for SEO)', '404-solution') . '</option>';
        echo '<option value="302"' . $selected302 . '>302 - ' . esc_html__('Temporary Redirect', '404-solution') . '</option>';
        echo '</select>';
        echo '</div>';

        echo '</div>';
        echo '<div class="abj404-modal-footer">';
        echo '<button type="button" class="abj404-btn abj404-btn-secondary" onclick="abj404CloseAddRedirectModal()">' . esc_html__('Cancel', '404-solution') . '</button>';
        echo '<button type="submit" class="abj404-btn abj404-btn-primary">' . esc_html__('Add Redirect', '404-solution') . '</button>';
        echo '</div>';
        echo '</form>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * Get modern pagination HTML
     */
    function getModernPagination($sub, $tableOptions) {
        // Use appropriate count method based on sub type
        if ($sub == 'abj404_logs') {
            $totalRows = $this->dao->getLogsCount($tableOptions['logsid']);
        } else {
            $totalRows = $this->dao->getRedirectsForViewCount($sub, $tableOptions);
        }
        $perPage = isset($tableOptions['perpage']) ? intval($tableOptions['perpage']) : 25;
        $currentPage = isset($tableOptions['paged']) ? intval($tableOptions['paged']) : 1;
        $totalPages = ceil($totalRows / $perPage);

        if ($totalPages <= 1) {
            return '';
        }

        $startItem = (($currentPage - 1) * $perPage) + 1;
        $endItem = min($currentPage * $perPage, $totalRows);

        $baseUrl = "?page=" . ABJ404_PP . "&subpage=" . $sub;
        // Include logsid for logs pagination
        if ($sub == 'abj404_logs' && isset($tableOptions['logsid'])) {
            $baseUrl .= "&id=" . $tableOptions['logsid'];
        }
        if ($tableOptions['filter'] != 0) {
            $baseUrl .= "&filter=" . $tableOptions['filter'];
        }
        if (!( $tableOptions['orderby'] == "url" && $tableOptions['order'] == "ASC" )) {
            $baseUrl .= "&orderby=" . sanitize_text_field($tableOptions['orderby']) . "&order=" . sanitize_text_field($tableOptions['order']);
        }

        // Different label for logs vs redirects
        $itemLabel = ($sub == 'abj404_logs') ? __('logs', '404-solution') : __('redirects', '404-solution');

        $html = '<div class="abj404-pagination">';
        $html .= '<div class="abj404-pagination-info">';
        $html .= sprintf(
            /* translators: %1$d is start item, %2$d is end item, %3$d is total count, %4$s is item type (logs/redirects) */
            esc_html__('Showing %1$d-%2$d of %3$d %4$s', '404-solution'),
            $startItem,
            $endItem,
            $totalRows,
            $itemLabel
        );
        $html .= '</div>';
        $html .= '<div class="abj404-pagination-controls">';

        // Previous button
        if ($currentPage > 1) {
            $html .= '<a href="' . esc_url($baseUrl . '&paged=' . ($currentPage - 1)) . '" class="abj404-page-btn">&lsaquo;</a>';
        } else {
            $html .= '<span class="abj404-page-btn disabled">&lsaquo;</span>';
        }

        // Page numbers
        $range = 2;
        for ($i = 1; $i <= $totalPages; $i++) {
            if ($i == 1 || $i == $totalPages || ($i >= $currentPage - $range && $i <= $currentPage + $range)) {
                $activeClass = ($i == $currentPage) ? ' active' : '';
                $html .= '<a href="' . esc_url($baseUrl . '&paged=' . $i) . '" class="abj404-page-btn' . $activeClass . '">' . $i . '</a>';
            } elseif ($i == $currentPage - $range - 1 || $i == $currentPage + $range + 1) {
                $html .= '<span class="abj404-page-ellipsis">&hellip;</span>';
            }
        }

        // Next button
        if ($currentPage < $totalPages) {
            $html .= '<a href="' . esc_url($baseUrl . '&paged=' . ($currentPage + 1)) . '" class="abj404-page-btn">&rsaquo;</a>';
        } else {
            $html .= '<span class="abj404-page-btn disabled">&rsaquo;</span>';
        }

        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }
    
    function getBulkOperationsFormURL($sub, $tableOptions) {
        $url = "?page=" . ABJ404_PP . "&subpage=" . $sub;
        if ($tableOptions['filter'] != 0) {
            $url .= "&filter=" . $tableOptions['filter'];
        }
        if (!( $tableOptions['orderby'] == "url" && $tableOptions['order'] == "ASC" )) {
            $url .= "&orderby=" . sanitize_text_field($tableOptions['orderby']) . "&order=" . sanitize_text_field($tableOptions['order']);
        }
        $url = wp_nonce_url($url, 'abj404_bulkProcess');
        return $url;
    }
    
    function getAdminRedirectsPageTable($sub) {
        
        $tableOptions = $this->logic->getTableOptions($sub);
        
        // these are used for a GET request so they're not translated.
        $columns = array();
        $columns['url']['title'] = __('URL', '404-solution');
        $columns['url']['orderby'] = "url";
        $columns['url']['width'] = "25%";
        $columns['status']['title'] = __('Status', '404-solution');
        $columns['status']['orderby'] = "status";
        $columns['status']['width'] = "5%";
        $columns['type']['title'] = __('Type', '404-solution');
        $columns['type']['orderby'] = "type";
        $columns['type']['width'] = "10%";
        $columns['dest']['title'] = __('Destination', '404-solution');;
        $columns['dest']['orderby'] = "final_dest";
        $columns['dest']['width'] = "22%";
        $columns['code']['title'] = __('Redirect', '404-solution');
        $columns['code']['orderby'] = "code";
        $columns['code']['width'] = "5%";
        $columns['hits']['title'] = __('Hits', '404-solution');
        $columns['hits']['orderby'] = "logshits";
        $columns['hits']['width'] = "7%";
        $columns['hits']['title_attr_html'] = $this->getHitsColumnTooltip();
        $columns['timestamp']['title'] = __('Created', '404-solution');
        $columns['timestamp']['orderby'] = "timestamp";
        $columns['timestamp']['width'] = "10%";
        $columns['timestamp']['class'] = "hide-on-tablet";
        $columns['last_used']['title'] = __('Last Used', '404-solution');
        $columns['last_used']['orderby'] = "last_used";
        $columns['last_used']['width'] = "10%";
        $columns['last_used']['title_attr_html'] = $this->getHitsColumnTooltip();

        $html = "<table class=\"abj404-table\"><thead>";
        $html .= $this->getTableColumns($sub, $columns);
        $html .= "</thead><tbody id=\"the-list\">";
        
        $rows = $this->dao->getRedirectsForView($sub, $tableOptions);
        $displayed = 0;
        $y = 1;
        foreach ($rows as $row) {
            $displayed++;
            $statusTitle = '';
            if ($row['status'] == ABJ404_STATUS_MANUAL) {
                $statusTitle = __('Manually created', '404-solution');
            } else if ($row['status'] == ABJ404_STATUS_AUTO) {
                $statusTitle = __('Automatically created', '404-solution');
            } else if ($row['status'] == ABJ404_STATUS_REGEX) {
                $statusTitle = __('Regular Expression (Manually Created)', '404-solution');
            } else {
                $statusTitle = __('Unknown', '404-solution');
            }

            $link = "";
            $title = __('Visit', '404-solution') . " ";
            if ($row['type'] == ABJ404_TYPE_EXTERNAL) {
                $link = $row['final_dest'];
                $title .= $row['final_dest'];
            } else if ($row['type'] == ABJ404_TYPE_CAT) {
                $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($row['final_dest'] . "|" . ABJ404_TYPE_CAT, 0);
                $link = $permalink['link'];
                $title .= __('Category:', '404-solution') . " " . $permalink['title'];
            } else if ($row['type'] == ABJ404_TYPE_TAG) {
                $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($row['final_dest'] . "|" . ABJ404_TYPE_TAG, 0);
                $link = $permalink['link'];
                $title .= __('Tag:', '404-solution') . " " . $permalink['title'];
            } else if ($row['type'] == ABJ404_TYPE_HOME) {
                $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($row['final_dest'] . "|" . ABJ404_TYPE_HOME, 0);
                $link = $permalink['link'];
                $title .= __('Home Page:', '404-solution') . " " . $permalink['title'];
            } else if ($row['type'] == ABJ404_TYPE_POST) {
                $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($row['final_dest'] . "|" . ABJ404_TYPE_POST, 0);
                $link = $permalink['link'];
                $title .= $permalink['title'];
                
            } else if ($row['type'] == ABJ404_TYPE_404_DISPLAYED) {
            	$permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($row['final_dest'] . "|" . ABJ404_TYPE_404_DISPLAYED, 0);
            	// for custom 404 page use the link
            	$link = $permalink['link'];
            	$title .= $permalink['title'];
            	
            	// for the normal 404 page just use #
            	if ($row['final_dest'] == '0') {
            	    $link = '';
            	}
            	
            } else {
                $this->logger->errorMessage("Unexpected row type while displaying table: " . $row['type']);
            }
            
            if ($link != '') {
                $link = "href='$link'";
            }

            $hits = $row['logshits'];
            
            $last_used = $row['last_used'];
            if ($last_used != 0) {
                $last = date("Y/m/d h:i:s A", abs(intval($last_used)));
            } else {
                $last = __('Never Used', '404-solution');
            }

            // Build action links using helper method
            $links = $this->buildTableActionLinks($row, $sub, $tableOptions, false);
            extract($links);

            $class = "";
            if ($y == 0) {
                $class = "alternate";
                $y++;
            } else {
                $y = 0;
                $class = "normal-non-alternate";
            }
            // make the entire row red if the destination doesn't exist or is unpublished.
            $destinationDoesNotExistClass = '';
            if (array_key_exists('published_status', $row)) {
                if ($row['published_status'] == '0') {
                    $destinationDoesNotExistClass = ' destination-does-not-exist';
                }
            }

            // Check if URL looks like a regex pattern but is not marked as a regex redirect
            $urlLooksLikeRegexClass = '';
            $urlLooksLikeRegex = ABJ_404_Solution_Functions::urlLooksLikeRegex($row['url']);
            $isRegexStatus = ($row['status'] == ABJ404_STATUS_REGEX);
            if ($urlLooksLikeRegex && !$isRegexStatus) {
                $urlLooksLikeRegexClass = ' url-looks-like-regex';
            }

            $class = $class . $destinationDoesNotExistClass . $urlLooksLikeRegexClass;
            
            // -------------------------------------------
            // Build modern row action buttons with icons
            $editBtnHTML = '';
            $logsBtnHTML = '';
            $trashBtnHTML = '';
            $deleteBtnHTML = '';

            if ($tableOptions['filter'] != ABJ404_TRASH_FILTER) {
                $editBtnHTML = '<a href="' . esc_url($editlink) . '" class="abj404-action-link" title="{Edit Redirect Details}">'
                    . '<svg viewBox="0 0 20 20" fill="currentColor"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/></svg> '
                    . '{Edit}</a>';
                $trashBtnHTML = '<a href="#" class="abj404-action-link danger ajax-trash-link" data-url="{ajaxTrashLink}" title="{Trash Redirected URL}">'
                    . '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"/></svg> '
                    . '{Trash}</a>';
            }
            if ($row['logsid'] > 0) {
                $logsBtnHTML = '<a href="{logsLink}" class="abj404-action-link" title="{View Redirect Logs}">'
                    . '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/></svg> '
                    . '{Logs}</a>';
            }
            if ($tableOptions['filter'] == ABJ404_TRASH_FILTER) {
                $trashBtnHTML = '<a href="{trashLink}" class="abj404-action-link" title="{Restore}">'
                    . '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd"/></svg> '
                    . '{Restore}</a>';
                $deleteBtnHTML = ' | <a href="{deletelink}" class="abj404-action-link danger" title="{Delete Redirect Permanently}">'
                    . '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"/></svg> '
                    . '{Delete}</a>';
            }

            // Determine badge classes
            $statusBadgeClass = 'abj404-badge-manual';
            if ($row['status'] == ABJ404_STATUS_AUTO) {
                $statusBadgeClass = 'abj404-badge-auto';
            } else if ($row['status'] == ABJ404_STATUS_REGEX) {
                $statusBadgeClass = 'abj404-badge-regex';
            }

            $codeBadgeClass = ($row['code'] == '301') ? 'abj404-badge-301' : 'abj404-badge-302';

            $lastUsedClass = '';
            if ($last_used == 0) {
                $lastUsedClass = 'abj404-never-used';
            }

            // Legacy variables for backwards compatibility
            $editlinkHTML = '';
            $logslinkHTML = '';
            $deletePermanentlyHTML = '';
            
            $destinationExists = '';
            $destinationDoesNotExist = 'display: none;';
            if (array_key_exists('published_status', $row)) {
                if ($row['published_status'] == '0') {
                    $destinationExists = 'display: none;';
                    $destinationDoesNotExist = '';
                }
            }

            // URL regex warning visibility
            $urlIsNormal = '';
            $urlLooksLikeRegexWarning = 'display: none;';
            if ($urlLooksLikeRegex && !$isRegexStatus) {
                $urlIsNormal = 'display: none;';
                $urlLooksLikeRegexWarning = '';
            }

            // Build full URL with WordPress base path for subdirectory installations
            $fullVisitorURL = esc_url(home_url($row['url']));

            $htmlTemp = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/tableRowPageRedirects.html");
            $htmlTemp = $this->f->str_replace('{rowid}', $row['id'], $htmlTemp);
            $htmlTemp = $this->f->str_replace('{rowClass}', $class, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{visitorURL}', $fullVisitorURL, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{rowURL}', esc_html($row['url']), $htmlTemp);

            // URL regex warning
            $htmlTemp = $this->f->str_replace('{url-is-normal}', $urlIsNormal, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{url-looks-like-regex}', $urlLooksLikeRegexWarning, $htmlTemp);

            // Modern row action buttons
            $htmlTemp = $this->f->str_replace('{editBtnHTML}', $editBtnHTML, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{logsBtnHTML}', $logsBtnHTML, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{trashBtnHTML}', $trashBtnHTML, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{deleteBtnHTML}', $deleteBtnHTML, $htmlTemp);

            // Badge classes
            $htmlTemp = $this->f->str_replace('{statusBadgeClass}', $statusBadgeClass, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{codeBadgeClass}', $codeBadgeClass, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{lastUsedClass}', $lastUsedClass, $htmlTemp);

            $htmlTemp = $this->f->str_replace('{link}', $link, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{title}', $title, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{dest}', $row['dest_for_view'], $htmlTemp);
            $htmlTemp = $this->f->str_replace('{destination-exists}', $destinationExists, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{destination-does-not-exist}', $destinationDoesNotExist, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{status}', $row['status_for_view'], $htmlTemp);
            $htmlTemp = $this->f->str_replace('{statusTitle}', $statusTitle, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{type}', $row['type_for_view'], $htmlTemp);
            $htmlTemp = $this->f->str_replace('{rowCode}', $row['code'], $htmlTemp);
            $htmlTemp = $this->f->str_replace('{hits}', esc_html($hits), $htmlTemp);
            $htmlTemp = $this->f->str_replace('{logsLink}', $logslink, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{trashLink}', $trashlink, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{ajaxTrashLink}', $ajaxTrashLink, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{trashtitle}', $trashtitle, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{deletelink}', $deletelink, $htmlTemp);
            $htmlTemp = $this->f->str_replace('{created_date}',
                    esc_html(date("Y/m/d h:i:s A", abs(intval($row['timestamp'])))), $htmlTemp);
            $htmlTemp = $this->f->str_replace('{last_used_date}', esc_html($last), $htmlTemp);

            $htmlTemp = $this->f->doNormalReplacements($htmlTemp);
            $html .= $htmlTemp;
        }
        if ($displayed == 0) {
            $html .= "<tr>\n" .
                "<td colspan=\"10\" class=\"abj404-empty-state\">" .
                "<div class=\"abj404-empty-state-icon\">📋</div>" .
                "<h3>" . __('No Redirect Records To Display', '404-solution') . "</h3>" .
                "<p>" . __('Redirects will appear here once created.', '404-solution') . "</p>" .
                "</td></tr>";
        }
        $html .= "</tbody></table>";
        
        return $html;
    }
    
    function echoAddManualRedirect($tableOptions) {

        $options = $this->logic->getOptions();
        
        $url = "?page=" . ABJ404_PP;
        if (!( $tableOptions['orderby'] == "url" && $tableOptions['order'] == "ASC" )) {
            $url .= "&orderby=" . sanitize_text_field($tableOptions['orderby']) . "&order=" . sanitize_text_field($tableOptions['order']);
        }
        if ($tableOptions['filter'] != 0) {
            $url .= "&filter=" . $tableOptions['filter'];
        }
        $link = wp_nonce_url($url, "abj404addRedirect");

        $urlPlaceholder = parse_url(get_home_url(), PHP_URL_PATH) . "/example";
        if (isset($_POST['url']) && $_POST['url'] != '') {
            $postedURL = esc_url($_POST['url']);
        } else {
            $postedURL = $urlPlaceholder;
        }

        $selected301 = "";
        $selected302 = "";
        if ($options['default_redirect'] == '301') {
            $selected301 = " selected ";
        } else {
            $selected302 = " selected ";
        }
        
        // read the html content.
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/addManualRedirectTop.html");
        $html .= ABJ_404_Solution_Functions::readFileContents(__DIR__ . 
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
        $html = $this->f->str_replace('{redirectPageTitle}', '', $html);
        $html = $this->f->str_replace('{pageIDAndType}', '', $html);
        $html = $this->f->str_replace('{redirectPageTitle}', '', $html);
        $html = $this->f->str_replace('{data-url}',
                "admin-ajax.php?action=echoRedirectToPages&includeDefault404Page=true&includeSpecial=true&nonce=" . wp_create_nonce('abj404_ajax'), $html);

        $html .= ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/addManualRedirectBottom.html");
        $html = $this->f->str_replace('{addManualRedirectAction}', $link, $html);
        $html = $this->f->str_replace('{urlPlaceholder}', esc_attr($urlPlaceholder), $html);
        $html = $this->f->str_replace('{postedURL}', esc_attr($postedURL), $html);
        $html = $this->f->str_replace('{301selected}', $selected301, $html);
        $html = $this->f->str_replace('{302selected}', $selected302, $html);
        
        // constants and translations.
        $html = $this->f->doNormalReplacements($html);
        
        echo $html;
    }
    
    /** This is used both to add and to edit a redirect.
     * @param string $destination
     * @param string $codeselected
     * @param string $label
     */
    function echoEditRedirect($destination, $codeselected, $label, $source_page = null, $filter = null, $orderby = null, $order = null) {
        // Redirect type dropdown
        echo '<div class="abj404-form-group">';
        echo '<label class="abj404-form-label" for="code">' . esc_html__('Redirect Type', '404-solution') . '</label>';
        echo '<select id="code" name="code" class="abj404-form-select">';

        $codes = array(301, 302);
        foreach ($codes as $code) {
            $selected = ($code == $codeselected) ? ' selected' : '';
            $title = ($code == 301) ? '301 - ' . __('Permanent Redirect (Recommended for SEO)', '404-solution') : '302 - ' . __('Temporary Redirect', '404-solution');
            echo '<option value="' . esc_attr($code) . '"' . $selected . '>' . esc_html($title) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        // Button group
        echo '<div class="abj404-button-group">';

        // Cancel button
        $cancelUrl = '?page=' . ABJ404_PP;
        if ($source_page) {
            $cancelUrl .= '&subpage=' . esc_attr($source_page);
        }
        if ($filter !== null) {
            $cancelUrl .= '&filter=' . esc_attr($filter);
        }
        if ($orderby !== null) {
            $cancelUrl .= '&orderby=' . esc_attr($orderby);
        }
        if ($order !== null) {
            $cancelUrl .= '&order=' . esc_attr($order);
        }
        echo '<a href="' . esc_url($cancelUrl) . '" class="abj404-btn abj404-btn-secondary">' . esc_html__('Cancel', '404-solution') . '</a>';

        // Submit button
        echo '<button type="submit" class="abj404-btn abj404-btn-primary">' . esc_html($label) . '</button>';
        echo '</div>';
    }
    
    function echoRedirectDestinationOptionsDefaults($currentlySelected) {
        $content = "";
        $content .= "\n" . '<optgroup label="' . __('Special', '404-solution') . '">' . "\n";

        $selected = "";
        if ($currentlySelected == ABJ404_TYPE_EXTERNAL) {
            $selected = " selected";
        }
        $content .= "\n<option value=\"" . ABJ404_TYPE_EXTERNAL . "|" . ABJ404_TYPE_EXTERNAL . "\"" . $selected . ">" . 
                __('External Page', '404-solution') . "</option>";

        if ($currentlySelected == ABJ404_TYPE_HOME) {
            $selected = " selected";
        } else {
            $selected = "";
        }
        $content .= "\n<option value=\"" . ABJ404_TYPE_HOME . "|" . ABJ404_TYPE_HOME . "\"" . $selected . ">" . 
                __('Home Page', '404-solution') . "</option>";

        $content .= "\n" . '</optgroup>' . "\n";
        
        return $content;
    }
    
    /** 
     * @global type $abj404dao
     * @global type $wpdb
     * @param array $options
     * @return string
     */
    function getAdminOptionsPageAutoRedirects($options) {
        
        $spaces = esc_html("&nbsp;&nbsp;&nbsp;");
        $content = "";
        $userSelectedDefault404Page = (array_key_exists('dest404page', $options) && 
                isset($options['dest404page']) ? $options['dest404page'] : null);
        $urlDestination = (array_key_exists('dest404pageURL', $options) && 
                isset($options['dest404pageURL']) ? $options['dest404pageURL'] : null);
        
        $pageMissingWarning = "";
        if ($userSelectedDefault404Page != null) {
        	$permalink = 
        		ABJ_404_Solution_Functions::permalinkInfoToArray($userSelectedDefault404Page, 0);
        	if (!in_array($permalink['status'], array('publish', 'published'))) {
        		$pageMissingWarning = __("(The specified page doesn't exist. " .
        				"Please update this setting.)", '404-solution');
        	}
        }

        $pageTitle = $this->logic->getPageTitleFromIDAndType($userSelectedDefault404Page, $urlDestination);
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . 
                "/html/addManualRedirectPageSearchDropdown.html");
        $html = $this->f->str_replace('{redirect_to_label}', __('Redirect all unhandled 404s to', '404-solution'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_EMPTY}', 
                __('(Type a page name or an external URL)', '404-solution'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_PAGE}', 
                __('(A page has been selected.)', '404-solution'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_CUSTOM_STRING}',
        	__('(A custom string has been entered.)', '404-solution'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_URL}',
        		__('(An external URL will be used.)', '404-solution'), $html);
        $html = $this->f->str_replace('{REDIRECT_TO_USER_FIELD_WARNING}', $pageMissingWarning, $html);
        
        $html = $this->f->str_replace('{redirectPageTitle}', $pageTitle, $html);
        $html = $this->f->str_replace('{pageIDAndType}', $userSelectedDefault404Page, $html);
        $html = $this->f->str_replace('{redirectPageTitle}', $pageTitle, $html);
        $html = $this->f->str_replace('{data-url}',
                "admin-ajax.php?action=echoRedirectToPages&includeDefault404Page=true&includeSpecial=true&nonce=" . wp_create_nonce('abj404_ajax'), $html);
        $html = $this->f->doNormalReplacements($html);
        $content .= $html;

        // -----------------------------------------------
        // Load auto redirects options template
        $selectedAutoRedirects = $this->getCheckedAttr($options, 'auto_redirects');
        $selectedAutoSlugs = $this->getCheckedAttr($options, 'auto_slugs');
        $selectedAutoCats = $this->getCheckedAttr($options, 'auto_cats');
        $selectedAutoTags = $this->getCheckedAttr($options, 'auto_tags');

        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/adminOptionsAutoRedirects.html");
        $html = $this->f->str_replace('{selectedAutoRedirects}', $selectedAutoRedirects, $html);
        $html = $this->f->str_replace('{selectedAutoSlugs}', $selectedAutoSlugs, $html);
        $html = $this->f->str_replace('{selectedAutoCats}', $selectedAutoCats, $html);
        $html = $this->f->str_replace('{selectedAutoTags}', $selectedAutoTags, $html);
        $html = $this->f->str_replace('{auto_deletion}', esc_attr($options['auto_deletion']), $html);
        $html = $this->f->str_replace('{spaces}', $spaces, $html);
        $html = $this->f->doNormalReplacements($html);
        $content .= $html;

        return $content;
    }

    function getAdminOptionsPageAdvancedSettings($options) {

        // Only allow redirecting all requests on trusted sites because someone will break
        // their website and complain to me about it and I don't want to hear that because I have
        // other things to do besides deal with people that don't listen to warnings about things 
        // that will break their website.
        $hideRedirectAllRequests = 'true';
        $serverName = array_key_exists('SERVER_NAME', $_SERVER) ? $_SERVER['SERVER_NAME'] : (array_key_exists('HTTP_HOST', $_SERVER) ? $_SERVER['HTTP_HOST'] : '(not found)');
        if (in_array($serverName, $GLOBALS['abj404_whitelist'])) {
        	$hideRedirectAllRequests = 'false';
        }

        $selectedDebugLogging = $this->getCheckedAttr($options, 'debug_mode');
        $selectedRedirectAllRequests = $this->getCheckedAttr($options, 'redirect_all_requests');
        $selectedLogRawIPs = $this->getCheckedAttr($options, 'log_raw_ips');
        
        $debugExplanation = __('<a>View</a> the debug file.', '404-solution');
        $debugLogLink = $this->logic->getDebugLogFileLink();
        $debugExplanation = $this->f->str_replace('<a>', '<a href="' . $debugLogLink . '" target="_blank" >', $debugExplanation);

        $kbFileSize = $this->logger->getDebugFileSize() / 1024;
        $kbFileSizePretty = number_format($kbFileSize, 2, ".", ",");
        $mbFileSize = $this->logger->getDebugFileSize() / 1024 / 1000;
        $mbFileSizePretty = number_format($mbFileSize, 2, ".", ",");
        /* Translators: 1: The file size in KB. 2: The file size in MB. */
        $debugFileSize = sprintf(__('Debug file size: %1$s KB (%2$s MB).', '404-solution'),
                $kbFileSizePretty, $mbFileSizePretty);                
        
        $allPostTypesTemp = $this->dao->getAllPostTypes();
        // Ensure we have an array before imploding
        $allPostTypes = is_array($allPostTypesTemp) ? esc_html(implode(', ', $allPostTypesTemp)) : '';
        
        // ----
        // read the html content.
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/settingsAdvanced.html");
        $html = $this->f->str_replace('{DATABASE_VERSION}', esc_html($options['DB_VERSION']), $html);
        $html = $this->f->str_replace('checked="debug_mode"', $selectedDebugLogging, $html);
        $html = $this->f->str_replace('checked="redirect_all_requests"', $selectedRedirectAllRequests, $html);
        $html = $this->f->str_replace('checked="log_raw_ips"', $selectedLogRawIPs, $html);
        $html = $this->f->str_replace('{<a>View</a> the debug file.}', $debugExplanation, $html);
        $html = $this->f->str_replace('{Debug file size: %s KB.}', $debugFileSize, $html);
        
        $html = $this->f->str_replace('{ignore_dontprocess}', 
            str_replace('\\n', "\n", wp_kses_post($options['ignore_dontprocess'])), $html);
        $html = $this->f->str_replace('{ignore_doprocess}', 
            str_replace('\\n', "\n", wp_kses_post($options['ignore_doprocess'])), $html);
        $html = $this->f->str_replace('{recognized_post_types}', 
            str_replace('\\n', "\n", wp_kses_post($options['recognized_post_types'])), $html);
        $html = $this->f->str_replace('{all_post_types}', $allPostTypes, $html);
        $html = $this->f->str_replace('{days_wait_before_major_update}', $options['days_wait_before_major_update'], $html);
        
        $html = $this->f->str_replace('{recognized_categories}', 
            str_replace('\\n', "\n", wp_kses_post($options['recognized_categories'])), $html);
        $html = $this->f->str_replace('{folders_files_ignore}', 
            str_replace('\\n', "\n", wp_kses_post($options['folders_files_ignore'])), $html);
        $html = $this->f->str_replace('{suggest_regex_exclusions}',
            str_replace('\\n', "\n", esc_textarea($options['suggest_regex_exclusions'])), $html); // Use esc_textarea for textareas

        // Handle plugin_admin_users - convert array to string first before sanitization
        $pluginAdminUsers = $options['plugin_admin_users'];
        if (is_array($pluginAdminUsers)) {
        	$pluginAdminUsers = implode("\n", $pluginAdminUsers);
        }
        $pluginAdminUsers = str_replace('\\n', "\n", wp_kses_post($pluginAdminUsers));
        $html = $this->f->str_replace('{plugin_admin_users}', wp_kses_post($pluginAdminUsers), $html);
        
        $html = $this->f->str_replace('{OPTION_MIN_AUTO_SCORE}', esc_attr($options['auto_score']), $html);
        $html = $this->f->str_replace('{OPTION_TEMPLATE_REDIRECT_PRIORITY}', esc_attr($options['template_redirect_priority']), $html);
        
        $html = $this->f->str_replace('{disallow-redirect-all-requests}', $hideRedirectAllRequests, $html);
        
        $html = $this->f->str_replace('{add-exclude-page-data-url}',
        	"admin-ajax.php?action=echoRedirectToPages&includeDefault404Page=false&includeSpecial=false&nonce=" . wp_create_nonce('abj404_ajax'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_EMPTY}',
        	__('(Type a page name)', '404-solution'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_PAGE}',
        	__('(A page has been selected.)', '404-solution'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_CUSTOM_STRING}',
        	__('(A custom string has been entered.)', '404-solution'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_URL}',
        	__('(An external URL will be used.)', '404-solution'), $html);

        $html = $this->f->str_replace('{loaded-excluded-pages}',
        	urlencode($options['excludePages[]']), $html);
        
        // constants and translations.
        $html = $this->f->doNormalReplacements($html);
        
        // ------------------
         
        return $html;
    }

    /**
     * Get the HTML content for the Content & URL Filtering section
     * @param array $options
     * @return string
     */
    function getAdminOptionsPageAdvancedContent($options) {
        $allPostTypesTemp = $this->dao->getAllPostTypes();
        // Ensure we have an array before imploding
        $allPostTypes = is_array($allPostTypesTemp) ? esc_html(implode(', ', $allPostTypesTemp)) : '';

        // Read the html content
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/settingsAdvancedContent.html");

        $html = $this->f->str_replace('{recognized_post_types}',
            str_replace('\\n', "\n", wp_kses_post($options['recognized_post_types'])), $html);
        $html = $this->f->str_replace('{all_post_types}', $allPostTypes, $html);

        $html = $this->f->str_replace('{recognized_categories}',
            str_replace('\\n', "\n", wp_kses_post($options['recognized_categories'])), $html);
        $html = $this->f->str_replace('{folders_files_ignore}',
            str_replace('\\n', "\n", wp_kses_post($options['folders_files_ignore'])), $html);
        $html = $this->f->str_replace('{suggest_regex_exclusions}',
            str_replace('\\n', "\n", esc_textarea($options['suggest_regex_exclusions'])), $html);

        $html = $this->f->str_replace('{add-exclude-page-data-url}',
            "admin-ajax.php?action=echoRedirectToPages&includeDefault404Page=false&includeSpecial=false&nonce=" . wp_create_nonce('abj404_ajax'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_EMPTY}',
            __('(Type a page name)', '404-solution'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_PAGE}',
            __('(A page has been selected.)', '404-solution'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_CUSTOM_STRING}',
            __('(A custom string has been entered.)', '404-solution'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_URL}',
            __('(An external URL will be used.)', '404-solution'), $html);
        $html = $this->f->str_replace('{loaded-excluded-pages}',
            urlencode($options['excludePages[]']), $html);

        // Constants and translations
        $html = $this->f->doNormalReplacements($html);

        return $html;
    }

    /**
     * Get the HTML content for the Logging & Privacy section
     * @param array $options
     * @return string
     */
    function getAdminOptionsPageAdvancedLogging($options) {
        $selectedLogRawIPs = $this->getCheckedAttr($options, 'log_raw_ips');
        $selectedDebugLogging = $this->getCheckedAttr($options, 'debug_mode');

        $debugExplanation = __('<a>View</a> the debug file.', '404-solution');
        $debugLogLink = $this->logic->getDebugLogFileLink();
        $debugExplanation = $this->f->str_replace('<a>', '<a href="' . $debugLogLink . '" target="_blank" >', $debugExplanation);

        $kbFileSize = $this->logger->getDebugFileSize() / 1024;
        $kbFileSizePretty = number_format($kbFileSize, 2, ".", ",");
        $mbFileSize = $this->logger->getDebugFileSize() / 1024 / 1000;
        $mbFileSizePretty = number_format($mbFileSize, 2, ".", ",");
        /* Translators: 1: The file size in KB. 2: The file size in MB. */
        $debugFileSize = sprintf(__('Debug file size: %1$s KB (%2$s MB).', '404-solution'),
                $kbFileSizePretty, $mbFileSizePretty);

        // Read the html content
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/settingsAdvancedLogging.html");

        $html = $this->f->str_replace('checked="log_raw_ips"', $selectedLogRawIPs, $html);
        $html = $this->f->str_replace('checked="debug_mode"', $selectedDebugLogging, $html);
        $html = $this->f->str_replace('{<a>View</a> the debug file.}', $debugExplanation, $html);
        $html = $this->f->str_replace('{Debug file size: %s KB.}', $debugFileSize, $html);

        $html = $this->f->str_replace('{ignore_dontprocess}',
            str_replace('\\n', "\n", wp_kses_post($options['ignore_dontprocess'])), $html);
        $html = $this->f->str_replace('{ignore_doprocess}',
            str_replace('\\n', "\n", wp_kses_post($options['ignore_doprocess'])), $html);

        // Constants and translations
        $html = $this->f->doNormalReplacements($html);

        return $html;
    }

    /**
     * Get the HTML content for the Advanced Configuration section
     * @param array $options
     * @return string
     */
    function getAdminOptionsPageAdvancedSystem($options) {
        $selectedRedirectAllRequests = $this->getCheckedAttr($options, 'redirect_all_requests');

        $hideRedirectAllRequests = "false";
        if (array_key_exists('disallow-redirect-all-requests', $options)
                && $options['disallow-redirect-all-requests'] == '1') {
            $hideRedirectAllRequests = "true";
        }

        // Read the html content
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/settingsAdvancedSystem.html");

        $html = $this->f->str_replace('{DATABASE_VERSION}', esc_html($options['DB_VERSION']), $html);
        $html = $this->f->str_replace('checked="redirect_all_requests"', $selectedRedirectAllRequests, $html);
        $html = $this->f->str_replace('{disallow-redirect-all-requests}', $hideRedirectAllRequests, $html);

        $html = $this->f->str_replace('{OPTION_MIN_AUTO_SCORE}', esc_attr($options['auto_score']), $html);
        $html = $this->f->str_replace('{OPTION_TEMPLATE_REDIRECT_PRIORITY}', esc_attr($options['template_redirect_priority']), $html);
        $html = $this->f->str_replace('{days_wait_before_major_update}', $options['days_wait_before_major_update'], $html);

        // Handle plugin_admin_users - convert array to string first before sanitization
        $pluginAdminUsers = $options['plugin_admin_users'];
        if (is_array($pluginAdminUsers)) {
            $pluginAdminUsers = implode("\n", $pluginAdminUsers);
        }
        $pluginAdminUsers = str_replace('\\n', "\n", wp_kses_post($pluginAdminUsers));
        $html = $this->f->str_replace('{plugin_admin_users}', wp_kses_post($pluginAdminUsers), $html);

        // Constants and translations
        $html = $this->f->doNormalReplacements($html);

        return $html;
    }

    /**
     * @param array $options
     * @return string
     */
    function getAdminOptionsPageGeneralSettings($options) {
        
        $selectedDefaultRedirect301 = "";
        if ($options['default_redirect'] == '301') {
            $selectedDefaultRedirect301 = " selected";
        }
        $selectedDefaultRedirect302 = "";
        if ($options['default_redirect'] == '302') {
            $selectedDefaultRedirect302 = " selected";
        }

        $selectedCapture404 = $this->getCheckedAttr($options, 'capture_404');
        $selectedSendErrorLogs = $this->getCheckedAttr($options, 'send_error_logs');

        $selectedUnderSettings = "";
        $selecteSsettingsLevel = "";
        if ($options['menuLocation'] == 'settingsLevel') {
            $selecteSsettingsLevel = " selected";
        } else {
            $selectedUnderSettings = " selected";
        }

        // Theme selection
        $adminTheme = isset($options['admin_theme']) ? $options['admin_theme'] : 'default';
        $selectedThemeDefault = ($adminTheme == 'default') ? " selected" : "";
        $selectedThemeCalm = ($adminTheme == 'calm') ? " selected" : "";
        $selectedThemeMono = ($adminTheme == 'mono') ? " selected" : "";
        $selectedThemeNeon = ($adminTheme == 'neon') ? " selected" : "";
        $selectedThemeObsidian = ($adminTheme == 'obsidian') ? " selected" : "";

        // Theme name translations
        $themeDefault = __('Default', '404-solution');

        // Language override selection
        $pluginLanguage = isset($options['plugin_language_override']) ? $options['plugin_language_override'] : '';
        $selectedLanguageDefault = ($pluginLanguage == '') ? " selected" : "";
        $selectedLanguageEnUS = ($pluginLanguage == 'en_US') ? " selected" : "";
        $selectedLanguageDeDE = ($pluginLanguage == 'de_DE') ? " selected" : "";
        $selectedLanguageEsES = ($pluginLanguage == 'es_ES') ? " selected" : "";
        $selectedLanguageFrFR = ($pluginLanguage == 'fr_FR') ? " selected" : "";
        $selectedLanguageItIT = ($pluginLanguage == 'it_IT') ? " selected" : "";
        $selectedLanguagePtBR = ($pluginLanguage == 'pt_BR') ? " selected" : "";
        $selectedLanguageNlNL = ($pluginLanguage == 'nl_NL') ? " selected" : "";
        $selectedLanguageRuRU = ($pluginLanguage == 'ru_RU') ? " selected" : "";
        $selectedLanguageJa = ($pluginLanguage == 'ja') ? " selected" : "";
        $selectedLanguageZhCN = ($pluginLanguage == 'zh_CN') ? " selected" : "";
        $selectedLanguageIdID = ($pluginLanguage == 'id_ID') ? " selected" : "";
        $selectedLanguageSvSE = ($pluginLanguage == 'sv_SE') ? " selected" : "";

        // Auto dark mode detection checkbox
        $disableAutoDarkMode = isset($options['disable_auto_dark_mode']) && $options['disable_auto_dark_mode'] == '1';
        $disableAutoDarkModeChecked = $disableAutoDarkMode ? " checked" : "";

        $logSizeBytes = $this->dao->getLogDiskUsage();
        $logSizeMB = round($logSizeBytes / (1024 * 1000), 2);
        $totalLogLines = $this->dao->getLogsCount(0);

        $timeToDisplay = $this->dao->getEarliestLogTimestamp();
        $earliestLogDate = 'N/A';
        if ($timeToDisplay >= 0) {
            $earliestLogDate = date('Y/m/d', $timeToDisplay) . ' ' . date('h:i:s', $timeToDisplay) . '&nbsp;' . 
            date('A', $timeToDisplay);
        }


        $selectedRemoveMatches = $this->getCheckedAttr($options, 'remove_matches');
        
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/adminOptionsGeneral.html");
        $html = $this->f->str_replace('{selectedSendErrorLogs}', $selectedSendErrorLogs, $html);
        $html = $this->f->str_replace('{selectedDefaultRedirect301}', $selectedDefaultRedirect301, $html);
        $html = $this->f->str_replace('{selectedDefaultRedirect302}', $selectedDefaultRedirect302, $html);
        $html = $this->f->str_replace('{selectedCapture404}', $selectedCapture404, $html);
        $html = $this->f->str_replace('{admin_notification}', $options['admin_notification'], $html);
        $html = $this->f->str_replace('{capture_deletion}', $options['capture_deletion'], $html);
        $html = $this->f->str_replace('{manual_deletion}', $options['manual_deletion'], $html);
        $html = $this->f->str_replace('{maximum_log_disk_usage}', $options['maximum_log_disk_usage'], $html);
        $html = $this->f->str_replace('{logCurrentSizeDiskUsage}', $logSizeMB, $html);
        $html = $this->f->str_replace('{logCurrentRowCount}', $totalLogLines, $html);
        $html = $this->f->str_replace('{earliestLogDate}', $earliestLogDate, $html);
        $html = $this->f->str_replace('{selectedRemoveMatches}', $selectedRemoveMatches, $html);
        $html = $this->f->str_replace('{selectedUnderSettings}', $selectedUnderSettings, $html);
        $html = $this->f->str_replace('{selecteSsettingsLevel}', $selecteSsettingsLevel, $html);
        $html = $this->f->str_replace('{selectedThemeDefault}', $selectedThemeDefault, $html);
        $html = $this->f->str_replace('{selectedThemeCalm}', $selectedThemeCalm, $html);
        $html = $this->f->str_replace('{selectedThemeMono}', $selectedThemeMono, $html);
        $html = $this->f->str_replace('{selectedThemeNeon}', $selectedThemeNeon, $html);
        $html = $this->f->str_replace('{selectedThemeObsidian}', $selectedThemeObsidian, $html);
        $html = $this->f->str_replace('{theme_default}', $themeDefault, $html);
        $html = $this->f->str_replace('{selectedLanguageDefault}', $selectedLanguageDefault, $html);
        $html = $this->f->str_replace('{selectedLanguageEnUS}', $selectedLanguageEnUS, $html);
        $html = $this->f->str_replace('{selectedLanguageDeDE}', $selectedLanguageDeDE, $html);
        $html = $this->f->str_replace('{selectedLanguageEsES}', $selectedLanguageEsES, $html);
        $html = $this->f->str_replace('{selectedLanguageFrFR}', $selectedLanguageFrFR, $html);
        $html = $this->f->str_replace('{selectedLanguageItIT}', $selectedLanguageItIT, $html);
        $html = $this->f->str_replace('{selectedLanguagePtBR}', $selectedLanguagePtBR, $html);
        $html = $this->f->str_replace('{selectedLanguageNlNL}', $selectedLanguageNlNL, $html);
        $html = $this->f->str_replace('{selectedLanguageRuRU}', $selectedLanguageRuRU, $html);
        $html = $this->f->str_replace('{selectedLanguageJa}', $selectedLanguageJa, $html);
        $html = $this->f->str_replace('{selectedLanguageZhCN}', $selectedLanguageZhCN, $html);
        $html = $this->f->str_replace('{selectedLanguageIdID}', $selectedLanguageIdID, $html);
        $html = $this->f->str_replace('{selectedLanguageSvSE}', $selectedLanguageSvSE, $html);
        $html = $this->f->str_replace('{disableAutoDarkModeChecked}', $disableAutoDarkModeChecked, $html);
        $html = $this->f->str_replace('{admin_notification_email}', $options['admin_notification_email'], $html);
        $html = $this->f->str_replace('{default_wordpress_admin_email}', get_option('admin_email'), $html);
        $html = $this->f->str_replace('{PHP_VERSION}', PHP_VERSION, $html);

        // constants and translations.
        $html = $this->f->doNormalReplacements($html);
        
        return $html;
    }
    
    /** 
     * @global type $abj404dao
     */
    function echoAdminLogsPage() {

        $sub = 'abj404_logs';
        $tableOptions = $this->logic->getTableOptions($sub);

        // Sanitizing unchecked table options
        $tableOptions = $this->logic->sanitizePostData($tableOptions);

        $timezone = get_option('timezone_string');
        if ('' == $timezone) {
            $timezone = 'UTC';
        }
        date_default_timezone_set($timezone);

        // Modern page wrapper
        echo '<div class="abj404-table-page">';

        // Header with page title
        echo '<div class="abj404-table-header">';
        echo '<h2>' . __('Redirect Logs', '404-solution') . '</h2>';
        echo '</div>';

        // Filter bar with search dropdown
        echo '<div class="abj404-filter-bar">';

        // Log search form
        echo '<form id="logs_search_form" name="admin-logs-page" method="GET" action="" class="abj404-logs-search-form">';
        echo '<input type="hidden" name="page" value="' . ABJ404_PP . '">';
        echo '<input type="hidden" name="subpage" value="abj404_logs">';

        // ----------------- dropdown search box. begin.
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ .
                "/html/viewLogsForSearchBox.html");

        $redirectPageTitle = $this->dao->getPostOrGetSanitize('redirect_to_data_field_title');
        $pageIDAndType = $this->dao->getPostOrGetSanitize('redirect_to_data_field_id');

        $html = $this->f->str_replace('{redirect_to_label}', __('View logs for', '404-solution'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_EMPTY}',
                __('(Begin typing a URL)', '404-solution'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_PAGE}',
                __('(A page has been selected.)', '404-solution'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_CUSTOM_STRING}',
        	__('(A custom string has been entered.)', '404-solution'), $html);
        $html = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_URL}',
                __('(Please choose from the dropdown list instead of typing your own URL.)', '404-solution'), $html);
        $html = $this->f->str_replace('{pageIDAndType}', $pageIDAndType, $html);
        $html = $this->f->str_replace('{redirectPageTitle}', $redirectPageTitle, $html);
        $html = $this->f->str_replace('{data-url}',
                "admin-ajax.php?action=echoViewLogsFor&nonce=" . wp_create_nonce('abj404_ajax'), $html);
        $html = $this->f->doNormalReplacements($html);
        echo $html;
        // ----------------- dropdown search box. end.

        echo '</form>';

        // Rows per page
        echo '<div class="abj404-rows-per-page">';
        echo '<span>' . __('Rows per page:', '404-solution') . '</span>';
        echo '<select onchange="window.location.href=this.value">';
        $perPageOptions = array(10, 25, 50, 100, 250);
        foreach ($perPageOptions as $opt) {
            $selected = ($tableOptions['perpage'] == $opt) ? ' selected' : '';
            $url = "?page=" . ABJ404_PP . "&subpage=abj404_logs" .
                   "&orderby=" . sanitize_text_field($tableOptions['orderby']) . "&order=" . sanitize_text_field($tableOptions['order']) . "&perpage=" . $opt;
            echo '<option value="' . esc_url($url) . '"' . $selected . '>' . $opt . '</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '</div><!-- .abj404-filter-bar -->';

        // Table
        echo $this->getAdminLogsPageTable($sub);

        // Pagination
        echo $this->getModernPagination($sub, $tableOptions);

        echo '</div><!-- .abj404-table-page -->';
    }
    
    function getAdminLogsPageTable($sub) {

        $tableOptions = $this->logic->getTableOptions($sub);

        // Build column headers with sorting
        $columns = array(
            'url' => array('title' => __('URL', '404-solution'), 'orderby' => 'url'),
            'host' => array('title' => __('IP Address', '404-solution'), 'orderby' => 'remote_host'),
            'refer' => array('title' => __('Referrer', '404-solution'), 'orderby' => 'referrer'),
            'dest' => array('title' => __('Action', '404-solution'), 'orderby' => 'action'),
            'timestamp' => array('title' => __('Date', '404-solution'), 'orderby' => 'timestamp'),
            'username' => array('title' => __('User', '404-solution'), 'orderby' => 'username'),
        );

        $html = '<table class="abj404-table abj404-logs-table">';
        $html .= '<thead><tr>';

        // Generate sortable column headers
        foreach ($columns as $key => $col) {
            $sortUrl = "?page=" . ABJ404_PP . "&subpage=abj404_logs";
            $sortUrl .= "&orderby=" . $col['orderby'];
            $newOrder = ($tableOptions['orderby'] == $col['orderby'] && $tableOptions['order'] == 'ASC') ? 'DESC' : 'ASC';
            $sortUrl .= "&order=" . $newOrder;

            $sortClass = '';
            $sortIndicator = '';
            if ($tableOptions['orderby'] == $col['orderby']) {
                $sortClass = ' class="sorted ' . strtolower($tableOptions['order']) . '"';
                $sortIndicator = $tableOptions['order'] == 'ASC' ? ' ↑' : ' ↓';
            }

            $html .= '<th scope="col"' . $sortClass . '><a href="' . esc_url($sortUrl) . '">' . esc_html($col['title']) . $sortIndicator . '</a></th>';
        }

        $html .= '</tr></thead>';
        $html .= '<tbody id="the-list">';

        $rows = $this->dao->getLogRecords($tableOptions);
        $logRecordsDisplayed = 0;

        foreach ($rows as $row) {
            $html .= '<tr>';

            // URL column
            $urlDisplay = esc_html($row['url']);
            if ($row['url_detail'] != null && trim($row['url_detail']) != '') {
                $urlDisplay .= ' <span class="abj404-url-detail">(' . esc_html(trim($row['url_detail'])) . ')</span>';
            }
            $html .= '<td class="abj404-url-cell" title="' . esc_attr($row['url']) . '">' . $urlDisplay . '</td>';

            // IP Address
            $html .= '<td class="abj404-ip-cell">' . esc_html($row['remote_host']) . '</td>';

            // Referrer
            $html .= '<td class="abj404-url-cell">';
            if ($row['referrer'] != "") {
                $html .= '<a href="' . esc_url($row['referrer']) . '" title="' . esc_attr($row['referrer']) . '" target="_blank">' . esc_html($row['referrer']) . '</a>';
            } else {
                $html .= '<span class="abj404-text-muted">-</span>';
            }
            $html .= '</td>';

            // Action Taken
            $html .= '<td>';
            if (trim($row['action']) == "404" || trim($row['action']) == "http://404") {
                $html .= '<span class="abj404-badge abj404-badge-404">' . __('404', '404-solution') . '</span>';
            } else {
                $html .= '<span class="abj404-badge abj404-badge-redirect">' . __('Redirect', '404-solution') . '</span><br>';
                $html .= '<a href="' . esc_url($row['action']) . '" title="' . esc_attr($row['action']) . '" target="_blank" class="abj404-action-url">' . esc_html($row['action']) . '</a>';
            }
            $html .= '</td>';

            // Date
            $timeToDisplay = abs(intval($row['timestamp']));
            $html .= '<td class="abj404-date-cell">' . date('Y/m/d', $timeToDisplay) . '<br>' . date('h:i:s A', $timeToDisplay) . '</td>';

            // User
            $html .= '<td>';
            if (!empty($row['username'])) {
                $html .= esc_html($row['username']);
            } else {
                $html .= '<span class="abj404-text-muted">-</span>';
            }
            $html .= '</td>';

            $html .= '</tr>';
            $logRecordsDisplayed++;
        }

        $this->logger->debugMessage($logRecordsDisplayed . " log records displayed on the page.");

        if ($logRecordsDisplayed == 0) {
            $html .= '<tr><td colspan="6" class="abj404-empty-message">' . __('No Results To Display', '404-solution') . '</td></tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }

    /** 
     * @param string $sub
     * @param array $tableOptions
     * @param array $columns
     */
    function getTableColumns($sub, $columns) {
        $tableOptions = $this->logic->getTableOptions($sub);
        
        $html = "<tr>";
        
        $cbinfo = 'class="manage-column column-cb check-column" style="{cb-info-style}"';
        $cbinfoStyle = 'vertical-align: middle; padding-bottom: 6px;';
        if ($sub == 'abj404_logs') {
            $cbinfoStyle .= ' width: 0px;';
        }
        $cbinfo = $this->f->str_replace('{cb-info-style}', $cbinfoStyle, $cbinfo);
        
        $html .= "<th scope=\"col\" " . $cbinfo . ">";
        if ($sub != 'abj404_logs') {
            $html .= "<input type=\"checkbox\" name=\"bulkSelectorCheckbox\" onchange=\"enableDisableApplyButton();\" aria-label=\"" . esc_attr__('Select all', '404-solution') . "\">";
        }
        $html .= "</th>";

        foreach ($columns as $column) {
            // Skip invalid column definitions (e.g., string instead of array)
            if (!is_array($column)) {
                continue;
            }

            $style = "";
            if (isset($column['width']) && $column['width'] != "") {
                $style = " style=\"width: " . esc_attr($column['width']) . ";\" ";
            }
            $nolink = 0;
            $sortorder = "";
            $orderby = isset($column['orderby']) ? $column['orderby'] : '';
            if ($tableOptions['orderby'] == $orderby) {
                $thClass = " sorted";
                if ($tableOptions['order'] == "ASC") {
                    $thClass .= " asc";
                    $sortorder = "DESC";
                } else {
                    $thClass .= " desc";
                    $sortorder = "ASC";
                }
            } else {
                if ($orderby != "") {
                    $thClass = " sortable";
                    if ($orderby == "timestamp" ||
                            $orderby == "last_used" ||
                            $orderby == "logshits") {
                        $thClass .= " asc";
                        $sortorder = "DESC";
                    } else {
                        $thClass .= " desc";
                        $sortorder = "ASC";
                    }
                } else {
                    $thClass = "";
                    $nolink = 1;
                }
            }

            $url = "?page=" . ABJ404_PP;
            if ($sub == 'abj404_captured') {
                $url .= "&subpage=abj404_captured";
            } else if ($sub == 'abj404_logs') {
                $url .= "&subpage=abj404_logs&id=" . $tableOptions['logsid'];
            }
            if ($tableOptions['filter'] != 0) {
                $url .= "&filter=" . $tableOptions['filter'];
            }
            $url .= "&orderby=" . $orderby . "&order=" . $sortorder;

            $cssTooltip = '';
            if (array_key_exists('title_attr_html', $column) && !empty($column['title_attr_html'])) {
                // Raw HTML (already escaped where needed)
                $cssTooltip = '<span class="lefty-tooltiptext">' . $column['title_attr_html'] . '</span>' . "\n";
                $thClass .= ' lefty-tooltip';
            } elseif (array_key_exists('title_attr', $column) && !empty($column['title_attr'])) {
                // Plain text - escape it
                $cssTooltip = '<span class="lefty-tooltiptext">' . esc_html($column['title_attr']) . '</span>' . "\n";
                $thClass .= ' lefty-tooltip';
            }

            // Support custom column classes (e.g., hide-on-tablet, hide-on-mobile)
            if (isset($column['class']) && $column['class'] != '') {
                $thClass .= ' ' . esc_attr($column['class']);
            }

            $html .= "<th scope=\"col\" " . $style . " class=\"manage-column column-title" . $thClass . "\"> \n";
            $html .= $cssTooltip;

            $title = isset($column['title']) ? $column['title'] : '';
            if ($nolink == 1) {
                $html .= $title;
            } else {
                $html .= "<a href=\"" . esc_url($url) . "\">";
                $html .= '<span class="table_header_' . $orderby . '">' .
                        esc_html($title) . "</span>";
                $html .= "<span class=\"sorting-indicator\"></span>";
                $html .= "</a>";
            }
            $html .= "</th>";
        }
        $html .= "</tr>";

        return $html;
    }

    /** 
     * @global type $abj404dao
     * @param string $sub
     * @param array $tableOptions
     */
    function getPaginationLinks($sub, $showSearchFilter = true) {
        
        $tableOptions = $this->logic->getTableOptions($sub);

        $url = "?page=" . ABJ404_PP;
        if ($sub == 'abj404_captured') {
            $url .= "&subpage=abj404_captured";
        } else if ($sub == 'abj404_logs') {
            $url .= "&subpage=abj404_logs&id=" . $tableOptions['logsid'];
        }

        $url .= "&orderby=" . sanitize_text_field($tableOptions['orderby']);
        $url .= "&order=" . sanitize_text_field($tableOptions['order']);
        $url .= "&filter=" . absint($tableOptions['filter']);

        if ($sub == 'abj404_logs') {
            $num_records = $this->dao->getLogsCount($tableOptions['logsid']);
        } else {
            $num_records = $this->dao->getRedirectsForViewCount($sub, $tableOptions);
        }

        // Ensure perpage is never 0 to prevent division by zero
        $perpage = isset($tableOptions['perpage']) ? absint($tableOptions['perpage']) : ABJ404_OPTION_MIN_PERPAGE;
        if ($perpage == 0) {
            $perpage = ABJ404_OPTION_MIN_PERPAGE;
        }

        // Ensure paged is a valid integer
        $paged = isset($tableOptions['paged']) ? absint($tableOptions['paged']) : 1;
        if ($paged == 0) {
            $paged = 1;
        }

        $total_pages = ceil($num_records / $perpage);
        if ($total_pages == 0) {
            $total_pages = 1;
        }

        $firsturl = $url;

        if ($paged == 1) {
            $prevurl = $url;
        } else {
            $prev = $paged - 1;
            $prevurl = $url . "&paged=" . $prev;
        }

        if ($paged + 1 > $total_pages) {
            if ($paged == 1) {
                $nexturl = $url;
            } else {
                $nexturl = $url . "&paged=" . $paged;
            }
        } else {
            $next = $paged + 1;
            $nexturl = $url . "&paged=" . $next;
        }

        if ($paged + 1 > $total_pages) {
            if ($paged == 1) {
                $lasturl = $url;
            } else {
                $lasturl = $url . "&paged=" . $paged;
            }
        } else {
            $lasturl = $url . "&paged=" . $total_pages;
        }

        // ------------
        $start = (($paged - 1) * $perpage) + 1;
        $end = min($start + $perpage - 1, $num_records);
        /* Translators: 1: Starting number, 2: Ending number, 3: Total count. */
        $currentlyShowingText = sprintf(__('%1$s - %2$s of %3$s', '404-solution'), $start, $end, $num_records);
        $currentPageText = __('Page', '404-solution') . " " . $paged . " " . __('of', '404-solution') . " " . esc_html($total_pages);
        $showRowsText = __('Rows per page:', '404-solution');
        $showRowsLink = wp_nonce_url($url . '&action=changeItemsPerRow', "abj404_changeItemsPerRow");

        $ajaxAction = 'ajaxUpdatePaginationLinks';
        $ajaxNonce = wp_create_nonce('abj404_updatePaginationLink');
        
        $searchFilterControl = '<!--';
        if ($sub == 'abj404_redirects' || $sub == 'abj404_captured') {
            $searchFilterControl = '';
        }
        if (!$showSearchFilter) {
            $searchFilterControl = '<!--';
        }
        
        if ($tableOptions['filterText'] != '') {
            $nexturl .= '&filterText=' . $tableOptions['filterText'];
            $prevurl .= '&filterText=' . $tableOptions['filterText'];
            $firsturl .= '&filterText=' . $tableOptions['filterText'];
            $lasturl .= '&filterText=' . $tableOptions['filterText'];
        }

        // read the html content.
        $html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/paginationLinks.html");
        // do special replacements
        $html = $this->f->str_replace(' value="' . $tableOptions['perpage'] . '"', 
                ' value="' . $tableOptions['perpage'] . '" selected', 
                $html);
        $html = $this->f->str_replace('{changeItemsPerPage}', $showRowsLink, $html);
        $html = $this->f->str_replace('{showSearchFilter}', $searchFilterControl, $html);
        $html = $this->f->str_replace('{TEXT_BEFORE_LINKS}', $currentlyShowingText, $html);
        $html = $this->f->str_replace('{TEXT_SHOW_ROWS}', $showRowsText, $html);
        $html = $this->f->str_replace('{LINK_FIRST_PAGE}', esc_url($firsturl), $html);
        $html = $this->f->str_replace('{LINK_PREVIOUS_PAGE}', esc_url($prevurl), $html);
        $html = $this->f->str_replace('{TEXT_CURRENT_PAGE}', $currentPageText, $html);
        $html = $this->f->str_replace('{LINK_NEXT_PAGE}', esc_url($nexturl), $html);
        $html = $this->f->str_replace('{LINK_LAST_PAGE}', esc_url($lasturl), $html);
        $html = $this->f->str_replace('{filterText}', esc_attr($tableOptions['filterText']), $html);
        $html = $this->f->str_replace('{data-pagination-ajax-url}', esc_attr(admin_url('admin-ajax.php')), $html);
        $html = $this->f->str_replace('{data-pagination-ajax-action}', esc_attr($ajaxAction), $html);
        $html = $this->f->str_replace('{data-pagination-ajax-subpage}', esc_attr($sub), $html);
        $html = $this->f->str_replace('{data-pagination-ajax-nonce}', esc_attr($ajaxNonce), $html);
        // constants and translations.
        $html = $this->f->doNormalReplacements($html);
        
        return $html;
    }    
    
    /** Output the filters for a tab.
     * @global type $abj404dao
     * @param string $sub
     * @param array $tableOptions
     */
    function getTabFilters($sub, $tableOptions) {

        if (empty($tableOptions)) {
        	$tableOptions = $this->logic->getTableOptions($sub);
        }
        
        $html = '';
        $html .= "<span class=\"clearbothdisplayblock\" style=\"clear: both; display: block;\" ></span>";
        
        $html .= $this->getSubSubSub($sub);
        
        $html .= "</span>";
        
        return $html;
    }
    
    function getSubSubSub($sub) {
        global $abj404_redirect_types;
        global $abj404_captured_types;
        
        $tableOptions = $this->logic->getTableOptions($sub);
        
        $url = "?page=" . ABJ404_PP;
        if ($sub == 'abj404_captured') {
            $url .= "&subpage=abj404_captured";
        } else if ($sub == 'abj404_redirects') {
            $url .= "&subpage=abj404_redirects";
        } else {
            $this->logger->errorMessage("Unexpected sub page: " . $sub);
        }

        $url .= "&orderby=" . sanitize_text_field($tableOptions['orderby']);
        $url .= "&order=" . sanitize_text_field($tableOptions['order']);

        if ($sub == 'abj404_redirects') {
            $types = $abj404_redirect_types;
        } else if ($sub == 'abj404_captured') {
            $types = $abj404_captured_types;
        } else {
            $this->logger->debugMessage("Unexpected sub type for tab filter: " . $sub);
            $types = $abj404_captured_types;
        }

        $class = "";
        if ($tableOptions['filter'] == 0) {
            $class = " class=\"current\"";
        }
        
        $html = '<ul class="subsubsub" >';
        if ($sub != 'abj404_captured') {
            $html .= "<li>";
            $html .= "<a href=\"" . esc_url($url) . "\"" . $class . ">" . __('All', '404-solution');
            $html .= " <span class=\"count\">(" . esc_html($this->dao->getRecordCount($types)) . ")</span>";
            $html .= "</a>";
            $html .= "</li>";
        }
        foreach ($types as $type) {
            $thisurl = $url . "&filter=" . $type;

            $class = "";
            if ($tableOptions['filter'] == $type) {
                $class = " class=\"current\"";
            }

            $recordCount = 0;
            if ($type == ABJ404_STATUS_MANUAL) {
                $title = __('Manual Redirects', '404-solution');
                $recordCount = $this->dao->getRecordCount(array($type, ABJ404_STATUS_REGEX));
            } else if ($type == ABJ404_STATUS_AUTO) {
                $title = __('Automatic Redirects', '404-solution');
                $recordCount = $this->dao->getRecordCount(array($type));
            } else if ($type == ABJ404_STATUS_CAPTURED) {
                $title = "Captured URLs";
                $recordCount = $this->dao->getRecordCount(array($type));
            } else if ($type == ABJ404_STATUS_IGNORED) {
                $title = "Ignored 404s";
                $recordCount = $this->dao->getRecordCount(array($type));
            } else if ($type == ABJ404_STATUS_LATER) {
                $title = "Organize Later";
                $recordCount = $this->dao->getRecordCount(array($type));
            } else if ($type == ABJ404_STATUS_REGEX) {
                // don't include a tab here because these are included in the manual redirects.
                continue;
            } else {
                $this->logger->errorMessage("Unrecognized redirect type in View: " . esc_html($type));
            }

            $html .= "<li>";
            if ($sub != 'abj404_captured' || $type != ABJ404_STATUS_CAPTURED) {
                $html .= " | ";
            }
            $html .= "<a href=\"" . esc_url($thisurl) . "\"" . $class . ">" . ( $title );
            $html .= " <span class=\"count\">(" . esc_html($recordCount) . ")</span>";
            $html .= "</a>";
            $html .= "</li>";
        }


        $trashurl = $url . "&filter=" . ABJ404_TRASH_FILTER;
        $class = "";
        if ($tableOptions['filter'] == ABJ404_TRASH_FILTER) {
            $class = " class=\"current\"";
        }
        $html .= "<li> | ";
        $html .= "<a href=\"" . esc_url($trashurl) . "\"" . $class . ">" . __('Trash', '404-solution');
        $html .= " <span class=\"count\">(" . esc_html($this->dao->getRecordCount($types, 1)) . ")</span>";
        $html .= "</a>";
        $html .= "</li>";
        $html .= "</ul>";
        $html .= "\n\n<!-- page-form big outer form could go here -->\n\n";
        
        $oneBigFormActionURL = $this->getBulkOperationsFormURL($sub, $tableOptions);
        $html .= '<form method="POST" name="bulk-operations-form" action="' . $oneBigFormActionURL . '">';

        
        return $html;
    }
}
