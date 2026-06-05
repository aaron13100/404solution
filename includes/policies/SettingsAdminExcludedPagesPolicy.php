<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Applies plugin-admin-user and excluded-page settings.
 */
class ABJ_404_Solution_SettingsAdminExcludedPagesPolicy {

    /** @var ABJ_404_Solution_Functions */
    private $functions;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_ContentRepositoryInterface */
    private $contentRepo;

    /**
     * @param ABJ_404_Solution_Functions $functions
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_ContentRepositoryInterface $contentRepo
     */
    public function __construct($functions, $logger, $contentRepo) {
        $this->functions = $functions;
        $this->logger = $logger;
        $this->contentRepo = $contentRepo;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $postData
     * @return string Empty string; kept for section-updater message contract.
     */
    public function applyAdminUsers(array &$options, array $postData): string {
        if (isset($postData['plugin_admin_users'])) {
            $pluginAdminUsers = $postData['plugin_admin_users'];
            if (is_array($pluginAdminUsers)) {
                $pluginAdminUsers = array_filter($pluginAdminUsers, function ($value) {
                    return is_scalar($value) && (bool)$this->functions->removeEmptyCustom((string)$value);
                });
            }
            $options['plugin_admin_users'] = $pluginAdminUsers;
        }

        return "";
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $postData
     * @return string Empty string; kept for section-updater message contract.
     */
    public function applyExcludedPages(array &$options, array $postData): string {
        if (is_array($options['excludePages[]'])) {
            $this->logger->warn("Exclude pages settings lost.");
            $options['excludePages[]'] = '';
        }

        if (isset($postData['excludePages[]'])) {
            $this->replaceExcludedPages($options, $postData['excludePages[]']);
        } else {
            $this->clearExcludedPages($options);
        }

        return "";
    }

    /**
     * @param array<string, mixed> $options
     * @param mixed $rawExcludedPages
     */
    private function replaceExcludedPages(array &$options, $rawExcludedPages): void {
        $excludePagesStr = is_string($options['excludePages[]']) ? $options['excludePages[]'] : '';
        $oldExcludePages = json_decode($excludePagesStr);
        $excludePages = is_array($rawExcludedPages) ? $rawExcludedPages : array($rawExcludedPages);
        $encodedPages = json_encode($excludePages);
        $options['excludePages[]'] = is_string($encodedPages) ? $encodedPages : '';
        $newExcludePages = json_decode($options['excludePages[]']);
        if ($newExcludePages !== $oldExcludePages) {
            $this->contentRepo->deleteSpellingCache();
        }
    }

    /** @param array<string, mixed> $options */
    private function clearExcludedPages(array &$options): void {
        $excludePagesStr = is_string($options['excludePages[]']) ? $options['excludePages[]'] : '';
        $oldExcludePages = json_decode($excludePagesStr);
        if (null !== $oldExcludePages) {
            $this->contentRepo->deleteSpellingCache();
        }
        $options['excludePages[]'] = null;
    }
}
