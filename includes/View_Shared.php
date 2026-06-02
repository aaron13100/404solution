<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ViewTrait_Shared methods.
 */
class ABJ_404_Solution_View_Shared extends ABJ_404_Solution_ViewComponent {

	/** @var array<string,string> Latest table data signatures by subpage. */
	protected $tableDataSignatures = array();

	/**
	 * Sanitize a GET or POST parameter.
	 * Delegates to Functions::getPostOrGetSanitize() when available,
	 * falls back to direct $_GET/$_POST read for test environments
	 * where the Functions mock may not have this method stubbed.
	 *
	 * @param string $name The parameter name.
	 * @param string|null $defaultValue Default value when not found.
	 * @return string
	 */
	public function viewGetPostOrGetSanitize($name, $defaultValue = null) {
		if (is_object($this->f)) {
			try {
				// DI resolver call: delegate to the injected Functions service
				$result = $this->f->getPostOrGetSanitize($name, $defaultValue);
				return is_string($result) ? $result : (is_scalar($result) ? (string)$result : '');
            } catch (\Throwable $e) {
                // allow-silent-catch: DI-injected service may not implement getPostOrGetSanitize.
                // DI-injected service may not implement getPostOrGetSanitize
                // (legacy mock). Fall through to inline GET/POST reader.
				$val = null;
			}
		}
		// Inline fallback for test contexts without the Functions mock expectation
		$val = isset($_GET[$name]) ? $_GET[$name] : (isset($_POST[$name]) ? $_POST[$name] : null);
		if ($val !== null && is_scalar($val)) {
			return function_exists('sanitize_text_field') ? sanitize_text_field((string)$val) : (string)$val;
		}
		return is_string($defaultValue) ? $defaultValue : '';
	}

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

		// Build the custom page dropdown for when 'custom' is selected
		$pageTitle = $this->logic->pageOrdering()->getPageTitleFromIDAndType($userSelectedDefault404Page, $urlDestination);
		$pageMissingWarning = "";
		if ($behavior === 'custom' && $userSelectedDefault404Page !== '') {
			$permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($userSelectedDefault404Page, 0);
			if (!in_array($permalink['status'], array('publish', 'published'))) {
				$pageMissingWarning = __("(The specified page doesn't exist. Please update this setting.)", '404-solution');
			}
		}

