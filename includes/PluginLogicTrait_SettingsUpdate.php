<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings update helpers: table options, POST sanitization, options-from-POST pipeline.
 * Used by ABJ_404_Solution_PluginLogic via `use`.
 */
trait ABJ_404_Solution_PluginLogicTrait_SettingsUpdate {

    /**
     * @param string $pageBeingViewed
     * @return array<string, mixed>
     */
    function getTableOptions(string $pageBeingViewed): array {
        $tableOptions = array();
        $options = $this->getOptions(true);

        $translationArray = array(
            '{ABJ404_STATUS_MANUAL_text}' => __('Man', '404-solution'),
            '{ABJ404_STATUS_AUTO_text}' => __('Auto', '404-solution'),
            '{ABJ404_STATUS_REGEX_text}' => __('RegEx', '404-solution'),
            '{ABJ404_TYPE_EXTERNAL_text}' => __('External', '404-solution'),
            '{ABJ404_TYPE_CAT_text}' => __('Category', '404-solution'),
            '{ABJ404_TYPE_TAG_text}' => __('Tag', '404-solution'),
       		'{ABJ404_TYPE_HOME_text}' => __('Home Page', '404-solution'),
       		'{ABJ404_TYPE_404_DISPLAYED_text}' => __('(Default 404 Page)', '404-solution'),
       		'{ABJ404_TYPE_SPECIAL_text}' => __('(Special)', '404-solution'),
        );

        $tableOptions['translations'] = $translationArray;

        $tableOptions['filter'] = intval($this->dao->getPostOrGetSanitize("filter", ""));
        if ($tableOptions['filter'] == "") {
            if ($this->dao->getPostOrGetSanitize('subpage') == 'abj404_captured') {
                $tableOptions['filter'] = ABJ404_STATUS_CAPTURED;
            } else {
                $tableOptions['filter'] = '0';
            }
        }

        $tableOptions['filterText'] = trim($this->dao->getPostOrGetSanitize("filterText", ""));
        // Remove comment markers early to prevent filterText from breaking SQL comments.
        $tableOptions['filterText'] = $this->f->str_replace(array('*', '/', '$'), '', $tableOptions['filterText']);

        $orderbyInput = $this->dao->getPostOrGetSanitize('orderby', "");
        if ($orderbyInput != "" && in_array($orderbyInput, self::$allowedOrderbyColumns, true)) {
            $tableOptions['orderby'] = $orderbyInput;

            if ($pageBeingViewed == 'abj404_redirects') {
                $options['page_redirects_order_by'] = $tableOptions['orderby'];
                $this->updateOptions($options);

            } else if ($pageBeingViewed == 'abj404_captured') {
                $options['captured_order_by'] = $tableOptions['orderby'];
                $this->updateOptions($options);
            }

        } else if ($pageBeingViewed == "abj404_logs") {
            $tableOptions['orderby'] = "timestamp";
        } else if ($pageBeingViewed == 'abj404_redirects') {
            $tableOptions['orderby'] = $options['page_redirects_order_by'];
        } else if ($pageBeingViewed == 'abj404_captured') {
            $tableOptions['orderby'] = $options['captured_order_by'];
        } else {
            $tableOptions['orderby'] = 'url';
        }

        $orderInput = strtoupper($this->dao->getPostOrGetSanitize('order', ''));
        if ($orderInput != '' && in_array($orderInput, self::$allowedOrderValues, true)) {
            $tableOptions['order'] = $orderInput;

            if ($pageBeingViewed == 'abj404_redirects') {
                $options['page_redirects_order'] = $tableOptions['order'];
                $this->updateOptions($options);

            } else if ($pageBeingViewed == 'abj404_captured') {
                $options['captured_order'] = $tableOptions['order'];
                $this->updateOptions($options);
            }

        } else if ($tableOptions['orderby'] == "created" || $tableOptions['orderby'] == "lastused" || $tableOptions['orderby'] == "timestamp") {
            $tableOptions['order'] = "DESC";

        } else if ($pageBeingViewed == 'abj404_redirects') {
            $tableOptions['order'] = $options['page_redirects_order'];

        } else if ($pageBeingViewed == 'abj404_captured') {
            $tableOptions['order'] = $options['captured_order'];

        } else {
            $tableOptions['order'] = "ASC";
        }

        $tableOptions['paged'] = $this->dao->getPostOrGetSanitize("paged", '1');

        $perPageOption = ABJ404_OPTION_DEFAULT_PERPAGE;
        if (isset($options['perpage'])) {
            $perPageOption = max(absint(is_scalar($options['perpage']) ? $options['perpage'] : 0), ABJ404_OPTION_MIN_PERPAGE);
        }
        $tableOptions['perpage'] = $this->dao->getPostOrGetSanitize("perpage", (string)$perPageOption);

        $tableOptions['logsid'] = 0;
        if ($this->dao->getPostOrGetSanitize('subpage') == "abj404_logs") {
            $logId = (string)$this->dao->getPostOrGetSanitize('id', '');
            if ($this->f->regexMatch('[0-9]+', $logId)) {
                $tableOptions['logsid'] = absint($logId);

            } else {
                $redirectToDataFieldId = (string)$this->dao->getPostOrGetSanitize('redirect_to_data_field_id', '');
                if ($this->f->regexMatch('[0-9]+', $redirectToDataFieldId)) {
                    $tableOptions['logsid'] = absint($redirectToDataFieldId);
                }
            }
        }

        // Score range filter (high / medium / low / manual / all).
        $rawScoreRange = (string)$this->dao->getPostOrGetSanitize('score_range', 'all');
        $allowedScoreRanges = array('all', 'high', 'medium', 'low', 'manual');
        $tableOptions['score_range'] = in_array($rawScoreRange, $allowedScoreRanges, true) ? $rawScoreRange : 'all';

        // sanitize all values.
        $sanitizedTableOptions = $this->sanitizePostData($tableOptions);

        return $sanitizedTableOptions;
    }

