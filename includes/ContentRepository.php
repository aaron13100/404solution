<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/ContentRepositoryInterface.php';
require_once __DIR__ . '/PublishedContentRepository.php';
require_once __DIR__ . '/PermalinkCacheRepository.php';
require_once __DIR__ . '/SpellingCacheRepository.php';
require_once __DIR__ . '/OldSlugRepository.php';

/**
 * Stable facade for published-content, cache, and old-slug repository operations.
 *
 * The legacy public contract remains here so existing callers can keep one
 * injection point while the data-access responsibilities live in focused
 * repositories.
 */
class ABJ_404_Solution_ContentRepository implements ABJ_404_Solution_ContentRepositoryInterface {

    /** @var ABJ_404_Solution_PublishedContentRepository */
    private $publishedContentRepository;

    /** @var ABJ_404_Solution_PermalinkCacheRepository */
    private $permalinkCacheRepository;

    /** @var ABJ_404_Solution_SpellingCacheRepository */
    private $spellingCacheRepository;

    /** @var ABJ_404_Solution_OldSlugRepository */
    private $oldSlugRepository;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_Logging|null $logging
     * @param mixed $optionsProvider Object exposing getOptions(): array. Defaults to options_repository service.
     * @param ABJ_404_Solution_DatabaseErrorClassifier|null $errorClassifier
     * @param ABJ_404_Solution_DatabaseCollationHelper|null $collationHelper
     * @param ABJ_404_Solution_PublishedContentRepository|null $publishedContentRepository
     * @param ABJ_404_Solution_PermalinkCacheRepository|null $permalinkCacheRepository
     * @param ABJ_404_Solution_SpellingCacheRepository|null $spellingCacheRepository
     * @param ABJ_404_Solution_OldSlugRepository|null $oldSlugRepository
     */
    public function __construct(
        ABJ_404_Solution_DatabaseCore $dbCore,
        $functions = null,
        $logging = null,
        $optionsProvider = null,
        $errorClassifier = null,
        $collationHelper = null,
        $publishedContentRepository = null,
        $permalinkCacheRepository = null,
        $spellingCacheRepository = null,
        $oldSlugRepository = null
    ) {
        $f = $functions !== null ? $functions : abj_service('functions');
        $logger = $logging !== null ? $logging : abj_service('logging');
        $resolvedErrorClassifier = $errorClassifier !== null ? $errorClassifier : $dbCore->errorClassifier();
        $resolvedCollationHelper = $collationHelper !== null ? $collationHelper : $dbCore->collationHelper();

        $this->publishedContentRepository = $publishedContentRepository !== null
            ? $publishedContentRepository
            : new ABJ_404_Solution_PublishedContentRepository(
                $dbCore,
                $f,
                $logger,
                $optionsProvider,
                $resolvedErrorClassifier,
                $resolvedCollationHelper
            );
        $this->permalinkCacheRepository = $permalinkCacheRepository !== null
            ? $permalinkCacheRepository
            : new ABJ_404_Solution_PermalinkCacheRepository($dbCore, $f, $optionsProvider);
        $this->spellingCacheRepository = $spellingCacheRepository !== null
            ? $spellingCacheRepository
            : new ABJ_404_Solution_SpellingCacheRepository($dbCore, $f);
        $this->oldSlugRepository = $oldSlugRepository !== null
            ? $oldSlugRepository
            : new ABJ_404_Solution_OldSlugRepository($dbCore, $f);
    }

    /** @inheritDoc */
    public function getPublishedPagesAndPostsIDs($slug = '', $searchTerm = '',
        $limitResults = '', $orderResults = '', $extraWhereClause = '') {
        return $this->publishedContentRepository->getPublishedPagesAndPostsIDs(
            $slug,
            $searchTerm,
            $limitResults,
            $orderResults,
            $extraWhereClause
        );
    }

    /** @inheritDoc */
    public function getPublishedImagesIDs() {
        return $this->publishedContentRepository->getPublishedImagesIDs();
    }

    /** @inheritDoc */
    public function getPublishedTags($slug = null, $limit = null) {
        return $this->publishedContentRepository->getPublishedTags($slug, $limit);
    }

    /** @inheritDoc */
    public function addURLToTermsRows($rows) {
        return $this->publishedContentRepository->addURLToTermsRows($rows);
    }

    /** @inheritDoc */
    public function getPublishedCategories($term_id = null, $slug = null, $limit = null) {
        return $this->publishedContentRepository->getPublishedCategories($term_id, $slug, $limit);
    }

    /** @inheritDoc */
    public function truncatePermalinkCacheTable(): void {
        $this->permalinkCacheRepository->truncatePermalinkCacheTable();
    }

    /** @inheritDoc */
    public function removeFromPermalinkCache(int $post_id): void {
        $this->permalinkCacheRepository->removeFromPermalinkCache($post_id);
    }

    /** @inheritDoc */
    public function getPermalinkFromCache($id) {
        return $this->permalinkCacheRepository->getPermalinkFromCache($id);
    }

    /** @inheritDoc */
    public function getPermalinksByIds(array $ids) {
        return $this->permalinkCacheRepository->getPermalinksByIds($ids);
    }

    /** @inheritDoc */
    public function getPermalinkEtcFromCache($id) {
        return $this->permalinkCacheRepository->getPermalinkEtcFromCache($id);
    }

    /** @inheritDoc */
    public function getIDsNeededForPermalinkCache() {
        return $this->permalinkCacheRepository->getIDsNeededForPermalinkCache();
    }

    /** @inheritDoc */
    public function updatePermalinkCache() {
        return $this->permalinkCacheRepository->updatePermalinkCache();
    }

    /** @inheritDoc */
    public function updatePermalinkCacheParentPages() {
        return $this->permalinkCacheRepository->updatePermalinkCacheParentPages();
    }

    /** @inheritDoc */
    public function getPermalinkCacheCount(): int {
        return $this->permalinkCacheRepository->getPermalinkCacheCount();
    }

    /** @inheritDoc */
    public function storeSpellingPermalinksToCache(string $requestedURLRaw, $returnValue): void {
        $this->spellingCacheRepository->storeSpellingPermalinksToCache($requestedURLRaw, $returnValue);
    }

    /** @inheritDoc */
    public function getSpellingPermalinksFromCache(string $requestedURLRaw) {
        return $this->spellingCacheRepository->getSpellingPermalinksFromCache($requestedURLRaw);
    }

    /** @inheritDoc */
    public function deleteSpellingCache(): void {
        $this->spellingCacheRepository->deleteSpellingCache();
    }

    /** @inheritDoc */
    public function getOldSlug($post_id) {
        return $this->oldSlugRepository->getOldSlug($post_id);
    }
}
