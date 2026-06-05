<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves selected spell-candidate IDs to permalinks after candidate pruning.
 */
class ABJ_404_Solution_SpellCandidatePermalinkLookup {

	/** @var ABJ_404_Solution_ContentRepository */
	private $contentRepository;

	/** @var ABJ_404_Solution_SpellURLMatcher */
	private $urlMatcher;

	/**
	 * @param ABJ_404_Solution_ContentRepository $contentRepository
	 * @param ABJ_404_Solution_SpellURLMatcher $urlMatcher
	 */
	public function __construct($contentRepository, $urlMatcher) {
		$this->contentRepository = $contentRepository;
		$this->urlMatcher = $urlMatcher;
	}

	/**
	 * @param array<int, mixed> $ids
	 * @param string $rowType
	 * @param array<int, string> $observedPermalinksById
	 * @return array<int|string, string>
	 */
	public function lookup(array $ids, string $rowType, array $observedPermalinksById = array()): array {
		if (empty($ids)) {
			return [];
		}
		$result = [];
		if ($rowType === 'pages' || $rowType === 'image') {
			$intIds = array_map(function($v) { return is_scalar($v) ? (int)$v : 0; }, $ids);
			$rows = $this->contentRepository->getPermalinksByIds($intIds);
			foreach ($rows as $row) {
				$row = (array)$row;
				if (isset($row['id'], $row['url']) && is_string($row['url'])) {
					$result[(int)$row['id']] = abj_service('sanitizer')->normalizeUrlString($row['url']);
				}
			}
			foreach ($intIds as $id) {
				if (!isset($result[$id]) && isset($observedPermalinksById[$id])) {
					$result[$id] = abj_service('sanitizer')->normalizeUrlString($observedPermalinksById[$id]);
				}
			}
			return $result;
		}

		foreach ($ids as $id) {
			$idInt = is_scalar($id) ? (int)$id : 0;
			$permalink = $this->urlMatcher->getPermalink($idInt, $rowType);
			if (is_string($permalink) && $permalink !== '') {
				$key = (is_int($id) || is_string($id)) ? $id : (string)$idInt;
				$result[$key] = $permalink;
			}
		}
		return $result;
	}
}
