<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Public contract for the redirects retention workflow.
 *
 * Extracted from RedirectsRepository (M201). Callers that schedule or
 * trigger redirect/log retention pruning, dead-destination flagging,
 * auto-redirect expiry, junk auto-trash, orphan cleanup, and the
 * coordinated cron run program against this interface.
 */
interface ABJ_404_Solution_RedirectsRetentionServiceInterface {

    /** @return int Number of orphaned auto redirects removed. */
    public function cleanupOrphanedAutoRedirects(): int;

    /** @return string Human-readable summary of the cron run. */
    public function deleteOldRedirectsCron();

    /** @return int Number of duplicate redirect rows removed. */
    public function removeDuplicatesCron(): int;

    /** @return bool Whether the debug log file was rotated. */
    public function limitDebugFileSize(): bool;

    /**
     * @param array<string, mixed> $options Plugin options array.
     * @return int Number of captured URLs auto-trashed.
     */
    public function autoTrashJunkCapturedUrls(array $options): int;

    /**
     * Flag redirects whose destination URL appears in the 404 log as a recent 404.
     *
     * @return void
     */
    public function flagDeadDestinationRedirects(): void;

    /**
     * Move auto-created redirects to trash if they are older than the configured expiration.
     *
     * @return int Number of redirects moved to trash.
     */
    public function expireOldAutoRedirects(): int;
}
