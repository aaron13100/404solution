<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin notice payloads and remediation text for staged-build host failures.
 *
 * The notices stay on the plugin's own admin notice channel and are deduped
 * through transients, matching the self-healing reliability policy.
 *
 */
class ABJ_404_Solution_ViewBuildHostFailureNotices extends ABJ_404_Solution_ViewBuildCollaborator {

    /**
     * Surface a deduplicated admin notice for a degraded-build event.
     *
     * @param int    $stageNumber
     * @param string $kind 'skipped' or 'halted'.
     * @param string $errorText
     * @return void
     */
    public function setStagedBuildDegradedNotice(int $stageNumber, string $kind, string $errorText): void {
        $key = sprintf(
            'abj404_view_build_s%d_%s_notice',
            $stageNumber,
            $kind === 'halted' ? 'halted' : 'skipped'
        );
        $message = $this->degradedNoticeBaseMessage($stageNumber, $kind)
            . $this->degradedNoticePrivilegeHint($stageNumber, $errorText)
            . ' ' . sprintf(
            function_exists('__') ? __('Original error: %s', '404-solution') : 'Original error: %s',
            substr(trim($errorText), 0, 200)
        );
        $payload = array(
            'stage'          => $stageNumber,
            'kind'           => $kind,
            'error'          => $errorText,
            'message'        => $message,
            'when'           => $this->host->dataBoundary()->clock()->now(),
        );
        if (function_exists('set_transient')) {
            // allow-cache-empty: degraded-state notice marker intentionally stores diagnostics, not query data.
            set_transient(
                $key,
                $payload,
                ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_DEGRADED_NOTICE_TTL_SECONDS
            );
        } elseif (function_exists('update_option')) {
            update_option($key, $payload, false);
        }
    }

    private function degradedNoticeBaseMessage(int $stageNumber, string $kind): string {
        if ($kind === 'halted') {
            return sprintf(
                function_exists('__')
                    ? __('The 404 Solution view-build pipeline halted at stage %d/11.', '404-solution')
                    : 'The 404 Solution view-build pipeline halted at stage %d/11.',
                $stageNumber
            );
        }
        return sprintf(
            function_exists('__')
                ? __('The 404 Solution view-build pipeline skipped optional stage %d/11.', '404-solution')
                : 'The 404 Solution view-build pipeline skipped optional stage %d/11.',
            $stageNumber
        );
    }

    private function degradedNoticePrivilegeHint(int $stageNumber, string $errorText): string {
        if (stripos($errorText, 'create temporary') !== false || stripos($errorText, "to database '") !== false
            || ($stageNumber === 9 && stripos($errorText, 'access denied') !== false)) {
            return ' ' . (function_exists('__') ? __('Ask your host to grant the CREATE TEMPORARY TABLES privilege to your WordPress database user so the hits aggregate column can be populated.', '404-solution') : 'Ask your host to grant the CREATE TEMPORARY TABLES privilege to your WordPress database user so the hits aggregate column can be populated.');
        }
        if (stripos($errorText, 'alter command denied') !== false) {
            return ' ' . (function_exists('__') ? __('Ask your host to grant the ALTER privilege to your WordPress database user.', '404-solution') : 'Ask your host to grant the ALTER privilege to your WordPress database user.');
        }
        if (stripos($errorText, 'rename') !== false || $stageNumber === 11) {
            return ' ' . (function_exists('__') ? __('Ask your host to grant ALTER + DROP + CREATE on the database used by WordPress so the view-build swap can complete.', '404-solution') : 'Ask your host to grant ALTER + DROP + CREATE on the database used by WordPress so the view-build swap can complete.');
        }
        if (stripos($errorText, 'access denied') !== false || stripos($errorText, 'command denied') !== false) {
            return ' ' . (function_exists('__') ? __('Ask your host to review your WordPress database user privileges.', '404-solution') : 'Ask your host to review your WordPress database user privileges.');
        }
        return '';
    }

    /**
     * Set a halt notice for a non-stage failure such as floor-kill streaks.
     *
     * @param string $scenarioKey
     * @param string $errorText
     * @return void
     */
    public function setStagedBuildHaltNotice(string $scenarioKey, string $errorText): void {
        $key = 'abj404_view_build_' . $scenarioKey . '_halt_notice';
        $payload = array(
            'scenario' => $scenarioKey,
            'kind'     => 'halted',
            'error'    => $errorText,
            'when'     => $this->host->dataBoundary()->clock()->now(),
        );
        if (function_exists('set_transient')) {
            // allow-cache-empty: scenario halt marker intentionally stores diagnostics, not query data.
            set_transient(
                $key,
                $payload,
                ABJ_404_Solution_ViewBuildConfig::VIEW_BUILD_DEGRADED_NOTICE_TTL_SECONDS
            );
        } elseif (function_exists('update_option')) {
            update_option($key, $payload, false);
        }
    }

    /**
     * Build a user-facing message describing the degraded build event.
     *
     * @param int    $stageNumber
     * @param string $kind 'skipped' or 'halted'.
     * @param string $errorText
     * @return string
     */
    public function describeDegradedNotice(int $stageNumber, string $kind, string $errorText): string {
        $errorSnippet = substr(trim($errorText), 0, 200);
        $base = $kind === 'halted'
            ? sprintf('The 404 Solution view-build pipeline halted at stage %d/11.', $stageNumber)
            : sprintf('The 404 Solution view-build pipeline skipped optional stage %d/11.', $stageNumber);

        $hint = '';
        if (stripos($errorText, 'create temporary') !== false || stripos($errorText, "to database '") !== false
            || ($stageNumber === 9 && stripos($errorText, 'access denied') !== false)) {
            $hint = ' Ask your host to grant the CREATE TEMPORARY TABLES privilege to your WordPress database user '
                . 'so the hits aggregate column can be populated.';
        } elseif (stripos($errorText, 'alter command denied') !== false) {
            $hint = ' Ask your host to grant the ALTER privilege to your WordPress database user.';
        } elseif (stripos($errorText, 'rename') !== false || $stageNumber === 11) {
            $hint = ' Ask your host to grant ALTER + DROP + CREATE on the database used by WordPress so the '
                . 'view-build swap can complete.';
        } elseif (stripos($errorText, 'access denied') !== false || stripos($errorText, 'command denied') !== false) {
            $hint = ' Ask your host to review your WordPress database user privileges.';
        }

        return $base . $hint . ' Original error: ' . $errorSnippet;
    }
}
