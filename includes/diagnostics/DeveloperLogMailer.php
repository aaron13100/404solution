<?php


if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: covered by tests/LoggingTest.php public emailLogFileToDeveloper fallback tests.

/**
 * wp_mail fallback transport for developer error and heartbeat reports.
 *
 * Converts a FeedbackTransport payload into the legacy HTML email, attaches
 * a debug-log zip when one can be built, dispatches through wp_mail(), and
 * removes the temporary zip afterwards.
 */
class ABJ_404_Solution_DeveloperLogMailer {

    /** @var ABJ_404_Solution_ErrorEmailBodyFormatter */
    private $formatter;
    /** @var ABJ_404_Solution_DebugLogArchiveBuilder */
    private $archiveBuilder;
    /** @var callable */
    private $debugLogger;
    /** @var callable */
    private $errorLogger;

    /**
     * @param ABJ_404_Solution_ErrorEmailBodyFormatter $formatter
     * @param ABJ_404_Solution_DebugLogArchiveBuilder $archiveBuilder
     * @param callable $debugLogger Receives debug-message strings.
     * @param callable $errorLogger Receives error-message strings.
     */
    public function __construct(
        ABJ_404_Solution_ErrorEmailBodyFormatter $formatter,
        ABJ_404_Solution_DebugLogArchiveBuilder $archiveBuilder,
        callable $debugLogger,
        callable $errorLogger
    ) {
        $this->formatter = $formatter;
        $this->archiveBuilder = $archiveBuilder;
        $this->debugLogger = $debugLogger;
        $this->errorLogger = $errorLogger;
    }

    /**
     * Send the developer log email.
     *
     * @param array<string, mixed> $payload FeedbackTransport-built payload.
     * @param string $debugFilePath
     * @param string $oldDebugFilePath
     * @param string $zipFilePath
     * @param string $debugFilename
     * @return bool True if wp_mail() reported success.
     */
    public function send(
        array $payload,
        string $debugFilePath,
        string $oldDebugFilePath,
        string $zipFilePath,
        string $debugFilename
    ): bool {
        $previouslySentLine = isset($payload['previously_sent_line']) && is_scalar($payload['previously_sent_line'])
            ? (int)$payload['previously_sent_line'] : 0;
        call_user_func($this->debugLogger, "Creating zip file of error log file. " .
            "Previously sent error line: " . $previouslySentLine);

        $logFileZip = $this->archiveBuilder->build($zipFilePath, $debugFilePath, $oldDebugFilePath);
        $subject = $this->formatter->buildSubject($payload);
        $body = $this->formatter->buildBody($payload, $subject, $debugFilename);

        $to = ABJ404_AUTHOR_EMAIL;
        $headers = array('Content-Type: text/html; charset=UTF-8');
        $adminEmail = get_option('admin_email');
        $headers[] = 'From: ' . (is_scalar($adminEmail) ? (string)$adminEmail : '');

        $attachments = array();
        if ($logFileZip !== '' && file_exists($logFileZip)) {
            $attachments[] = $logFileZip;
        }

        call_user_func($this->debugLogger, "Sending error log zip file as attachment.");
        $result = wp_mail($to, $subject, $body, $headers, $attachments);

        if ($logFileZip !== '' && file_exists($logFileZip)) {
            ABJ_404_Solution_FileSystemService::safeUnlink($logFileZip);
        }
        if ((bool)$result) {
            call_user_func($this->debugLogger, "Mail sent. Log zip file deleted.");
        } else {
            call_user_func($this->errorLogger,
                "wp_mail() returned false while sending the developer error/heartbeat log email. " .
                "Recipient: " . $to . ". Subject: " . $subject);
        }
        return (bool)$result;
    }
}
