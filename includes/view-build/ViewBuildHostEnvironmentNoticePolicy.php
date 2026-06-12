<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin notice policy for staged-build host-environment warnings.
 *
 * Keeps user-visible notice persistence and clearing separate from the
 * read-only host probes.
 */
class ABJ_404_Solution_ViewBuildHostEnvironmentNoticePolicy extends ABJ_404_Solution_ViewBuildCollaborator {

    private const LOW_MEMORY_NOTICE_KEY = 'abj404_view_build_low_memory_limit_notice';
    private const FILESYSTEM_NOTICE_KEY = 'abj404_view_build_filesystem_env_notice';
    private const SESSION_NOTICE_KEY = 'abj404_view_build_session_env_notice';
    private const SESSION_DEDUP_KEY = 'abj404_view_build_session_env_dedup';

    /** @return void */
    public function setLowMemoryLimitAdminNotice(int $memoryBytes): void {
        $payload = array(
            'kind'         => 'low_memory_limit',
            'bytes'        => $memoryBytes,
            'recommended'  => ABJ_404_Solution_ViewBuildConfig::PHP_MEMORY_LIMIT_RECOMMENDED_BYTES,
            'message'      => sprintf(
                function_exists('__')
                    ? __('Your PHP memory_limit (%s) is below the recommended 128M; the redirect view rebuild may fail on large sites.', '404-solution')
                    : 'Your PHP memory_limit (%s) is below the recommended 128M; the redirect view rebuild may fail on large sites.',
                $this->formatPhpMemoryBytesHuman($memoryBytes)
            ),
            'when'         => $this->now(),
        );
        $this->storeNotice(self::LOW_MEMORY_NOTICE_KEY, $payload);
    }

    /**
     * @param array<string,mixed> $probe
     * @return void
     */
    public function setFilesystemEnvAdminNotice(array $probe): void {
        $rawWarnings = isset($probe['warnings']) && is_array($probe['warnings']) ? $probe['warnings'] : array();
        $stringWarnings = array();
        foreach ($rawWarnings as $w) {
            if (is_string($w)) { $stringWarnings[] = $w; }
        }
        $payload = array(
            'kind'           => 'filesystem_env',
            'warnings'       => $stringWarnings,
            'message'        => (function_exists('__')
                ? __('The 404 Solution view-build pipeline detected filesystem host constraints that may degrade the next rebuild: ', '404-solution')
                : 'The 404 Solution view-build pipeline detected filesystem host constraints that may degrade the next rebuild: ')
                . implode(' | ', $stringWarnings),
            'when'           => $this->now(),
        );
        $this->storeNotice(self::FILESYSTEM_NOTICE_KEY, $payload);
    }

    /**
     * @param array<int,string> $warnings
     * @return void
     */
    public function setSessionEnvAdminNotice(array $warnings): void {
        if (function_exists('get_transient') && get_transient(self::SESSION_DEDUP_KEY) !== false) {
            return;
        }
        $payload = array(
            'kind'           => 'session_env',
            'warnings'       => $warnings,
            'message'        => (function_exists('__')
                ? __('The 404 Solution view-build pipeline detected MySQL session-variable settings that may degrade the next rebuild: ', '404-solution')
                : 'The 404 Solution view-build pipeline detected MySQL session-variable settings that may degrade the next rebuild: ')
                . implode(' | ', $warnings),
            'when'           => $this->now(),
        );
        $this->storeNotice(self::SESSION_NOTICE_KEY, $payload);
        if (function_exists('set_transient')) {
            // allow-cache-empty: session-environment dedup marker intentionally stores diagnostics, not query data.
            set_transient(
                self::SESSION_DEDUP_KEY,
                1,
                ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_DEGRADED_NOTICE_TTL_SECONDS
            );
        }
    }

    /** @return void */
    public function clearPhpAndFilesystemEnvironmentNotices(): void {
        $this->deleteNotice(self::LOW_MEMORY_NOTICE_KEY);
        $this->deleteNotice(self::FILESYSTEM_NOTICE_KEY);
    }

    /** @return void */
    public function clearSessionEnvironmentNotices(): void {
        $this->deleteNotice(self::SESSION_NOTICE_KEY);
        $this->deleteNotice(self::SESSION_DEDUP_KEY);
    }

    /**
     * @param array<string,mixed> $payload
     * @return void
     */
    private function storeNotice(string $key, array $payload): void {
        if (function_exists('set_transient')) {
            // allow-cache-empty: host-environment warning marker intentionally stores diagnostics, not query data.
            set_transient(
                $key,
                $payload,
                ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_DEGRADED_NOTICE_TTL_SECONDS
            );
        } elseif (function_exists('update_option')) {
            update_option($key, $payload, false);
        }
    }

    /** @return void */
    private function deleteNotice(string $key): void {
        if (function_exists('delete_transient')) {
            delete_transient($key);
        }
        if (function_exists('delete_option')) {
            delete_option($key);
        }
    }

    /** @return int */
    private function now(): int {
        $clock = $this->host->dataBoundary()->clock();
        if (!$clock instanceof ABJ_404_Solution_Clock) {
            throw new \RuntimeException('ViewBuildHostEnvironmentNoticePolicy requires ABJ_404_Solution_Clock from host.');
        }
        return $clock->now();
    }

    /** @return string */
    private function formatPhpMemoryBytesHuman(int $bytes): string {
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
}
