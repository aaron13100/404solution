<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read side of the AJAX checkpoint journal: everything the support-collection
 * pipeline is served from it.
 *
 * Split out of ABJ_404_Solution_AjaxCheckpointLogger, which is now the
 * append-only WRITER and nothing else. The dependency stays one-directional
 * by design: this reader resolves the journal's location through the writer
 * (the single owner of the directory contract, kept there so recording works
 * even when nothing ever reads), and the writer never calls back into the
 * reader, so a bug in support collection cannot take checkpoint recording
 * down with it.
 *
 * Consumers: ABJ_404_Solution_SupportEvidenceExcerpt (the bounded support
 * payload), ABJ_404_Solution_DetachAbEvidence (verdicts read straight off the
 * journal), and ABJ_404_Solution_DeveloperLogMailer (the unbounded archive).
 */
final class ABJ_404_Solution_CheckpointJournalReader {

    /**
     * Share of the support payload's excerpt field this journal may claim.
     *
     * Sized against a measured session, not chosen for tidiness: one table
     * request costs 26-27 records, so 32 KB (the previous value, further
     * halved by an even per-file split) bought about ONE request while a
     * failing session is six failing attempts plus a canary ladder plus polls.
     * The per-section budgets are proven to sum inside the report contract by
     * SupportExcerptBudgetContractTest.
     */
    const MAX_SUPPORT_EXCERPT_BYTES = 131072;

    /**
     * Bounded recent checkpoint lines for the support-request payload.
     *
     * Without this the checkpoints are written and never read by anyone: the
     * support payload carried only the stage trace, so a request that died
     * BEFORE its first stage -- the exact beta.1 failure -- reached the
     * developer as an empty excerpt. Every pre-stage boundary (auth, rate
     * limit, trace construction, service resolution) and every post-stage
     * boundary (encode, echo, each ob close, flush, finish-request, exit) is
     * recorded only here, so this is the channel that makes "nothing after
     * authorized" a readable fact instead of an absence.
     *
     * The rotated file is included: a session busy enough to rotate is a
     * session whose oldest evidence is still the most interesting.
     *
     * @param array<string, bool> $knownFailingIds Requests condemned across every
     *   journal, so this excerpt and the stage-trace one rank identically. This
     *   journal already contains its own verdicts; passing the union in is what
     *   makes the two agree rather than each ranking off what it happens to hold.
     */
    public static function readRecentForSupport(array $knownFailingIds = array()): string {
        $directory = self::journalDirectory();
        if ($directory === '') {
            return '';
        }
        return ABJ_404_Solution_DiagnosticJournalExcerpt::compose(
            self::supportExcerptPaths($directory),
            self::MAX_SUPPORT_EXCERPT_BYTES,
            "Recent AJAX request checkpoints (JSONL):\n",
            $knownFailingIds
        );
    }

    /**
     * What readRecentForSupport() will look at, whether or not any of it
     * exists, for ABJ_404_Solution_DiagnosticCollectionManifest. The candidate
     * list is the reader's own, so the manifest can never describe a different
     * set of files than the one that was actually read.
     *
     * The directory is reported even when it turned out to be unusable: which
     * path this channel tried is exactly the fact a wrong-node or unwritable
     * uploads directory is diagnosed from.
     *
     * @return array{channel: string, directory: string, usable: bool, paths: array<int, string>}
     */
    public static function supportCollectionSource(): array {
        try {
            $directory = self::journalDirectory();
            $usable = $directory !== '';
            return array(
                'channel' => 'ajax_checkpoints',
                'directory' => $usable ? $directory
                    : ABJ_404_Solution_AjaxCheckpointLogger::resolveDirectoryPath(),
                'usable' => $usable,
                'paths' => $usable ? self::supportExcerptPaths($directory) : array(),
            );
        } catch (Throwable $e) {
            self::reportFailure('AJAX checkpoint support source resolution failed: ' . $e->getMessage());
            return array('channel' => 'ajax_checkpoints', 'directory' => '', 'usable' => false, 'paths' => array());
        }
    }

    /**
     * Rotated file then current journal: oldest first, the order the excerpt
     * reader breaks mtime ties on. File names come from the writer, the one
     * owner of the journal's on-disk contract.
     *
     * @param string $directory With a trailing separator.
     * @return array<int, string>
     */
    private static function supportExcerptPaths(string $directory): array {
        return array(
            $directory . ABJ_404_Solution_CheckpointJournalWriter::ROTATED_FILE,
            $directory . ABJ_404_Solution_CheckpointJournalWriter::CHECKPOINT_FILE,
        );
    }

    /**
     * Existing journal files, for a channel that carries them WHOLE.
     *
     * The support excerpt is bounded by a byte budget and a ranking, and a
     * budget decision must never again be the single point of loss for a
     * session we only get once. The developer log archive has no such bound,
     * so it carries both journals in full alongside the debug logs.
     *
     * @return array<int, string>
     */
    public static function supportArchivePaths(): array {
        $directory = self::journalDirectory();
        if ($directory === '') {
            return array();
        }
        $paths = array();
        foreach (array(ABJ_404_Solution_CheckpointJournalWriter::CHECKPOINT_FILE,
                ABJ_404_Solution_CheckpointJournalWriter::ROTATED_FILE) as $name) {
            if (@is_file($directory . $name)) {
                $paths[] = $directory . $name;
            }
        }
        return $paths;
    }

    /**
     * The journal directory via the writer's resolution, or '' when the
     * writer class itself is unreachable. A corrupt install can be missing
     * any plugin file (the safe autoloader returns silently for a missing
     * class; see the error-18 work), and the read side must degrade to
     * "nothing to read" rather than fatal the support request.
     */
    private static function journalDirectory(): string {
        return class_exists('ABJ_404_Solution_AjaxCheckpointLogger')
            ? ABJ_404_Solution_AjaxCheckpointLogger::resolveDirectory()
            : '';
    }

    private static function reportFailure(string $message): void {
        // Unconditional: abj404_logPhpFallback() is defined at plugin entry
        // (404-solution.php), before any class here can be autoloaded, so a
        // raw error_log() second sink would be unreachable dead weight.
        abj404_logPhpFallback('ajax-checkpoint', $message);
    }
}
