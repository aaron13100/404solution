<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings update helpers: table options, POST sanitization, options-from-POST pipeline.
 * Standalone class extracted from PluginLogicTrait_SettingsUpdate.
 */
class ABJ_404_Solution_PluginLogicSettingsUpdate {

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_ContentRepositoryInterface */
    private $contentRepo;

    /** @var ABJ_404_Solution_PluginLogic */
    private $pluginLogic;

    /** Allowed column names for orderby parameter.
     * @var array<int, string> */
    private static $allowedOrderbyColumns = [
        'url',
        'status',
        'type',
        'dest',
        'final_dest',
        'code',
        'score',
        'timestamp',
        'created',
        'lastused',
        'last_used',
        'logshits',
        'remote_host',
        'referrer',
        'action',
        'username'
    ];

    /** Allowed values for order parameter.
     * @var array<int, string> */
    private static $allowedOrderValues = ['ASC', 'DESC'];

    /**
     * @param ABJ_404_Solution_Functions $f
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_ContentRepositoryInterface $contentRepo
     * @param ABJ_404_Solution_PluginLogic $pluginLogic
     */
    function __construct($f, $logger, $contentRepo, $pluginLogic) {
        $this->f = $f;
        $this->logger = $logger;
        $this->contentRepo = $contentRepo;
        $this->pluginLogic = $pluginLogic;
    }

    /**
     * Read a scalar query parameter directly from REQUEST_URI.
     *
     * @param string $name
     * @return string
     */
    private function getQueryParamFromRequestUri($name) {
        if (!is_string($name) || $name === '') {
            return '';
        }
        $requestUri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if ($requestUri === '') {
            return '';
        }
        $queryString = parse_url($requestUri, PHP_URL_QUERY);
        if (!is_string($queryString) || $queryString === '') {
            return '';
        }
        $query = array();
        parse_str($queryString, $query);
        if (!array_key_exists($name, $query) || !is_scalar($query[$name])) {
            return '';
        }
        return sanitize_text_field((string)$query[$name]);
    }

