<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves the per-page options array that drives every admin list-table
 * view (Redirects, Captured, Logs): filter, filterText, orderby/order,
 * paging, perpage, score range, and the optional logsid focus row.
 *
 * Source of input is the current request ($_POST / $_GET / REQUEST_URI).
 * The resolved array is fed to view classes and pagination AJAX endpoints.
 *
 * Two responsibilities the audit (M201, CQS finding at PluginLogicSettingsUpdate:131)
 * called out are now visible as discrete steps inside this class:
 *
 *   1. Pure resolution of tableOptions from the request.
 *   2. The "remember my chosen sort" side effect that persists the
 *      user-supplied orderby/order onto the saved options. The persist call
 *      runs through {@see rememberSortPreference()}, named for what it is,
 *      so the side effect is no longer hidden inside a get*() method.
 *
 * Extracted from PluginLogicSettingsUpdate.php during the M201 decomposition.
 */
class ABJ_404_Solution_TableViewOptionsResolver {

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var callable|null */
    private $sanitizer;

    /** Allowed column names for the orderby request parameter.
     * @var array<int, string> */
    private static $allowedOrderbyColumns = [
        'url',
        'status',
        'type',
        'dest',
        'final_dest',
        'code',
        'score',
        'timestamp',
        'created',
        'lastused',
        'last_used',
        'logshits',
        'remote_host',
        'referrer',
        'action',
        'username'
    ];

    /** Allowed values for the order request parameter.
     * @var array<int, string> */
    private static $allowedOrderValues = ['ASC', 'DESC'];

    /**
     * @param ABJ_404_Solution_Functions $f
     * @param callable|null $sanitizer Optional fn(array): array used to sanitize
     *                                 the resolved tableOptions. If null, falls
     *                                 back to the canonical sanitizer obtained
     *                                 via service lookup on first use.
     */
    function __construct($f, $sanitizer = null) {
        $this->f = $f;
        $this->sanitizer = is_callable($sanitizer) ? $sanitizer : null;
    }

    /**
     * Resolve the table options array for a single admin list-table view.
     *
     * Side effects: when the request carries a new orderby or order
     * parameter, the user's preference is persisted to options via
     * {@see rememberSortPreference()}.
     *
     * @param string $pageBeingViewed One of abj404_redirects, abj404_captured, abj404_logs.
     * @return array<string, mixed>
     */
    function resolve(string $pageBeingViewed): array {
        $preludeTracer = ABJ_404_Solution_TableRendererPreludeTracer::begin();
        try {
            if ($preludeTracer !== null) {
                $preludeTracer->prepareTranslationDomain();
            }
            $tableOptions = array();
            $tableOptions['translations'] = $this->tracePrelude(
                $preludeTracer, 'translation_tokens', fn() => $this->translationTokens());
            $tableOptions['filter'] = $this->tracePrelude(
                $preludeTracer, 'filter_resolution', fn() => $this->resolveFilter());
            $tableOptions['filterText'] = $this->tracePrelude(
                $preludeTracer, 'filter_text_resolution', fn() => $this->resolveFilterText());

            $orderbyInput = ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize('orderby', '');
            $orderInput = strtoupper(ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize('order', ''));
            $sortPreferenceRequested = in_array($pageBeingViewed, array('abj404_redirects', 'abj404_captured'), true)
                && (
                    ($orderbyInput !== '' && in_array($orderbyInput, self::$allowedOrderbyColumns, true))
                    || ($orderInput !== '' && in_array($orderInput, self::$allowedOrderValues, true))
                );
            $optionTracer = $sortPreferenceRequested
                ? ABJ_404_Solution_OptionPersistenceTracer::begin()
                : null;
            try {
                $optionsRead = static function (): array {
                    return abj_service('options_repository')->getOptions(true);
                };
                $options = $this->tracePrelude(
                    $preludeTracer,
                    'options_read',
                    static fn() => $optionTracer === null
                        ? $optionsRead()
                        : $optionTracer->traceOperation('sort_preference_options_read', $optionsRead)
                );

                $tableOptions['orderby'] = $this->tracePrelude(
                    $preludeTracer,
                    'orderby_resolution',
                    fn() => $this->resolveOrderby($orderbyInput, $pageBeingViewed, $options)
                );
                $tableOptions['order'] = $this->tracePrelude(
                    $preludeTracer,
                    'order_resolution',
                    fn() => $this->resolveOrder(
                        $orderInput, $tableOptions['orderby'], $pageBeingViewed, $options)
                );
                $sortPreferenceWrite = function () use (
                    $orderbyInput, $orderInput, $pageBeingViewed, $options
                ): void {
                    $this->rememberSortPreference($orderbyInput, $orderInput, $pageBeingViewed, $options);
                };
                $this->tracePrelude(
                    $preludeTracer,
                    'sort_preference_write',
                    static fn() => $optionTracer === null
                        ? $sortPreferenceWrite()
                        : $optionTracer->traceOperation('sort_preference_write', $sortPreferenceWrite)
                );
            } finally {
                if ($optionTracer !== null) {
                    $optionTracer->finish();
                }
            }

            $tableOptions['paged'] = $this->tracePrelude(
                $preludeTracer, 'paged_resolution', fn() => $this->resolvePaged());
            $tableOptions['perpage'] = $this->tracePrelude(
                $preludeTracer, 'perpage_resolution', fn() => $this->resolvePerPage($options));
            $tableOptions['logsid'] = $this->tracePrelude(
                $preludeTracer, 'logsid_resolution', fn() => $this->resolveLogsId());
            $tableOptions['score_range'] = $this->tracePrelude(
                $preludeTracer, 'score_range_resolution', fn() => $this->resolveScoreRange());

            $forceRebuild = $this->tracePrelude(
                $preludeTracer, 'force_view_rebuild_resolution', fn() => $this->resolveForceViewRebuild());
            if ($forceRebuild !== null) {
                $tableOptions['_abj404_force_view_rebuild'] = $forceRebuild;
            }
            $sanitized = $this->tracePrelude(
                $preludeTracer, 'sanitize', fn() => $this->sanitize($tableOptions));
            return $this->tracePrelude(
                $preludeTracer, 'normalize_types', fn() => $this->normalizeResolvedTypes($sanitized));
        } finally {
            if ($preludeTracer !== null) {
                $preludeTracer->finish();
            }
        }
    }

