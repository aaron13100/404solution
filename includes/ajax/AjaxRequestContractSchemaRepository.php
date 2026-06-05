<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Loads vendored AJAX request-contract schemas from disk.
 */
class ABJ_404_Solution_AjaxRequestContractSchemaRepository {

    /** @var string */
    private $schemaDirectory;

    /** @var array<string, array<string, mixed>|null> */
    private $cache = array();

    public function __construct(?string $schemaDirectory = null) {
        $this->schemaDirectory = $schemaDirectory ?? dirname(__DIR__, 2) . '/contracts/schemas';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadSchema(string $contractId): ?array {
        if (array_key_exists($contractId, $this->cache)) {
            return $this->cache[$contractId];
        }

        if (!preg_match('/\Aajax-[a-z0-9-]+\z/', $contractId)) {
            $this->cache[$contractId] = null;
            return null;
        }

        $path = rtrim($this->schemaDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . $contractId . '.schema.json';
        $raw = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($raw) || $raw === '') {
            $this->cache[$contractId] = null;
            return null;
        }

        $decoded = json_decode($raw, true);
        $schema = json_last_error() === JSON_ERROR_NONE
            ? $this->asStringKeyedArray($decoded) : null;
        $this->cache[$contractId] = $schema;
        return $schema;
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>|null
     */
    private function asStringKeyedArray($value): ?array {
        if (!is_array($value)) {
            return null;
        }

        $result = array();
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                return null;
            }
            $result[$key] = $item;
        }
        return $result;
    }
}
