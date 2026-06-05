<?php


if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: covered by tests/LoggingTest.php through public ABJ_404_Solution_Logging message entry points.

/**
 * Formats and writes severity-tagged debug log messages.
 *
 * Owns the DEBUG/INFO/WARN/ERROR line shapes and the stored-debug-message
 * buffer used when debug mode is disabled. It deliberately receives its time,
 * debug-mode, and file-write dependencies as callables so the public
 * ABJ_404_Solution_Logging facade remains the only production entry point.
 */
class ABJ_404_Solution_LoggingMessageWriter {

    /** @var callable */
    private $timestampProvider;
    /** @var callable */
    private $debugModeProvider;
    /** @var callable */
    private $lineWriter;
    /** @var array<int, string> */
    private $storedDebugMessages;

    /**
     * @param callable $timestampProvider Returns the formatted current timestamp.
     * @param callable $debugModeProvider Returns true when debug mode is enabled.
     * @param callable $lineWriter Receives one fully-formatted log line.
     * @param array<int, string> $storedDebugMessages Shared debug buffer from the facade.
     */
    public function __construct(
        callable $timestampProvider,
        callable $debugModeProvider,
        callable $lineWriter,
        array &$storedDebugMessages
    ) {
        $this->timestampProvider = $timestampProvider;
        $this->debugModeProvider = $debugModeProvider;
        $this->lineWriter = $lineWriter;
        $this->storedDebugMessages =& $storedDebugMessages;
    }

    /**
     * Write immediately when debug mode is enabled; otherwise buffer for the
     * next error log entry.
     *
     * @param string $message
     * @param \Throwable|null $e
     * @return void
     */
    public function debugMessage(string $message, $e = null): void {
        $stacktrace = "";
        if ($e != null) {
            $stacktrace = ", Stacktrace: " . $e->getTraceAsString();
        }

        $line = $this->timestampPrefix('DEBUG') . $message . $stacktrace;
        if ((bool)call_user_func($this->debugModeProvider)) {
            $this->writeLine($line);
            return;
        }

        $this->storedDebugMessages[] = $line;
    }

    /**
     * @param string $message
     * @return void
     */
    public function infoMessage(string $message): void {
        $this->writeLine($this->timestampPrefix('INFO') . $message);
    }

    /**
     * @param string $message
     * @return void
     */
    public function warn(string $message): void {
        $this->writeLine($this->timestampPrefix('WARN') . $message);
    }

    /**
     * Flush any debug messages buffered while debug mode was disabled, then
     * write the ERROR line with request/plugin context.
     *
     * @param string $message
     * @param \Exception|null $e
     * @return void
     */
    public function errorMessage(string $message, $e = null): void {
        if ($e == null) {
            $e = new Exception;
        }
        $stacktrace = $e->getTraceAsString();

        $savedDebugMessages = implode("\n", $this->storedDebugMessages);
        $this->storedDebugMessages = array();

        $referrer = '';
        if (array_key_exists('HTTP_REFERER', $_SERVER) && !empty($_SERVER['HTTP_REFERER'])) {
            $referrer = $_SERVER['HTTP_REFERER'];
        }
        $requestedURL = '';
        if (array_key_exists('REQUEST_URI', $_SERVER) && !empty($_SERVER['REQUEST_URI'])) {
            $requestedURL = $_SERVER['REQUEST_URI'];
        }

        $this->writeLine($this->timestampPrefix('ERROR') . $message . ", PHP version: " . PHP_VERSION .
            ", WP ver: " . get_bloginfo('version') . ", Plugin ver: " . ABJ404_VERSION .
            ", Referrer: " . $referrer . ", Requested URL: " . $requestedURL .
            ", \nStored debug messages: \n" . $savedDebugMessages . ", \nTrace: " . $stacktrace);
    }

    /**
     * @param string $level
     * @return string
     */
    private function timestampPrefix(string $level): string {
        return (string)call_user_func($this->timestampProvider) . ' (' . $level . '): ';
    }

    /**
     * @param string $line
     * @return void
     */
    private function writeLine(string $line): void {
        call_user_func($this->lineWriter, $line);
    }
}