    /**
     * @template T
     * @param ABJ_404_Solution_TableRendererPreludeTracer|null $tracer
     * @param callable():T $work
     * @return T
     */
    private function tracePrelude($tracer, string $operation, callable $work) {
        return $tracer === null ? $work() : $tracer->traceOperation($operation, $work);
    }

    /**
     * Persist the user's chosen sort preference for the redirects / captured
     * tables. Called from {@see resolve()} when a new orderby/order is in
     * the request. Public so the side effect can be tested independently
     * of the read path.
     *
     * @param string $orderbyInput Raw orderby from the request.
     * @param string $orderInput Raw order from the request (already uppercased).
     * @param string $pageBeingViewed Admin page slug.
     * @param array<string, mixed> $options Current options snapshot to mutate / save.
     * @return void
     */
    public function rememberSortPreference(string $orderbyInput, string $orderInput, string $pageBeingViewed, array $options): void {
        $changed = false;

        if ($orderbyInput !== '' && in_array($orderbyInput, self::$allowedOrderbyColumns, true)) {
            if ($pageBeingViewed === 'abj404_redirects') {
                $options['page_redirects_order_by'] = $orderbyInput;
                $changed = true;
            } else if ($pageBeingViewed === 'abj404_captured') {
                $options['captured_order_by'] = $orderbyInput;
                $changed = true;
            }
        }

        if ($orderInput !== '' && in_array($orderInput, self::$allowedOrderValues, true)) {
            if ($pageBeingViewed === 'abj404_redirects') {
                $options['page_redirects_order'] = $orderInput;
                $changed = true;
            } else if ($pageBeingViewed === 'abj404_captured') {
                $options['captured_order'] = $orderInput;
                $changed = true;
            }
        }

        if ($changed) {
            abj_service('options_repository')->updateOptions($options);
        }
    }

    /**
     * Localised label tokens injected into table cells. Centralised here so
     * the catalog stays POT-extractable (literal strings inside __() calls).
     *
     * @return array<string, string>
     */
    private function translationTokens(): array {
        return array(
            '{ABJ404_STATUS_MANUAL_text}' => __('Man', '404-solution'),
            '{ABJ404_STATUS_AUTO_text}' => __('Auto', '404-solution'),
            '{ABJ404_STATUS_REGEX_text}' => __('RegEx', '404-solution'),
            '{ABJ404_TYPE_EXTERNAL_text}' => __('External', '404-solution'),
            '{ABJ404_TYPE_CAT_text}' => __('Category', '404-solution'),
            '{ABJ404_TYPE_TAG_text}' => __('Tag', '404-solution'),
            '{ABJ404_TYPE_HOME_text}' => __('Home Page', '404-solution'),
            '{ABJ404_TYPE_404_DISPLAYED_text}' => __('(Default 404 Page)', '404-solution'),
            '{ABJ404_TYPE_SPECIAL_text}' => __('(Special)', '404-solution'),
        );
    }