    /**
     * @param array<string, mixed> $postData
     * @param bool $restoreNewlines
     * @return array<string, mixed>
     */
    function sanitizePostData(array $postData, bool $restoreNewlines = false): array {
        $newData = array();
        foreach ($postData as $key => $value) {
            $key = wp_kses_post($key);
            if (is_array($value)) {
                $newData[$key] = $this->sanitizePostData($value, $restoreNewlines);
            } else {
                // Handle null values (PHP 8.1+ deprecation fix)
                if ($value === null) {
                    $newData[$key] = '';
                } else {
                    $valueStr = is_string($value) ? $value : (is_scalar($value) ? (string)$value : '');
                    $newData[$key] = wp_kses_post($valueStr);
                    $newData[$key] = esc_sql($newData[$key]);
                    if ($restoreNewlines) {
                        $newData[$key] = str_replace('\n', "\n", $newData[$key]);
                    }
                }
            }
        }
        return $newData;
    }

    /** Remove non a-zA-Z0-9 or _ characters.
     * @param string $str
     * @return string
     */
    function sanitizeForSQL($str) {
        if ($str == null || $str == '') {
            return '';
        }
        $re = '/[^\w_]/';

        $result = preg_replace($re, '', $str);
        return is_string($result) ? $result : $str;
    }