    /**
     * @param string $pageBeingViewed
     * @return array<string, mixed>
     */
    function getTableOptions(string $pageBeingViewed): array {
        $tableOptions = array();
        $options = $this->pluginLogic->getOptions(true);

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

        $rawFilter = $this->f->getPostOrGetSanitize("filter", "");
        if ($rawFilter === "") {
            if ($this->f->getPostOrGetSanitize('subpage') == 'abj404_captured') {
                $tableOptions['filter'] = ABJ404_STATUS_CAPTURED;
            } else {
                $tableOptions['filter'] = 0;
            }
        } else {
            $tableOptions['filter'] = intval($rawFilter);
        }

        $tableOptions['filterText'] = trim($this->f->getPostOrGetSanitize("filterText", ""));
        $tableOptions['filterText'] = $this->f->str_replace(array('*', '/', '$'), '', $tableOptions['filterText']);

        $orderbyInput = $this->f->getPostOrGetSanitize('orderby', "");
        if ($orderbyInput != "" && in_array($orderbyInput, self::$allowedOrderbyColumns, true)) {
            $tableOptions['orderby'] = $orderbyInput;

            if ($pageBeingViewed == 'abj404_redirects') {
                $options['page_redirects_order_by'] = $tableOptions['orderby'];
                $this->pluginLogic->updateOptions($options);

            } else if ($pageBeingViewed == 'abj404_captured') {
                $options['captured_order_by'] = $tableOptions['orderby'];
                $this->pluginLogic->updateOptions($options);
            }

        } else if ($pageBeingViewed == "abj404_logs") {
            $tableOptions['orderby'] = "timestamp";
        } else if ($pageBeingViewed == 'abj404_redirects') {
            $savedRedirectsOrderBy = isset($options['page_redirects_order_by']) && is_scalar($options['page_redirects_order_by'])
                ? (string)$options['page_redirects_order_by'] : 'url';
            $tableOptions['orderby'] = in_array($savedRedirectsOrderBy, self::$allowedOrderbyColumns, true)
                ? $savedRedirectsOrderBy : 'url';
        } else if ($pageBeingViewed == 'abj404_captured') {
            $savedCapturedOrderBy = isset($options['captured_order_by']) && is_scalar($options['captured_order_by'])
                ? (string)$options['captured_order_by'] : 'timestamp';
            $tableOptions['orderby'] = in_array($savedCapturedOrderBy, self::$allowedOrderbyColumns, true)
                ? $savedCapturedOrderBy : 'timestamp';
        } else {
            $tableOptions['orderby'] = 'url';
        }

        $orderInput = strtoupper($this->f->getPostOrGetSanitize('order', ''));
        if ($orderInput != '' && in_array($orderInput, self::$allowedOrderValues, true)) {
            $tableOptions['order'] = $orderInput;

            if ($pageBeingViewed == 'abj404_redirects') {
                $options['page_redirects_order'] = $tableOptions['order'];
                $this->pluginLogic->updateOptions($options);

            } else if ($pageBeingViewed == 'abj404_captured') {
                $options['captured_order'] = $tableOptions['order'];
                $this->pluginLogic->updateOptions($options);
            }

        } else if ($tableOptions['orderby'] == "created" || $tableOptions['orderby'] == "lastused" || $tableOptions['orderby'] == "timestamp") {
            $tableOptions['order'] = "DESC";

        } else if ($pageBeingViewed == 'abj404_redirects') {
            $savedRedirectsOrder = isset($options['page_redirects_order']) && is_scalar($options['page_redirects_order'])
                ? strtoupper((string)$options['page_redirects_order']) : 'ASC';
            $tableOptions['order'] = in_array($savedRedirectsOrder, self::$allowedOrderValues, true)
                ? $savedRedirectsOrder : 'ASC';

        } else if ($pageBeingViewed == 'abj404_captured') {
            $savedCapturedOrder = isset($options['captured_order']) && is_scalar($options['captured_order'])
                ? strtoupper((string)$options['captured_order']) : 'DESC';
            $tableOptions['order'] = in_array($savedCapturedOrder, self::$allowedOrderValues, true)
                ? $savedCapturedOrder : 'DESC';

        } else {
            $tableOptions['order'] = "ASC";
        }

        $paged = $this->f->getPostOrGetSanitize("paged", '');
        if ($paged === '') {
            $paged = $this->getQueryParamFromRequestUri('paged');
        }
        $tableOptions['paged'] = ($paged === '') ? '1' : $paged;

        $perPageOption = ABJ404_OPTION_DEFAULT_PERPAGE;
        if (isset($options['perpage'])) {
            $perPageOption = max(absint(is_scalar($options['perpage']) ? $options['perpage'] : 0), ABJ404_OPTION_MIN_PERPAGE);
        }
        $tableOptions['perpage'] = $this->f->getPostOrGetSanitize("perpage", (string)$perPageOption);

        $tableOptions['logsid'] = 0;
        if ($this->f->getPostOrGetSanitize('subpage') == "abj404_logs") {
            $logId = (string)$this->f->getPostOrGetSanitize('id', '');
            if ($this->f->regexMatch('[0-9]+', $logId)) {
                $tableOptions['logsid'] = absint($logId);

            } else {
                $redirectToDataFieldId = (string)$this->f->getPostOrGetSanitize('redirect_to_data_field_id', '');
                if ($this->f->regexMatch('[0-9]+', $redirectToDataFieldId)) {
                    $tableOptions['logsid'] = absint($redirectToDataFieldId);
                }
            }
        }

        $rawScoreRange = (string)$this->f->getPostOrGetSanitize('score_range', 'all');
        $allowedScoreRanges = array('all', 'high', 'medium', 'low', 'manual');
        $tableOptions['score_range'] = in_array($rawScoreRange, $allowedScoreRanges, true) ? $rawScoreRange : 'all';

        $forceViewRebuild = (string)$this->f->getPostOrGetSanitize('forceViewRebuild', '');
        if ($forceViewRebuild === '') {
            $forceViewRebuild = (string)$this->f->getPostOrGetSanitize('abj404_force_view_rebuild', '');
        }
        if ($forceViewRebuild === '1') {
            $tableOptions['_abj404_force_view_rebuild'] = '1';
        }

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
        $options = $this->pluginLogic->getOptions();

        $returnData = array();
        $returnData['newURL'] = admin_url() . "options-general.php?page=" . ABJ404_PP . '&subpage=abj404_options';

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

        $nonce = isset($postData['nonce']) ? $postData['nonce'] : '';
        if (!wp_verify_nonce($nonce, 'abj404UpdateOptions') || !is_admin()) {
            return array(
                'success' => false,
                'status' => 403,
                'message' => 'Invalid security token',
            );
        }

        $_POST = $postData;

        if (array_key_exists('deleteDebugFile', $_POST) && $_POST['deleteDebugFile'] == true) {
            $sub = '';
            $returnData['error'] = '';
            $returnData['message'] = $this->pluginLogic->handlePluginAction('updateOptions', $sub);

        } else {
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

            $excludedPages = $options['excludePages[]'];

            /** Sanitize all data. */
            $new_options = array();
            $new_options = $this->sanitizePostData($options, true);

            $excludedPages = $excludedPages == null ? '' : trim($excludedPages);
            $excludedPages = preg_replace('/[^\[\",\]a-zA-Z\d\|\\\\ ]/', '', $excludedPages);
            $new_options['excludePages[]'] = $excludedPages;

            $this->pluginLogic->updateOptions($new_options);

            $permalinkCache = abj_service('permalink_cache');
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
            $candidateUrlLegacy = null;
            if (isset($postData['redirect_to_data_field_title'])) {
                $candidateUrlLegacy = sanitize_text_field(is_string($postData['redirect_to_data_field_title']) ? $postData['redirect_to_data_field_title'] : '');
                if (strlen($candidateUrlLegacy) > ABJ404_MAX_URL_LENGTH) {
                    $message .= sprintf(__('Error: 404 destination URL exceeds the maximum length of %d characters', '404-solution'), ABJ404_MAX_URL_LENGTH) . ".<BR/>";
                    $candidateUrlLegacy = null;
                }
            }
            if ($candidateUrlLegacy !== null) {
                if (isset($postData['redirect_to_data_field_id'])) {
                    $options['dest404page'] = sanitize_text_field(is_string($postData['redirect_to_data_field_id']) ? $postData['redirect_to_data_field_id'] : '');
                }
                $options['dest404pageURL'] = $candidateUrlLegacy;
                if ($options['dest404page'] == ABJ404_TYPE_EXTERNAL . '|' . ABJ404_TYPE_EXTERNAL) {
                    $options['dest404page'] = $options['dest404pageURL'] . '|' . ABJ404_TYPE_EXTERNAL;
                }
            } else if (isset($postData['redirect_to_data_field_id']) && !isset($postData['redirect_to_data_field_title'])) {
                $options['dest404page'] = sanitize_text_field(is_string($postData['redirect_to_data_field_id']) ? $postData['redirect_to_data_field_id'] : '');
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
     * @param array<string, mixed> $options
     * @param string $behavior
     * @param array<string, mixed> $postData
     * @return string Any error messages
     */
    private function applyBehaviorToDest404Page(array &$options, string $behavior, array $postData): string {
        switch ($behavior) {
            case 'suggest':
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
                if (isset($postData['redirect_to_data_field_title'])) {
                    $candidateUrl = sanitize_text_field(
                        is_string($postData['redirect_to_data_field_title']) ? $postData['redirect_to_data_field_title'] : ''
                    );
                    if (strlen($candidateUrl) > ABJ404_MAX_URL_LENGTH) {
                        return sprintf(__('Error: 404 destination URL exceeds the maximum length of %d characters', '404-solution'), ABJ404_MAX_URL_LENGTH) . ".<BR/>";
                    }
                    if (isset($postData['redirect_to_data_field_id'])) {
                        $options['dest404page'] = sanitize_text_field(
                            is_string($postData['redirect_to_data_field_id']) ? $postData['redirect_to_data_field_id'] : ''
                        );
                    }
                    $options['dest404pageURL'] = $candidateUrl;
                    if ($options['dest404page'] == ABJ404_TYPE_EXTERNAL . '|' . ABJ404_TYPE_EXTERNAL) {
                        $options['dest404page'] = $options['dest404pageURL'] . '|' . ABJ404_TYPE_EXTERNAL;
                    }
                } else if (isset($postData['redirect_to_data_field_id'])) {
                    $options['dest404page'] = sanitize_text_field(
                        is_string($postData['redirect_to_data_field_id']) ? $postData['redirect_to_data_field_id'] : ''
                    );
                }
                break;

            case 'theme_default':
            default:
                $options['dest404page'] = '0|' . ABJ404_TYPE_404_DISPLAYED;
                break;
        }

        return "";
    }

    /** @param array<string, mixed> $options @param array<string, mixed> $postData @return string */
    public function updateWordPressSettings(array &$options, array $postData): string {
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
            $allowed_themes = array('default', 'calm', 'mono', 'neon', 'obsidian');
            $theme = sanitize_text_field(is_string($postData['admin_theme']) ? $postData['admin_theme'] : '');
            if (in_array($theme, $allowed_themes)) {
                $options['admin_theme'] = $theme;
            } else {
                $message .= __('Error: Invalid theme selected', '404-solution') . ".<BR/>";
            }
        }

        if (isset($postData['plugin_language_override'])) {
            $allowed_locales = array('', 'en_US', 'de_DE', 'es_ES', 'fr_FR', 'it_IT', 'pt_BR', 'nl_NL', 'ru_RU', 'ja', 'zh_CN', 'id_ID', 'sv_SE');
            $locale = sanitize_text_field(is_string($postData['plugin_language_override']) ? $postData['plugin_language_override'] : '');
            if (in_array($locale, $allowed_locales)) {
                $options['plugin_language_override'] = $locale;
            } else {
                $message .= __('Error: Invalid language selected', '404-solution') . ".<BR/>";
            }
        }

        if (isset($postData['disable_auto_dark_mode']) && $postData['disable_auto_dark_mode'] == '1') {
            $options['disable_auto_dark_mode'] = '1';
        } else {
            $options['disable_auto_dark_mode'] = '0';
        }

        if (isset($postData['days_wait_before_major_update'])) {
            $rawDaysWait = is_scalar($postData['days_wait_before_major_update']) ? $postData['days_wait_before_major_update'] : '';
            if (is_numeric($rawDaysWait) && (int)$rawDaysWait >= 0) {
                $options['days_wait_before_major_update'] = (int)$rawDaysWait;
            } else {
                $message .= sprintf(__('Error: The time to wait before an automatic update must be a number between 0 and something around %d.', '404-solution'), PHP_INT_MAX) . "<BR/>";
            }
        }

        return $message;
    }

    /** @param array<string, mixed> $options @param array<string, mixed> $postData @return string */
    private function updateNotificationSettings(&$options, $postData) {
        $message = "";

        if (isset($postData['admin_notification'])) {
            $rawAdminNotification = is_scalar($postData['admin_notification']) ? $postData['admin_notification'] : '';
            if (is_numeric($rawAdminNotification) && (int)$rawAdminNotification >= 0) {
                $options['admin_notification'] = (int)$rawAdminNotification;
            } else if (is_numeric($rawAdminNotification)) {
                $message .= __('Error: Admin notification threshold must be a non-negative number', '404-solution') . ".<BR/>";
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
                $emailDigest = new ABJ_404_Solution_EmailDigest(abj_service('logs_repository'), abj_service('stats_repository'), $this->logger);
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
     * @param array<string, mixed> $options
     * @param array<string, mixed> $postData
     * @param string $fieldName
     * @param string $errorMessage
     * @param int $minValue
     * @param bool $useAbsintForCheck
     * @return string
     */
    private function validateAndSetNumericField(array &$options, array $postData, string $fieldName, string $errorMessage, int $minValue = 0, bool $useAbsintForCheck = false): string {
        if (isset($postData[$fieldName])) {
            $value = $postData[$fieldName];
            $scalarValue = is_scalar($value) ? $value : 0;
            $passesValidation = false;

            if ($useAbsintForCheck) {
                $passesValidation = is_numeric($value) && absint($scalarValue) > $minValue;
            } else {
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

    /** @param array<string, mixed> $options @param array<string, mixed> $postData @return string */
    public function updateDeletionSettings(array &$options, array $postData): string {
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

    /** @param array<string, mixed> $options @param array<string, mixed> $postData @return string */
    public function updateSuggestionSettings(array &$options, array $postData): string {
        $message = "";

        if (isset($postData['suggest_max'])) {
            if (is_numeric($postData['suggest_max']) && $postData['suggest_max'] >= 1) {
                if ($options['suggest_max'] != absint($postData['suggest_max'])) {
                    $this->logger->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
                            ": Truncating spelling cache because the max suggestions # changed from " .
                            $options['suggest_max'] . ' to ' . absint($postData['suggest_max']));

                    $this->contentRepo->deleteSpellingCache();
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

    /** @param array<string, mixed> $options @param array<string, mixed> $postData @return string */
    public function updateBooleanToggles(array &$options, array $postData): string {
        $message = "";

        $settingsMode = $this->pluginLogic->getSettingsMode();

        $allBooleanOptions = array('remove_matches', 'debug_mode', 'suggest_cats', 'suggest_tags',
            'auto_redirects', 'auto_slugs', 'auto_cats', 'auto_tags', 'auto_trash_redirect',
            'capture_404', 'send_error_logs', 'log_raw_ips',
        	'redirect_all_requests', 'update_suggest_url', 'suggest_minscore_enabled',
            'auto_trash_junk_urls',
        );

        $simpleModeOptions = array('auto_redirects', 'capture_404', 'auto_trash_junk_urls');

        if ($settingsMode === 'simple') {
            $optionsToProcess = $simpleModeOptions;
        } else {
            $optionsToProcess = $allBooleanOptions;
        }

        foreach ($optionsToProcess as $optionName) {
        	$newVal = (array_key_exists($optionName, $postData) && $postData[$optionName] == "1") ? 1 : 0;

        	if (!array_key_exists($optionName, $options) ||
        		$options[$optionName] != $newVal) {

        		$this->contentRepo->deleteSpellingCache();
        	}
            $options[$optionName] = $newVal;
        }

        if ($settingsMode === 'simple') {
            $autoRedirectsValue = isset($options['auto_redirects']) ? $options['auto_redirects'] : 0;
            $options['auto_cats'] = $autoRedirectsValue;
            $options['auto_tags'] = $autoRedirectsValue;
        }

        return $message;
    }

    /** @param array<string, mixed> $options @param array<string, mixed> $postData @return string */
    private function updateSuggestionHTMLOptions(array &$options, array $postData): string {
        $message = "";

        $optionsListSuggest = array('suggest_title', 'suggest_before', 'suggest_after', 'suggest_entrybefore',
            'suggest_entryafter', 'suggest_noresults');
        foreach ($optionsListSuggest as $optionName) {
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
     * @param array<string, mixed> $options
     * @return bool True when any option was changed.
     */
    function normalizeSuggestionTemplateOptions(array &$options): bool {
        $changed = false;
        $defaults = $this->pluginLogic->getDefaultOptions();

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

    /** @param array<string, mixed> $options @param array<string, mixed> $postData @return string */
    private function updateRegexPatternSettings(array &$options, array $postData): string {
        $message = "";

        if (isset($postData['folders_files_ignore'])) {
            $foldersFilesVal = is_string($postData['folders_files_ignore']) ? $postData['folders_files_ignore'] : '';
            $options['folders_files_ignore'] = wp_unslash(wp_kses_post($foldersFilesVal));

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
            $suggestRegexRaw = is_string($postData['suggest_regex_exclusions']) ? $postData['suggest_regex_exclusions'] : '';
            $sanitized_exclusions = sanitize_textarea_field( wp_unslash( $suggestRegexRaw ) );
            $options['suggest_regex_exclusions'] = $sanitized_exclusions;

            $patternsToIgnore = $this->f->explodeNewline( $sanitized_exclusions );
            $usableFilePatterns = array();
            foreach ( $patternsToIgnore as $patternToIgnore ) {
                $trimmedPattern = trim( $patternToIgnore );
                if ( ! empty( $trimmedPattern ) ) {
                    $newPattern = '^' . preg_quote( $trimmedPattern, '/' ) . '$';
                    $newPattern = str_replace( '\*', '.*', $newPattern );
                    $usableFilePatterns[] = $newPattern;
                }
            }
            $options['suggest_regex_exclusions_usable'] = $usableFilePatterns;
        }

        return $message;
    }

    /** @param array<string, mixed> $options @param array<string, mixed> $postData @return string */
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

    /** @param array<string, mixed> $options @param array<string, mixed> $postData @return string */
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
        		$this->contentRepo->deleteSpellingCache();
        	}
        } else {
        	$excludePagesStr2 = is_string($options['excludePages[]']) ? $options['excludePages[]'] : '';
        	$oldExcludePages = json_decode($excludePagesStr2);
        	if (null !== $oldExcludePages) {
        		$this->contentRepo->deleteSpellingCache();
        	}
        	$options['excludePages[]'] = null;
        }

        return $message;
    }

}
