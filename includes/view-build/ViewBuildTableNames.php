<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves the physical table names and prefixed option names used by the
 * admin view-snapshot build, plus existence checks for those tables.
 *
 * Pure name/identity resolution over the active table prefix: no build logic,
 * no scheduling, no locking. Shared by the orchestrator and its build
 * collaborators so every one of them agrees on exactly which tables and
 * options a rebuild touches.
 */
class ABJ_404_Solution_ViewBuildTableNames {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /** @param ABJ_404_Solution_DatabaseCore $dbCore */
    public function __construct($dbCore) {
        $this->dbCore = $dbCore;
    }

    /** @return string */
    public function prefix(): string {
        return $this->dbCore->tableNameResolver()->getLowercasePrefix();
    }

    /** @param string $name @return string */
    public function prefixedOption(string $name): string {
        return $this->prefix() . $name;
    }

    /** @return string */
    public function viewBuild(): string {
        return $this->dbCore->doTableNameReplacements('{wp_abj404_view_build}');
    }

    /** @return string */
    public function viewDone(): string {
        return $this->dbCore->doTableNameReplacements('{wp_abj404_view_done}');
    }

    /** @return string */
    public function viewDeleteme(): string {
        return $this->dbCore->doTableNameReplacements('{wp_abj404_view_deleteme}');
    }

    /** @return string */
    public function logsHits(): string {
        return $this->dbCore->doTableNameReplacements('{wp_abj404_logs_hits}');
    }

    /** @return string */
    public function viewDoneFreshnessOption(): string {
        return $this->prefixedOption('abj404_view_done_built_at');
    }

    /** @return string */
    public function viewDoneDataBuiltAtOption(): string {
        return $this->prefixedOption('abj404_view_done_data_built_at');
    }

    /** @param string $tableName @return bool */
    public function tableExists(string $tableName): bool {
        if (method_exists($this->dbCore, 'tableNameResolver')) {
            return $this->dbCore->tableNameResolver()->tableExists($tableName);
        }
        return false;
    }
}