    /**
     * @return array<string, mixed>
     */
    function updateOptionsFromPOST() {
        $message = "";
        $options = $this->getOptions();

        // to return after handling the ajax call.
        $returnData = array();
        $returnData['newURL'] = admin_url() . "options-general.php?page=" . ABJ404_PP . '&subpage=abj404_options';

        // get the submitted settings
        if (!isset($_POST['encodedData'])) {
            $this->logger->errorMessage('Missing encodedData in POST');
            return array(
                'success' => false,
                'status' => 400,
                'message' => 'Missing form data',
            );
        }

        $encodedData = $_POST['encodedData'];
        $postData = $this->f->decodeComplicatedData($encodedData);
        if (!is_array($postData)) {
            $this->logger->errorMessage('Invalid JSON encodedData in POST');
            return array(
                'success' => false,
                'status' => 400,
                'message' => 'Missing form data',
            );
        }

        // verify nonce (defense-in-depth; Ajax_Php already verifies for admin-ajax calls)
        $nonce = isset($postData['nonce']) ? $postData['nonce'] : '';
        if (!wp_verify_nonce($nonce, 'abj404UpdateOptions') || !is_admin()) {
            return array(
                'success' => false,
                'status' => 403,
                'message' => 'Invalid security token',
            );
        }

        $_POST = $postData;

        // delete the debug file if requested.
        if (array_key_exists('deleteDebugFile', $_POST) && $_POST['deleteDebugFile'] == true) {
            $sub = '';
            $returnData['error'] = '';
            $returnData['message'] = $this->handlePluginAction('updateOptions', $sub);

        } else {
            // save all options - grouped by related functionality
            $message .= $this->updateRedirectSettings($options, $_POST);
            $message .= $this->updateWordPressSettings($options, $_POST);
            $message .= $this->updateNotificationSettings($options, $_POST);
            $message .= $this->updateDeletionSettings($options, $_POST);
            $message .= $this->updateSuggestionSettings($options, $_POST);
            $message .= $this->updateBooleanToggles($options, $_POST);
            $message .= $this->updateSuggestionHTMLOptions($options, $_POST);
            $message .= $this->updateRegexPatternSettings($options, $_POST);
            $message .= $this->updateAdminUsers($options, $_POST);
            $message .= $this->updateExcludedPages($options, $_POST);

            // save this for later to sanitize it ourselves.
            $excludedPages = $options['excludePages[]'];

            /** Sanitize all data. */
            $new_options = array();
            // when sanitizing data we keep the newlines (\n) because some data
            // is entered that way and it shouldn't allow any kind of sql
            // injection or any other security issues that I foresee at this point.
            $new_options = $this->sanitizePostData($options, true);

            // only some characters in the string.
            $excludedPages = $excludedPages == null ? '' : trim($excludedPages);
            $excludedPages = preg_replace('/[^\[\",\]a-zA-Z\d\|\\\\ ]/', '', $excludedPages);
            $new_options['excludePages[]'] = $excludedPages;

            $this->updateOptions($new_options);

            // update the permalink cache because the post types included may have changed.
            $permalinkCache = ABJ_404_Solution_PermalinkCache::getInstance();
            $permalinkCache->updatePermalinkCache(2);

            $returnData['error'] = $message;
            if ($message == "") {
                $returnData['message'] = __('Options Saved Successfully!', '404-solution');
            } else {
                $returnData['message'] = __('Some options were not saved successfully.', '404-solution') .
                    '		' . $message;
            }
        }

        return array(
            'success' => true,
            'status' => 200,
            'data' => $returnData,
        );
    }

    /** Update redirect-related settings.
     * @param array<string, mixed> $options The options array to update
     * @param array<string, mixed> $postData The POST data
     * @return string Any error messages
     */
    private function updateRedirectSettings(array &$options, array $postData): string {
        $message = "";

        if (isset($postData['default_redirect'])) {
            $validDefaultCodes = array('301', '302', '307', '308');
            if (in_array((string)(is_scalar($postData['default_redirect']) ? $postData['default_redirect'] : ''), $validDefaultCodes, true)) {
                $options['default_redirect'] = is_scalar($postData['default_redirect']) ? intval($postData['default_redirect']) : 301;
            } else {
                $message .= __('Error: Invalid value specified for default redirect type', '404-solution') . ".<BR/>";
            }
        }

        // Handle behavior tile selection
        if (isset($postData['dest404_behavior'])) {
            $validBehaviors = array('suggest', 'homepage', 'custom', 'theme_default');
            $behavior = sanitize_text_field(is_string($postData['dest404_behavior']) ? $postData['dest404_behavior'] : '');
            if (in_array($behavior, $validBehaviors, true)) {
                $options['dest404_behavior'] = $behavior;
                $message .= $this->applyBehaviorToDest404Page($options, $behavior, $postData);
            } else {
                $message .= __('Error: Invalid 404 behavior selected', '404-solution') . ".<BR/>";
            }
        } else {
            // Legacy: handle direct redirect_to_data_field_id (for backward compat)
            if (isset($postData['redirect_to_data_field_id'])) {
                $options['dest404page'] = sanitize_text_field(is_string($postData['redirect_to_data_field_id']) ? $postData['redirect_to_data_field_id'] : '');
            }
            if (isset($postData['redirect_to_data_field_title'])) {
                $options['dest404pageURL'] = sanitize_text_field(is_string($postData['redirect_to_data_field_title']) ? $postData['redirect_to_data_field_title'] : '');
                if ($options['dest404page'] == ABJ404_TYPE_EXTERNAL . '|' . ABJ404_TYPE_EXTERNAL) {
                    $options['dest404page'] = $options['dest404pageURL'] . '|' . ABJ404_TYPE_EXTERNAL;
                }
            }
        }

        if (isset($postData['template_redirect_priority'])) {
            if (is_numeric($postData['template_redirect_priority']) && $postData['template_redirect_priority'] >= 0 && $postData['template_redirect_priority'] <= 999) {
                $options['template_redirect_priority'] = absint($postData['template_redirect_priority']);
            } else {
                $message .= __('Error: Template redirect priority value must be a number between 0 and 999', '404-solution') . ".<BR/>";
            }
        }

        return $message;
    }

