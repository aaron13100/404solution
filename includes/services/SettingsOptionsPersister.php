<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Signals a miswired settings persistence collaborator. */
class ABJ_404_Solution_SettingsPersistenceException extends \RuntimeException {}

/**
 * Persists the completed settings option snapshot.
 *
 * Owns the save-time sanitation contract, excluded-pages normalization, the
 * options repository write, and the permalink-cache refresh that must follow
 * a settings update.
 */
class ABJ_404_Solution_SettingsOptionsPersister {

    /** @var object|null */
    private $optionsRepository;

    /** @var object|null */
    private $permalinkCache;

    /**
     * @param object|null $optionsRepository Optional service exposing getOptions()/updateOptions().
     * @param object|null $permalinkCache Optional service exposing updatePermalinkCache().
     */
    public function __construct($optionsRepository = null, $permalinkCache = null) {
        $this->optionsRepository = $optionsRepository;
        $this->permalinkCache = $permalinkCache;
    }

    /**
     * @return array<string, mixed>
     */
    public function loadCurrentOptions(): array {
        $repository = $this->optionsRepository();
        if (!is_callable(array($repository, 'getOptions'))) {
            throw new ABJ_404_Solution_SettingsPersistenceException('Options repository cannot load settings options.');
        }
        $options = call_user_func(array($repository, 'getOptions'));
        return is_array($options) ? $options : array();
    }

    /**
     * Sanitize and persist options, then refresh the permalink cache.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed> The sanitized options that were persisted.
     */
    public function persist(array $options): array {
        $excludedPages = isset($options['excludePages[]']) ? $options['excludePages[]'] : '';

        $newOptions = $this->sanitizePostData($options, true);

        $excludedPages = ($excludedPages == null || !is_scalar($excludedPages)) ? '' : trim((string)$excludedPages);
        $excludedPages = preg_replace('/[^\[\",\]a-zA-Z\d\|\\\\ ]/', '', $excludedPages);
        $newOptions['excludePages[]'] = is_string($excludedPages) ? $excludedPages : '';

        $repository = $this->optionsRepository();
        if (!is_callable(array($repository, 'updateOptions'))) {
            throw new ABJ_404_Solution_SettingsPersistenceException('Options repository cannot persist settings options.');
        }
        call_user_func(array($repository, 'updateOptions'), $newOptions);

        $permalinkCache = $this->permalinkCache();
        if (!is_callable(array($permalinkCache, 'updatePermalinkCache'))) {
            throw new ABJ_404_Solution_SettingsPersistenceException('Permalink cache cannot refresh after settings save.');
        }
        call_user_func(array($permalinkCache, 'updatePermalinkCache'), 2);

        return $newOptions;
    }

    /**
     * Sanitize request/option arrays using the historical settings-save rules.
     *
     * @param array<mixed, mixed> $postData
     * @param bool $restoreNewlines
     * @return array<string, mixed>
     */
    public function sanitizePostData(array $postData, bool $restoreNewlines = false): array {
        $newData = array();
        foreach ($postData as $key => $value) {
            $key = wp_kses_post($key);
            if (is_array($value)) {
                $newData[$key] = $this->sanitizePostData($value, $restoreNewlines);
            } else {
                if ($value === null) {
                    $newData[$key] = '';
                } else {
                    $valueStr = is_string($value) ? $value : (is_scalar($value) ? (string)$value : '');
                    $newData[$key] = wp_kses_post($valueStr);
                    $newData[$key] = esc_sql($newData[$key]);
                    if ($restoreNewlines) {
                        $newData[$key] = str_replace('\n', "\n", $newData[$key]);
                    }
                }
            }
        }
        return $newData;
    }

    /** @return object */
    private function optionsRepository() {
        if ($this->optionsRepository === null) {
            $this->optionsRepository = abj_service('options_repository');
        }
        return $this->optionsRepository;
    }

    /** @return object */
    private function permalinkCache() {
        if ($this->permalinkCache === null) {
            $this->permalinkCache = abj_service('permalink_cache');
        }
        return $this->permalinkCache;
    }
}