    /** @return int */
    private function resolveFilter(): int {
        $rawFilter = ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize('filter', '');
        if ($rawFilter === '') {
            if (ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize('subpage') == 'abj404_captured') {
                return ABJ404_STATUS_CAPTURED;
            }
            return 0;
        }
        return intval($rawFilter);
    }

    /** @return string */
    private function resolveFilterText(): string {
        $filterText = trim(ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize('filterText', ''));
        return $this->f->str_replace(array('*', '/', '$'), '', $filterText);
    }

    /**
     * @param string $orderbyInput
     * @param string $pageBeingViewed
     * @param array<string, mixed> $options
     * @return string
     */
    private function resolveOrderby(string $orderbyInput, string $pageBeingViewed, array $options): string {
        if ($orderbyInput !== '' && in_array($orderbyInput, self::$allowedOrderbyColumns, true)) {
            return $orderbyInput;
        }
        if ($pageBeingViewed === 'abj404_logs') {
            return 'timestamp';
        }
        if ($pageBeingViewed === 'abj404_redirects') {
            $saved = isset($options['page_redirects_order_by']) && is_scalar($options['page_redirects_order_by'])
                ? (string)$options['page_redirects_order_by'] : 'url';
            return in_array($saved, self::$allowedOrderbyColumns, true) ? $saved : 'url';
        }
        if ($pageBeingViewed === 'abj404_captured') {
            $saved = isset($options['captured_order_by']) && is_scalar($options['captured_order_by'])
                ? (string)$options['captured_order_by'] : 'timestamp';
            return in_array($saved, self::$allowedOrderbyColumns, true) ? $saved : 'timestamp';
        }
        return 'url';
    }

    /**
     * @param string $orderInput
     * @param string $resolvedOrderby
     * @param string $pageBeingViewed
     * @param array<string, mixed> $options
     * @return string
     */
    private function resolveOrder(string $orderInput, string $resolvedOrderby, string $pageBeingViewed, array $options): string {
        if ($orderInput !== '' && in_array($orderInput, self::$allowedOrderValues, true)) {
            return $orderInput;
        }
        if ($resolvedOrderby === 'created' || $resolvedOrderby === 'lastused' || $resolvedOrderby === 'timestamp') {
            return 'DESC';
        }
        if ($pageBeingViewed === 'abj404_redirects') {
            $saved = isset($options['page_redirects_order']) && is_scalar($options['page_redirects_order'])
                ? strtoupper((string)$options['page_redirects_order']) : 'ASC';
            return in_array($saved, self::$allowedOrderValues, true) ? $saved : 'ASC';
        }
        if ($pageBeingViewed === 'abj404_captured') {
            $saved = isset($options['captured_order']) && is_scalar($options['captured_order'])
                ? strtoupper((string)$options['captured_order']) : 'DESC';
            return in_array($saved, self::$allowedOrderValues, true) ? $saved : 'DESC';
        }
        return 'ASC';
    }

    /** @return int */
    private function resolvePaged(): int {
        $paged = ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize('paged', '');
        if ($paged === '') {
            $paged = $this->readScalarFromRequestUriQuery('paged');
        }
        return $this->positiveIntOrDefault($paged, 1);
    }

    /**
     * @param array<string, mixed> $options
     * @return int
     */
    private function resolvePerPage(array $options): int {
        $perPageOption = ABJ404_OPTION_DEFAULT_PERPAGE;
        if (isset($options['perpage'])) {
            $perPageOption = max(absint(is_scalar($options['perpage']) ? $options['perpage'] : 0), ABJ404_OPTION_MIN_PERPAGE);
        }
        $rawPerPage = ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize('perpage', '');
        if ($rawPerPage === '') {
            return $perPageOption;
        }
        return max($this->positiveIntOrDefault($rawPerPage, $perPageOption), ABJ404_OPTION_MIN_PERPAGE);
    }

    /** @return int */
    private function resolveLogsId(): int {
        if (ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize('subpage') != 'abj404_logs') {
            return 0;
        }
        $logId = (string)ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize('id', '');
        if (preg_match('/^\d+$/', $logId) === 1) {
            return absint($logId);
        }
        $redirectToDataFieldId = (string)ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize('redirect_to_data_field_id', '');
        if (preg_match('/^\d+$/', $redirectToDataFieldId) === 1) {
            return absint($redirectToDataFieldId);
        }
        return 0;
    }

