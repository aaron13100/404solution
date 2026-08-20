<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Presents the Simple Mode options page.
 */
class ABJ_404_Solution_View_SimpleSettings extends ABJ_404_Solution_ViewComponent {

    /**
     * Echo the Simple Mode options page.
     * @param array<string, mixed> $options The plugin options
     * @return void
     */
    function echoSimpleModeOptions($options) {
        $behaviorTilesHtml = $this->optionsPresenter->getBehaviorTilesHTML($options);

        $selectedAutoRedirects = $this->optionsPresenter->getCheckedAttr($options, 'auto_redirects');
        $selectedCapture404 = $this->optionsPresenter->getCheckedAttr($options, 'capture_404');
        $selectedDefaultRedirect301 = ($options['default_redirect'] == '301') ? 'selected' : '';
        $selectedDefaultRedirect302 = ($options['default_redirect'] == '302') ? 'selected' : '';
        $selectedDefaultRedirect307 = ($options['default_redirect'] == '307') ? 'selected' : '';
        $selectedDefaultRedirect308 = ($options['default_redirect'] == '308') ? 'selected' : '';

        $selectedThemeDefault = ($options['admin_theme'] == 'default') ? 'selected' : '';
        $selectedThemeCalm = ($options['admin_theme'] == 'calm') ? 'selected' : '';
        $selectedThemeMono = ($options['admin_theme'] == 'mono') ? 'selected' : '';
        $selectedThemeNeon = ($options['admin_theme'] == 'neon') ? 'selected' : '';
        $selectedThemeObsidian = ($options['admin_theme'] == 'obsidian') ? 'selected' : '';

        $notifyFrequency = isset($options['admin_notification_frequency']) ? (string)(is_scalar($options['admin_notification_frequency']) ? $options['admin_notification_frequency'] : 'instant') : 'instant';
        $selectedNotifyInstant = ($notifyFrequency === 'instant') ? 'selected' : '';
        $selectedNotifyDaily   = ($notifyFrequency === 'daily')   ? 'selected' : '';
        $selectedNotifyWeekly  = ($notifyFrequency === 'weekly')  ? 'selected' : '';
        $selectedNotifyNever   = ($notifyFrequency === 'never')   ? 'selected' : '';

        $html = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/adminOptionsSimple.html");
        $html = $this->f->str_replace('{behaviorTiles}', $behaviorTilesHtml, $html);
        $html = $this->f->str_replace('{selectedAutoRedirects}', $selectedAutoRedirects, $html);
        $html = $this->f->str_replace('{selectedCapture404}', $selectedCapture404, $html);
        $html = $this->f->str_replace('{selectedDefaultRedirect301}', $selectedDefaultRedirect301, $html);
        $html = $this->f->str_replace('{selectedDefaultRedirect302}', $selectedDefaultRedirect302, $html);
        $html = $this->f->str_replace('{selectedDefaultRedirect307}', $selectedDefaultRedirect307, $html);
        $html = $this->f->str_replace('{selectedDefaultRedirect308}', $selectedDefaultRedirect308, $html);

        $html = $this->f->str_replace('{capture_deletion}', esc_attr($this->optionsPresenter->optStr($options, 'capture_deletion')), $html);
        $html = $this->f->str_replace('{admin_notification}', esc_attr($this->optionsPresenter->optStr($options, 'admin_notification')), $html);
        $html = $this->f->str_replace('{maximum_log_disk_usage}', esc_attr($this->optionsPresenter->optStr($options, 'maximum_log_disk_usage')), $html);

        $html = $this->f->str_replace('{selectedThemeDefault}', $selectedThemeDefault, $html);
        $html = $this->f->str_replace('{selectedThemeCalm}', $selectedThemeCalm, $html);
        $html = $this->f->str_replace('{selectedThemeMono}', $selectedThemeMono, $html);
        $html = $this->f->str_replace('{selectedThemeNeon}', $selectedThemeNeon, $html);
        $html = $this->f->str_replace('{selectedThemeObsidian}', $selectedThemeObsidian, $html);
        $html = $this->f->str_replace('{theme_default}', __('Default (Follows WordPress)', '404-solution'), $html);

        $html = $this->f->str_replace('{Core Settings}', __('Core Settings', '404-solution'), $html);
        $html = $this->f->str_replace('{404 Capture}', __('404 Capture', '404-solution'), $html);
        $html = $this->f->str_replace('{Maintenance}', __('Maintenance', '404-solution'), $html);
        $html = $this->f->str_replace('{Default 404 destination}', __('Default 404 destination', '404-solution'), $html);
        $html = $this->f->str_replace('{Where to send visitors when a 404 error occurs}', __('Where to send visitors when a 404 error occurs', '404-solution'), $html);
        $html = $this->f->str_replace('{Create automatic redirects}', __('Create automatic redirects', '404-solution'), $html);
        $html = $this->f->str_replace(
            '{Automatically redirect 404s to similar pages that score at least %1$s out of 100. To change that number, switch to Advanced Mode and look for %2$s in the System section.}',
            sprintf(
                /* translators: 1: the configured minimum match score, e.g. 90. 2: the localized label of the Minimum match score setting, so it matches the Advanced Mode screen exactly. */
                __('Automatically redirect 404s to similar pages that score at least %1$s out of 100. To change that number, switch to Advanced Mode and look for %2$s in the System section.', '404-solution'),
                esc_html($this->getMinimumMatchScoreForDisplay($options)),
                esc_html(__('Minimum match score', '404-solution'))
            ),
            $html
        );
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

        $html = $this->f->str_replace('{selectedNotifyInstant}', $selectedNotifyInstant, $html);
        $html = $this->f->str_replace('{selectedNotifyDaily}', $selectedNotifyDaily, $html);
        $html = $this->f->str_replace('{selectedNotifyWeekly}', $selectedNotifyWeekly, $html);
        $html = $this->f->str_replace('{selectedNotifyNever}', $selectedNotifyNever, $html);
        $html = $this->f->str_replace('{Email notification frequency}', __('Email notification frequency', '404-solution'), $html);
        $html = $this->f->str_replace('{Instant (when threshold exceeded)}', __('Instant (when threshold exceeded)', '404-solution'), $html);
        $html = $this->f->str_replace('{Daily digest}', __('Daily digest', '404-solution'), $html);
        $html = $this->f->str_replace('{Weekly digest}', __('Weekly digest', '404-solution'), $html);
        $html = $this->f->str_replace('{Never}', __('Never', '404-solution'), $html);
        $html = $this->f->str_replace('{Choose how often to receive email notifications about captured 404s.}', __('Choose how often to receive email notifications about captured 404s.', '404-solution'), $html);
        $html = $this->f->str_replace('{Temporary 307 (preserve method)}', __('Temporary 307 (preserve method)', '404-solution'), $html);
        $html = $this->f->str_replace('{Permanent 308 (preserve method)}', __('Permanent 308 (preserve method)', '404-solution'), $html);

        echo $html;
    }

    /**
     * The minimum match score to name in the Simple Mode help text.
     *
     * Simple Mode does not render the auto_score field itself (it lives in
     * Advanced Mode > System), so the help text is the only place a simple-mode
     * admin can learn what the bar currently is. Always report the value the
     * matcher will actually apply rather than the shipped 90, so an admin who
     * already changed it is not told the wrong number.
     *
     * Delegates to MinimumAutoRedirectScore so this screen and the admin-only
     * note on the front-end suggestions page can never quote different numbers
     * for the same setting.
     *
     * @param array<string, mixed> $options The plugin options.
     * @return string The configured score, or the shipped default when the
     *   stored value is missing, blank, or not a number.
     */
    private function getMinimumMatchScoreForDisplay($options) {
        return ABJ_404_Solution_MinimumAutoRedirectScore::forDisplay($options);
    }
}
