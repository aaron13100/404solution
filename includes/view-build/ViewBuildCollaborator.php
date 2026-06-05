<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Base class for staged view-build collaborators.
 *
 * Collaborators share an explicit collaboration context for peer calls and
 * host services. The context replaces the former orchestrator __call/__get
 * routing while each collaborator keeps ownership of its local behavior.
 */
abstract class ABJ_404_Solution_ViewBuildCollaborator {

    /**
     * @var object
     * @phpstan-var ABJ_404_Solution_ViewBuildCollaborationContext
     */
    protected object $host;

    /**
     * @param object $host Explicit context or test double exposing the same methods.
     * @phpstan-param ABJ_404_Solution_ViewBuildCollaborationContext $host
     */
    public function __construct($host) {
        $this->host = $host;
    }

    /** @return int */
    protected function stagedQueryTimeoutSeconds(): int {
        return (int)$this->host->stageServices()->stagedSqlExecutor()->getStagedQueryTimeoutSeconds();
    }

    /** @param int $seconds @return void */
    protected function setStagedQueryTimeoutSeconds(int $seconds): void {
        $this->host->stageServices()->stagedSqlExecutor()->setStagedQueryTimeoutSeconds(max(0, $seconds));
    }

    /** @return array<string, mixed>|null */
    protected function sqlModeProbeCache() {
        $cache = $this->host->stageServices()->sqlModeProbe()->getSqlModeProbeCache();
        if (!is_array($cache)) {
            return null;
        }
        $stringKeyed = array();
        foreach ($cache as $key => $value) {
            if (is_string($key)) {
                $stringKeyed[$key] = $value;
            }
        }
        return $stringKeyed;
    }
}
