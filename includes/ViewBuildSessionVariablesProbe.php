<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MySQL session-variable warning probe for staged view-build entry.
 */
class ABJ_404_Solution_ViewBuildSessionVariablesProbe extends ABJ_404_Solution_ViewBuildCollaborator {

    /** @var ABJ_404_Solution_ViewBuildHostEnvironmentNoticePolicy */
    private $noticePolicy;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_ViewBuildSessionVariablesRepository */
    private $repository;

    /** @var ABJ_404_Solution_ViewBuildSessionVariableWarningClassifier */
    private $warningClassifier;

    /** @var array<string,mixed>|null */
    private $sessionVariablesProbeCache = null;

    public function __construct(
        ABJ_404_Solution_ViewBuildOrchestrator $host,
        ?ABJ_404_Solution_ViewBuildSessionVariablesRepository $repository = null,
        ?ABJ_404_Solution_ViewBuildSessionVariableWarningClassifier $warningClassifier = null
    ) {
        parent::__construct($host);
        $this->noticePolicy = new ABJ_404_Solution_ViewBuildHostEnvironmentNoticePolicy($host);
        $logger = $host->viewBuildCollaboratorDependency('logger');
        if (!$logger instanceof ABJ_404_Solution_Logging) {
            throw new \UnexpectedValueException('ViewBuildSessionVariablesProbe requires ABJ_404_Solution_Logging from host.');
        }
        $this->logger = $logger;
        $this->repository = $repository ?: new ABJ_404_Solution_ViewBuildSessionVariablesRepository($this->logger);
        $this->warningClassifier = $warningClassifier ?: new ABJ_404_Solution_ViewBuildSessionVariableWarningClassifier();
    }

    /** @return string */
    public function sessionVariablesProbeOptionName(): string {
        return 'abj404_view_build_session_env_probe';
    }

    /**
     * Probe the live MySQL session for operational and DDL-safety variables
     * that can degrade or break the staged view-build pipeline.
     *
     * @return array<string,mixed>
     */
    public function probeSessionVariablesAtS1Entry(): array {
        if (is_array($this->sessionVariablesProbeCache)) {
            return $this->sessionVariablesProbeCache;
        }

        $defaults = array(
            'innodb_lock_wait_timeout'         => 0,
            'tmp_table_size'                   => 0,
            'max_heap_table_size'              => 0,
            'slow_query_log'                   => 0,
            'long_query_time'                  => 0.0,
            'innodb_buffer_pool_size'          => 0,
            'wait_timeout'                     => 0,
            'interactive_timeout'              => 0,
            'innodb_flush_method'              => '',
            'character_set_server'             => '',
            'collation_server'                 => '',
            'sql_require_primary_key'          => '',
            'innodb_file_per_table'            => '',
            'thread_stack'                     => 0,
            'open_files_limit'                 => 0,
            'innodb_online_alter_log_max_size' => 0,
            'probe_succeeded'                  => false,
        );

        $row = $this->repository->fetchSessionVariablesRowOrEmpty();
        $values = $defaults;
        if (!empty($row)) {
            $values['probe_succeeded'] = true;
            foreach ($row as $k => $v) {
                $klow = strtolower((string)$k);
                if (!array_key_exists($klow, $defaults)) { continue; }
                if ($klow === 'long_query_time') {
                    $values[$klow] = is_scalar($v) ? (float)$v : 0.0;
                } elseif (is_int($defaults[$klow])) {
                    $values[$klow] = is_scalar($v) ? (int)$v : 0;
                } else {
                    $values[$klow] = is_scalar($v) ? (string)$v : '';
                }
            }
        }

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('abj404_session_env_probe', $values);
            if (is_array($filtered)) {
                $values = array_merge($values, $filtered);
            }
        }

        $warnings = $this->classifySessionVariableWarnings($values);
        $values['warnings'] = $warnings;

        foreach ($warnings as $w) {
            if (is_scalar($w)) {
                $this->logger->warn('[staged] ' . (string)$w);
            }
        }
        if (!empty($warnings)) {
            $this->noticePolicy->setSessionEnvAdminNotice($warnings);
        }

        if (function_exists('update_option')) {
            update_option($this->sessionVariablesProbeOptionName(), $values, false);
        }

        $this->sessionVariablesProbeCache = $values;
        return $values;
    }

    /** @return array<string,string> */
    public function fetchSessionVariablesRowOrEmpty(): array {
        return $this->repository->fetchSessionVariablesRowOrEmpty();
    }

    /**
     * @param array<string,mixed> $values
     * @return array<int,string>
     */
    public function classifySessionVariableWarnings(array $values): array {
        return $this->warningClassifier->classifySessionVariableWarnings($values);
    }

    /** @return void */
    public function clearSessionVariablesProbeCache(): void {
        $this->sessionVariablesProbeCache = null;
        if (function_exists('delete_option')) {
            delete_option($this->sessionVariablesProbeOptionName());
        }
        $this->noticePolicy->clearSessionEnvironmentNotices();
    }
}
