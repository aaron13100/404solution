<?php
/* allow-hardcoded-color: file-level exemption. Every literal in this file is required: HTML emitted here is delivered via wp_mail() and rendered by remote mail clients (Gmail, Outlook, Apple Mail) which do not load the admin stylesheet and strip external CSS, so theme vars (--abj404-*) cannot be used. */
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

    /** @var ABJ_404_Solution_LogsRepository */
    private $logsRepo;

    /** @var ABJ_404_Solution_StatsRepositoryInterface */
    private $statsRepo;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_LogsRepository|object $logsRepoOrLegacyDao Real LogsRepository, or a
     *     DataAccess facade that exposes getLogsRepo() (legacy + test path). When a DataAccess is
     *     supplied, this class resolves the real LogsRepository off the facade so it does not
     *     depend on pass-through LogsRepo methods existing on DataAccess.
     * @param ABJ_404_Solution_Logging|ABJ_404_Solution_StatsRepositoryInterface|null $loggerOrStatsRepo
     *     StatsRepository when first arg is LogsRepository (modern signature); otherwise the
     *     Logging service (legacy signature where the DAO is also the stats repo via pass-through).
     * @param ABJ_404_Solution_Logging|null $logger Logging service for the modern signature.
     */
    public function __construct($logsRepoOrLegacyDao, $loggerOrStatsRepo = null, $logger = null) {
        if ($logsRepoOrLegacyDao instanceof ABJ_404_Solution_LogsRepository) {
            $this->logsRepo = $logsRepoOrLegacyDao;
            $this->statsRepo = $loggerOrStatsRepo instanceof ABJ_404_Solution_StatsRepositoryInterface
                ? $loggerOrStatsRepo
                : $this->resolveStatsRepository();
            $this->logger = $logger !== null ? $logger : abj_service('logging');
        } else {
            // Legacy / test path: caller handed in a DataAccess facade. Resolve the real
            // LogsRepository off the facade; StatsRepository must be injected or registered.
            $this->logsRepo = method_exists($logsRepoOrLegacyDao, 'getLogsRepo')
                ? $logsRepoOrLegacyDao->getLogsRepo()
                : $logsRepoOrLegacyDao;
            $this->statsRepo = $this->resolveStatsRepository();
            $this->logger = $loggerOrStatsRepo instanceof ABJ_404_Solution_Logging
                ? $loggerOrStatsRepo
                : abj_service('logging');
        }
    }

    /**
     * @return ABJ_404_Solution_StatsRepositoryInterface
     */
    private function resolveStatsRepository(): ABJ_404_Solution_StatsRepositoryInterface {
        $service = class_exists('ABJ_404_Solution_ServiceContainer')
            ? ABJ_404_Solution_ServiceContainer::safeGet('stats_repository')
            : null;
        if ($service instanceof ABJ_404_Solution_StatsRepositoryInterface) {
            return $service;
        }

        return ABJ_404_Solution_StatsRepositoryResolver::resolve(__CLASS__);
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
            $dateRange = date('Y-m-d', abj_clock()->now());
        }

        $adminUrl = function_exists('admin_url')
            ? admin_url('options-general.php?page=' . ABJ404_PP . '&subpage=abj404_captured')
            : '#';
        $settingsUrl = function_exists('admin_url')
            ? admin_url('options-general.php?page=' . ABJ404_PP . '&subpage=abj404_options')
            : '#';

        $s = $this->computeDigestStats($stats);
        $tableRows = $this->buildDigestTableRows($topCaptured, $rollupAvailable);
        $t = $this->getDigestTranslations((int) $s['resolved'], (int) $s['totalAll']);

        // Load the digest email body template from disk and substitute
        // computed values. The HTML/CSS lives in includes/html/emailDigestBody.html
        // so that presentation can be edited independently of PHP. Email-client
        // compatibility requires inline / embedded CSS, so styles intentionally
        // live inside the template rather than an external stylesheet.
        $template = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/emailDigestBody.html', false);

        $replacements = array(
            '{t_digest}'        => $t['digest'],
            '{t_report}'        => $t['report'],
            '{t_summary}'       => $t['summary'],
            '{t_captured}'      => $t['captured'],
            '{t_urls404}'       => $t['urls404'],
            '{t_auto}'          => $t['auto'],
            '{t_redirected}'    => $t['redirected'],
            '{t_manual}'        => $t['manual'],
            '{t_configured}'    => $t['configured'],
            '{t_resolution}'    => $t['resolution'],
            '{t_handled}'       => $t['handled'],
            '{t_top_urls}'      => $t['top_urls'],
            '{t_url}'           => $t['url'],
            '{t_hits}'          => $t['hits'],
            '{t_first_seen}'    => $t['first_seen'],
            '{t_view_cta}'      => $t['view_cta'],
            '{t_settings}'      => $t['settings'],
            '{t_unsubscribe}'   => $t['unsubscribe'],
            '{t_manage}'        => $t['manage'],
            '{dateRange}'       => esc_html($dateRange),
            '{totalCaptured}'   => (string) $s['totalCaptured'],
            '{totalAuto}'       => (string) $s['totalAuto'],
            '{totalManual}'     => (string) $s['totalManual'],
            '{resolutionPct}'   => (string) $s['resolutionPct'],
            '{progressBarFill}' => (string) $s['progressBarFill'],
            '{progressBarEmpty}'=> (string) $s['progressBarEmpty'],
            '{tableRows}'       => $tableRows,
            '{adminUrl}'        => esc_url($adminUrl),
            '{settingsUrl}'     => esc_url($settingsUrl),
            '{pluginVersion}'   => esc_html((string) $s['pluginVersion']),
            '{phpVersion}'      => esc_html((string) $s['phpVersion']),
            '{sentAt}'          => esc_html((string) $s['sentAt']),
        );

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * @param array{total_captured: int, total_manual: int, total_auto: int} $stats
     * @return array<string, int|string>
     */
    private function computeDigestStats(array $stats): array {
        $totalCaptured = intval($stats['total_captured']);
        $totalManual   = intval($stats['total_manual']);
        $totalAuto     = intval($stats['total_auto']);
        $totalAll      = $totalCaptured + $totalAuto + $totalManual;
        $resolved      = $totalAuto + $totalManual;
        $resolutionPct = $totalAll > 0 ? min(100, (int) round($resolved / $totalAll * 100)) : 0;
        $remainderPct  = 100 - $resolutionPct;

        $progressBarFill = $resolutionPct > 0
            ? '<td width="' . $resolutionPct . '%" bgcolor="#2563eb" style="background:#2563eb;border-radius:3px;font-size:0;line-height:0;" height="6">&nbsp;</td>'
            : '';
        $progressBarEmpty = $remainderPct > 0
            ? '<td width="' . $remainderPct . '%" style="font-size:0;line-height:0;" height="6">&nbsp;</td>'
            : '';

        return [
            'totalCaptured' => $totalCaptured, 'totalManual' => $totalManual,
            'totalAuto' => $totalAuto, 'totalAll' => $totalAll, 'resolved' => $resolved,
            'resolutionPct' => $resolutionPct, 'progressBarFill' => $progressBarFill,
            'progressBarEmpty' => $progressBarEmpty,
            'pluginVersion' => defined('ABJ404_VERSION') ? ABJ404_VERSION : '',
            'phpVersion' => PHP_VERSION, 'sentAt' => date('Y-m-d H:i T', abj_clock()->now()),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $topCaptured
     * @param bool $rollupAvailable
     * @return string
     */
    private function buildDigestTableRows(array $topCaptured, bool $rollupAvailable): string {
        if (empty($topCaptured)) {
            $emptyMessage = $rollupAvailable
                ? esc_html__('No captured 404s in this period.', '404-solution')
                : esc_html__('Top URLs unavailable: log rollup is being rebuilt. Will be available in the next digest.', '404-solution');
            $emptyTemplate = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/emailDigestEmptyRow.html', false);
            return str_replace('{emptyMessage}', $emptyMessage, $emptyTemplate);
        }

        $rowTemplate = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/emailDigestTableRow.html', false);
        $tableRows = '';
        $rowIndex = 0;
        foreach ($topCaptured as $row) {
            $rowIndex++;
            $rawUrl  = isset($row['url']) && is_string($row['url']) ? $row['url'] : '';
            $urlText = esc_html($rawUrl);
            $hits    = isset($row['logshits']) ? intval(is_scalar($row['logshits']) ? $row['logshits'] : 0) : 0;
            $created = isset($row['created']) ? date('Y-m-d', intval(is_scalar($row['created']) ? $row['created'] : 0)) : '';

            $rowBg   = ($rowIndex % 2 === 0) ? '#f8fafc' : '#ffffff';

            if ($hits >= 100) {
                $badgeBg = '#fee2e2'; $badgeFg = '#dc2626';
            } elseif ($hits >= 20) {
                $badgeBg = '#fef3c7'; $badgeFg = '#d97706';
            } else {
                $badgeBg = '#f1f5f9'; $badgeFg = '#475569';
            }

            $tableRows .= str_replace(
                array('{rowBg}', '{urlText}', '{badgeBg}', '{badgeFg}', '{hits}', '{created}'),
                array($rowBg, $urlText, $badgeBg, $badgeFg, (string) $hits, esc_html($created)),
                $rowTemplate
            );
        }
        return $tableRows;
    }

    /**
     * @param int $resolved
     * @param int $totalAll
     * @return array<string, string>
     */
    private function getDigestTranslations(int $resolved, int $totalAll): array {
        return [
            'digest'      => esc_html__('404 Solution Digest', '404-solution'),
            'report'      => esc_html__('Digest Report', '404-solution'),
            'summary'     => esc_html__('Summary', '404-solution'),
            'captured'    => esc_html__('Captured', '404-solution'),
            'urls404'     => esc_html__('404 URLs', '404-solution'),
            'auto'        => esc_html__('Auto', '404-solution'),
            'redirected'  => esc_html__('Redirected', '404-solution'),
            'manual'      => esc_html__('Manual', '404-solution'),
            'configured'  => esc_html__('Configured', '404-solution'),
            'resolution'  => esc_html__('Resolution Rate', '404-solution'),
            'handled'     => sprintf(
                /* translators: 1: resolved count, 2: total count */
                esc_html__('%1$d of %2$d URLs handled', '404-solution'),
                $resolved,
                $totalAll
            ),
            'top_urls'    => esc_html__('Top Captured 404 URLs', '404-solution'),
            'url'         => esc_html__('URL', '404-solution'),
            'hits'        => esc_html__('Hits', '404-solution'),
            'first_seen'  => esc_html__('First Seen', '404-solution'),
            'view_cta'    => esc_html__('View Captured 404s', '404-solution'),
            'settings'    => esc_html__('Manage Settings', '404-solution'),
            'unsubscribe' => esc_html__('To stop these emails, update your notification settings.', '404-solution'),
            'manage'      => esc_html__('Manage settings', '404-solution'),
        ];
    }

    /**
     * Send the digest email. Returns a description of what happened.
     *
     * @return string
     */
    public function sendDigest(): string {
        $options = $this->getOptions();
        $frequency = $this->readFrequencyOption($options);

        if ($frequency === 'instant' || $frequency === 'never') {
            return 'Digest skipped: frequency is ' . $frequency . '.';
        }

        // Centralized cadence gate: sendDigest() is reachable from more than
        // one trigger (the dedicated abj404_send_digest WP-Cron event, and
        // the plugin's daily maintenance cron via
        // emailCaptured404Notification()). Without this check here, ANY
        // trigger firing more often than the configured frequency (e.g. the
        // daily maintenance cron running while frequency=weekly) sends a
        // digest every time it runs, regardless of what the admin selected.
        $cooldownSkip = $this->cooldownSkipMessage($frequency, $options);
        if ($cooldownSkip !== '') {
            return $cooldownSkip;
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
        $rollupAvailable = $this->logsRepo->logsHitsTableExists();
        if (!$rollupAvailable) {
            // Schedule a rebuild now so the next digest run has data.
            $this->logsRepo->scheduleHitsTableRebuild();
            $topCaptured = array();
        } else {
            $topCaptured = $this->statsRepo->getTopCapturedForDigest($limit);
        }
        $stats = $this->statsRepo->getDigestSummaryStats();

        // Skip the email entirely only when there is genuinely nothing to report
        // AND the rollup is healthy. If the rollup is unavailable but stats show
        // captured rows exist, ship the email with a "top URLs unavailable" note
        // so the admin learns about the rebuild rather than hearing silence.
        if ($rollupAvailable && intval($stats['total_captured']) === 0 && empty($topCaptured)) {
            return 'Digest skipped: no captured 404s to report.';
        }

        $dateRange = date('Y-m-d', abj_clock()->now());
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
        $sent = wp_mail($to, $subject, $body, $headers);

        if (!$sent) {
            // Do not stamp admin_notification_last_sent on a failed send:
            // that would suppress the cooldown gate's retry for up to a
            // week even though nothing actually went out. A hosting-level
            // mail failure is a warning (the plugin can still function),
            // not an error -- the next daily-maintenance-cron trigger
            // retries naturally since last_sent is unchanged.
            $this->logger->warn('404 digest email failed to send to: ' . $to . ' (wp_mail() reported failure).');
            return 'Digest email failed to send to: ' . $to;
        }

        $this->logger->debugMessage('404 digest email sent.');

        // Write through the options repository (into the bundled
        // abj404_settings option), NOT a bare update_option() call: this
        // must land in the SAME storage location cooldownSkipMessage()
        // reads via getOptions(), or the cooldown gate silently never
        // engages (the storage-mismatch half of WP.org support topic
        // weekly-digest-3 -- see EmailDigestCadenceRealStorageRoundTripTest).
        $lastSent = abj_clock()->now();
        abj_service('options_repository')->setRawSettingValue('admin_notification_last_sent', $lastSent);

        // Compatibility-window dual-write: 4.3.1 (already shipped to
        // production) persisted this same value as a STANDALONE WP option
        // via a bare update_option('admin_notification_last_sent', ...)
        // call. External integrations (other plugins, monitoring scripts,
        // site-owner custom code) may read that released contract via
        // get_option('admin_notification_last_sent'). The options_repository
        // write above does not touch that standalone option -- it lands
        // inside abj404_settings instead -- so without this second write,
        // any such external reader would silently stop receiving updates
        // after upgrading to 4.3.2+ (design-audit category 110 Contract
        // Compatibility finding). Keep both writes: the repository write is
        // load-bearing for this class's own cooldown gate, and this one
        // preserves the public contract for everyone else.
        if (function_exists('update_option')) {
            update_option('admin_notification_last_sent', $lastSent);
        }

        return 'Digest email sent to: ' . $to;
    }

    /**
     * Schedule the next digest send based on the frequency option.
     * Reschedules or clears WP-Cron as needed.
     *
     * The dedicated `abj404_send_digest` WP-Cron event always runs at a
     * fixed `daily` recurrence, regardless of the configured
     * admin_notification_frequency (daily/weekly). WP-Cron only fires
     * opportunistically on page loads with no guaranteed exact timing, so
     * tying this event's OWN recurrence to the desired send interval means
     * a single missed `weekly`-recurrence firing silently doubles the wait
     * to two weeks. cooldownSkipMessage() (called from sendDigest(), the
     * actual send boundary) is the single source of truth for cadence,
     * keyed off `admin_notification_last_sent`. A daily trigger just needs
     * to check in often enough that a missed firing costs at most a day,
     * never a week -- the same pattern LoggingFeedbackDispatcher uses for
     * its weekly heartbeat (frequent trigger, elapsed-time gate).
     *
     * @param string|null $frequencyOverride When provided, used instead of
     *     re-reading the option. Callers that just validated and are about
     *     to persist a new frequency value (e.g. SettingsNotificationPolicy)
     *     must pass it explicitly: the options repository write happens
     *     later in the same request, so a re-fetch here would read the
     *     stale pre-save value and could incorrectly clear/schedule against
     *     the wrong instant-vs-not state.
     * @return void
     */
    public function scheduleNextDigest(?string $frequencyOverride = null): void {
        $frequency = $frequencyOverride !== null ? $frequencyOverride : $this->readFrequencyOption($this->getOptions());

        $scheduler = abj_cron_scheduler();
        $hook = ABJ_404_Solution_CronScheduler::HOOK_SEND_DIGEST;

        if ($frequency === 'instant' || $frequency === 'never') {
            $scheduler->clearHook($hook);
            return;
        }

        abj_cron_recurrence_migration()->ensureDailyRecurrence($hook);
    }

    /** @param array<string, mixed> $options */
    private function readFrequencyOption(array $options): string {
        return isset($options['admin_notification_frequency']) && is_string($options['admin_notification_frequency'])
            ? $options['admin_notification_frequency']
            : 'instant';
    }

    /**
     * Returns a non-empty skip message when the configured cadence has not
     * yet elapsed since the last successful send, or '' when sending is
     * allowed. `admin_notification_last_sent` (written at the bottom of
     * sendDigest()) is the single source of truth for cadence enforcement,
     * independent of which caller/cron triggered this method.
     *
     * @param array<string, mixed> $options
     */
    private function cooldownSkipMessage(string $frequency, array $options): string {
        $intervalSeconds = $frequency === 'weekly'
            ? (defined('WEEK_IN_SECONDS') ? WEEK_IN_SECONDS : 604800)
            : (defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400);

        $lastSentRaw = isset($options['admin_notification_last_sent']) && is_scalar($options['admin_notification_last_sent'])
            ? $options['admin_notification_last_sent']
            : 0;
        $lastSent = intval($lastSentRaw);
        if ($lastSent <= 0) {
            return '';
        }

        $elapsed = abj_clock()->now() - $lastSent;
        if ($elapsed < $intervalSeconds) {
            return sprintf(
                'Digest skipped: last sent %d seconds ago; next %s digest eligible in %d seconds.',
                $elapsed,
                $frequency,
                $intervalSeconds - $elapsed
            );
        }

        return '';
    }

    /** @return array<string, mixed> */
    private function getOptions(): array {
        return abj_service('options_repository')->getOptions(true);
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
