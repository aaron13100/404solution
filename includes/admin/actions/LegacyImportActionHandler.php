<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles legacy import/export admin actions that are still invoked from
 * View.php outside the POST action-handler registry.
 */
class ABJ_404_Solution_LegacyImportActionHandler {

    /** @var ABJ_404_Solution_PluginLogicAdminActions */
    private $parent;

    public function __construct(ABJ_404_Solution_PluginLogicAdminActions $parent) {
        $this->parent = $parent;
    }

    /** @return void */
    public function handleActionChangeItemsPerRow(): void {
        $userIsPluginAdmin = abj_service('admin_access_policy')->isPluginAdmin();
        if (ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize('action') == 'changeItemsPerRow' && $userIsPluginAdmin) {
            check_admin_referer('abj404_changeItemsPerRow');
            $this->parent->updatePerPageOption(absint(ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize('perpage')));
        }
    }

    /** @return void */
    public function handleActionExport(): void {
        if ((ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize('action') == 'exportRedirects') && abj_service('admin_access_policy')->isPluginAdmin()) {
            check_admin_referer('abj404_exportRedirects');
            $this->parent->getPluginLogic()->importExport()->doExport();
        }
    }

    /** @return string|null */
    public function handleActionImportFile() {
        if ((ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize('action') == 'importRedirectsFile') && abj_service('admin_access_policy')->isPluginAdmin()) {
            check_admin_referer('abj404_importRedirectsFile');
            $result = $this->parent->getPluginLogic()->importExport()->doImportFile();
            return $result;
        }

        return null;
    }

    /**
     * @return string
     */
    public function handleActionImportRedirects(): string {
        $message = "";

        if (ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize('action') == 'importRedirects') {
            if (ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize('sanity_404redirected') != '1') {
                return __("Error: You didn't check the I understand checkbox. No importing for you!", '404-solution');
            }

            check_admin_referer('abj404_importRedirects');

            try {
                $result = $this->parent->getDependencies()->getPluginUpdateRepo()->importDataFromPluginRedirectioner();
                if ($result['last_error'] != '') {
                    $lastErrorJson = json_encode($result['last_error']);
                    $message = sprintf(__("Error: No records were imported. SQL result: %s", '404-solution'),
                            wp_kses_post(is_string($lastErrorJson) ? $lastErrorJson : ''));
                } else {
                    $rowsAffected = is_scalar($result['rows_affected']) ? (string)$result['rows_affected'] : '0';
                    $message = sprintf(__("Records imported: %s", '404-solution'), esc_html($rowsAffected));
                }

            } catch (Exception $e) {
                $message = "Error: Importing failed. Message: " . $e->getMessage();
                $this->parent->getLogger()->errorMessage('Error importing redirects.', $e);
            }
        }

        return $message;
    }

    /**
     * @return string Human-readable result message.
     */
    public function handleActionUndoRegexAutoPromote(): string {
        $notice = ABJ_404_Solution_RegexAutoPromote::readNotice();
        if ($notice === null || $notice['redirect_id'] <= 0) {
            return __('Error: No regex auto-promotion to undo.', '404-solution');
        }
        $dbQuery = $this->parent->getDbQuery();
        $redirectsTable = $dbQuery->doTableNameReplacements('{wp_abj404_redirects}');
        $sql = "UPDATE `" . $redirectsTable . "` SET `url` = %s, `status` = %d WHERE `id` = %d";
        $dbQuery->queryAndGetResults($sql, array('query_params' => array(
            $notice['original_url'],
            (int)ABJ404_STATUS_MANUAL,
            (int)$notice['redirect_id'],
        )));
        ABJ_404_Solution_RegexAutoPromote::clearNotice();
        return sprintf(
            /* translators: %s = the original from_url string that was restored */
            __('Regex auto-promotion undone. Restored "%s" with status Manual.', '404-solution'),
            $notice['original_url']
        );
    }

    /**
     * @return string Human-readable result message.
     */
    public function handleActionImportFromPlugin(): string {
        $source = ABJ_404_Solution_RequestInputNormalizer::readText(
            $_POST, array('name' => 'import_source'));

        if ($source === '') {
            return __('Error: No source plugin specified.', '404-solution');
        }

        $allowedSources = array('rankmath', 'yoast', 'aioseo', 'safe-redirect-manager', 'redirection');
        if (!in_array($source, $allowedSources, true)) {
            return sprintf(
                /* translators: %s = unknown source identifier */
                __('Error: Unknown source plugin "%s".', '404-solution'),
                esc_html($source)
            );
        }

        $importer = new ABJ_404_Solution_CrossPluginImporter($this->parent->getRedirectsRepo(), $this->parent->getLogger());
        $count = $importer->importFrom($source);

        return sprintf(
            /* translators: %d = number of redirects imported */
            _n(
                '%d redirect imported successfully.',
                '%d redirects imported successfully.',
                $count,
                '404-solution'
            ),
            $count
        );
    }
}
