<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: covered through Tools page entry-point tests in tests/ViewToolsPageTest.php

/**
 * Renders the Tools admin tab and debug-file viewer.
 */
class ABJ_404_Solution_View_Tools extends ABJ_404_Solution_ViewComponent {

    /**
     * Load an HTML template from includes/html/ as a string.
     *
     * @param string $name Filename relative to includes/html/.
     * @return string
     */
    private function tpl($name) {
        return (string)ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/' . $name);
    }

    /** @return void */
    function echoAdminDebugFile() {
        $isPluginAdmin = abj_service('admin_access_policy')->isPluginAdmin();
        if ($isPluginAdmin) {
            $filesToEcho = array($this->logger->getDebugFilePath(),
                    $this->logger->getDebugFilePathOld());
            $wrapperTpl = $this->tpl('viewStatsDebugFileWrapper.html');
            for ($i = 0; $i < count($filesToEcho); $i++) {
                $currentFile = $filesToEcho[$i];
                ob_start();
                $this->echoFileContents($currentFile);
                $contents = (string)ob_get_clean();
                $row = $this->f->str_replace('{file_name}', esc_html((string)$currentFile), $wrapperTpl);
                $row = $this->f->str_replace('{contents}', $contents, $row);
                echo $row;
            }

        } else {
            echo "Non-admin request to view debug file.";
            $current_user = ABJ_404_Solution_UserRef::fromWpUser(wp_get_current_user());
            $userLogin = $current_user !== null ? $current_user->getLogin() : '';
            $userDisplay = $current_user !== null ? $current_user->getDisplayName() : '';
            $userEmail = $current_user !== null ? $current_user->getEmail() : '';
            $userId = $current_user !== null ? $current_user->getId() : 0;
            $userInfo = "Login: " . $userLogin . ", display name: " .
                $userDisplay . ", Email: " . $userEmail .
                ", UserID: " . $userId;
            $this->logger->infoMessage("Non-admin request to view debug file. User info: " .
                $userInfo);
        }
    }

    /**
     * @param string $fileName
     * @return void
     */
    function echoFileContents($fileName) {
        if (!is_string($fileName)) {
            $fileName = '';
        }

        if (file_exists($fileName)) {
            $linesRead = 0;
            $handle = null;
            try {
                if ($handle = fopen($fileName, "r")) {
                    $truncatedTpl = $this->tpl('viewStatsDebugFileTruncated.html');
                    while (($line = fgets($handle)) !== false) {
                        $linesRead++;
                        echo nl2br(esc_html($line));

                        if ($linesRead > 1000000) {
                            echo $this->f->str_replace('{lines_read}', esc_html((string)$linesRead), $truncatedTpl);
                            break;
                        }
                    }
                } else {
                    $this->logger->errorMessage("Error opening debug file.");
                }

            } catch (Exception $e) {
                $this->logger->errorMessage("Error while reading debug file.", $e);
            } finally {
                // finally (not "after the try/catch") so the handle still
                // closes if a Throwable that isn't an Exception escapes the
                // loop -- the previous unconditional-looking cleanup below
                // the try/catch was skipped in that case. Same
                // resource-lifecycle shape as
                // includes/import/ImportService.php::doImportFile().
                if ($handle != null) {
                    fclose($handle);
                }
            }

        } else {
            echo nl2br(__('(The log file does not exist.)', '404-solution'));
        }
    }

