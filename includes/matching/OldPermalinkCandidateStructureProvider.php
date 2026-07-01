<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gathers the old-permalink-structure candidates to evaluate for a request:
 * previously observed structures from the structure store, a small built-in
 * well-known-structure list, and whatever the abj404_old_permalink_candidate_structures
 * filter adds or replaces them with. Normalizes, deduplicates, and caps the
 * result so a single request never evaluates an unbounded candidate list.
 *
 * Extracted from ABJ_404_Solution_OldPermalinkStructureResolver because
 * assembling the candidate list is a distinct concern from compiling a
 * structure into a regex or resolving a match to a post.
 */
class ABJ_404_Solution_OldPermalinkCandidateStructureProvider {

    private const MAX_EVALUATED_STRUCTURES = 10;

    /** @var ABJ_404_Solution_OldPermalinkStructureStore */
    private $structureStore;

    /** @var ABJ_404_Solution_PermalinkStructureCompiler */
    private $compiler;

    /**
     * @param ABJ_404_Solution_OldPermalinkStructureStore $structureStore
     * @param ABJ_404_Solution_PermalinkStructureCompiler $compiler
     */
    public function __construct(
        ABJ_404_Solution_OldPermalinkStructureStore $structureStore,
        ABJ_404_Solution_PermalinkStructureCompiler $compiler
    ) {
        $this->structureStore = $structureStore;
        $this->compiler = $compiler;
    }

    /**
     * @param string $path
     * @return array<int, array{structure: string, post_types: array<int, string>}>
     */
    public function candidatesFor(string $path): array {
        $raw = array();
        foreach ($this->structureStore->getObservedStructures() as $item) {
            $raw[] = array('structure' => $item['structure'], 'post_types' => array());
        }
        foreach ($this->defaultWellKnownStructures() as $structure) {
            $raw[] = array('structure' => $structure, 'post_types' => array());
        }

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('abj404_old_permalink_candidate_structures', $raw, $path);
            if (is_array($filtered)) {
                $raw = $filtered;
            }
        }

        $out = array();
        $seen = array();
        foreach ($raw as $entry) {
            $candidate = $this->normalizeCandidateEntry($entry);
            if ($candidate === null) {
                continue;
            }
            $key = $candidate['structure'] . '|' . implode(',', $candidate['post_types']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $candidate;
            if (count($out) >= self::MAX_EVALUATED_STRUCTURES) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    private function defaultWellKnownStructures(): array {
        return array(
            '/%year%/%monthnum%/%day%/%postname%/',
            '/%year%/%monthnum%/%postname%/',
            '/%postname%/%year%/',
            '/%postname%/%year%/%monthnum%/',
            '/archives/%post_id%/',
        );
    }

    /**
     * @param mixed $entry
     * @return array{structure: string, post_types: array<int, string>}|null
     */
    private function normalizeCandidateEntry($entry): ?array {
        if (is_string($entry)) {
            return array('structure' => $this->compiler->normalizeStructure($entry), 'post_types' => array());
        }
        if (!is_array($entry) || !isset($entry['structure']) || !is_scalar($entry['structure'])) {
            return null;
        }

        $postTypes = array();
        if (isset($entry['post_types']) && is_array($entry['post_types'])) {
            foreach ($entry['post_types'] as $postType) {
                if (is_scalar($postType)) {
                    $postType = sanitize_key((string)$postType);
                    if ($postType !== '') {
                        $postTypes[] = $postType;
                    }
                }
            }
        }

        return array(
            'structure' => $this->compiler->normalizeStructure((string)$entry['structure']),
            'post_types' => array_values(array_unique($postTypes)),
        );
    }
}