    /**
     * Apply the selected behavior tile to the dest404page option.
     *
     * @param array<string, mixed> $options The options array to update (by reference)
     * @param string $behavior The selected behavior: suggest, homepage, custom, theme_default
     * @param array<string, mixed> $postData The POST data
     * @return string Any error messages
     */
    private function applyBehaviorToDest404Page(array &$options, string $behavior, array $postData): string {
        switch ($behavior) {
            case 'suggest':
                // Create or find the system page
                $systemPage = ABJ_404_Solution_SystemPage::getInstance();
                $pageId = $systemPage->getOrCreateSystemPage();
                if ($pageId > 0) {
                    $options['dest404page'] = $pageId . '|' . ABJ404_TYPE_POST;
                } else {
                    return __('Error: Could not create the suggestion page', '404-solution') . ".<BR/>";
                }
                break;

            case 'homepage':
                $options['dest404page'] = '0|' . ABJ404_TYPE_HOME;
                break;

            case 'custom':
                // Use the page picker value
                if (isset($postData['redirect_to_data_field_id'])) {
                    $options['dest404page'] = sanitize_text_field(
                        is_string($postData['redirect_to_data_field_id']) ? $postData['redirect_to_data_field_id'] : ''
                    );
                }
                if (isset($postData['redirect_to_data_field_title'])) {
                    $options['dest404pageURL'] = sanitize_text_field(
                        is_string($postData['redirect_to_data_field_title']) ? $postData['redirect_to_data_field_title'] : ''
                    );
                    if ($options['dest404page'] == ABJ404_TYPE_EXTERNAL . '|' . ABJ404_TYPE_EXTERNAL) {
                        $options['dest404page'] = $options['dest404pageURL'] . '|' . ABJ404_TYPE_EXTERNAL;
                    }
                }
                break;

            case 'theme_default':
            default:
                $options['dest404page'] = '0|' . ABJ404_TYPE_404_DISPLAYED;
                break;
        }

        return "";
    }

    /** Update WordPress-specific settings.
     * @param array<string, mixed> $options The options array to update
     * @param array<string, mixed> $postData The POST data
     * @return string Any error messages
     */
    private function updateWordPressSettings(array &$options, array $postData): string {
        $message = "";

        if (isset($postData['ignore_dontprocess'])) {
        	$options['ignore_dontprocess'] = wp_kses_post(is_string($postData['ignore_dontprocess']) ? $postData['ignore_dontprocess'] : '');
        }
        if (isset($postData['ignore_doprocess'])) {
        	$options['ignore_doprocess'] = wp_kses_post(is_string($postData['ignore_doprocess']) ? $postData['ignore_doprocess'] : '');
        }
        if (isset($postData['recognized_post_types'])) {
        	$options['recognized_post_types'] = wp_kses_post(is_string($postData['recognized_post_types']) ? $postData['recognized_post_types'] : '');
        }
        if (isset($postData['recognized_categories'])) {
        	$options['recognized_categories'] = wp_kses_post(is_string($postData['recognized_categories']) ? $postData['recognized_categories'] : '');
        }
        if (isset($postData['menuLocation'])) {
        	$options['menuLocation'] = wp_kses_post(is_string($postData['menuLocation']) ? $postData['menuLocation'] : '');
        }

        if (isset($postData['admin_theme'])) {
            // Only allow specific theme values
            $allowed_themes = array('default', 'calm', 'mono', 'neon', 'obsidian');
            $theme = sanitize_text_field(is_string($postData['admin_theme']) ? $postData['admin_theme'] : '');
            if (in_array($theme, $allowed_themes)) {
                $options['admin_theme'] = $theme;
            } else {
                $message .= __('Error: Invalid theme selected', '404-solution') . ".<BR/>";
            }
        }

        if (isset($postData['plugin_language_override'])) {
            // Only allow specific locale values
            $allowed_locales = array('', 'en_US', 'de_DE', 'es_ES', 'fr_FR', 'it_IT', 'pt_BR', 'nl_NL', 'ru_RU', 'ja', 'zh_CN', 'id_ID', 'sv_SE');
            $locale = sanitize_text_field(is_string($postData['plugin_language_override']) ? $postData['plugin_language_override'] : '');
            if (in_array($locale, $allowed_locales)) {
                $options['plugin_language_override'] = $locale;
            } else {
                $message .= __('Error: Invalid language selected', '404-solution') . ".<BR/>";
            }
        }

        // Handle disable_auto_dark_mode checkbox (unchecked = not in postData)
        if (isset($postData['disable_auto_dark_mode']) && $postData['disable_auto_dark_mode'] == '1') {
            $options['disable_auto_dark_mode'] = '1';
        } else {
            $options['disable_auto_dark_mode'] = '0';
        }

        if (isset($postData['days_wait_before_major_update'])) {
            if (is_numeric($postData['days_wait_before_major_update'])) {
                $options['days_wait_before_major_update'] = absint($postData['days_wait_before_major_update']);
            } else {
                $message .= sprintf(__('Error: The time to wait before an automatic update must be a number between 0 and something around %d.', '404-solution'), PHP_INT_MAX) . "<BR/>";
            }
        }

        return $message;
    }

