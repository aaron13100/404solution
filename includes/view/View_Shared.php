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
	 * The hover-tooltip text for a URL / Destination header whose narrow sort key
	 * cannot yet be served index-ordered, or '' when the sort is available now,
	 * the column is not sort-key-backed, or no readiness service was supplied.
	 *
	 * Single source of truth for the pending-sort message, shared by both header
	 * renderers (ABJ_404_Solution_AdminTableColumnHeaders for the Page Redirects
	 * tab and ABJ_404_Solution_View_CapturedURLsTable for the captured tab) so the
	 * two cannot drift. Each renderer still decides WHICH tabs the gate applies to
	 * (the Logs tab sorts a different table) and how to render the non-sortable
	 * cell; this only owns the readiness + percentage + message string. Readiness
	 * and the build percentage come from the centralized predicate
	 * (RedirectsDenormSchemaReadiness::sortKeyReadyForColumn via the read service).
	 *
	 * @param string $orderby UI orderby alias (url, dest, final_dest, ...).
	 * @param ABJ_404_Solution_ViewReadServiceInterface|null $viewReadService
	 * @return string
	 */
	public function pendingSortTooltipText(string $orderby, $viewReadService): string {
		if ($viewReadService === null) {
			return '';
		}
		if ($orderby !== 'url' && $orderby !== 'dest' && $orderby !== 'final_dest') {
			return '';
		}
		$status = method_exists($viewReadService, 'sortReadinessStatusForOrderby')
			? $viewReadService->sortReadinessStatusForOrderby($orderby)
			: ($viewReadService->isSortReadyForOrderby($orderby)
				? ABJ_404_Solution_ViewReadServiceInterface::SORT_READINESS_READY
				: ABJ_404_Solution_ViewReadServiceInterface::SORT_READINESS_BACKFILL_PENDING);
		if ($status === ABJ_404_Solution_ViewReadServiceInterface::SORT_READINESS_READY) {
			return '';
		}
		if ($status === ABJ_404_Solution_ViewReadServiceInterface::SORT_READINESS_SCHEMA_UNAVAILABLE) {
			return __('Sorting by this column is unavailable on this site. The list shows newest first.', '404-solution');
		}
		$percent = $viewReadService->sortBackfillPercentForOrderby($orderby);
		return sprintf(
			/* translators: %d: index-build completion percentage */
			__('Sorting by this column is being prepared for your number of URLs (%d%% complete). The list shows newest first until it is ready.', '404-solution'),
			$percent
		);
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
		$rawFilter = array_key_exists('filter', $tableOptions) ? $tableOptions['filter'] : 0;
		$rawPaged = array_key_exists('paged', $tableOptions) ? $tableOptions['paged'] : 0;
		return [
			'orderby' => array_key_exists('orderby', $tableOptions) && is_string($tableOptions['orderby']) ? $tableOptions['orderby'] : '',
			'order' => array_key_exists('order', $tableOptions) && is_string($tableOptions['order']) ? $tableOptions['order'] : '',
			'filter' => is_scalar($rawFilter) ? $rawFilter : 0,
			'paged' => is_scalar($rawPaged) ? max(0, intval($rawPaged)) : 0,
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
