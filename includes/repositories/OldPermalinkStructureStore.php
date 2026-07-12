<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stores a small bounded list of permalink structures that were active before
 * a WordPress permalink settings change.
 */
class ABJ_404_Solution_OldPermalinkStructureStore {

    public const OPTION_NAME = 'abj404_previous_permalink_structures';
    public const MAX_ITEMS = 5;
    private const MAX_STRUCTURE_LENGTH = 512;

    /**
     * @return array<int, array{structure: string, captured_at: int, source: string}>
     */
    public function getObservedStructures(): array {
        $stored = function_exists('get_option') ? get_option(self::OPTION_NAME, array()) : array();
        return $this->normalizeStoredOption($stored)['items'];
    }

    /**
     * @param string $structure
     * @param int|null $capturedAt
     * @return void
     */
    public function recordPreviousStructure(string $structure, ?int $capturedAt = null): void {
        $normalizedStructure = $this->normalizeStructure($structure);
        if ($normalizedStructure === '') {
            return;
        }

        $items = $this->getObservedStructures();
        $items = array_values(array_filter($items, static function($item) use ($normalizedStructure): bool {
            return $item['structure'] !== $normalizedStructure;
        }));

        array_unshift($items, array(
            'structure' => $normalizedStructure,
            'captured_at' => $capturedAt ?? $this->now(),
            'source' => 'observed',
        ));

        $items = array_slice($items, 0, self::MAX_ITEMS);
        if (function_exists('update_option')) {
            update_option(self::OPTION_NAME, array(
                'version' => 1,
                'items' => $items,
            ));
        }
    }

    /**
     * @param mixed $stored
     * @return array{version: int, items: array<int, array{structure: string, captured_at: int, source: string}>}
     */
    public function normalizeStoredOption($stored): array {
        if (!is_array($stored)) {
            return array('version' => 1, 'items' => array());
        }

        $rawItems = isset($stored['items']) && is_array($stored['items']) ? $stored['items'] : array();
        $items = array();
        $seen = array();

        foreach ($rawItems as $rawItem) {
            if (!is_array($rawItem)) {
                continue;
            }
            $structure = isset($rawItem['structure']) && is_scalar($rawItem['structure'])
                ? $this->normalizeStructure((string)$rawItem['structure'])
                : '';
            if ($structure === '' || isset($seen[$structure])) {
                continue;
            }
            $seen[$structure] = true;
            $source = isset($rawItem['source']) && $rawItem['source'] === 'observed' ? 'observed' : 'observed';
            $capturedAt = isset($rawItem['captured_at']) && is_numeric($rawItem['captured_at'])
                ? (int)$rawItem['captured_at']
                : 0;

            $items[] = array(
                'structure' => $structure,
                'captured_at' => $capturedAt,
                'source' => $source,
            );

            if (count($items) >= self::MAX_ITEMS) {
                break;
            }
        }

        return array('version' => 1, 'items' => $items);
    }

    /** @param string $structure @return string */
    private function normalizeStructure(string $structure): string {
        $structure = trim($structure);
        if ($structure === '' || strlen($structure) > self::MAX_STRUCTURE_LENGTH) {
            return '';
        }

        $path = parse_url($structure, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            $structure = $path;
        }

        if ($structure[0] !== '/') {
            $structure = '/' . $structure;
        }

        return $structure;
    }

    /** @return int */
    private function now(): int {
        return abj_clock()->now();
    }
}
