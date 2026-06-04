<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Looks up the best destination suggestion for a captured 404 URL.
 */
class ABJ_404_Solution_RedirectDestinationSuggestionService {

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct($logger) {
        $this->logger = $logger;
    }

    /**
     * Get the best suggested destination for a captured URL using the spell-checker.
     *
     * @param string $url The captured 404 URL.
     * @param array<string, mixed> $options Plugin options.
     * @return array{title: string, score: int, id_and_type: string, type_label: string}|null
     */
    public function getSuggestedDestination(string $url, array $options): ?array {
        try {
            $match = $this->findTopMatch($url);
            if ($match === null) {
                return null;
            }

            $permalink = $this->resolvePermalink($match['id_and_type'], $match['score'], $match['row_type'], $options);
            if ($permalink === null) {
                return null;
            }

            return array(
                'title' => $permalink['title'],
                'score' => $match['score'],
                'id_and_type' => $match['id_and_type'],
                'type_label' => $this->buildTypeLabelForIdAndType($match['id_and_type']),
            );
        } catch (\Throwable $e) {
            $this->logger->debugMessage(
                'Unable to build redirect suggestion for captured URL: ' . $e->getMessage()
            );
            return null;
        }
    }

    /**
     * @return array{id_and_type: string, score: int, row_type: string}|null
     */
    private function findTopMatch(string $url): ?array {
        $spellChecker = abj_service('spell_checker');
        $permalinksPacket = $spellChecker->findMatchingPosts($url, '1', '1');
        $permalinks = is_array($permalinksPacket[0] ?? null) ? $permalinksPacket[0] : array();
        if (empty($permalinks)) {
            return null;
        }

        $topIdAndType = array_key_first($permalinks);
        if (!is_string($topIdAndType)) {
            return null;
        }

        $topScoreRaw = $permalinks[$topIdAndType] ?? 0;
        $topScore = is_scalar($topScoreRaw) ? intval($topScoreRaw) : 0;
        if ($topScore < 25) {
            return null;
        }

        return array(
            'id_and_type' => $topIdAndType,
            'score' => $topScore,
            'row_type' => is_string($permalinksPacket[1] ?? '') ? (string)($permalinksPacket[1] ?? '') : '',
        );
    }

    /**
     * @param string $idAndType
     * @param int $score
     * @param string $rowType
     * @param array<string, mixed> $options
     * @return array{title: string}|null
     */
    private function resolvePermalink(string $idAndType, int $score, string $rowType, array $options): ?array {
        $permalink = ABJ_404_Solution_PermalinkResolver::permalinkInfoToArray(
            $idAndType,
            $score,
            $rowType,
            $options
        );

        $title = is_string($permalink['title'] ?? '') ? (string)($permalink['title'] ?? '') : '';
        if ($title === '' || ($permalink['status'] ?? '') === 'trash') {
            return null;
        }

        return array('title' => $title);
    }

    /**
     * @return string
     */
    private function buildTypeLabelForIdAndType(string $idAndType): string {
        $typeParts = explode('|', $idAndType);
        $typeInt = isset($typeParts[1]) && is_numeric($typeParts[1]) ? (int)$typeParts[1] : -1;
        return $this->buildTypeLabel($typeInt, $typeParts);
    }

    /**
     * @param int $typeInt
     * @param array<int, string> $typeParts
     * @return string
     */
    private function buildTypeLabel(int $typeInt, array $typeParts): string {
        if ($typeInt === ABJ404_TYPE_POST) {
            $postType = get_post_type((int)$typeParts[0]);
            return ($postType === 'page') ? __('Page', '404-solution') : __('Post', '404-solution');
        }
        if ($typeInt === ABJ404_TYPE_CAT) {
            return __('Category', '404-solution');
        }
        if ($typeInt === ABJ404_TYPE_TAG) {
            return __('Tag', '404-solution');
        }
        if ($typeInt === ABJ404_TYPE_HOME) {
            return __('Home', '404-solution');
        }

        return '';
    }
}
