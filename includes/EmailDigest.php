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
     * @return string HTML email body with inline CSS.
     */
    public function generateDigestHTML(array $topCaptured, array $stats, string $dateRange = ''): string {
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
        $totalManual = intval($stats['total_manual']);
        $totalAuto = intval($stats['total_auto']);

        // ---- HTML rows for the top-captured table ----
        $tableRows = '';
        if (empty($topCaptured)) {
            $tableRows = '<tr><td colspan="3" style="padding:8px;text-align:center;color:#666;">'
                . esc_html__('No captured 404s in this period.', '404-solution')
                . '</td></tr>';
        } else {
            foreach ($topCaptured as $row) {
                $url = isset($row['url']) && is_string($row['url']) ? esc_html($row['url']) : '';
                $hits = isset($row['logshits']) ? intval(is_scalar($row['logshits']) ? $row['logshits'] : 0) : 0;
                $created = isset($row['created']) ? date('Y-m-d', intval(is_scalar($row['created']) ? $row['created'] : 0)) : '';
                $tableRows .= '<tr>'
                    . '<td style="padding:6px 10px;border-bottom:1px solid #e5e7eb;word-break:break-all;">' . $url . '</td>'
                    . '<td style="padding:6px 10px;border-bottom:1px solid #e5e7eb;text-align:center;">' . $hits . '</td>'
                    . '<td style="padding:6px 10px;border-bottom:1px solid #e5e7eb;text-align:center;">' . $created . '</td>'
                    . '</tr>' . "\n";
            }
        }

        $pluginVersion = defined('ABJ404_VERSION') ? ABJ404_VERSION : '';
        $phpVersion = PHP_VERSION;

        $html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . esc_html__('404 Solution Digest', '404-solution') . '</title>
</head>
<body style="margin:0;padding:0;background-color:#f3f4f6;font-family:Arial,sans-serif;font-size:14px;color:#1f2937;">
<table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f3f4f6;">
<tr><td align="center" style="padding:30px 16px;">

<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.1);">

<!-- Header -->
<tr>
<td style="background:#2563eb;padding:24px 32px;">
<h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;">'
. esc_html__('404 Solution Digest', '404-solution')
. '</h1>
<p style="margin:6px 0 0;color:#bfdbfe;font-size:14px;">'
. esc_html($dateRange)
. '</p>
</td>
</tr>

<!-- Summary Stats -->
<tr>
<td style="padding:24px 32px;">
<h2 style="margin:0 0 16px;font-size:16px;color:#374151;border-bottom:1px solid #e5e7eb;padding-bottom:8px;">'
. esc_html__('Summary', '404-solution')
. '</h2>
<table width="100%" cellpadding="0" cellspacing="0">
<tr>
<td style="padding:8px 12px;background:#eff6ff;border-radius:6px;text-align:center;width:33%;">
<div style="font-size:28px;font-weight:700;color:#1d4ed8;">' . intval($totalCaptured) . '</div>
<div style="font-size:12px;color:#6b7280;">' . esc_html__('Captured 404s', '404-solution') . '</div>
</td>
<td style="padding:8px;" width="8"></td>
<td style="padding:8px 12px;background:#f0fdf4;border-radius:6px;text-align:center;width:33%;">
<div style="font-size:28px;font-weight:700;color:#16a34a;">' . intval($totalAuto) . '</div>
<div style="font-size:12px;color:#6b7280;">' . esc_html__('Auto Redirects', '404-solution') . '</div>
</td>
<td style="padding:8px;" width="8"></td>
<td style="padding:8px 12px;background:#faf5ff;border-radius:6px;text-align:center;width:33%;">
<div style="font-size:28px;font-weight:700;color:#7c3aed;">' . intval($totalManual) . '</div>
<div style="font-size:12px;color:#6b7280;">' . esc_html__('Manual Redirects', '404-solution') . '</div>
</td>
</tr>
</table>
</td>
</tr>