    /** Update notification settings.
     * @param array<string, mixed> $options The options array to update
     * @param array<string, mixed> $postData The POST data
     * @return string Any error messages
     */
    private function updateNotificationSettings(&$options, $postData) {
        $message = "";

        if (isset($postData['admin_notification'])) {
            if (is_numeric($postData['admin_notification'])) {
                $options['admin_notification'] = absint($postData['admin_notification']);
            }
        }

        if (isset($postData['admin_notification_email'])) {
            $options['admin_notification_email'] = trim(wp_kses_post(is_string($postData['admin_notification_email']) ? $postData['admin_notification_email'] : ''));
        }

        if (isset($postData['admin_notification_frequency'])) {
            $allowed_frequencies = array('instant', 'daily', 'weekly');
            $freq = sanitize_text_field(is_string($postData['admin_notification_frequency']) ? $postData['admin_notification_frequency'] : '');
            if (in_array($freq, $allowed_frequencies, true)) {
                $options['admin_notification_frequency'] = $freq;
                // Reschedule digest cron whenever frequency changes.
                $emailDigest = new ABJ_404_Solution_EmailDigest($this->dao, $this->logger);
                $emailDigest->scheduleNextDigest();
            } else {
                $message .= __('Error: Invalid email notification frequency selected', '404-solution') . ".<BR/>";
            }
        }

        if (isset($postData['admin_notification_digest_limit'])) {
            if (is_numeric($postData['admin_notification_digest_limit']) && $postData['admin_notification_digest_limit'] >= 1) {
                $options['admin_notification_digest_limit'] = absint($postData['admin_notification_digest_limit']);
            } else {
                $message .= __('Error: Digest limit must be a number greater than or equal to 1', '404-solution') . ".<BR/>";
            }
        }

        return $message;
    }

    /**
     * Validate and set a numeric field value from POST data.
     * Eliminates duplication in settings update methods.
     *
     * @param array<string, mixed> $options Reference to options array to update
     * @param array<string, mixed> $postData POST data containing field value
     * @param string $fieldName Name of the field to validate
     * @param string $errorMessage Error message to display on validation failure
     * @param int $minValue Minimum allowed value (default: 0)
     * @param bool $useAbsintForCheck Whether to use absint() before comparison (default: false)
     * @return string Error message if validation fails, empty string otherwise
     */
    private function validateAndSetNumericField(array &$options, array $postData, string $fieldName, string $errorMessage, int $minValue = 0, bool $useAbsintForCheck = false): string {
        if (isset($postData[$fieldName])) {
            $value = $postData[$fieldName];
            $scalarValue = is_scalar($value) ? $value : 0;
            $passesValidation = false;

            if ($useAbsintForCheck) {
                // For maximum_log_disk_usage: check absint(value) > minValue
                $passesValidation = is_numeric($value) && absint($scalarValue) > $minValue;
            } else {
                // For other fields: check value >= minValue
                $passesValidation = is_numeric($value) && $value >= $minValue;
            }

            if ($passesValidation) {
                $options[$fieldName] = absint($scalarValue);
                return "";
            } else {
                return __($errorMessage, '404-solution') . ".<BR/>";
            }
        }
        return "";
    }

