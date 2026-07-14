<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX handler for the Cross-Plugin Importer preview step.
 *
 * Action: abj404_crossPluginPreview
 *
 * POST params:
 *   nonce        – wp_create_nonce('abj404_crossPluginPreview')
 *   import_source – one of 'rankmath', 'yoast', 'aioseo', 'safe-redirect-manager', 'redirection'
 *
 * Returns JSON:
 *   { success: true,  data: { count: N, source: '...', label: '...' } }
 *   { success: false, data: { message: '...' } }
 */
class ABJ_404_Solution_Ajax_CrossPluginImporter {

    /** @return void */
    public static function handlePreview(): void {
        if (!ABJ_404_Solution_AjaxRequestContractValidator::requireValidCurrentRequest('ajax-cross-plugin-preview')) {
            return;
        }

        abj_service('ajax_security_gate')->requireAdminWithNonce('abj404_crossPluginPreview');

        $allowedSources = array('rankmath', 'yoast', 'aioseo', 'safe-redirect-manager', 'redirection');
        $source = isset($_POST['import_source']) && is_string($_POST['import_source'])
            ? sanitize_text_field($_POST['import_source'])
            : '';

        if ($source === '' || !in_array($source, $allowedSources, true)) {
            wp_send_json_error(array('message' => __('Invalid source plugin.', '404-solution')));
            return; // @phpstan-ignore deadCode.unreachable
        }

        $redirectsRepository = abj_service('redirects_repository');
        $logger = abj_service('logging');
        $importer = new ABJ_404_Solution_CrossPluginImporter($redirectsRepository, $logger);

        // Count-only path: never materializes the full row set (see
        // CrossPluginImporter::countImportable() / ForeignRedirectSourceReader::countSource()).
        $count = $importer->countImportable($source);

        // This array lists all allowed sources — $source has already been validated above.
        $pluginLabels = array(
            'rankmath'              => __('Rank Math', '404-solution'),
            'yoast'                 => __('Yoast SEO Premium', '404-solution'),
            'aioseo'                => __('AIOSEO', '404-solution'),
            'safe-redirect-manager' => __('Safe Redirect Manager', '404-solution'),
            'redirection'           => __('Redirection Plugin', '404-solution'),
        );
        $label = $pluginLabels[$source];

        wp_send_json_success(array(
            'count'  => $count,
            'source' => $source,
            'label'  => $label,
        ));
    }
}
