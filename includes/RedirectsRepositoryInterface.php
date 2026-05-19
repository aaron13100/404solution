<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Public contract for redirect CRUD, conditions, regex matching, and cleanup operations.
 *
 * Extracted from DataAccess in Phase 2 of the DataAccess refactor. Callers that
 * need redirect lookups, mutations, scheduled cleanup, or condition management
 * program against this interface.
 */
interface ABJ_404_Solution_RedirectsRepositoryInterface {

    // =========================================================================
    // Redirect CRUD (from DataAccessTrait_Redirects)
    // =========================================================================

    /**
     * @param int|string $id
     * @return void
     */
    public function deleteRedirect($id);

    /**
     * Store a redirect for future use.
     *
     * @param string $fromURL
     * @param string $status ABJ404_STATUS_MANUAL etc
     * @param string $type ABJ404_TYPE_POST, ABJ404_TYPE_CAT, ABJ404_TYPE_TAG, etc.
     * @param string $final_dest
     * @param string $code
     * @param int $disabled
     * @param string|null $engine
     * @param float|null $score
     * @return int
     */
    public function setupRedirect($fromURL, $status, $type, $final_dest, $code, $disabled = 0, $engine = null, $score = null);

    /**
     * @param string $url
     * @param bool $degradedMode
     * @return array<string, mixed>
     */
    public function getActiveRedirectForURL($url, $degradedMode = false);

    /**
     * @param string $url
     * @return array<string, mixed>
     */
    public function getExistingRedirectForURL($url);

    /** @return string */
    public function deleteSpecifiedRedirects();

    // =========================================================================
    // Redirect conditions (from DataAccessTrait_Redirects)
    // =========================================================================

    /**
     * @param int $redirectId
     * @return array<int, array<string, mixed>>
     */
    public function getRedirectConditions(int $redirectId): array;

    /**
     * @param int $redirectId
     * @param array<int, array<string, mixed>> $conditions
     * @return void
     */
    public function saveRedirectConditions(int $redirectId, array $conditions): void;

    // =========================================================================
    // Redirect updates (from DataAccessTrait_Stats)
    // =========================================================================

    /**
     * @param string $type
     * @param string $dest
     * @param string $fromURL
     * @param int $idForUpdate
     * @param string $redirectCode
     * @param string $statusType
     * @param int|null $startTs
     * @param int|null $endTs
     * @return string
     */
    public function updateRedirect($type, $dest, $fromURL, $idForUpdate, $redirectCode, $statusType, $startTs = null, $endTs = null);

    /**
     * @param array<int, int|string> $ids
     * @return array<int, array<string, mixed>>
     */
    public function getRedirectsByIDs($ids);

    /**
     * @param int $id
     * @param string $newstatus
     * @return string
     */
    public function updateRedirectTypeStatus($id, $newstatus);

    /**
     * @param int $id
     * @param int $trash
     * @return string
     */
    public function moveRedirectsToTrash($id, $trash);

    // =========================================================================
    // Cron maintenance (from DataAccessTrait_Redirects)
    // =========================================================================

    /** @return int */
    public function cleanupOrphanedAutoRedirects(): int;

    /** @return string */
    public function deleteOldRedirectsCron();

    /** @return int */
    public function removeDuplicatesCron(): int;

    /** @return bool */
    public function limitDebugFileSize(): bool;

    /**
     * @param array<string, mixed> $options
     * @return int
     */
    public function autoTrashJunkCapturedUrls(array $options): int;

    // =========================================================================
    // Redirect maintenance (from DataAccessTrait_Maintenance, Phase 5)
    // =========================================================================

    /**
     * Flag redirects whose destination URL appears in the 404 log as a recent 404.
     *
     * @return void
     */
    public function flagDeadDestinationRedirects(): void;

    /**
     * Move auto-created redirects to trash if they are older than the configured expiration.
     *
     * @return int Number of redirects moved to trash
     */
    public function expireOldAutoRedirects(): int;

    // =========================================================================
    // Regex cache (from DataAccess static state)
    // =========================================================================

    /** @return void */
    public function clearRegexRedirectsCache(): void;

    // =========================================================================
    // Static utilities (from DataAccessTrait_Redirects)
    // =========================================================================

    /**
     * @param mixed $url
     * @return string
     */
    public static function computeRedirectsCanonicalUrl($url): string;

    /**
     * @param string $columnExpr
     * @return string
     */
    public static function hitsCanonicalUrlSqlExpression(string $columnExpr): string;
}