<!-- Top Captured URLs Table -->
<tr>
<td style="padding:0 32px 24px;">
<h2 style="margin:0 0 12px;font-size:16px;color:#374151;border-bottom:1px solid #e5e7eb;padding-bottom:8px;">'
. esc_html__('Top Captured 404 URLs', '404-solution')
. '</h2>
<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;">
<thead>
<tr style="background:#f9fafb;">
<th style="padding:8px 10px;text-align:left;font-weight:600;color:#374151;border-bottom:2px solid #e5e7eb;">'
. esc_html__('URL', '404-solution')
. '</th>
<th style="padding:8px 10px;text-align:center;font-weight:600;color:#374151;border-bottom:2px solid #e5e7eb;">'
. esc_html__('Hits', '404-solution')
. '</th>
<th style="padding:8px 10px;text-align:center;font-weight:600;color:#374151;border-bottom:2px solid #e5e7eb;">'
. esc_html__('First Seen', '404-solution')
. '</th>
</tr>
</thead>
<tbody>
' . $tableRows . '
</tbody>
</table>
</td>
</tr>

<!-- CTA -->
<tr>
<td style="padding:0 32px 24px;text-align:center;">
<a href="' . esc_url($adminUrl) . '" style="display:inline-block;padding:10px 24px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;font-size:14px;font-weight:600;">'
. esc_html__('View Captured 404s', '404-solution')
. '</a>
</td>
</tr>

<!-- Footer -->
<tr>
<td style="padding:16px 32px;background:#f9fafb;border-top:1px solid #e5e7eb;">
<p style="margin:0;font-size:12px;color:#9ca3af;">'
. esc_html__('To stop receiving these emails, update your notification settings.', '404-solution')
. ' <a href="' . esc_url($settingsUrl) . '" style="color:#2563eb;">'
. esc_html__('Manage settings', '404-solution')
. '</a></p>
<p style="margin:6px 0 0;font-size:11px;color:#d1d5db;">'
. esc_html__('Sent', '404-solution') . ' ' . date('Y/m/d H:i:s T')
. ' &mdash; PHP ' . esc_html($phpVersion)
. ' &mdash; ' . esc_html__('Plugin', '404-solution') . ' ' . esc_html($pluginVersion)
. '</p>
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

        $topCaptured = $this->dao->getTopCapturedForDigest($limit);
        $stats = $this->dao->getDigestSummaryStats();

        if (intval($stats['total_captured']) === 0 && empty($topCaptured)) {
            return 'Digest skipped: no captured 404s to report.';
        }

        $dateRange = date('Y-m-d');
        $body = $this->generateDigestHTML($topCaptured, $stats, $dateRange);

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

        // Optionally attach a PDF summary.
        $attachments = array();
        $pdfEnabled = isset($options['pdf_email_reports']) && (bool)$options['pdf_email_reports'];
        if ($pdfEnabled) {
            $pdfPath = $this->generateDigestPDF($body, $dateRange);
            if ($pdfPath !== '') {
                $attachments[] = $pdfPath;
            }
        }

        $this->logger->debugMessage('Sending 404 digest email to: ' . $to);
        wp_mail($to, $subject, $body, $headers, $attachments);
        $this->logger->debugMessage('404 digest email sent.');

        // Clean up temp PDF file after sending.
        foreach ($attachments as $att) {
            if (file_exists($att)) {
                @unlink($att);
            }
        }

        if (function_exists('update_option')) {
            update_option('admin_notification_last_sent', time());
        }

        return 'Digest email sent to: ' . $to;
    }

    /**
     * Convert the digest HTML to a PDF file and return the temp file path.
     *
     * Returns an empty string if Dompdf is unavailable or generation fails.
     *
     * @param string $html      HTML to convert.
     * @param string $dateRange Label used in the file name.
     * @return string Temp file path, or '' on failure.
     */
    public function generateDigestPDF(string $html, string $dateRange = ''): string {
        if (!class_exists('Dompdf\Dompdf')) {
            return '';
        }

        try {
            $options = new \Dompdf\Options();
            $options->set('isRemoteEnabled', false);
            $options->set('isHtml5ParserEnabled', true);

            $dompdf = new \Dompdf\Dompdf($options);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            $pdfContent = $dompdf->output();
            if (!is_string($pdfContent) || $pdfContent === '') {
                return '';
            }

            $safeDateRange = preg_replace('/[^a-zA-Z0-9_-]/', '-', $dateRange !== '' ? $dateRange : date('Y-m-d'));
            $tmpPath = sys_get_temp_dir() . '/abj404-digest-' . $safeDateRange . '-' . wp_generate_password(8, false) . '.pdf';

            if (file_put_contents($tmpPath, $pdfContent) === false) {
                return '';
            }

            return $tmpPath;
        } catch (\Throwable $e) {
            $this->logger->debugMessage('PDF generation failed: ' . $e->getMessage());
            return '';
        }
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
