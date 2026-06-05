<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__FILE__) . '/SettingsFieldValidator.php';
require_once dirname(__FILE__) . '/TableViewOptionsResolver.php';
require_once dirname(__FILE__) . '/policies/SettingsAdminExcludedPagesPolicy.php';
require_once dirname(__FILE__) . '/policies/SettingsBooleanModePolicy.php';
require_once dirname(__FILE__) . '/policies/SettingsNotificationPolicy.php';
require_once dirname(__FILE__) . '/policies/SettingsRedirectPolicy.php';
require_once dirname(__FILE__) . '/policies/SettingsRegexPatternPolicy.php';
require_once dirname(__FILE__) . '/policies/SettingsRetentionPolicy.php';
require_once dirname(__FILE__) . '/policies/SettingsSuggestionPolicy.php';
require_once dirname(__FILE__) . '/policies/SettingsWordPressPolicy.php';
require_once dirname(__FILE__) . '/services/SettingsOptionsPersister.php';
require_once dirname(__FILE__) . '/services/SettingsUpdateRequestDecoder.php';
require_once dirname(__FILE__) . '/services/SettingsUpdateResultBuilder.php';

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

    /** @var ABJ_404_Solution_SettingsUpdateRequestDecoder */
    private $requestDecoder;

    /** @var ABJ_404_Solution_SettingsOptionsPersister */
    private $optionsPersister;

    /** @var ABJ_404_Solution_SettingsRegexPatternPolicy */
    private $regexPatternPolicy;

    /** @var array<string, object> */
    private $sectionPolicies = array();

    /** @var ABJ_404_Solution_SettingsUpdateResultBuilder|null */
    private $resultBuilder;

    /**
     * @param ABJ_404_Solution_Functions $f
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_ContentRepositoryInterface $contentRepo
     * @param ABJ_404_Solution_PluginLogic $pluginLogic
     * @param ABJ_404_Solution_TableViewOptionsResolver|null $tableViewOptionsResolver
     * @param ABJ_404_Solution_SettingsFieldValidator|null $fieldValidator
     * @param ABJ_404_Solution_SettingsUpdateRequestDecoder|null $requestDecoder
     * @param ABJ_404_Solution_SettingsOptionsPersister|null $optionsPersister
     * @param ABJ_404_Solution_SettingsRegexPatternPolicy|null $regexPatternPolicy
     */
    function __construct(
        $f,
        $logger,
        $contentRepo,
        $pluginLogic,
        $tableViewOptionsResolver = null,
        $fieldValidator = null,
        $requestDecoder = null,
        $optionsPersister = null,
        $regexPatternPolicy = null
    ) {
        $this->f = $f;
        $this->logger = $logger;
        $this->contentRepo = $contentRepo;
        $this->pluginLogic = $pluginLogic;
        $this->tableViewOptionsResolver = $tableViewOptionsResolver;
        $this->fieldValidator = $fieldValidator !== null ? $fieldValidator : new ABJ_404_Solution_SettingsFieldValidator();
        $this->requestDecoder = $requestDecoder !== null ? $requestDecoder : new ABJ_404_Solution_SettingsUpdateRequestDecoder($logger);
        $this->optionsPersister = $optionsPersister !== null ? $optionsPersister : new ABJ_404_Solution_SettingsOptionsPersister();
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
        return $this->optionsPersister->sanitizePostData($postData, $restoreNewlines);
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
        $decodedRequest = $this->requestDecoder->decode($_POST);
        if (!$decodedRequest['success']) {
            return $decodedRequest;
        }
        if (!isset($decodedRequest['postData']) || !is_array($decodedRequest['postData'])) {
            $this->logger->errorMessage('Settings request decoder returned success without postData array');
            return array('success' => false, 'status' => 400, 'message' => 'Missing form data');
        }

        $_POST = $decodedRequest['postData'];
        if (array_key_exists('deleteDebugFile', $_POST) && $_POST['deleteDebugFile'] == true) {
            $returnData = $this->resultBuilder()->baseData();
            $returnData['error'] = '';
            $sub = '';
            $returnData['message'] = $this->pluginLogic->adminActions()->handlePluginAction('updateOptions', $sub);
            return $this->resultBuilder()->success($returnData);
        }

        $options = $this->optionsPersister->loadCurrentOptions();
        $message = $this->applySettingsSections($options, $_POST);
        $this->optionsPersister->persist($options);

        return $this->resultBuilder()->success($this->resultBuilder()->settingsSaveData($message));
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

    /** @return ABJ_404_Solution_SettingsUpdateResultBuilder */
    private function resultBuilder(): ABJ_404_Solution_SettingsUpdateResultBuilder {
        if ($this->resultBuilder === null) {
            $this->resultBuilder = new ABJ_404_Solution_SettingsUpdateResultBuilder();
        }
        return $this->resultBuilder;
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
