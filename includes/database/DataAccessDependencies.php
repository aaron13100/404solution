<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Explicit composition input for the DataAccess compatibility facade.
 *
 * DataAccess still owns the facade graph, but callers must name collaborator
 * overrides by responsibility instead of threading nullable positional values.
 */
class ABJ_404_Solution_DataAccessDependencies {

    /** @var array<string, bool> */
    private const KNOWN_KEYS = array(
        'functions' => true,
        'logging' => true,
        'dbCore' => true,
        'contentRepo' => true,
        'redirectsRepo' => true,
        'retentionService' => true,
        'logsRepo' => true,
        'statsRepo' => true,
        'viewReadService' => true,
        'viewBuildOrchestrator' => true,
    );

    /** @var array<string, mixed> */
    private $dependencies = array();

    /**
     * @param array<string, mixed> $dependencies Named collaborator overrides.
     * @throws InvalidArgumentException when an unknown dependency name is supplied.
     */
    public function __construct(array $dependencies = array()) {
        foreach ($dependencies as $name => $dependency) {
            if (!isset(self::KNOWN_KEYS[$name])) {
                throw new InvalidArgumentException('Unknown DataAccess dependency: ' . $name);
            }
            $this->dependencies[$name] = $dependency;
        }
    }

    /** @return ABJ_404_Solution_Functions|null */
    public function functions(): ?ABJ_404_Solution_Functions {
        $value = $this->get('functions');
        return $value instanceof ABJ_404_Solution_Functions ? $value : null;
    }

    /** @return ABJ_404_Solution_Logging|null */
    public function logging(): ?ABJ_404_Solution_Logging {
        $value = $this->get('logging');
        return $value instanceof ABJ_404_Solution_Logging ? $value : null;
    }

    /** @return ABJ_404_Solution_DatabaseCore|null */
    public function dbCore(): ?ABJ_404_Solution_DatabaseCore {
        $value = $this->get('dbCore');
        return $value instanceof ABJ_404_Solution_DatabaseCore ? $value : null;
    }

    /** @return ABJ_404_Solution_ContentRepository|null */
    public function contentRepo(): ?ABJ_404_Solution_ContentRepository {
        $value = $this->get('contentRepo');
        return $value instanceof ABJ_404_Solution_ContentRepository ? $value : null;
    }

    /** @return ABJ_404_Solution_RedirectsRepository|null */
    public function redirectsRepo(): ?ABJ_404_Solution_RedirectsRepository {
        $value = $this->get('redirectsRepo');
        return $value instanceof ABJ_404_Solution_RedirectsRepository ? $value : null;
    }

    /** @return ABJ_404_Solution_RedirectsRetentionService|null */
    public function retentionService(): ?ABJ_404_Solution_RedirectsRetentionService {
        $value = $this->get('retentionService');
        return $value instanceof ABJ_404_Solution_RedirectsRetentionService ? $value : null;
    }

    /** @return ABJ_404_Solution_LogsRepository|null */
    public function logsRepo(): ?ABJ_404_Solution_LogsRepository {
        $value = $this->get('logsRepo');
        return $value instanceof ABJ_404_Solution_LogsRepository ? $value : null;
    }

    /** @return ABJ_404_Solution_StatsRepository|null */
    public function statsRepo(): ?ABJ_404_Solution_StatsRepository {
        $value = $this->get('statsRepo');
        return $value instanceof ABJ_404_Solution_StatsRepository ? $value : null;
    }

    /** @return ABJ_404_Solution_ViewReadService|null */
    public function viewReadService(): ?ABJ_404_Solution_ViewReadService {
        $value = $this->get('viewReadService');
        return $value instanceof ABJ_404_Solution_ViewReadService ? $value : null;
    }

    /** @return ABJ_404_Solution_ViewBuildOrchestrator|null */
    public function viewBuildOrchestrator(): ?ABJ_404_Solution_ViewBuildOrchestrator {
        $value = $this->get('viewBuildOrchestrator');
        return $value instanceof ABJ_404_Solution_ViewBuildOrchestrator ? $value : null;
    }

    /**
     * @param string $name
     * @return mixed|null
     */
    private function get(string $name) {
        return array_key_exists($name, $this->dependencies) ? $this->dependencies[$name] : null;
    }
}