    /**
     * Display the tools page.
     * @return void
     */
    function echoAdminToolsPage() {
        $view = $this->view;

        $header = $this->tpl('viewStatsToolsPageHeader.html');
        $header = $this->f->str_replace('{title}', esc_html__('Tools', '404-solution'), $header);
        $header = $this->f->str_replace('{expand_all}', esc_html__('Expand All', '404-solution'), $header);
        echo $header;

        $link = wp_nonce_url("?page=" . ABJ404_PP . "&subpage=abj404_tools", "abj404_exportRedirects");
        $html = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/toolsExportForm.html");
        $html = $this->f->str_replace('{toolsExportRedirectsLink}', $link, $html);
        $html = $this->f->doNormalReplacements($html);
        $view->echoOptionsSection(new ABJ_404_Solution_OptionsSectionView('tools-export', 'abj404-exportRedirects', __('Export', '404-solution'), $html, true, $view->getCardIcon('download')));

        $link = wp_nonce_url("?page=" . ABJ404_PP . "&subpage=abj404_tools", "abj404_importRedirectsFile");
        $html = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/toolsImportForm.html");
        $html = $this->f->str_replace('{toolsImportRedirectsLink}', $link, $html);
        $html = $this->f->doNormalReplacements($html);
        $view->echoOptionsSection(new ABJ_404_Solution_OptionsSectionView('tools-import', 'abj404-importRedirects', __('Import', '404-solution'), $html, false, $view->getCardIcon('upload')));

        $url = "?page=" . ABJ404_PP . "&subpage=abj404_tools";
        $link = wp_nonce_url($url, "abj404_purgeRedirects");
        $html = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/toolsPurgeForm.html");
        $html = $this->f->str_replace('{toolsPurgeFormActionLink}', $link, $html);
        $html = $this->f->doNormalReplacements($html);
        $view->echoOptionsSection(new ABJ_404_Solution_OptionsSectionView('tools-purge', 'abj404-purgeRedirects', __('Purge Options', '404-solution'), $html, false, $view->getCardIcon('trash')));

        $ngramLink = wp_nonce_url("?page=" . ABJ404_PP . "&subpage=abj404_tools", "abj404_rebuildNgramCache");
        $spellingLink = wp_nonce_url("?page=" . ABJ404_PP . "&subpage=abj404_tools", "abj404_clearSpellingCache");
        $html = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/toolsCacheForm.html");
        $html = $this->f->str_replace('{toolsNgramCacheFormActionLink}', $ngramLink, $html);
        $html = $this->f->str_replace('{toolsSpellingCacheFormActionLink}', $spellingLink, $html);
        $html = $this->f->doNormalReplacements($html);
        $view->echoOptionsSection(new ABJ_404_Solution_OptionsSectionView('tools-cache', 'abj404-cacheTools', __('Cache Management', '404-solution'), $html, false, $view->getCardIcon('database')));

        $html = $this->getToolsDiagnosticsMarkup();
        $view->echoOptionsSection(new ABJ_404_Solution_OptionsSectionView('tools-diagnostics', 'abj404-diagnosticsTools', __('Diagnostics', '404-solution'), $html, false, $view->getCardIcon('warning')));

        $link = wp_nonce_url("?page=" . ABJ404_PP . "&subpage=abj404_tools", "abj404_runMaintenance");
        $link .= '&manually_fired=true';
        $html = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/toolsEtcForm.html");
        $html = $this->f->str_replace('{toolsMaintenanceFormActionLink}', $link, $html);
        $html = $this->f->doNormalReplacements($html);
        $view->echoOptionsSection(new ABJ_404_Solution_OptionsSectionView('tools-etc', 'abj404-etcTools', __('Etcetera', '404-solution'), $html, false, $view->getCardIcon('cog')));

        $html = $this->getMigrateFromPluginMarkup();
        $view->echoOptionsSection(new ABJ_404_Solution_OptionsSectionView('tools-migrate', 'abj404-migrateFromPlugin', __('Migrate from Another Plugin', '404-solution'), $html, false, $view->getCardIcon('upload')));

        echo $this->tpl('viewStatsToolsPageFooter.html');
    }