    /** @return string */
    private function resolveScoreRange(): string {
        $raw = (string)ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize('score_range', 'all');
        $allowed = array('all', 'high', 'medium', 'low', 'manual');
        return in_array($raw, $allowed, true) ? $raw : 'all';
    }

    /** @return string|null */
    private function resolveForceViewRebuild() {
        $val = (string)ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize('forceViewRebuild', '');
        if ($val === '') {
            $val = (string)ABJ_404_Solution_RequestInputNormalizer::getPostOrGetSanitize('abj404_force_view_rebuild', '');
        }
        return $val === '1' ? '1' : null;
    }

    /**
     * Read a scalar query parameter directly from REQUEST_URI, bypassing the
     * $_GET superglobal. Used as a fallback for paged numbers that may have
     * been pre-stripped from $_GET in some hosting setups.
     *
     * @param string $name
     * @return string
     */
    private function readScalarFromRequestUriQuery(string $name): string {
        if ($name === '') {
            return '';
        }
        $requestUri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if ($requestUri === '') {
            return '';
        }
        $queryString = parse_url($requestUri, PHP_URL_QUERY);
        if (!is_string($queryString) || $queryString === '') {
            return '';
        }
        $query = array();
        parse_str($queryString, $query);
        if (!array_key_exists($name, $query) || !is_scalar($query[$name])) {
            return '';
        }
        return sanitize_text_field((string)$query[$name]);
    }

    /**
     * Sanitize the assembled table options array using the injected
     * sanitizer, or a service-resolved canonical sanitizer as fallback.
     *
     * @param array<string, mixed> $tableOptions
     * @return array<string, mixed>
     */
    private function sanitize(array $tableOptions): array {
        if ($this->sanitizer !== null) {
            return ($this->sanitizer)($tableOptions);
        }
        $pluginLogic = abj_service('plugin_logic');
        if ($pluginLogic !== null && method_exists($pluginLogic, 'settingsUpdate')) {
            $settingsUpdate = $pluginLogic->settingsUpdate();
            if ($settingsUpdate !== null && method_exists($settingsUpdate, 'sanitizePostData')) {
                return $settingsUpdate->sanitizePostData($tableOptions);
            }
        }
        return $tableOptions;
    }

    /**
     * @param mixed $raw
     */
    private function positiveIntOrDefault($raw, int $default): int {
        if (!is_scalar($raw)) {
            return $default;
        }
        $raw = trim((string)$raw);
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            return $default;
        }
        $value = intval($raw);
        return $value > 0 ? $value : $default;
    }

    /**
     * The legacy sanitizer returns scalar values as strings. Re-assert the
     * table-options contract at this boundary so downstream readers receive
     * typed numeric values.
     *
     * @param array<string, mixed> $tableOptions
     * @return array<string, mixed>
     */
    private function normalizeResolvedTypes(array $tableOptions): array {
        $tableOptions['filter'] = $this->normalizeFilter($tableOptions['filter'] ?? 0);
        $tableOptions['paged'] = $this->positiveIntOrDefault($tableOptions['paged'] ?? 1, 1);
        $tableOptions['perpage'] = $this->positiveIntOrDefault(
            $tableOptions['perpage'] ?? ABJ404_OPTION_DEFAULT_PERPAGE,
            ABJ404_OPTION_DEFAULT_PERPAGE
        );
        $tableOptions['logsid'] = $this->positiveIntOrZero($tableOptions['logsid'] ?? 0);
        return $tableOptions;
    }

    /**
     * Normalize the status-filter value. Unlike paged/perpage/logsid, the
     * filter legitimately carries negative sentinels alongside non-negative
     * status/type codes: ABJ404_TRASH_FILTER (-1, the Trash tab) and
     * ABJ404_HANDLED_FILTER (-2, the Captured "Handled" view). It must NOT pass
     * through the positive-only sanitizer, which rejects the leading minus via
     * its /^\d+$/ guard and silently coerces the sentinel to 0 (All) -- the
     * defect that broke every Trash/Handled tab (incident 2026-06-20). Any
     * other negative or non-numeric value still fails closed to 0.
     *
     * @param mixed $raw
     */
    private function normalizeFilter($raw): int {
        if (!is_scalar($raw)) {
            return 0;
        }
        $value = intval(trim((string)$raw));
        if ($value === ABJ404_TRASH_FILTER || $value === ABJ404_HANDLED_FILTER) {
            return $value;
        }
        return $value > 0 ? $value : 0;
    }

    /**
     * @param mixed $raw
     */
    private function positiveIntOrZero($raw): int {
        if (!is_scalar($raw)) {
            return 0;
        }
        $raw = trim((string)$raw);
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            return 0;
        }
        return intval($raw);
    }
}
