<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Write-side operations on the log subsystem: redirect-hit logging, queued
 * batch flush, lookup-table population, and the static pipeline-trace
 * decoder paired with the writer.
 */
interface ABJ_404_Solution_LogWriteInterface {

    /**
     * @param ABJ_404_Solution_RedirectHitLogEntry $entry
     * @return void
     */
    public function logRedirectHit(ABJ_404_Solution_RedirectHitLogEntry $entry): void;

    /**
     * @param array<string, mixed> $entry
     * @return void
     */
    public function queueLogEntry(array $entry): void;

    /** @return void */
    public function flushLogQueue(): void;

    /**
     * @param string $valueToInsert
     * @return int
     */
    public function insertLookupValueAndGetID($valueToInsert);

    /**
     * @param string $userName
     * @return int
     */
    public function getLookupIDForUser($userName);

    /** @return void */
    public function correctDuplicateLookupValues(): void;

    /**
     * @param string|null $raw
     * @return array<int, array{step: string, outcome: string, detail: string}>|null
     */
    public static function decompressPipelineTrace(?string $raw): ?array;
}
