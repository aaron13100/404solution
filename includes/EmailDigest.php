<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generates and sends rich digest email notifications for captured 404s.
 *
 * This class is responsible for:
 * - Building an HTML email digest with a summary of captured 404 URLs.
 * - Sending the digest via wp_mail().
 * - Managing the WP-Cron schedule for daily/weekly digests.
 */
class ABJ_404_Solution_EmailDigest {

    /** @var ABJ_404_Solution_DataAccess */
    private $dao;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_DataAccess $dao
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct($dao, $logger) {
        $this->dao = $dao;
        $this->logger = $logger;
    }

    /**
     * Generate HTML email body for the digest.
     *
     * @param array<int, array<string, mixed>> $topCaptured Array of captured 404 rows from getTopCapturedForDigest().
     * @param array{total_captured: int, total_manual: int, total_auto: int} $stats From getDigestSummaryStats().
     * @param string $dateRange Human-readable date range label for the digest header.
     * @param bool $rollupAvailable Whether the logs_hits rollup is currently
     *    available. When false and $topCaptured is empty, the empty-state
     *    cell renders an "unavailable, rebuild scheduled" message instead of
     *    "No captured 404s in this period" so the admin can distinguish the
     *    two cases.
     * @return string HTML email body with inline CSS.
     */
    public function generateDigestHTML(array $topCaptured, array $stats, string $dateRange = '', bool $rollupAvailable = true): string {
        if ($dateRange === '') {
            $dateRange = date('Y-m-d');
        }

        $adminUrl = function_exists('admin_url')
            ? admin_url('options-general.php?page=' . ABJ404_PP . '&subpage=abj404_captured')
            : '#';
        $settingsUrl = function_exists('admin_url')
            ? admin_url('options-general.php?page=' . ABJ404_PP . '&subpage=abj404_options')
            : '#';

        $totalCaptured = intval($stats['total_captured']);
        $totalManual   = intval($stats['total_manual']);
        $totalAuto     = intval($stats['total_auto']);

        // Resolution rate: fraction of all tracked URLs that have been handled.
        $totalAll      = $totalCaptured + $totalAuto + $totalManual;
        $resolved      = $totalAuto + $totalManual;
        $resolutionPct = $totalAll > 0 ? min(100, (int) round($resolved / $totalAll * 100)) : 0;
        $remainderPct  = 100 - $resolutionPct;

        // Progress bar: avoid a zero-width cell in edge cases.
        $progressBarFill = $resolutionPct > 0
            ? '<td width="' . $resolutionPct . '%" bgcolor="#2563eb" style="background:#2563eb;border-radius:3px;font-size:0;line-height:0;" height="6">&nbsp;</td>'
            : '';
        $progressBarEmpty = $remainderPct > 0
            ? '<td width="' . $remainderPct . '%" style="font-size:0;line-height:0;" height="6">&nbsp;</td>'
            : '';

        // ---- HTML rows for the top-captured table ----
        $tableRows = '';
        if (empty($topCaptured)) {
            $emptyMessage = $rollupAvailable
                ? esc_html__('No captured 404s in this period.', '404-solution')
                : esc_html__('Top URLs unavailable: log rollup is being rebuilt. Will be available in the next digest.', '404-solution');
            $tableRows = '<tr><td colspan="3" style="padding:14px;text-align:center;color:#94a3b8;font-size:13px;">'
                . $emptyMessage
                . '</td></tr>';
        } else {
            $rowIndex = 0;
            foreach ($topCaptured as $row) {
                $rowIndex++;
                $rawUrl  = isset($row['url']) && is_string($row['url']) ? $row['url'] : '';
                $urlText = esc_html($rawUrl);
                $hits    = isset($row['logshits']) ? intval(is_scalar($row['logshits']) ? $row['logshits'] : 0) : 0;
                $created = isset($row['created']) ? date('Y-m-d', intval(is_scalar($row['created']) ? $row['created'] : 0)) : '';

                $rowBg   = ($rowIndex % 2 === 0) ? '#f8fafc' : '#ffffff';

                // Color-coded hit badge.
                if ($hits >= 100) {
                    $badgeBg = '#fee2e2'; $badgeFg = '#dc2626';
                } elseif ($hits >= 20) {
                    $badgeBg = '#fef3c7'; $badgeFg = '#d97706';
                } else {
                    $badgeBg = '#f1f5f9'; $badgeFg = '#475569';
                }

                $tableRows .= '<tr bgcolor="' . $rowBg . '" style="background:' . $rowBg . ';">'
                    . '<td style="padding:9px 12px;border-bottom:1px solid #f1f5f9;font-size:12px;'
                    .   'font-family:\'Courier New\',Courier,monospace;word-break:break-all;color:#334155;">'
                    .   $urlText
                    . '</td>'
                    . '<td style="padding:9px 12px;border-bottom:1px solid #f1f5f9;text-align:center;white-space:nowrap;">'
                    .   '<span style="display:inline-block;padding:2px 8px;background:' . $badgeBg . ';color:' . $badgeFg . ';'
                    .     'border-radius:12px;font-size:12px;font-weight:700;">' . $hits . '</span>'
                    . '</td>'
                    . '<td style="padding:9px 12px;border-bottom:1px solid #f1f5f9;text-align:center;'
                    .   'font-size:12px;color:#64748b;white-space:nowrap;">' . esc_html($created) . '</td>'
                    . '</tr>' . "\n";
            }
        }

        $pluginVersion = defined('ABJ404_VERSION') ? ABJ404_VERSION : '';
        $phpVersion    = PHP_VERSION;
        $sentAt        = date('Y-m-d H:i T');

        // Translatable strings resolved once for readability.
        $t_digest      = esc_html__('404 Solution Digest', '404-solution');
        $t_report      = esc_html__('Digest Report', '404-solution');
        $t_summary     = esc_html__('Summary', '404-solution');
        $t_captured    = esc_html__('Captured', '404-solution');
        $t_404urls     = esc_html__('404 URLs', '404-solution');
        $t_auto        = esc_html__('Auto', '404-solution');
        $t_redirected  = esc_html__('Redirected', '404-solution');
        $t_manual      = esc_html__('Manual', '404-solution');
        $t_configured  = esc_html__('Configured', '404-solution');
        $t_resolution  = esc_html__('Resolution Rate', '404-solution');
        $t_handled     = sprintf(
            /* translators: 1: resolved count, 2: total count */
            esc_html__('%1$d of %2$d URLs handled', '404-solution'),
            $resolved,
            $totalAll
        );
        $t_top_urls    = esc_html__('Top Captured 404 URLs', '404-solution');
        $t_url         = esc_html__('URL', '404-solution');
        $t_hits        = esc_html__('Hits', '404-solution');
        $t_first_seen  = esc_html__('First Seen', '404-solution');
        $t_view_cta    = esc_html__('View Captured 404s', '404-solution');
        $t_settings    = esc_html__('Manage Settings', '404-solution');
        $t_unsubscribe = esc_html__('To stop these emails, update your notification settings.', '404-solution');
        $t_manage      = esc_html__('Manage settings', '404-solution');

        $html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . $t_digest . '</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:14px;color:#1e293b;">

<!-- Outer wrapper -->
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f1f5f9;">
<tr><td align="center" style="padding:32px 16px;">

<!-- Main card -->
<table width="600" cellpadding="0" cellspacing="0" role="presentation"
  style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;
         box-shadow:0 4px 6px rgba(0,0,0,0.07),0 1px 3px rgba(0,0,0,0.06);">

<!-- ===== HEADER ===== -->
<tr>
<td style="background:#1d4ed8;padding:0;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td style="padding:26px 32px 22px;">
<table cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td style="background:rgba(255,255,255,0.18);border-radius:10px;padding:9px 11px;vertical-align:middle;">
<span style="font-size:22px;line-height:1;" role="img" aria-label="shield">&#x1F6E1;&#xFE0F;</span>
</td>
<td style="padding-left:14px;vertical-align:middle;">
<div style="color:#ffffff;font-size:19px;font-weight:700;letter-spacing:-0.2px;">404 Solution</div>
<div style="color:#93c5fd;font-size:11px;font-weight:600;letter-spacing:0.8px;text-transform:uppercase;margin-top:2px;">'
. $t_report . '</div>
</td>
</tr>
</table>
</td>
<td align="right" style="padding:26px 32px 22px;vertical-align:middle;">
<div style="background:rgba(255,255,255,0.15);border-radius:8px;padding:6px 14px;display:inline-block;">
<div style="color:#bfdbfe;font-size:12px;font-weight:500;">' . esc_html($dateRange) . '</div>
</div>
</td>
</tr>
</table>
</td>
</tr>

<!-- ===== SUMMARY STATS ===== -->
<tr>
<td style="padding:24px 32px 0;">
<div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin-bottom:12px;">'
. $t_summary . '</div>
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<!-- Captured -->
<td style="width:32%;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr><td style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 10px;text-align:center;">
<div style="font-size:10px;font-weight:700;color:#3b82f6;text-transform:uppercase;letter-spacing:0.8px;margin-bottom:7px;">&#x1F4CA; ' . $t_captured . '</div>
<div style="font-size:32px;font-weight:800;color:#1d4ed8;line-height:1;">' . $totalCaptured . '</div>
<div style="font-size:11px;color:#94a3b8;margin-top:5px;">' . $t_404urls . '</div>
</td></tr>
</table>
</td>
<td width="8">&nbsp;</td>
<!-- Auto -->
<td style="width:32%;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr><td style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px 10px;text-align:center;">
<div style="font-size:10px;font-weight:700;color:#16a34a;text-transform:uppercase;letter-spacing:0.8px;margin-bottom:7px;">&#x2705; ' . $t_auto . '</div>
<div style="font-size:32px;font-weight:800;color:#15803d;line-height:1;">' . $totalAuto . '</div>
<div style="font-size:11px;color:#94a3b8;margin-top:5px;">' . $t_redirected . '</div>
</td></tr>
</table>
</td>
<td width="8">&nbsp;</td>
<!-- Manual -->
<td style="width:32%;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr><td style="background:#faf5ff;border:1px solid #ddd6fe;border-radius:10px;padding:14px 10px;text-align:center;">
<div style="font-size:10px;font-weight:700;color:#7c3aed;text-transform:uppercase;letter-spacing:0.8px;margin-bottom:7px;">&#x270D; ' . $t_manual . '</div>
<div style="font-size:32px;font-weight:800;color:#6d28d9;line-height:1;">' . $totalManual . '</div>
<div style="font-size:11px;color:#94a3b8;margin-top:5px;">' . $t_configured . '</div>
</td></tr>
</table>
</td>
</tr>
</table>
</td>
</tr>

<!-- ===== RESOLUTION RATE BAR ===== -->
<tr>
<td style="padding:14px 32px 0;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr><td style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 16px;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td><span style="font-size:12px;font-weight:600;color:#475569;">' . $t_resolution . '</span></td>
<td align="right"><span style="font-size:12px;font-weight:800;color:#2563eb;">' . $resolutionPct . '%</span></td>
</tr>
</table>
<!-- Progress bar track -->
<table width="100%" cellpadding="0" cellspacing="0" role="presentation"
  style="margin-top:8px;border-radius:3px;overflow:hidden;background:#e2e8f0;" height="6">
<tr>' . $progressBarFill . $progressBarEmpty . '</tr>
</table>
<div style="font-size:11px;color:#94a3b8;margin-top:7px;">' . $t_handled . '</div>
</td></tr>
</table>
</td>
</tr>

<!-- ===== TOP URLS TABLE ===== -->
<tr>
<td style="padding:20px 32px 0;">
<div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin-bottom:10px;">'
. $t_top_urls . '</div>
<table width="100%" cellpadding="0" cellspacing="0" role="presentation"
  style="border-collapse:collapse;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
<thead>
<tr style="background:#f8fafc;">
<th style="padding:10px 12px;text-align:left;font-size:11px;font-weight:700;color:#64748b;
    text-transform:uppercase;letter-spacing:0.7px;border-bottom:1px solid #e2e8f0;">'
. $t_url . '</th>
<th style="padding:10px 12px;text-align:center;font-size:11px;font-weight:700;color:#64748b;
    text-transform:uppercase;letter-spacing:0.7px;border-bottom:1px solid #e2e8f0;white-space:nowrap;">'
. $t_hits . '</th>
<th style="padding:10px 12px;text-align:center;font-size:11px;font-weight:700;color:#64748b;
    text-transform:uppercase;letter-spacing:0.7px;border-bottom:1px solid #e2e8f0;white-space:nowrap;">'
. $t_first_seen . '</th>
</tr>
</thead>
<tbody>
' . $tableRows . '
</tbody>
</table>
</td>
</tr>

<!-- ===== CTA BUTTONS ===== -->
<tr>
<td style="padding:20px 32px;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td style="width:50%;padding-right:6px;">
<a href="' . esc_url($adminUrl) . '"
  style="display:block;padding:12px 0;background:#2563eb;color:#ffffff;text-decoration:none;
         border-radius:8px;font-size:13px;font-weight:700;text-align:center;letter-spacing:0.2px;">'
. $t_view_cta . ' &#x2192;</a>
</td>
<td style="width:50%;padding-left:6px;">
<a href="' . esc_url($settingsUrl) . '"
  style="display:block;padding:12px 0;background:#f8fafc;color:#374151;text-decoration:none;
         border-radius:8px;font-size:13px;font-weight:600;text-align:center;
         border:1px solid #e2e8f0;letter-spacing:0.2px;">'
. $t_settings . '</a>
</td>
</tr>
</table>
</td>
</tr>

<!-- ===== FOOTER ===== -->
<tr>
<td style="padding:16px 32px;background:#f8fafc;border-top:1px solid #e2e8f0;border-radius:0 0 12px 12px;">
<p style="margin:0;font-size:12px;color:#94a3b8;text-align:center;">'
. $t_unsubscribe
. ' <a href="' . esc_url($settingsUrl) . '" style="color:#2563eb;text-decoration:none;">'
. $t_manage . '</a></p>
<p style="margin:8px 0 0;font-size:11px;color:#cbd5e1;text-align:center;">404 Solution v'
. esc_html($pluginVersion)
. ' &nbsp;&#183;&nbsp; PHP ' . esc_html($phpVersion)
. ' &nbsp;&#183;&nbsp; ' . esc_html($sentAt) . '</p>
</td>
</tr>

</table>
</td></tr>
</table>
</body>
</html>';

        return $html;
    }

