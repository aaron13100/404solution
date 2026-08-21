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
     * Fetch manual and regex redirects that are eligible for server-format
     * export, with destination URLs resolved into the serialized read shape.
     *
     * @return array<int, array{source: string, dest: string, code: int, is_regex: bool}>
     */
    public function getExportableRedirects(): array;

    /**
     * Store a redirect for future use.
     *
     * Refactored in queue task c738 (audit source: design-audit-2026-05-29.md,
     * criterion 220 Interface Size). The previous 8-positional signature
     * (fromURL, status, type, final_dest, code, disabled, engine, score) had
     * two transposable URL strings and five overlapping numeric fields; all
     * those fields are now bundled into {@see ABJ_404_Solution_RedirectSpec}.
     *
     * @param ABJ_404_Solution_RedirectSpec $spec
     * @return int
     */
    public function setupRedirect(ABJ_404_Solution_RedirectSpec $spec);

    /**
     * Store a redirect only when no row already has its normalized source URL.
     * The persistence boundary performs the check and insert in one statement.
     *
     * @return int New row id, or 0 when the source already exists/write fails.
     */
    public function setupRedirectIfSourceAbsent(ABJ_404_Solution_RedirectSpec $spec): int;

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

    /**
     * Move redirects with the specified status/type values to trash.
     *
     * @param array<int, int|string> $types
     * @param string $purgeType
     * @return array{status: string, rows_affected: int, redirect_types: array<int, int>}
     */
    public function deleteSpecifiedRedirects(array $types, string $purgeType): array;

    // =========================================================================
    // Redirect conditions (from DataAccessTrait_Redirects)
    // =========================================================================

    /**
     * @param int $redirectId
     * @return array<int, array<string, mixed>>
     */
    public function getRedirectConditions(int $redirectId): array;

    /**
     * Replace the full condition set for a redirect. The delete + inserts run
     * inside a single transaction so a mid-replacement DB failure cannot leave
     * a partial condition set.
     *
     * @param int $redirectId
     * @param array<int, array<string, mixed>> $conditions
     * @return string Error message on failure ('' on success).
     */
    public function saveRedirectConditions(int $redirectId, array $conditions): string;

    // =========================================================================
    // Redirect updates (from DataAccessTrait_Stats)
    // =========================================================================

    /**
     * Mutate an existing redirect row. Inputs flow through
     * {@see ABJ_404_Solution_RedirectUpdate}, a typed parameter object that
     * replaced an eight-argument positional signature so $fromUrl and
     * $destination (both arbitrary strings) cannot be silently swapped.
     *
     * @return string Error message on validation failure, empty string on success.
     */
    public function updateRedirect(ABJ_404_Solution_RedirectUpdate $update): string;

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
