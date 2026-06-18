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

    /**
     * Return the logging dependency. Accepts the production Logging class
     * and also duck-typed test spies that expose the same method surface
     * (debugMessage / infoMessage / warn / errorMessage). Returning the
     * raw spy lets DAO components hold it as $this->logger and dispatch
     * calls through it; without this, a spy is silently dropped and the
     * production singleton is used instead, so level-discipline assertions
     * see no captured warn/error output (broken test-as-mirror feedback).
     *
     * The widened return type lets the strict instanceof check live in one
     * place (DataAccess::resolveLogger) instead of being duplicated here
     * and there with adapter wrappers that the mock-lint blocks.
     *
     * @return ABJ_404_Solution_Logging|object|null
     */
    public function logging() {
        $value = $this->get('logging');
        if ($value instanceof ABJ_404_Solution_Logging) {
            return $value;
        }
        if (is_object($value) && method_exists($value, 'warn') && method_exists($value, 'errorMessage')) {
            return $value;
        }
        return null;
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

    /**
     * @param string $name
     * @return mixed|null
     */
    private function get(string $name) {
        return array_key_exists($name, $this->dependencies) ? $this->dependencies[$name] : null;
    }
}