		$customDropdown = ABJ_404_Solution_Functions::readFileContents(__DIR__ .
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

		// Build tiles template
		$html = ABJ_404_Solution_Functions::readFileContents(__DIR__ . "/html/behaviorTiles.html");

		$behaviors = array('suggest', 'homepage', 'custom', 'theme_default');
		foreach ($behaviors as $b) {
			$key = str_replace('_', '_', $b); // just to be explicit
			$isSelected = ($behavior === $b);
			$html = $this->f->str_replace('{tile_' . $key . '_selected}', $isSelected ? ' selected' : '', $html);
			$html = $this->f->str_replace('{' . $key . '_aria_checked}', $isSelected ? 'true' : 'false', $html);
		}

		$html = $this->f->str_replace('{selected_behavior}', esc_attr($behavior), $html);
		$html = $this->f->str_replace('{pageIDAndType}', esc_attr($userSelectedDefault404Page), $html);
		$html = $this->f->str_replace('{custom_picker_display}', $behavior === 'custom' ? '' : 'none', $html);
		$html = $this->f->str_replace('{customPageDropdown}', $customDropdown, $html);

		// Translations
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

	/**
	 * Normalize a scalar value for table signature comparisons.
	 *
	 * @param mixed $value
	 * @return string
	 */
	public function normalizeSignatureValue($value) {
		if ($value === null) {
			return '';
		}
		if (is_bool($value)) {
			return $value ? '1' : '0';
		}
		if (is_int($value) || is_float($value)) {
			return (string)$value;
		}
		if (is_array($value)) {
			$value = implode(',', array_map(array($this, 'normalizeSignatureValue'), $value));
		}
		$text = is_scalar($value) ? (string)$value : '';
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = preg_replace('/\s+/', ' ', $text);
		return trim((string)$text);
	}

	/**
	 * Build a deterministic row signature payload for a specific admin list subpage.
	 *
	 * @param string $sub
	 * @param array<string, mixed> $row
	 * @return array<string,string>
	 */
	public function getSignatureFieldsForSubpage($sub, $row) {
		$sub = (string)$sub;

		if ($sub === 'abj404_redirects') {
			return array(
				'id' => $this->normalizeSignatureValue($row['id'] ?? ''),
				'url' => $this->normalizeSignatureValue($row['url'] ?? ''),
				'status' => $this->normalizeSignatureValue($row['status'] ?? ''),
				'type' => $this->normalizeSignatureValue($row['type'] ?? ''),
				'final_dest' => $this->normalizeSignatureValue($row['final_dest'] ?? ''),
				'dest_for_view' => $this->normalizeSignatureValue($row['dest_for_view'] ?? ''),
				'code' => $this->normalizeSignatureValue($row['code'] ?? ''),
				'logshits' => $this->normalizeSignatureValue($row['logshits'] ?? 0),
				'timestamp' => $this->normalizeSignatureValue($row['timestamp'] ?? 0),
				'last_used' => $this->normalizeSignatureValue($row['last_used'] ?? 0),
			);
		}

		if ($sub === 'abj404_captured') {
			$hits = array_key_exists('logshits', $row) ? $row['logshits'] : ($row['hit_count'] ?? 0);
			$timestamp = array_key_exists('timestamp', $row) ? $row['timestamp'] : ($row['created'] ?? 0);
			return array(
				'id' => $this->normalizeSignatureValue($row['id'] ?? ''),
				'url' => $this->normalizeSignatureValue($row['url'] ?? ''),
				'status' => $this->normalizeSignatureValue($row['status'] ?? ''),
				'logshits' => $this->normalizeSignatureValue($hits),
				'timestamp' => $this->normalizeSignatureValue($timestamp),
				'last_used' => $this->normalizeSignatureValue($row['last_used'] ?? 0),
			);
		}

		if ($sub === 'abj404_logs') {
			return array(
				'id' => $this->normalizeSignatureValue($row['id'] ?? ''),
				'url' => $this->normalizeSignatureValue($row['url'] ?? ''),
				'url_detail' => $this->normalizeSignatureValue($row['url_detail'] ?? ''),
				'remote_host' => $this->normalizeSignatureValue($row['remote_host'] ?? ''),
				'referrer' => $this->normalizeSignatureValue($row['referrer'] ?? ''),
				'action' => $this->normalizeSignatureValue($row['action'] ?? ''),
				'timestamp' => $this->normalizeSignatureValue($row['timestamp'] ?? 0),
				'username' => $this->normalizeSignatureValue($row['username'] ?? ''),
			);
		}

		$normalized = array();
		foreach ($row as $k => $v) {
			if (is_scalar($v) || is_array($v) || $v === null) {
				$normalized[(string)$k] = $this->normalizeSignatureValue($v);
			}
		}
		ksort($normalized);
		return $normalized;
	}

	/**
	 * Compute and remember a deterministic table signature for detect-only refresh checks.
	 *
	 * @param string $sub
	 * @param array<int, array<string, mixed>> $rows
	 * @return void
	 */
	public function rememberTableDataSignature($sub, $rows) {
		$sub = (string)$sub;
		if (!is_array($rows)) {
			$this->tableDataSignatures[$sub] = sha1($sub . '|0');
			return;
		}

		$rowSignatures = array();
		foreach ($rows as $row) {
			$fields = $this->getSignatureFieldsForSubpage($sub, $row);
			$parts = array();
			foreach ($fields as $k => $v) {
				$parts[] = $k . '=' . $v;
			}
			$rowSignatures[] = implode("\x1f", $parts);
		}
		sort($rowSignatures, SORT_STRING);
		$payload = $sub . '|' . count($rowSignatures) . '|' . implode("\n", $rowSignatures);
		$this->tableDataSignatures[$sub] = sha1($payload);
	}

	/**
	 * Get the most recently computed table data signature for a subpage.
	 *
	 * @param string $sub
	 * @return string
	 */
	public function getCurrentTableDataSignature($sub) {
		$sub = (string)$sub;
		return (string)($this->tableDataSignatures[$sub] ?? '');
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
		$options = abj_service('plugin_logic')->optionsResolver()->getOptions();
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

		$options = array_merge($defaults, $options);

		return $options;
	}

	/**
	 * Get the tooltip HTML for Hits/Last Used columns when those values may lag.
	 *
	 * We only show this for views sorted by hits/last_used because those modes may
	 * rely on the aggregated logs-hits table. Other sorts use live per-row lookup,
	 * so showing an aggregation timestamp there is misleading.
	 *
	 * @param array<string, mixed> $tableOptions Current table options.
	 * @return string Tooltip HTML (not escaped - contains data attributes)
	 */
	public function getHitsColumnTooltip($tableOptions = array()) {
		$rawOrderby = $tableOptions['orderby'] ?? '';
		$orderby = strtolower(is_string($rawOrderby) ? $rawOrderby : '');
		$isAggregatedMode = ($orderby === 'logshits' || $orderby === 'last_used');
		if (!$isAggregatedMode) {
			return '';
		}

		// Ensure the "last checked"/"refresh scheduled" tooltip state is computed for this request.
		// This runs cheap checks and (when needed) schedules the expensive rebuild for shutdown.
		if (is_object($this->viewReadService) && method_exists($this->viewReadService, 'maybeUpdateRedirectsForViewHitsTable')) {
			$this->viewReadService->maybeUpdateRedirectsForViewHitsTable();
		}

		$timestamp = $this->logsRepository->getLogsHitsTableLastUpdated();
		$lines = array();
		if ($timestamp !== null) {
			$lastUpdated = $this->logsRepository->getLogsHitsTableLastUpdatedHuman();
			$timeHtml = '<span class="abj404-time-ago" data-timestamp="' . esc_attr((string)$timestamp) . '">' . esc_html($lastUpdated) . '</span>';
			$lines[] = sprintf(__('Last updated: %s', '404-solution'), $timeHtml);
		}

		$checkedAt = $this->logsRepository->getLogsHitsTableLastCheckedAt();
		if ($checkedAt !== null) {
			$checkedHtml = '<span class="abj404-time-ago" data-timestamp="' . esc_attr((string)$checkedAt) . '">' . esc_html($this->formatTimeAgo($checkedAt)) . '</span>';
			$lines[] = sprintf(__('Last checked: %s', '404-solution'), $checkedHtml);
		}

			$decision = $this->logsRepository->getLogsHitsTableLastDecision();
			// Treat "cooldown" as "scheduled recently" from a user perspective.
			if ($decision === 'scheduled' || $decision === 'cooldown') {
				$lines[] = __('Refresh scheduled', '404-solution');
			} else if ($decision === 'running') {
				$lines[] = __('Refresh running', '404-solution');
			} else if ($decision === 'paused') {
				$lines[] = __('Refresh paused', '404-solution');
			}

		return implode('<br>', array_filter($lines));
	}

	/**
	 * Small, dependency-free time-ago formatter for tooltip use.
	 * (We don't want to rely on WP human_time_diff() in unit tests.)
	 *
	 * @param int $timestamp
	 * @return string
	 */
	public function formatTimeAgo($timestamp) {
		$diff = time() - absint($timestamp);
		if ($diff < 60) {
			return __('Just now', '404-solution');
		}
		if ($diff < 3600) {
			$minutes = (int)floor($diff / 60);
			return sprintf(_n('%d minute ago', '%d minutes ago', $minutes, '404-solution'), $minutes);
		}
		if ($diff < 86400) {
			$hours = (int)floor($diff / 3600);
			return sprintf(_n('%d hour ago', '%d hours ago', $hours, '404-solution'), $hours);
		}
		$days = (int)floor($diff / 86400);
		return sprintf(_n('%d day ago', '%d days ago', $days, '404-solution'), $days);
	}

	/**
	 * Build shared sort state for table headers.
	 *
	 * @param array<string, mixed> $tableOptions
	 * @param string $orderby
	 * @param bool $preferDescOnFirstClick
	 * @return array{isSortable:bool,thClass:string,nextOrder:string,indicator:string}
	 */
	public function getHeaderSortState($tableOptions, $orderby, $preferDescOnFirstClick = false) {
		$result = array(
			'isSortable' => false,
			'thClass' => '',
			'nextOrder' => 'ASC',
			'indicator' => '',
		);

		$orderby = (string)$orderby;
		if ($orderby === '') {
			return $result;
		}

		$result['isSortable'] = true;
		$rawCurrentOrderby = $tableOptions['orderby'] ?? '';
		$currentOrderby = is_string($rawCurrentOrderby) ? $rawCurrentOrderby : '';
		$rawCurrentOrder = $tableOptions['order'] ?? 'ASC';
		$currentOrder = strtoupper(is_string($rawCurrentOrder) ? $rawCurrentOrder : 'ASC');
		if ($currentOrder !== 'DESC') {
			$currentOrder = 'ASC';
		}

		if ($currentOrderby === $orderby) {
			$result['thClass'] = 'sorted ' . strtolower($currentOrder);
			$result['nextOrder'] = ($currentOrder === 'ASC') ? 'DESC' : 'ASC';
			$result['indicator'] = ($currentOrder === 'ASC') ? ' ↑' : ' ↓';
			return $result;
		}

		$result['thClass'] = 'sortable ' . ($preferDescOnFirstClick ? 'asc' : 'desc');
		$result['nextOrder'] = $preferDescOnFirstClick ? 'DESC' : 'ASC';
		return $result;
	}

	/**
	 * Build action links for table rows (edit, logs, trash, delete, etc.)
	 *
	 * @param array<string, mixed> $row The data row from the database
	 * @param string $sub The subpage parameter value
	 * @param array<string, mixed> $tableOptions Table options including filter, orderby, order
	 * @param bool $isCapturedPage True for captured URLs page, false for redirects page
	 * @return array<string, string> Array of links and titles
	 */
	public function buildTableActionLinks($row, $sub, $tableOptions, $isCapturedPage = false) {
		$sub = rawurlencode($sub);
		$ids = $this->resolveTableActionIds($row, $isCapturedPage);
		$result = $this->buildBaseTableActionLinks($ids['id'], $ids['logsId'], $ids['rawId'], $sub, $isCapturedPage);
		$options = $this->extractTableActionOptions($tableOptions);

		$result = $this->applyTrashAction($result, $options['filter']);
		if ($isCapturedPage) {
			$result = $this->applyCapturedPageActionLinks($result, $ids['id'], $sub, $options['filter']);
		}

		$result = $this->appendTableActionQueryArgs($result, $options, $isCapturedPage);
		return $this->applyTableActionNonces($result, $options['filter'], $isCapturedPage);
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{id: mixed, logsId: mixed, rawId: mixed}
	 */
	private function resolveTableActionIds(array $row, bool $isCapturedPage): array {
		$rawId = $row['id'] ?? 0;
		$rawLogsId = $row['logsid'] ?? 0;
		if ($isCapturedPage) {
			return ['id' => $rawId, 'logsId' => $rawLogsId, 'rawId' => $rawId];
		}

		return [
			'id' => absint(is_scalar($rawId) ? $rawId : 0),
			'logsId' => absint(is_scalar($rawLogsId) ? $rawLogsId : 0),
			'rawId' => $rawId,
		];
	}

	/**
	 * @param mixed $id
	 * @param mixed $logsId
	 * @param mixed $rawId
	 * @return array<string, string>
	 */
	private function buildBaseTableActionLinks($id, $logsId, $rawId, string $sub, bool $isCapturedPage): array {
		$result = [];
		$result['editlink'] = "?page=" . ABJ404_PP . "&subpage=abj404_edit&id=" . $id . "&source_page=" . $sub;
		$result['logslink'] = "?page=" . ABJ404_PP . "&subpage=abj404_logs&id=" . $logsId;
		$result['trashlink'] = "?page=" . ABJ404_PP . "&id=" . $id . "&subpage=" . $sub;
		$result['deletelink'] = "?page=" . ABJ404_PP . "&remove=1&id=" . $id . "&subpage=" . $sub;
		$ajaxId = $isCapturedPage ? absint(is_scalar($rawId) ? $rawId : 0) : $id;
		$result['ajaxTrashLink'] = "admin-ajax.php?action=trashLink&id=" . $ajaxId . "&subpage=" . $sub;
		return $result;
	}

	/**
	 * @param array<string, mixed> $tableOptions
	 * @return array{orderby: string, order: string, filter: mixed, paged: mixed}
	 */
	private function extractTableActionOptions(array $tableOptions): array {
		return [
			'orderby' => array_key_exists('orderby', $tableOptions) && is_string($tableOptions['orderby']) ? $tableOptions['orderby'] : '',
			'order' => array_key_exists('order', $tableOptions) && is_string($tableOptions['order']) ? $tableOptions['order'] : '',
			'filter' => array_key_exists('filter', $tableOptions) ? $tableOptions['filter'] : 0,
			'paged' => array_key_exists('paged', $tableOptions) ? $tableOptions['paged'] : 0,
		];
	}

	/**
	 * @param array<string, string> $result
	 * @param mixed $toFilter
	 * @return array<string, string>
	 */
	private function applyTrashAction(array $result, $toFilter): array {
		if ($toFilter == ABJ404_TRASH_FILTER) {
			$result['trashlink'] .= "&trash=0";
			$result['ajaxTrashLink'] .= "&trash=0";
			$result['trashtitle'] = __('Restore', '404-solution');
			return $result;
		}

		$result['trashlink'] .= "&trash=1";
		$result['ajaxTrashLink'] .= "&trash=1";
		$result['trashtitle'] = __('Trash', '404-solution');
		return $result;
	}

	/**
	 * @param array<string, string> $result
	 * @param mixed $id
	 * @param mixed $toFilter
	 * @return array<string, string>
	 */
	private function applyCapturedPageActionLinks(array $result, $id, string $sub, $toFilter): array {
		$result['ignorelink'] = "?page=" . ABJ404_PP . "&id=" . $id . "&subpage=" . $sub;
		$result['laterlink'] = "?page=" . ABJ404_PP . "&id=" . $id . "&subpage=" . $sub;
		$result['ignoretitle'] = $toFilter == ABJ404_STATUS_IGNORED ? __('Remove Ignore Status', '404-solution') : __('Ignore 404 Error', '404-solution');
		$result['ignorelink'] .= $toFilter == ABJ404_STATUS_IGNORED ? "&ignore=0" : "&ignore=1";
		$result['latertitle'] = $toFilter == ABJ404_STATUS_LATER ? __('Remove Later Status', '404-solution') : __('Organize Later', '404-solution');
		$result['laterlink'] .= $toFilter == ABJ404_STATUS_LATER ? "&later=0" : "&later=1";
		return $result;
	}

	/**
	 * @param array<string, string> $result
	 * @param array{orderby: string, order: string, filter: mixed, paged: mixed} $options
	 * @return array<string, string>
	 */
	private function appendTableActionQueryArgs(array $result, array $options, bool $isCapturedPage): array {
		$sortArgs = $this->buildSortQueryArgs($options['orderby'], $options['order']);
		if ($sortArgs !== '') {
			foreach (['trashlink', 'deletelink', 'editlink'] as $key) {
				$result[$key] .= $sortArgs;
			}
			if ($isCapturedPage) {
				$result['ignorelink'] .= $sortArgs;
				$result['laterlink'] .= $sortArgs;
			}
		}

		if ($options['filter'] != 0) {
			$result = $this->appendFilterQueryArgs($result, $options['filter'], $isCapturedPage);
		}
		if ($options['paged'] > 1) {
			$result['editlink'] .= "&paged=" . $options['paged'];
		}
		return $result;
	}

	private function buildSortQueryArgs(string $toOrderby, string $toOrder): string {
		if ($toOrderby === '' || $toOrder === '' || ($toOrderby == "url" && $toOrder == "ASC")) {
			return '';
		}
		return "&orderby=" . sanitize_text_field($toOrderby) . "&order=" . sanitize_text_field($toOrder);
	}

	/**
	 * @param array<string, string> $result
	 * @param mixed $toFilter
	 * @return array<string, string>
	 */
	private function appendFilterQueryArgs(array $result, $toFilter, bool $isCapturedPage): array {
		foreach (['trashlink', 'deletelink', 'editlink'] as $key) {
			$result[$key] .= "&filter=" . $toFilter;
		}
		if ($isCapturedPage) {
			$result['ignorelink'] .= "&filter=" . $toFilter;
			$result['laterlink'] .= "&filter=" . $toFilter;
		}
		return $result;
	}

	/**
	 * @param array<string, string> $result
	 * @param mixed $toFilter
	 * @return array<string, string>
	 */
	private function applyTableActionNonces(array $result, $toFilter, bool $isCapturedPage): array {
		$result['trashlink'] = wp_nonce_url($result['trashlink'], "abj404_trashRedirect");
		$result['ajaxTrashLink'] = wp_nonce_url($result['ajaxTrashLink'], "abj404_ajaxTrash");
		if ($toFilter == ABJ404_TRASH_FILTER) {
			$result['deletelink'] = wp_nonce_url($result['deletelink'], "abj404_removeRedirect");
		}
		if ($isCapturedPage) {
			$result['ignorelink'] = wp_nonce_url($result['ignorelink'], "abj404_ignore404");
			$result['laterlink'] = wp_nonce_url($result['laterlink'], "abj404_organizeLater");
		}
		return $result;
	}


}