    /** Update deletion-related settings.
     * @param array<string, mixed> $options The options array to update
     * @param array<string, mixed> $postData The POST data
     * @return string Any error messages
     */
    private function updateDeletionSettings(array &$options, array $postData): string {
        $message = "";

        $message .= $this->validateAndSetNumericField($options, $postData, 'capture_deletion',
            'Error: Collected URL deletion value must be a number greater than or equal to zero');

        $message .= $this->validateAndSetNumericField($options, $postData, 'manual_deletion',
            'Error: Manual redirect deletion value must be a number greater than or equal to zero');

        $message .= $this->validateAndSetNumericField($options, $postData, 'log_deletion',
            'Error: Log deletion value must be a number greater than or equal to zero');

        $message .= $this->validateAndSetNumericField($options, $postData, 'auto_deletion',
            'Error: Auto redirect deletion value must be a number greater than or equal to zero');

        $message .= $this->validateAndSetNumericField($options, $postData, 'auto_302_expiration_days',
            'Error: Auto-redirect expiration days must be a number greater than or equal to zero');

        $message .= $this->validateAndSetNumericField($options, $postData, 'maximum_log_disk_usage',
            'Error: Maximum log disk usage must be a number greater than zero', 0, true);

        return $message;
    }

    /** Update suggestion/spelling settings.
     * @param array<string, mixed> $options The options array to update
     * @param array<string, mixed> $postData The POST data
     * @return string Any error messages
     */
    private function updateSuggestionSettings(array &$options, array $postData): string {
        $message = "";

        if (isset($postData['suggest_max'])) {
            if (is_numeric($postData['suggest_max']) && $postData['suggest_max'] >= 1) {
                if ($options['suggest_max'] != absint($postData['suggest_max'])) {
                    $this->logger->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
                            ": Truncating spelling cache because the max suggestions # changed from " .
                            $options['suggest_max'] . ' to ' . absint($postData['suggest_max']));

                    // the spelling cache only stores up to X entries. X is based on suggest_max
                    // so the spelling cache has to be reset when this number changes.
                    $this->dao->deleteSpellingCache();
                }

                $options['suggest_max'] = absint($postData['suggest_max']);
            } else {
                $message .= __('Error: Maximum number of suggest value must be a number greater than or equal to 1', '404-solution') . ".<BR/>";
            }
        }

        if (isset($postData['auto_score'])) {
            if (is_numeric($postData['auto_score']) && $postData['auto_score'] >= 0 && $postData['auto_score'] <= 99) {
                $options['auto_score'] = absint($postData['auto_score']);
            } else {
                $message .= __('Error: Auto match score value must be a number between 0 and 99', '404-solution') . ".<BR/>";
            }
        }

        // Per-engine score overrides: accept empty string (use global) or numeric 0–99
        $engineScoreKeys = ['auto_score_title', 'auto_score_category_tag', 'auto_score_content'];
        foreach ($engineScoreKeys as $key) {
            if (isset($postData[$key])) {
                $raw = $postData[$key];
                $val = is_string($raw) ? trim($raw) : (is_numeric($raw) ? trim(strval($raw)) : '');
                if ($val === '') {
                    $options[$key] = '';
                } elseif (is_numeric($val) && $val >= 0 && $val <= 99) {
                    $options[$key] = absint($val);
                } else {
                    $message .= __('Error: Per-engine score override must be empty or a number between 0 and 99', '404-solution') . ".<BR/>";
                }
            }
        }

