<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Exception thrown by getRedirectsForView() / getRedirectsForViewCount() when the
 * underlying SQL query fails or times out. Carries a structured diagnostics
 * payload (table counts, indexes, engine + collation, canonical_url backfill
 * state, MySQL/MariaDB version, EXPLAIN output, redacted query) so the AJAX
 * error path in ViewUpdater can surface evidence to plugin admins and write it
 * to the debug log without a follow-up debug zip from the user.
 *
 * The diagnostics array is intentionally schema-loose: every diagnostic
 * sub-query in captureViewQueryFailureDiagnostics() is wrapped in try/catch so
 * one failed probe never blocks the others. Consumers should treat any key as
 * potentially absent or string-typed (an error marker) and render accordingly.
 */
class ABJ_404_Solution_ViewQueryFailureException extends Exception {

    /** @var array<string, mixed> */
    private $diagnostics;

    /**
     * @param string $message
     * @param array<string, mixed> $diagnostics
     * @param Throwable|null $previous
     */
    public function __construct(string $message, array $diagnostics = [], ?Throwable $previous = null) {
        parent::__construct($message, 0, $previous);
        $this->diagnostics = $diagnostics;
    }

    /** @return array<string, mixed> */
    public function getDiagnostics(): array {
        return $this->diagnostics;
    }
}
