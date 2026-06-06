<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Presents option values for admin settings views.
 *
 * Owns view-only defaults, checked-state helpers, safe string extraction, and
 * the behavior-tile markup for the default 404 destination setting.
 */
class ABJ_404_Solution_View_OptionsPresenter extends ABJ_404_Solution_ViewComponent {

	/** Get the 'checked' attribute for a checkbox based on option value.
	 * @param array<string, mixed> $options The options array
	 * @param string $key The option key to check
	 * @return string Returns ' checked' if option is '1', empty string otherwise
	 */
	public function getCheckedAttr($options, $key) {
		return (array_key_exists($key, $options) && $options[$key] == '1') ? " checked" : "";
	}

	/** @return array<string, string> */
	public function getFallbackOptionDefaults() {
		return array(
			'default_redirect' => '301',
			'DB_VERSION' => defined('ABJ404_VERSION') ? ABJ404_VERSION : '',
			'menuLocation' => 'optionsLevel',
			'admin_theme' => 'default',
			'capture_deletion' => '0',
			'admin_notification' => '0',
			'maximum_log_disk_usage' => '0',
			'admin_notification_email' => '',
			'suggest_cats' => '0',
			'suggest_tags' => '0',
			'update_suggest_url' => '0',
			'suggest_max' => '5',
			'suggest_title' => '',
			'suggest_before' => '',
			'suggest_after' => '',
			'suggest_entrybefore' => '',
			'suggest_entryafter' => '',
			'suggest_noresults' => '',
			'ignore_doprocess' => '',
			'ignore_dontprocess' => '',
			'recognized_post_types' => 'page',
			'recognized_categories' => '',
			'folders_files_ignore' => '',
			'suggest_regex_exclusions' => '',
			'plugin_admin_users' => '',
			'auto_score' => '0',
			'template_redirect_priority' => '9',
			'days_wait_before_major_update' => '0',
			'excludePages[]' => '',
			// These are used by other option cards; harmless defaults.
			'auto_deletion' => '0',
			'manual_deletion' => '0',
		);
	}

	/**
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	public function normalizeOptionsForView($options) {
		return array_merge($this->getFallbackOptionDefaults(), $options);
	}

	/**
	 * Get plugin options merged with defaults.
	 *
	 * Some tests use partial/mocked PluginLogic instances; this method must not
	 * assume getDefaultOptions() is available or safe to call.
	 *
	 * @return array<string, mixed>
	 */
	public function getOptionsWithDefaults() {
		$options = abj_service('options_repository')->getOptions();
		if (!is_array($options)) {
			$options = array();
		}

		$defaults = array();
		if (is_object($this->logic) && method_exists($this->logic, 'getDefaultOptions')) {
			try {
				$defaults = ABJ_404_Solution_PluginLogicDefaults::defaults();
			} catch (Throwable $e) { // allow-silent-catch: getDefaultOptions() is best-effort; empty array merges with getFallbackOptionDefaults() below
				$defaults = array();
			}
		}

		$defaults = is_array($defaults) && !empty($defaults)
			? array_merge($this->getFallbackOptionDefaults(), $defaults)
			: $this->getFallbackOptionDefaults();

		return array_merge($defaults, $options);
	}

