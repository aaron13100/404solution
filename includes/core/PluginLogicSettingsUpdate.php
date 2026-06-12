<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../settings/SettingsFieldValidator.php';
require_once __DIR__ . '/../view/TableViewOptionsResolver.php';
require_once __DIR__ . '/../policies/SettingsAdminExcludedPagesPolicy.php';
require_once __DIR__ . '/../policies/SettingsBooleanModePolicy.php';
require_once __DIR__ . '/../policies/SettingsNotificationPolicy.php';
require_once __DIR__ . '/../policies/SettingsRedirectPolicy.php';
require_once __DIR__ . '/../policies/SettingsRegexPatternPolicy.php';
require_once __DIR__ . '/../policies/SettingsRetentionPolicy.php';
require_once __DIR__ . '/../policies/SettingsSuggestionPolicy.php';
require_once __DIR__ . '/../policies/SettingsWordPressPolicy.php';

/** Signals a miswired settings persistence dependency. */
class ABJ_404_Solution_SettingsPersistenceException extends \RuntimeException {}

/**
 * Settings save workflow: nonce verification, encoded POST decoding,
 * per-section option policy dispatch, persistence, and structured AJAX result
 * assembly.
 *
 * Decomposed during the M201 audit (design-audit-2026-06-04.md).
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

    /** @var ABJ_404_Solution_TableViewOptionsResolver|null */
    private $tableViewOptionsResolver;

    /** @var ABJ_404_Solution_SettingsFieldValidator */
    private $fieldValidator;

    /** @var ABJ_404_Solution_SettingsRegexPatternPolicy */
    private $regexPatternPolicy;

    /** @var array<string, object> */
    private $sectionPolicies = array();

    /**
     * @param ABJ_404_Solution_Functions $f
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_ContentRepositoryInterface $contentRepo
     * @param ABJ_404_Solution_PluginLogic $pluginLogic
     * @param ABJ_404_Solution_TableViewOptionsResolver|null $tableViewOptionsResolver
     * @param ABJ_404_Solution_SettingsFieldValidator|null $fieldValidator
     * @param ABJ_404_Solution_SettingsRegexPatternPolicy|null $regexPatternPolicy
     */
    function __construct(
        $f,
        $logger,
        $contentRepo,
        $pluginLogic,
        $tableViewOptionsResolver = null,
        $fieldValidator = null,
        $regexPatternPolicy = null
    ) {
        $this->f = $f;
        $this->logger = $logger;
        $this->contentRepo = $contentRepo;
        $this->pluginLogic = $pluginLogic;
        $this->tableViewOptionsResolver = $tableViewOptionsResolver;
        $this->fieldValidator = $fieldValidator !== null ? $fieldValidator : new ABJ_404_Solution_SettingsFieldValidator();
        $this->regexPatternPolicy = $regexPatternPolicy !== null ? $regexPatternPolicy : new ABJ_404_Solution_SettingsRegexPatternPolicy($f);
    }

    /**
     * Resolve the per-page table view options for an admin list-table.
     *
     * @param string $pageBeingViewed
     * @return array<string, mixed>
     */
    function getTableOptions(string $pageBeingViewed): array {
        return $this->tableViewOptionsResolver()->resolve($pageBeingViewed);
    }

    /**
     * @param array<mixed, mixed> $postData
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
        $result = preg_replace('/[^\w_]/', '', $str);
        return is_string($result) ? $result : $str;
    }

    /**
     * @return array<string, mixed>
     */
    function updateOptionsFromPOST() {
        $decodedRequest = $this->decodeSettingsPost($_POST);
        if (!$decodedRequest['success']) {
            return $decodedRequest;
        }
        if (!isset($decodedRequest['postData']) || !is_array($decodedRequest['postData'])) {
            $this->logger->errorMessage('Settings request decode returned success without postData array');
            return array('success' => false, 'status' => 400, 'message' => 'Missing form data');
        }

        $_POST = $decodedRequest['postData'];
        if (array_key_exists('deleteDebugFile', $_POST) && $_POST['deleteDebugFile'] == true) {
            $returnData = $this->baseSettingsUpdateData();
            $returnData['error'] = '';
            $sub = '';
            $returnData['message'] = $this->pluginLogic->adminActions()->handlePluginAction('updateOptions', $sub);
            return $this->successResult($returnData);
        }

        $options = $this->loadCurrentOptions();
        $message = $this->applySettingsSections($options, $_POST);
        $this->persistOptions($options);

        return $this->successResult($this->settingsSaveData($message));
    }

    /**
     * Decode a settings POST array into the actual form payload.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function decodeSettingsPost(array $post): array {
        if (!isset($post['encodedData'])) {
            $this->logger->errorMessage('Missing encodedData in POST');
            return $this->settingsRequestFailure(400, 'Missing form data');
        }

        $encodedData = $post['encodedData'];
        $encodedData = is_scalar($encodedData) ? (string)$encodedData : '';

        try {
            $postData = call_user_func(array(abj_service('query_string_helper'), 'decodeComplicatedData'), $encodedData);
        } catch (\Throwable $e) {
            $this->logger->errorMessage('Invalid JSON encodedData in POST: ' . $e->getMessage());
            return $this->settingsRequestFailure(400, 'Missing form data');
        }

        if (!is_array($postData)) {
            $this->logger->errorMessage('Invalid JSON encodedData in POST');
            return $this->settingsRequestFailure(400, 'Missing form data');
        }

        $nonce = isset($postData['nonce']) && is_scalar($postData['nonce']) ? (string)$postData['nonce'] : '';
        if (!wp_verify_nonce($nonce, 'abj404UpdateOptions') || !is_admin()) {
            return $this->settingsRequestFailure(403, 'Invalid security token');
        }

        return array(
            'success' => true,
            'postData' => $postData,
        );
    }

    /**
     * @param int $status
     * @param string $message
     * @return array<string, mixed>
     */
    private function settingsRequestFailure(int $status, string $message): array {
        return array(
            'success' => false,
            'status' => $status,
            'message' => $message,
        );
    }

    /** @return array<string, mixed> */
    private function loadCurrentOptions(): array {
        $repository = abj_service('options_repository');
        if (!is_callable(array($repository, 'getOptions'))) {
            throw new ABJ_404_Solution_SettingsPersistenceException('Options repository cannot load settings options.');
        }
        $options = call_user_func(array($repository, 'getOptions'));
        return is_array($options) ? $options : array();
    }

    /**
     * Sanitize and persist options, then refresh the permalink cache.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function persistOptions(array $options): array {
        $excludedPages = isset($options['excludePages[]']) ? $options['excludePages[]'] : '';

        $newOptions = $this->sanitizePostData($options, true);

        $excludedPages = ($excludedPages == null || !is_scalar($excludedPages)) ? '' : trim((string)$excludedPages);
        $excludedPages = preg_replace('/[^\[\",\]a-zA-Z\d\|\\\\ ]/', '', $excludedPages);
        $newOptions['excludePages[]'] = is_string($excludedPages) ? $excludedPages : '';

        $repository = abj_service('options_repository');
        if (!is_callable(array($repository, 'updateOptions'))) {
            throw new ABJ_404_Solution_SettingsPersistenceException('Options repository cannot persist settings options.');
        }
        call_user_func(array($repository, 'updateOptions'), $newOptions);

        $permalinkCache = abj_service('permalink_cache');
        if (!is_callable(array($permalinkCache, 'updatePermalinkCache'))) {
            throw new ABJ_404_Solution_SettingsPersistenceException('Permalink cache cannot refresh after settings save.');
        }
        call_user_func(array($permalinkCache, 'updatePermalinkCache'), 2);

        return $newOptions;
    }

    /**
     * @param string $errorMessage Concatenated translated validation messages.
     * @return array<string, mixed>
     */
    private function settingsSaveData(string $errorMessage): array {
        $returnData = $this->baseSettingsUpdateData();
        $returnData['error'] = $errorMessage;
        if ($errorMessage === "") {
            $returnData['message'] = __('Options Saved Successfully!', '404-solution');
        } else {
            $returnData['message'] = __('Some options were not saved successfully.', '404-solution') .
                '		' . $errorMessage;
        }
        return $returnData;
    }

    /** @return array<string, mixed> */
    private function baseSettingsUpdateData(): array {
        return array(
            'newURL' => admin_url() . "options-general.php?page=" . ABJ404_PP . '&subpage=abj404_options',
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function successResult(array $data): array {
        return array(
            'success' => true,
            'status' => 200,
            'data' => $data,
        );
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $postData
     */
    private function applySettingsSections(array &$options, array $postData): string {
        $message = "";
        $message .= $this->updateRedirectSettings($options, $postData);
        $message .= $this->updateWordPressSettings($options, $postData);
        $message .= $this->updateNotificationSettings($options, $postData);
        $message .= $this->updateDeletionSettings($options, $postData);
        $message .= $this->updateSuggestionSettings($options, $postData);
        $message .= $this->updateBooleanToggles($options, $postData);
        $message .= $this->updateSuggestionHTMLOptions($options, $postData);
        $message .= $this->updateRegexPatternSettings($options, $postData);
        $message .= $this->updateAdminUsers($options, $postData);
        $message .= $this->updateExcludedPages($options, $postData);
        return $message;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $postData
     * @return string
     */
    private function updateRedirectSettings(array &$options, array $postData): string {
        return $this->redirectPolicy()->apply($options, $postData);
    }

    /** @param array<string, mixed> $options @param array<string, mixed> $postData @return string */
    public function updateWordPressSettings(array &$options, array $postData): string {
        return $this->wordPressPolicy()->apply($options, $postData);
    }

    /** @param array<string, mixed> $options @param array<string, mixed> $postData @return string */
    private function updateNotificationSettings(&$options, $postData) {
        return $this->notificationPolicy()->apply($options, $postData);
    }

    /** @param array<string, mixed> $options @param array<string, mixed> $postData @return string */
    public function updateDeletionSettings(array &$options, array $postData): string {
        return $this->retentionPolicy()->apply($options, $postData);
    }

    /** @param array<string, mixed> $options @param array<string, mixed> $postData @return string */
    public function updateSuggestionSettings(array &$options, array $postData): string {
        return $this->suggestionPolicy()->applyScoringOptions($options, $postData);
    }

    /** @param array<string, mixed> $options @param array<string, mixed> $postData @return string */
    public function updateBooleanToggles(array &$options, array $postData): string {
        return $this->booleanModePolicy()->apply($options, $postData);
    }

    /** @param array<string, mixed> $options @param array<string, mixed> $postData @return string */
    private function updateSuggestionHTMLOptions(array &$options, array $postData): string {
        return $this->suggestionPolicy()->applyTemplateOptions($options, $postData);
    }

    /**
     * @param array<string, mixed> $options
     * @return bool True when any option was changed.
     */
    function normalizeSuggestionTemplateOptions(array &$options): bool {
        return $this->suggestionPolicy()->normalizeTemplateOptions($options);
    }

    /** @param array<string, mixed> $options @param array<string, mixed> $postData @return string */
    private function updateRegexPatternSettings(array &$options, array $postData): string {
        return $this->regexPatternPolicy->apply($options, $postData);
    }

    /** @param array<string, mixed> $options @param array<string, mixed> $postData @return string */
    private function updateAdminUsers(array &$options, array $postData): string {
        return $this->adminExcludedPagesPolicy()->applyAdminUsers($options, $postData);
    }

    /** @param array<string, mixed> $options @param array<string, mixed> $postData @return string */
    private function updateExcludedPages(array &$options, array $postData): string {
        return $this->adminExcludedPagesPolicy()->applyExcludedPages($options, $postData);
    }

    /** @return ABJ_404_Solution_TableViewOptionsResolver */
    private function tableViewOptionsResolver(): ABJ_404_Solution_TableViewOptionsResolver {
        if ($this->tableViewOptionsResolver === null) {
            $self = $this;
            $this->tableViewOptionsResolver = new ABJ_404_Solution_TableViewOptionsResolver(
                $this->f,
                function (array $tableOptions) use ($self) {
                    return $self->sanitizePostData($tableOptions);
                }
            );
        }
        return $this->tableViewOptionsResolver;
    }

    /** @return ABJ_404_Solution_SettingsRedirectPolicy */
    private function redirectPolicy(): ABJ_404_Solution_SettingsRedirectPolicy {
        return $this->policy('redirect', ABJ_404_Solution_SettingsRedirectPolicy::class, function () {
            return new ABJ_404_Solution_SettingsRedirectPolicy();
        });
    }

    /** @return ABJ_404_Solution_SettingsWordPressPolicy */
    private function wordPressPolicy(): ABJ_404_Solution_SettingsWordPressPolicy {
        return $this->policy('wordpress', ABJ_404_Solution_SettingsWordPressPolicy::class, function () {
            return new ABJ_404_Solution_SettingsWordPressPolicy();
        });
    }

    /** @return ABJ_404_Solution_SettingsNotificationPolicy */
    private function notificationPolicy(): ABJ_404_Solution_SettingsNotificationPolicy {
        return $this->policy('notification', ABJ_404_Solution_SettingsNotificationPolicy::class, function () {
            return new ABJ_404_Solution_SettingsNotificationPolicy($this->logger);
        });
    }

    /** @return ABJ_404_Solution_SettingsRetentionPolicy */
    private function retentionPolicy(): ABJ_404_Solution_SettingsRetentionPolicy {
        return $this->policy('retention', ABJ_404_Solution_SettingsRetentionPolicy::class, function () {
            return new ABJ_404_Solution_SettingsRetentionPolicy($this->fieldValidator);
        });
    }

    /** @return ABJ_404_Solution_SettingsSuggestionPolicy */
    private function suggestionPolicy(): ABJ_404_Solution_SettingsSuggestionPolicy {
        return $this->policy('suggestion', ABJ_404_Solution_SettingsSuggestionPolicy::class, function () {
            return new ABJ_404_Solution_SettingsSuggestionPolicy($this->logger, $this->contentRepo);
        });
    }

    /** @return ABJ_404_Solution_SettingsBooleanModePolicy */
    private function booleanModePolicy(): ABJ_404_Solution_SettingsBooleanModePolicy {
        return $this->policy('booleanMode', ABJ_404_Solution_SettingsBooleanModePolicy::class, function () {
            return new ABJ_404_Solution_SettingsBooleanModePolicy($this->contentRepo);
        });
    }

    /** @return ABJ_404_Solution_SettingsAdminExcludedPagesPolicy */
    private function adminExcludedPagesPolicy(): ABJ_404_Solution_SettingsAdminExcludedPagesPolicy {
        return $this->policy('adminExcludedPages', ABJ_404_Solution_SettingsAdminExcludedPagesPolicy::class, function () {
            return new ABJ_404_Solution_SettingsAdminExcludedPagesPolicy($this->f, $this->logger, $this->contentRepo);
        });
    }

    /**
     * @template T of object
     * @param class-string<T> $className
     * @param callable(): T $factory
     * @return T
     */
    private function policy(string $name, string $className, callable $factory) {
        if (!isset($this->sectionPolicies[$name]) || !$this->sectionPolicies[$name] instanceof $className) {
            $this->sectionPolicies[$name] = $factory();
        }
        return $this->sectionPolicies[$name];
    }
}