        return $message;
    }

    /** Update boolean toggle options (checkboxes).
     * @param array<string, mixed> $options The options array to update
     * @param array<string, mixed> $postData The POST data
     * @return string Any error messages
     */
    private function updateBooleanToggles(array &$options, array $postData): string {
        $message = "";

        // Check if we're in simple or advanced settings mode
        $settingsMode = $this->getSettingsMode();

        // All boolean options that could be in forms
        $allBooleanOptions = array('remove_matches', 'debug_mode', 'suggest_cats', 'suggest_tags',
            'auto_redirects', 'auto_slugs', 'auto_cats', 'auto_tags', 'auto_trash_redirect',
            'capture_404', 'send_error_logs', 'log_raw_ips',
        	'redirect_all_requests', 'update_suggest_url', 'suggest_minscore_enabled',
        );

        // Options that appear in Simple Mode form
        $simpleModeOptions = array('auto_redirects', 'capture_404');

        // Determine which options to process from POST data
        if ($settingsMode === 'simple') {
            // Simple mode: only process options that are actually in the form
            $optionsToProcess = $simpleModeOptions;
        } else {
            // Advanced mode: process all options (existing behavior)
            $optionsToProcess = $allBooleanOptions;
        }

        foreach ($optionsToProcess as $optionName) {
        	$newVal = (array_key_exists($optionName, $postData) && $postData[$optionName] == "1") ? 1 : 0;

        	// in case the suggest_cats or suggest_tags is changed.
        	if (!array_key_exists($optionName, $options) ||
        		$options[$optionName] != $newVal) {

        		$this->dao->deleteSpellingCache();
        	}
            $options[$optionName] = $newVal;
        }

        // In Simple Mode, sync auto_cats and auto_tags with auto_redirects
        if ($settingsMode === 'simple') {
            $autoRedirectsValue = isset($options['auto_redirects']) ? $options['auto_redirects'] : 0;
            $options['auto_cats'] = $autoRedirectsValue;
            $options['auto_tags'] = $autoRedirectsValue;
        }

        return $message;
    }

    /** Update suggestion HTML display options.
     * @param array<string, mixed> $options The options array to update
     * @param array<string, mixed> $postData The POST data
     * @return string Any error messages
     */
    private function updateSuggestionHTMLOptions(array &$options, array $postData): string {
        $message = "";

        // the suggest_.* options have html in them.
        $optionsListSuggest = array('suggest_title', 'suggest_before', 'suggest_after', 'suggest_entrybefore',
            'suggest_entryafter', 'suggest_noresults');
        foreach ($optionsListSuggest as $optionName) {
            // Only update if the option was posted (Simple Mode doesn't include these)
            if (isset($postData[$optionName])) {
                $options[$optionName] = wp_kses_post(is_string($postData[$optionName]) ? $postData[$optionName] : '');
            }
        }

        $this->normalizeSuggestionTemplateOptions($options);

        return $message;
    }

    /**
     * Repair malformed suggestion template options.
     *
     * Keep valid custom text intact; only heal known-broken literal token forms.
     *
     * @param array<string, mixed> $options
     * @return bool True when any option was changed.
     */
    private function normalizeSuggestionTemplateOptions(array &$options): bool {
        $changed = false;
        $defaults = $this->getDefaultOptions();

        $titleDefault = isset($defaults['suggest_title']) && is_string($defaults['suggest_title']) ?
            $defaults['suggest_title'] : '<h3>{suggest_title_text}</h3>';
        $noResultsDefault = isset($defaults['suggest_noresults']) && is_string($defaults['suggest_noresults']) ?
            $defaults['suggest_noresults'] : '<p>{suggest_noresults_text}</p>';

        $titleValue = isset($options['suggest_title']) && is_scalar($options['suggest_title']) ?
            (string)$options['suggest_title'] : '';
        $titleLower = strtolower(trim($titleValue));
        $titleHasBareBrokenToken = (strpos($titleValue, 'suggest_title_text') !== false &&
            strpos($titleValue, '{suggest_title_text}') === false);
        if (
            $titleValue === '' ||
            in_array($titleLower, array('suggest_title_text', '{suggest_title_text}'), true) ||
            $titleHasBareBrokenToken
        ) {
            if ($titleValue !== $titleDefault) {
                $options['suggest_title'] = $titleDefault;
                $changed = true;
            }
        }

        $noResultsValue = isset($options['suggest_noresults']) && is_scalar($options['suggest_noresults']) ?
            (string)$options['suggest_noresults'] : '';
        $noResultsLower = strtolower(trim($noResultsValue));
        $noResultsHasBareBrokenToken = (strpos($noResultsValue, 'suggest_noresults_text') !== false &&
            strpos($noResultsValue, '{suggest_noresults_text}') === false);
        if (
            $noResultsValue === '' ||
            in_array($noResultsLower, array('suggest_noresults_text', '{suggest_noresults_text}'), true) ||
            $noResultsHasBareBrokenToken
        ) {
            if ($noResultsValue !== $noResultsDefault) {
                $options['suggest_noresults'] = $noResultsDefault;
                $changed = true;
            }
        }

        return $changed;
    }

    /** Update regex pattern settings for ignoring files/folders and suggestion exclusions.
     * @param array<string, mixed> $options The options array to update
     * @param array<string, mixed> $postData The POST data
     * @return string Any error messages
     */
    private function updateRegexPatternSettings(array &$options, array $postData): string {
        $message = "";

        if (isset($postData['folders_files_ignore'])) {
            $foldersFilesVal = is_string($postData['folders_files_ignore']) ? $postData['folders_files_ignore'] : '';
            $options['folders_files_ignore'] = wp_unslash(wp_kses_post($foldersFilesVal));

            // make the regular expressions usable.
            $patternsToIgnore = $this->f->explodeNewline($options['folders_files_ignore']);
            $usableFilePatterns = array();
            foreach ($patternsToIgnore as $patternToIgnore) {
                $newPattern = '^' . preg_quote(trim($patternToIgnore), '/') . '$';
                $newPattern = $this->f->str_replace("\*",".*", $newPattern);
                $usableFilePatterns[] = $newPattern;
            }
            $options['folders_files_ignore_usable'] = $usableFilePatterns;
        }

        if ( isset( $postData['suggest_regex_exclusions'] ) ) {
            // 1. Sanitize the raw input using the appropriate function for multi-line text without HTML.
            $suggestRegexRaw = is_string($postData['suggest_regex_exclusions']) ? $postData['suggest_regex_exclusions'] : '';
            $sanitized_exclusions = sanitize_textarea_field( wp_unslash( $suggestRegexRaw ) );
            $options['suggest_regex_exclusions'] = $sanitized_exclusions;

            // 2. Generate the usable regex patterns *from the sanitized input*.
            $patternsToIgnore = $this->f->explodeNewline( $sanitized_exclusions );
            $usableFilePatterns = array();
            foreach ( $patternsToIgnore as $patternToIgnore ) {
                $trimmedPattern = trim( $patternToIgnore );
                // Only process non-empty lines
                if ( ! empty( $trimmedPattern ) ) {
                    // Escape regex special characters, then convert literal '*' into '.*' for wildcard matching.
                    $newPattern = '^' . preg_quote( $trimmedPattern, '/' ) . '$';
                    // Use standard str_replace; $this->f->str_replace is likely unnecessary here unless it provides specific multibyte handling not needed for '\*'.
                    $newPattern = str_replace( '\*', '.*', $newPattern );
                    $usableFilePatterns[] = $newPattern;
                }
            }
            $options['suggest_regex_exclusions_usable'] = $usableFilePatterns;
        }

        return $message;
    }

    /** Update plugin admin users list.
     * @param array<string, mixed> $options The options array to update
     * @param array<string, mixed> $postData The POST data
     * @return string Any error messages
     */
    private function updateAdminUsers(array &$options, array $postData): string {
        $message = "";

        if (isset($postData['plugin_admin_users'])) {
        	$pluginAdminUsers = $postData['plugin_admin_users'];
        	if (is_array($pluginAdminUsers)) {
        		$pluginAdminUsers = array_filter($pluginAdminUsers,
        			array($this->f, 'removeEmptyCustom'));
        	}

        	$options['plugin_admin_users'] = $pluginAdminUsers;
        }

        return $message;
    }

    /** Update excluded pages list.
     * @param array<string, mixed> $options The options array to update
     * @param array<string, mixed> $postData The POST data
     * @return string Any error messages
     */
    private function updateExcludedPages(array &$options, array $postData): string {
        $message = "";

        if (is_array($options['excludePages[]'])) {
            $this->logger->warn("Exclude pages settings lost.");
            $options['excludePages[]'] = '';
        }
        if (isset($postData['excludePages[]'])) {
        	$excludePagesStr = is_string($options['excludePages[]']) ? $options['excludePages[]'] : '';
        	$oldExcludePages = json_decode($excludePagesStr);
        	if (!is_array($postData['excludePages[]'])) {
        		$postData['excludePages[]'] = array($postData['excludePages[]']);
        	}
        	$encodedPages = json_encode($postData['excludePages[]']);
        	$options['excludePages[]'] = is_string($encodedPages) ? $encodedPages : '';
        	$newExcludePages = json_decode($options['excludePages[]']);
        	if ($newExcludePages !== $oldExcludePages) {
        		// if any excluded pages changed or if the number of excluded pages changed
        		// then the spelling cache has to be reset.
        		$this->dao->deleteSpellingCache();
        	}
        } else {
        	$excludePagesStr2 = is_string($options['excludePages[]']) ? $options['excludePages[]'] : '';
        	$oldExcludePages = json_decode($excludePagesStr2);
        	if (null !== $oldExcludePages) {
        		// if any excluded pages changed or if the number of excluded pages changed
        		// then the spelling cache has to be reset.
        		$this->dao->deleteSpellingCache();
        	}
        	$options['excludePages[]'] = null;
        }

        return $message;
    }

}