    /**
     * Build the "Migrate from Another Plugin" card markup.
     *
     * @return string
     */
    public function getMigrateFromPluginMarkup(): string {
        // Use the redirects repository already injected into this component
        // (ViewComponent::$redirectsRepository) rather than re-resolving it from
        // the service container. The dependency is in hand; reaching for
        // abj_service() here would bypass the component's own wiring.
        $redirectsRepo = is_object($this->redirectsRepository)
            ? $this->redirectsRepository
            : abj_service('redirects_repository');
        $logger        = abj_service('logging');
        $importer = new ABJ_404_Solution_CrossPluginImporter($redirectsRepo, $logger);

        $detected = $importer->detectInstalledPlugins();

        $pluginLabels = array(
            'rankmath'           => __('Rank Math', '404-solution'),
            'yoast'              => __('Yoast SEO Premium', '404-solution'),
            'aioseo'             => __('AIOSEO', '404-solution'),
            'safe-redirect-manager' => __('Safe Redirect Manager', '404-solution'),
            'redirection'        => __('Redirection Plugin', '404-solution'),
        );

        $availableSources = array();
        foreach ($detected as $slug => $isAvailable) {
            if ($isAvailable) {
                $availableSources[$slug] = isset($pluginLabels[$slug]) ? $pluginLabels[$slug] : $slug;
            }
        }

        $migrateActionUrl = wp_nonce_url(
            '?page=' . ABJ404_PP . '&subpage=abj404_tools',
            'abj404_importFromPlugin'
        );

        if (empty($availableSources)) {
            $html = $this->tpl('viewStatsMigrateEmpty.html');
            $html = $this->f->str_replace('{empty_text}', esc_html__('No supported redirect plugins detected on this site.', '404-solution'), $html);
            $html = $this->f->str_replace('{supported_text}', esc_html__('Supported plugins: Rank Math, Yoast SEO Premium, AIOSEO, Safe Redirect Manager, Redirection.', '404-solution'), $html);
            return $html;
        }

        $detectedNames = array_values($availableSources);

        $previewNonce = wp_create_nonce('abj404_crossPluginPreview');
        $ajaxUrl      = admin_url('admin-ajax.php');

        $optionTpl = $this->tpl('viewStatsMigrateOption.html');
        $sourceOptionsHtml = '';
        foreach ($availableSources as $slug => $label) {
            $opt = $this->f->str_replace('{slug}', esc_attr((string)$slug), $optionTpl);
            $opt = $this->f->str_replace('{label}', esc_html((string)$label), $opt);
            $sourceOptionsHtml .= $opt;
        }

        // allow-em-dash: preserving shipped translation string verbatim (extracted, not authored, here)
        $msgFound = __('Found %d redirect(s) from %s — proceed with import?', '404-solution');
        $migrateConfig = wp_json_encode(array(
            'ajaxUrl'  => $ajaxUrl,
            'nonce'    => $previewNonce,
            'msgFound' => $msgFound,
            'msgNone'  => __('No redirects found in %s. Nothing to import.', '404-solution'),
            'msgError' => __('Could not fetch preview. Please try again.', '404-solution'),
        ));

        $html = $this->tpl('viewStatsMigrateForm.html');
        $html = $this->f->str_replace('{detected_label}', esc_html__('Detected redirect plugins:', '404-solution'), $html);
        $html = $this->f->str_replace('{detected_names}', esc_html(implode(', ', $detectedNames)), $html);
        $html = $this->f->str_replace('{source_label}', esc_html__('Source plugin:', '404-solution'), $html);
        $html = $this->f->str_replace('{source_options}', $sourceOptionsHtml, $html);
        $html = $this->f->str_replace('{preview_text}', esc_html__('Preview Import', '404-solution'), $html);
        $html = $this->f->str_replace('{action_url}', esc_url($migrateActionUrl), $html);
        $html = $this->f->str_replace('{confirm_text}', esc_attr__('Confirm Import', '404-solution'), $html);
        $html = $this->f->str_replace('{back_text}', esc_html__('Back', '404-solution'), $html);
        $html = $this->f->str_replace('{note_text}', esc_html__('This will import all active redirects from the selected plugin into 404 Solution. Regex and redirect codes are preserved.', '404-solution'), $html);
        $html = $this->f->str_replace('{migrate_config}', esc_attr((string)$migrateConfig), $html);

        return $html;
    }

