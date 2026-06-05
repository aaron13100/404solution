<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * PHP runtime host-environment probe for the staged view-build pipeline.
 *
 * Detects PHP-side constraints that affect build stage budgets and memory
 * safety. Filesystem and MySQL session probes live in their own focused
 * collaborators.
 */
class ABJ_404_Solution_ViewBuildHostEnvironmentProbe extends ABJ_404_Solution_ViewBuildCollaborator {

    /** @var ABJ_404_Solution_ViewBuildHostEnvironmentNoticePolicy */
    private $noticePolicy;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * Cached PHP-environment probe result for the current request.
     *
     * @var array<string,mixed>|null
     */
    private $phpEnvironmentProbeCache = null;

    public function __construct(ABJ_404_Solution_ViewBuildCollaborationContext $host) {
        parent::__construct($host);
        $this->noticePolicy = new ABJ_404_Solution_ViewBuildHostEnvironmentNoticePolicy($host);
        $this->logger = $host->logger();
    }

    /** @return string */
    public function phpEnvironmentProbeOptionName(): string {
        return 'abj404_view_build_php_env_probe';
    }

    /**
     * Probe the PHP runtime for environmental constraints that affect the
     * staged view build:
     *
     *   - set_time_limit() in disable_functions: the build cannot extend its
     *     time budget mid-stage on hardened shared hosts.
     *   - memory_limit below the 128M recommended floor: the S9 hits aggregate
     *     can OOM on busy sites.
     *
     * @return array<string,mixed>
     */
    public function probePhpEnvironmentForBuild(): array {
        if (is_array($this->phpEnvironmentProbeCache)) {
            return $this->phpEnvironmentProbeCache;
        }

        $rawMemory = (string)ini_get('memory_limit');
        $memoryBytes = $this->host->parsePhpMemoryLimitToBytes($rawMemory);

        $disabled = $this->host->phpDisabledFunctionsList();
        $setTimeLimitAvailable = function_exists('set_time_limit')
            && !in_array('set_time_limit', $disabled, true);

        $result = array(
            'set_time_limit_available' => $setTimeLimitAvailable,
            'memory_limit_raw'         => $rawMemory,
            'memory_limit_bytes'       => $memoryBytes,
            'memory_limit_low'         => ($memoryBytes > 0
                && $memoryBytes < ABJ_404_Solution_ViewBuildConfig::PHP_MEMORY_LIMIT_RECOMMENDED_BYTES),
        );

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('abj404_php_env_probe', $result);
            if (is_array($filtered)) {
                $result = array_merge($result, $filtered);
            }
        }

        if (empty($result['set_time_limit_available'])) {
            $this->logger->infoMessage(
                '[staged] set_time_limit() unavailable (disable_functions); '
                . 'switching to tighter cron-tick budget mode.'
            );
        }
        if (!empty($result['memory_limit_low'])) {
            $resultMemoryBytes = isset($result['memory_limit_bytes']) && is_numeric($result['memory_limit_bytes'])
                ? (int)$result['memory_limit_bytes'] : 0;
            $this->noticePolicy->setLowMemoryLimitAdminNotice($resultMemoryBytes);
        }

        if (function_exists('update_option')) {
            update_option($this->host->phpEnvironmentProbeOptionName(), $result, false);
        }

        $this->phpEnvironmentProbeCache = $result;
        return $result;
    }

    /** @return bool */
    public function probeSetTimeLimitAvailability(): bool {
        $probe = $this->host->probePhpEnvironmentForBuild();
        return !empty($probe['set_time_limit_available']);
    }

    /** @return int */
    public function probeMemoryLimitForS9(): int {
        $probe = $this->host->probePhpEnvironmentForBuild();
        $bytes = $probe['memory_limit_bytes'] ?? 0;
        return is_numeric($bytes) ? (int)$bytes : 0;
    }

    /**
     * Parse a php.ini-style memory size (`128M`, `1G`, `262144`, `-1`) into
     * raw bytes. Returns 0 for unlimited or unparseable input.
     *
     * @return int
     */
    public function parsePhpMemoryLimitToBytes(string $raw): int {
        $raw = trim($raw);
        if ($raw === '' || $raw === '-1' || $raw === '0') {
            return 0;
        }
        $unit = strtoupper(substr($raw, -1));
        $num = (int)$raw;
        if ($num <= 0) {
            return 0;
        }
        switch ($unit) {
            case 'G': return $num * 1073741824;
            case 'M': return $num * 1048576;
            case 'K': return $num * 1024;
            default:
                return is_numeric($raw) ? (int)$raw : 0;
        }
    }

    /**
     * @return array<int,string> Trimmed list of names from ini disable_functions.
     */
    public function phpDisabledFunctionsList(): array {
        $raw = (string)ini_get('disable_functions');
        if ($raw === '') {
            return array();
        }
        $names = array_map('trim', explode(',', $raw));
        return array_values(array_filter($names, function ($n) { return $n !== ''; }));
    }

    /**
     * Format a byte count as a php.ini-style suffix string for admin notices.
     *
     * @return string
     */
    public function formatPhpMemoryBytesHuman(int $bytes): string {
        if ($bytes <= 0) {
            return 'unlimited';
        }
        if ($bytes >= 1073741824) {
            $g = $bytes / 1073741824;
            return ($g == (int)$g ? (string)(int)$g : number_format($g, 1)) . 'G';
        }
        if ($bytes >= 1048576) {
            return (string)(int)round($bytes / 1048576) . 'M';
        }
        if ($bytes >= 1024) {
            return (string)(int)round($bytes / 1024) . 'K';
        }
        return (string)$bytes;
    }

    /** @return void */
    public function clearPhpEnvironmentProbeCache(): void {
        $this->phpEnvironmentProbeCache = null;
        if (function_exists('delete_option')) {
            delete_option($this->host->phpEnvironmentProbeOptionName());
        }
        $this->host->clearFilesystemEnvironmentProbeCache();
        $this->noticePolicy->clearPhpAndFilesystemEnvironmentNotices();
    }
}
