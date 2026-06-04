<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stores resumable import checkpoints keyed by uploaded CSV content hash.
 */
class ABJ_404_Solution_ImportProgressStore {

    /** @var string */
    private $optionKey;

    function __construct(string $optionKey) {
        $this->optionKey = $optionKey;
    }

    /**
     * @param string $contentHash
     * @return array<string, mixed>|null
     */
    function getResumeProgress($contentHash) {
        if ($contentHash === '' || !function_exists('get_option')) {
            return null;
        }
        $progress = get_option($this->optionKey, null);
        if (!is_array($progress) || !isset($progress['hash']) || !is_string($progress['hash'])) {
            return null;
        }
        if ($progress['hash'] !== $contentHash) {
            return null;
        }
        /** @var array<string, mixed> $progress */
        return $progress;
    }

    /**
     * @param string $contentHash
     * @param array<string, mixed> $state
     * @return void
     */
    function persistImportProgress($contentHash, $state) {
        if ($contentHash === '' || !function_exists('update_option')) {
            return;
        }
        $state['hash'] = $contentHash;
        update_option($this->optionKey, $state);
    }

    /**
     * @return void
     */
    function clearImportProgress() {
        if (function_exists('delete_option')) {
            delete_option($this->optionKey);
        }
    }

    /**
     * @param array<string, mixed> $progress
     * @param string $key
     * @param int $default
     * @return int
     */
    static function progressInt(array $progress, string $key, int $default): int {
        if (!isset($progress[$key])) {
            return $default;
        }
        $v = $progress[$key];
        if (is_int($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int)$v;
        }
        return $default;
    }
}