    /**
     * Build compact diagnostics markup for the Tools page.
     *
     * @return string
     */
    public function getToolsDiagnosticsMarkup() {
        $rows = $this->getToolsDiagnosticsRows();
        $rowTpl = $this->tpl('viewStatsDiagnosticsRow.html');
        $rowsHtml = '';

        foreach ($rows as $row) {
            $label = $this->readString($row, 'label');
            $value = $this->readString($row, 'value');
            $valueHtml = $this->readString($row, 'value_html');
            $status = $this->readString($row, 'status');
            if ($status === '') {
                $status = 'info';
            }
            $statusLabel = ($status === 'ok') ? __('OK', '404-solution') : (($status === 'warn') ? __('Warning', '404-solution') : __('Info', '404-solution'));
            $statusClass = ($status === 'ok') ? 'abj404-pill-success' : (($status === 'warn') ? 'abj404-pill-warning' : 'abj404-pill-info');

            $valueCell = ($valueHtml !== '') ? wp_kses_post($valueHtml) : esc_html($value);

            $rowHtml = $this->f->str_replace('{label}', esc_html($label), $rowTpl);
            $rowHtml = $this->f->str_replace('{value_cell}', $valueCell, $rowHtml);
            $rowHtml = $this->f->str_replace('{status_class}', esc_attr($statusClass), $rowHtml);
            $rowHtml = $this->f->str_replace('{status_label}', esc_html($statusLabel), $rowHtml);
            $rowsHtml .= $rowHtml;
        }

        $html = $this->tpl('viewStatsDiagnosticsTable.html');
        $html = $this->f->str_replace('{intro_text}', esc_html__('Quick environment checks for troubleshooting and support.', '404-solution'), $html);
        $html = $this->f->str_replace('{rows}', $rowsHtml, $html);

        return $html;
    }

    /**
     * Collect diagnostics fields displayed on the Tools page.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getToolsDiagnosticsRows() {
        $collector = new ABJ_404_Solution_ToolsDiagnostics();
        $rows = $collector->getRows();
        foreach ($rows as $index => $row) {
            $controls = is_array($row) && isset($row['controls']) && is_array($row['controls'])
                ? $row['controls']
                : null;
            if ($controls === null) {
                continue;
            }

            $rows[$index]['value_html'] = $this->renderDiagnosticsControls($controls);
            unset($rows[$index]['controls']);
        }

        return $rows;
    }

    /**
     * @param array<mixed> $controls
     * @return string
     */
    private function renderDiagnosticsControls(array $controls): string {
        if (($controls['kind'] ?? '') !== 'simulated-db-latency') {
            return '';
        }

        $urls = isset($controls['urls']) && is_array($controls['urls']) ? $controls['urls'] : array();
        $labels = isset($controls['labels']) && is_array($controls['labels']) ? $controls['labels'] : array();

        $controlsHtml = $this->tpl('viewStatsDiagnosticsLatencyControls.html');
        $controlsHtml = $this->f->str_replace('{value_text}', esc_html($this->valueToString($controls['value_text'] ?? '')), $controlsHtml);
        $controlsHtml = $this->f->str_replace('{url_250}', esc_url($this->valueToString($urls[250] ?? '')), $controlsHtml);
        $controlsHtml = $this->f->str_replace('{url_500}', esc_url($this->valueToString($urls[500] ?? '')), $controlsHtml);
        $controlsHtml = $this->f->str_replace('{url_900}', esc_url($this->valueToString($urls[900] ?? '')), $controlsHtml);
        $controlsHtml = $this->f->str_replace('{url_0}', esc_url($this->valueToString($urls[0] ?? '')), $controlsHtml);
        $controlsHtml = $this->f->str_replace('{label_250}', esc_html($this->valueToString($labels[250] ?? '')), $controlsHtml);
        $controlsHtml = $this->f->str_replace('{label_500}', esc_html($this->valueToString($labels[500] ?? '')), $controlsHtml);
        $controlsHtml = $this->f->str_replace('{label_900}', esc_html($this->valueToString($labels[900] ?? '')), $controlsHtml);
        $controlsHtml = $this->f->str_replace('{label_disable}', esc_html($this->valueToString($labels[0] ?? '')), $controlsHtml);

        return $controlsHtml;
    }

    /**
     * @param array<mixed> $row
     * @param string $key
     * @return string
     */
    private function readString(array $row, string $key): string {
        return $this->valueToString($row[$key] ?? '');
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function valueToString($value): string {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string)$value;
        }

        return '';
    }
}