    /**
     * Send the digest email. Returns a description of what happened.
     *
     * @return string
     */
    public function sendDigest(): string {
        $options = ABJ_404_Solution_PluginLogic::getInstance()->getOptions(true);

        $frequency = isset($options['admin_notification_frequency']) && is_string($options['admin_notification_frequency'])
            ? $options['admin_notification_frequency']
            : 'instant';

        if ($frequency === 'instant') {
            return 'Digest skipped: frequency is instant.';
        }

        $to = isset($options['admin_notification_email']) && is_string($options['admin_notification_email'])
            ? trim($options['admin_notification_email'])
            : '';

        if ($to === '') {
            $adminEmail = function_exists('get_option') ? get_option('admin_email') : '';
            $to = is_string($adminEmail) ? $adminEmail : '';
        }

        if ($to === '') {
            return 'Digest skipped: no recipient email address configured.';
        }

        $limit = isset($options['admin_notification_digest_limit']) && is_numeric($options['admin_notification_digest_limit'])
            ? max(1, intval($options['admin_notification_digest_limit']))
            : 10;

        // Pre-check rollup availability so the email distinguishes "rollup is
        // being rebuilt" from "no captured 404s." Without this, a missing
        // rollup silently produces an "No captured 404s in this period" cell
        // even when captured rows exist — misleading to the admin.
        $rollupAvailable = $this->dao->logsHitsTableExists();
        if (!$rollupAvailable) {
            // Schedule a rebuild now so the next digest run has data.
            $this->dao->scheduleHitsTableRebuild();
            $topCaptured = array();
        } else {
            $topCaptured = $this->dao->getTopCapturedForDigest($limit);
        }
        $stats = $this->dao->getDigestSummaryStats();

        // Skip the email entirely only when there is genuinely nothing to report
        // AND the rollup is healthy. If the rollup is unavailable but stats show
        // captured rows exist, ship the email with a "top URLs unavailable" note
        // so the admin learns about the rebuild rather than hearing silence.
        if ($rollupAvailable && intval($stats['total_captured']) === 0 && empty($topCaptured)) {
            return 'Digest skipped: no captured 404s to report.';
        }

        $dateRange = date('Y-m-d');
        $body = $this->generateDigestHTML($topCaptured, $stats, $dateRange, $rollupAvailable);

        $subject = sprintf(
            /* translators: %s: current date */
            __('404 Solution Digest — %s', '404-solution'),
            $dateRange
        );

        $adminEmail = function_exists('get_option') ? get_option('admin_email') : '';
        $adminEmailStr = is_string($adminEmail) ? $adminEmail : '';
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $adminEmailStr . ' <' . $adminEmailStr . '>',
        );

