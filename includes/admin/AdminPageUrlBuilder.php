<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds absolute URLs to this plugin's own admin screens.
 *
 * The plugin registers its menu in one of two places depending on the
 * `menuLocation` option (see WordPress_Connector::addMainSettingsPageLink),
 * so the admin file that owns the page is either `admin.php` (top-level menu)
 * or `options-general.php` (Settings submenu). A link that hard-codes the
 * wrong one still resolves, because the options page redirects to the correct
 * URL, but the redirect drops anything the link was carrying that only the
 * browser understands -- a `#fragment` in particular. Any link that has to
 * land on a specific field therefore has to be built against the right admin
 * file in the first place, which is what this class is for.
 *
 * // allow-no-test-found: exercised by SuggestionsPageAdminNoteLinkTargetsTest
 */
class ABJ_404_Solution_AdminPageUrlBuilder {

    const FILE_TOP_LEVEL = 'admin.php';
    const FILE_UNDER_SETTINGS = 'options-general.php';

    /**
     * The wp-admin file that owns this plugin's pages for the current
     * `menuLocation` setting.
     *
     * @param array<string, mixed> $options The plugin options.
     * @return string 'admin.php' or 'options-general.php'.
     */
    public static function pageFile(array $options): string {
        $menuLocation = isset($options['menuLocation']) && is_string($options['menuLocation'])
            ? $options['menuLocation'] : '';
        return $menuLocation === 'settingsLevel' ? self::FILE_TOP_LEVEL : self::FILE_UNDER_SETTINGS;
    }

    /**
     * An absolute, unescaped URL to one of the plugin's subpages.
     *
     * Returned raw (not run through esc_url) so callers can append a
     * `#fragment` and escape once, at the point the URL is written into
     * markup.
     *
     * @param string $subpage The subpage slug, e.g. 'abj404_options'.
     * @param array<string, string> $queryArgs Extra query args, added in the
     *   order given. Keys and values are both url-encoded.
     * @param array<string, mixed> $options The plugin options.
     * @return string
     */
    public static function subpageUrl(string $subpage, array $queryArgs, array $options): string {
        $path = self::pageFile($options) . '?page=' . rawurlencode(ABJ404_PP) .
            '&subpage=' . rawurlencode($subpage);
        foreach ($queryArgs as $key => $value) {
            $path .= '&' . rawurlencode((string)$key) . '=' . rawurlencode((string)$value);
        }
        return admin_url($path);
    }
}
