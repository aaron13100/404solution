<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Coordinates frequent checkpoint writes from request-safe boundaries.
 *
 * Directory resolution belongs to the checkpoint logger, while the low-level
 * writer owns the request cache and append path. Keeping their coordination
 * here prevents either collaborator from depending back on the other.
 */
final class ABJ_404_Solution_AjaxCheckpointBoundaryWriter {

    /**
     * Resolve once, then reuse the cached result for the rest of the request.
     *
     * An empty directory is a cached failure: retrying WordPress filters can
     * recurse into the same database boundary being traced. Callbacks already
     * executing inside wpdb must use the low-level writer with a previously
     * resolved directory instead.
     *
     * @param array<string, mixed> $fields
     */
    public static function record(
        string $requestId,
        string $event,
        array $fields = array()
    ): void {
        if (!ABJ_404_Solution_AjaxFrequentCheckpointWriter::hasResolvedDirectoryForRequest(
            $requestId
        )) {
            ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent(
                $requestId,
                $event,
                $fields
            );
            return;
        }
        ABJ_404_Solution_AjaxFrequentCheckpointWriter::append(
            $requestId,
            $event,
            $fields,
            ABJ_404_Solution_AjaxFrequentCheckpointWriter::resolvedDirectoryForRequest(
                $requestId
            ),
            true
        );
    }
}