        $this->logger->debugMessage('Sending 404 digest email to: ' . $to);
        wp_mail($to, $subject, $body, $headers);
        $this->logger->debugMessage('404 digest email sent.');

        if (function_exists('update_option')) {
            update_option('admin_notification_last_sent', time());
        }

        return 'Digest email sent to: ' . $to;
    }

    /**
     * Schedule the next digest send based on the frequency option.
     * Reschedules or clears WP-Cron as needed.
     *
     * @return void
     */
    public function scheduleNextDigest(): void {
        $options = ABJ_404_Solution_PluginLogic::getInstance()->getOptions(true);
        $frequency = isset($options['admin_notification_frequency']) && is_string($options['admin_notification_frequency'])
            ? $options['admin_notification_frequency']
            : 'instant';

        $hook = 'abj404_send_digest';

        if ($frequency === 'instant') {
            if (function_exists('wp_clear_scheduled_hook')) {
                wp_clear_scheduled_hook($hook);
            }
            return;
        }

        $recurrence = ($frequency === 'weekly') ? 'weekly' : 'daily';

        if (function_exists('wp_next_scheduled') && !wp_next_scheduled($hook)) {
            if (function_exists('wp_schedule_event')) {
                wp_schedule_event(time(), $recurrence, $hook);
            }
        }
    }

    /**
     * Hook callback for the WP-Cron event 'abj404_send_digest'.
     *
     * @return void
     */
    public function onCronSendDigest(): void {
        $result = $this->sendDigest();
        $this->logger->debugMessage('onCronSendDigest: ' . $result);
    }

}