	/**
	 * Build the behavior tiles HTML for the 404 destination setting.
	 * Used by both simple and advanced mode.
	 *
	 * @param array<string, mixed> $options
	 * @return string
	 */
	public function getBehaviorTilesHTML($options) {
		$behavior = isset($options['dest404_behavior']) && is_string($options['dest404_behavior'])
			? $options['dest404_behavior'] : 'theme_default';

		$userSelectedDefault404PageRaw = (array_key_exists('dest404page', $options) &&
			isset($options['dest404page']) ? $options['dest404page'] : null);
		$userSelectedDefault404Page = is_string($userSelectedDefault404PageRaw) ? $userSelectedDefault404PageRaw : '';
		$urlDestinationRaw = (array_key_exists('dest404pageURL', $options) &&
			isset($options['dest404pageURL']) ? $options['dest404pageURL'] : null);
		$urlDestination = is_string($urlDestinationRaw) ? $urlDestinationRaw : '';

		$pageTitle = $this->logic->pageOrdering()->getPageTitleFromIDAndType($userSelectedDefault404Page, $urlDestination);
		$pageMissingWarning = "";
		if ($behavior === 'custom' && $userSelectedDefault404Page !== '') {
			$permalink = ABJ_404_Solution_PermalinkResolver::permalinkInfoToArray($userSelectedDefault404Page, 0);
			if (!in_array($permalink['status'], array('publish', 'published'))) {
				$pageMissingWarning = __("(The specified page doesn't exist. Please update this setting.)", '404-solution');
			}
		}

		$customDropdown = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) .
			"/html/addManualRedirectPageSearchDropdown.html");
		$customDropdown = $this->f->str_replace('{redirect_to_label}', '', $customDropdown);
		$customDropdown = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_EMPTY}',
			__('(Type a page name or an external URL)', '404-solution'), $customDropdown);
		$customDropdown = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_PAGE}',
			__('(A page has been selected.)', '404-solution'), $customDropdown);
		$customDropdown = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_CUSTOM_STRING}',
			__('(A custom string has been entered.)', '404-solution'), $customDropdown);
		$customDropdown = $this->f->str_replace('{TOOLTIP_POPUP_EXPLANATION_URL}',
			__('(An external URL will be used.)', '404-solution'), $customDropdown);
		$customDropdown = $this->f->str_replace('{REDIRECT_TO_USER_FIELD_WARNING}', $pageMissingWarning, $customDropdown);
		$customDropdown = $this->f->str_replace('{redirectPageTitle}', esc_attr($pageTitle), $customDropdown);
		$customDropdown = $this->f->str_replace('{pageIDAndType}', esc_attr($userSelectedDefault404Page), $customDropdown);
		$customDropdown = $this->f->str_replace('{data-url}',
			"admin-ajax.php?action=echoRedirectToPages&includeDefault404Page=true&includeSpecial=true&nonce=" . wp_create_nonce('abj404_ajax'), $customDropdown);
		$customDropdown = $this->f->doNormalReplacements($customDropdown);

		$html = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/behaviorTiles.html");

		$behaviors = array('suggest', 'homepage', 'custom', 'theme_default');
		foreach ($behaviors as $b) {
			$isSelected = ($behavior === $b);
			$html = $this->f->str_replace('{tile_' . $b . '_selected}', $isSelected ? ' selected' : '', $html);
			$html = $this->f->str_replace('{' . $b . '_aria_checked}', $isSelected ? 'true' : 'false', $html);
		}

		$html = $this->f->str_replace('{selected_behavior}', esc_attr($behavior), $html);
		$html = $this->f->str_replace('{pageIDAndType}', esc_attr($userSelectedDefault404Page), $html);
		$html = $this->f->str_replace('{custom_picker_display}', $behavior === 'custom' ? '' : 'none', $html);
		$html = $this->f->str_replace('{customPageDropdown}', $customDropdown, $html);

		$html = $this->f->str_replace('{Recommended}', __('Recommended', '404-solution'), $html);
		$html = $this->f->str_replace('{Suggest similar pages}', __('Suggest similar pages', '404-solution'), $html);
		$html = $this->f->str_replace('{Shows visitors a list of pages matching the URL they were looking for}',
			__('Shows visitors a list of pages matching the URL they were looking for', '404-solution'), $html);
		$html = $this->f->str_replace('{Redirect to homepage}', __('Redirect to homepage', '404-solution'), $html);
		$html = $this->f->str_replace('{Sends all 404 visitors to the site front page}',
			__('Sends all 404 visitors to the site front page', '404-solution'), $html);
		$html = $this->f->str_replace('{Custom page}', __('Custom page', '404-solution'), $html);
		$html = $this->f->str_replace('{Choose a specific page to show for all 404 errors}',
			__('Choose a specific page to show for all 404 errors', '404-solution'), $html);
		$html = $this->f->str_replace('{Theme default}', __('Theme default', '404-solution'), $html);
		$html = $this->f->str_replace('{Uses the theme built-in 404 page, no redirect}',
			__('Uses the theme built-in 404 page, no redirect', '404-solution'), $html);
		$html = $this->f->str_replace('{Select a page}', __('Select a page', '404-solution'), $html);

		return $html;
	}

	/**
	 * Safely extract a string value from an options array.
	 *
	 * @param array<string, mixed> $options
	 * @param string $key
	 * @param string $default
	 * @return string
	 */
	public function optStr($options, $key, $default = '') {
		if (!array_key_exists($key, $options)) {
			return $default;
		}
		$val = $options[$key];
		if (is_string($val)) {
			return $val;
		}
		if (is_scalar($val)) {
			return (string)$val;
		}
		return $default;
	}
}
