<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * File and directory I/O for the plugin: reading SQL templates and HTML
 * fragments, writing remote-downloaded payloads, and removing temp dirs.
 *
 * Extracted from ABJ_404_Solution_Functions per design-audit-2026-06-02
 * M201 (Functions.php grab-bag split). All methods are static and have
 * no dependency on the polymorphic mbstring adapter base class.
 *
 * Survival of transient stream-wrapper warnings (under Patchwork during
 * heavy ParaTest parallelism) is the responsibility of
 * fileGetContentsWithTransientRetry(), which retries up to
 * FILE_READ_MAX_ATTEMPTS on EINTR-style "Interrupted system call"
 * warnings before giving up and falling through to the curl fallback.
 */
class ABJ_404_Solution_FileSystemService {

    private const FILE_READ_MAX_ATTEMPTS = 3;
    private const FILE_READ_RETRY_BASE_US = 10000;
    public const CURL_FILE_READ_TIMEOUT_SECONDS = 5;

    /** @var callable(string,string,array<string,int|string|bool|null>,callable): mixed|null */
    private static $operationTracer = null;

    /** @param callable(string,string,array<string,int|string|bool|null>,callable): mixed|null $tracer */
    public static function setOperationTracer($tracer): void {
        self::$operationTracer = is_callable($tracer) ? $tracer : null;
    }

    /** Returns true if the file does not exist after calling this method.
     * @param string $path
     * @return boolean
     */
    static function safeUnlink($path) {
        if (!file_exists($path)) {
            return true;
        }

        $unlinkError = null;
        set_error_handler(static function($errno, $errstr) use (&$unlinkError) {
            $unlinkError = (string)$errstr;
            return true;
        });
        try {
            $result = unlink($path);
        } finally {
            restore_error_handler();
        }
        if ($result !== false) {
            return true;
        }

        clearstatcache(true, (string)$path);
        if (!file_exists($path)) {
            return true;
        }

        $reason = $unlinkError !== null
            ? $unlinkError
            : 'unknown unlink failure';
        self::logWarning('Unable to unlink file: ' . (string)$path . ' (' . $reason . ')');
        return false;
    }

    /** Recursively delete a directory.
     * @param string $dir
     * @throws Exception
     * @return boolean
     */
    static function deleteDirectoryRecursively($dir) {
    	// if the directory isn't a part of our plugin then don't do it.
    	if (strpos($dir, ABJ404_PATH) === false) {
    		throw new Exception("Can't delete " . esc_html($dir));
    	}

    	// if it's already gone then we're done.
    	if (!file_exists($dir)) {
    		return true;
    	}

    	// if it's not a directory then delete the file.
    	if (!is_dir($dir)) {
    		return unlink($dir);
    	}

    	// get a list of all files (and directories) in the directory.
    	$items = scandir($dir);
    	if (!is_array($items)) { $items = array(); }
    	foreach ($items as $item) {
    		if ($item == '.' || $item == '..') {
    			continue;
    		}

    		// call self to delete the file/directory.
    		if (!self::deleteDirectoryRecursively($dir . DIRECTORY_SEPARATOR . $item)) {
    			return false;
    		}

    	}

    	// remove the original directory.
    	return rmdir($dir);
    }

    /**
     * Create a directory (recursively), logging error_get_last() reasons on
     * failure. Returns true if the directory exists when this method
     * returns, false otherwise.
     *
     * @param string $directory
     * @return boolean
     */
    static function createDirectoryWithErrorMessages($directory) {
    	if (!is_dir($directory)) {
    		if (file_exists($directory) || file_exists(rtrim($directory, '/'))) {
    			$unlinkErr = null;
    			if (!@unlink($directory)) {
    				$lastErr = error_get_last();
    				$unlinkErr = is_array($lastErr) ? $lastErr['message'] : 'unknown';
    			}

    			if (file_exists($directory) || file_exists(rtrim($directory, '/'))) {
					self::logWarning("Error creating the directory " .
    						$directory . ". A file with that name already exists" .
    						($unlinkErr !== null ? " and unlink() failed: " . $unlinkErr : "") .
    						". Action: aborting directory creation, returning false.");
    				return false;
    			}

    		} else if (!@mkdir($directory, 0755, true)) {
    			$lastErr = error_get_last();
    			$mkdirErr = is_array($lastErr) ? $lastErr['message'] : 'unknown';
					self::logWarning("Error creating the directory " .
    					$directory . ". mkdir() failed: " . $mkdirErr .
    					". Action: aborting directory creation, returning false.");
    			return false;
    		}
    	}
    	return true;
    }

    /** Reads an entire file at once into a string and return it.
     * @param string $path
     * @param boolean $appendExtraData
     * @throws Exception
     * @return string
     */
    static function readFileContents($path, $appendExtraData = true) {
    	// modify what's returned to make debugging easier.
    	$dataSupplement = self::getDataSupplement($path, $appendExtraData);

        $exists = self::traceFileOperation(
            'stat',
            (string)$path,
            array(),
            static fn(): bool => file_exists($path)
        );
        if (!$exists) {
            throw new Exception("Error: Can't find file: " . esc_html($path));
        }

        $readResult = self::fileGetContentsWithTransientRetry($path);
        $fileContents = $readResult['contents'];
        if ($fileContents !== false) {
            if (!empty($readResult['warnings'])) {
                self::traceFileOperation(
                    'warning_log',
                    (string)$path,
                    array('warning_count' => count($readResult['warnings'])),
                    static function () use ($path, $readResult): void {
                        self::logWarning(
                            'readFileContents recovered after transient file-open failure for '
                            . $path . '. ' . self::formatFileReadWarnings($readResult['warnings'])
                        );
                    }
                );
            }
            return $dataSupplement['prefix'] . $fileContents . $dataSupplement['suffix'];
        }

        $warningDetails = self::formatFileReadWarnings($readResult['warnings']);

        // if we can't read the file that way then try curl.
        if (!function_exists('curl_init')) {
            throw new Exception("Error: Can't read file: " . esc_html($path) .
                    "\n   file_get_contents didn't work and curl is not installed." . $warningDetails);
        }
        $output = self::traceFileOperation(
            'curl_fallback',
            (string)$path,
            array('timeout_seconds' => self::CURL_FILE_READ_TIMEOUT_SECONDS),
            static function () use ($path) {
                $ch = curl_init();
                if ($ch === false) {
                    return false;
                }
                try {
                    curl_setopt($ch, CURLOPT_URL, 'file://' . $path);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CURL_FILE_READ_TIMEOUT_SECONDS);
                    curl_setopt($ch, CURLOPT_TIMEOUT, self::CURL_FILE_READ_TIMEOUT_SECONDS);
                    if (defined('CURLOPT_NOSIGNAL')) {
                        curl_setopt($ch, CURLOPT_NOSIGNAL, true);
                    }
                    return curl_exec($ch);
                } finally {
                    curl_close($ch);
                }
            }
        );

        if (!is_string($output)) {
            throw new Exception("Error: Can't read file, even with cURL: " . esc_html($path) . $warningDetails);
        }

        if ($warningDetails !== '') {
            self::traceFileOperation(
                'warning_log',
                (string)$path,
                array('warning_count' => count($readResult['warnings'])),
                static function () use ($path, $warningDetails): void {
                    self::logWarning(
                        'readFileContents used cURL fallback after file_get_contents failed for '
                        . $path . '. ' . $warningDetails
                    );
                }
            );
        }

        return $dataSupplement['prefix'] . $output . $dataSupplement['suffix'];
    }

    /**
     * Reads a file while retrying only the transient EINTR-style failures
     * emitted by Patchwork's stream wrapper under high ParaTest load.
     *
     * @param string $path
     * @return array{contents:string|false,warnings:array<int,array{errno:int,message:string,file:string,line:int}>}
     */
    private static function fileGetContentsWithTransientRetry($path): array {
        $warnings = array();

        for ($attempt = 1; $attempt <= self::FILE_READ_MAX_ATTEMPTS; $attempt++) {
            $attemptWarnings = array();

            set_error_handler(
                static function(int $errno, string $errstr, string $errfile = '', int $errline = 0) use (&$attemptWarnings): bool {
                    if (($errno & (E_WARNING | E_USER_WARNING)) === 0) {
                        return false;
                    }
                    $attemptWarnings[] = array(
                        'errno' => $errno,
                        'message' => $errstr,
                        'file' => $errfile,
                        'line' => $errline,
                    );
                    return true;
                },
                E_WARNING | E_USER_WARNING
            );
            try {
                $contents = self::traceFileOperation(
                    'read_attempt',
                    (string)$path,
                    array('attempt' => $attempt),
                    static fn() => file_get_contents($path)
                );
            } finally {
                restore_error_handler();
            }

            if ($contents !== false) {
                return array(
                    'contents' => $contents,
                    'warnings' => array_merge($warnings, $attemptWarnings),
                );
            }

            $warnings = array_merge($warnings, $attemptWarnings);
            if (!self::isTransientFileReadWarning($attemptWarnings)) {
                break;
            }

            if ($attempt < self::FILE_READ_MAX_ATTEMPTS) {
                $delayUs = self::FILE_READ_RETRY_BASE_US * $attempt;
                self::traceFileOperation(
                    'retry_wait',
                    (string)$path,
                    array('attempt' => $attempt, 'delay_us' => $delayUs),
                    static fn() => usleep($delayUs)
                );
            }
        }

        return array(
            'contents' => false,
            'warnings' => $warnings,
        );
    }

    /**
     * @param array<int,array{errno:int,message:string,file:string,line:int}> $warnings
     * @return bool
     */
    private static function isTransientFileReadWarning(array $warnings): bool {
        foreach ($warnings as $warning) {
            if (strpos(strtolower($warning['message']), 'interrupted system call') !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int,array{errno:int,message:string,file:string,line:int}> $warnings
     * @return string
     */
    private static function formatFileReadWarnings(array $warnings): string {
        if (empty($warnings)) {
            return '';
        }

        $parts = array();
        foreach ($warnings as $warning) {
            $parts[] = sprintf(
                '[%d] %s in %s:%d',
                $warning['errno'],
                $warning['message'],
                $warning['file'],
                $warning['line']
            );
        }

        return "\n   file_get_contents warnings: " . implode(' | ', $parts);
    }

    /**
     * Build the BEGIN/END banner pair that brackets file contents in the
     * debug-log dump so an admin reading a debug bundle can see where each
     * embedded artifact starts and ends. SQL gets /* ... *\/ comments;
     * HTML gets HTML comments; other types get a generic marker.
     *
     * @param string $filePath
     * @param bool $appendExtraData
     * @return array<string, string>
     */
    private static function getDataSupplement(string $filePath, bool $appendExtraData = true): array {
        $path = strtolower($filePath);

        // remove the first part of the path because some people don't want to see
        // it in the log file.
        $homepath = (string) dirname(ABSPATH);
        $beginningOfPath = (string) substr($path, 0, strlen($homepath));
        if (strtolower($beginningOfPath) === strtolower($homepath)) {
            $path = (string) substr($path, strlen($homepath));
        }

        $supplement = array();

        if (!$appendExtraData) {
        	$supplement['prefix'] = '';
        	$supplement['suffix'] = '';

        } else if (self::endsWithCaseInsensitive($path, '.sql')) {
            $supplement['prefix'] = "\n/* ------------------ " . $filePath . " BEGIN ----- */ \n";
            $supplement['suffix'] = "\n/* ------------------ " . $filePath . " END ----- */ \n";

        } else if (self::endsWithCaseInsensitive($path, '.html')) {
            $supplement['prefix'] = "\n<!-- ------------------ " . $filePath . " BEGIN ----- --> \n";
            $supplement['suffix'] = "\n<!-- ------------------ " . $filePath . " END ----- --> \n";

        } else {
            $supplement['prefix'] = "\n/* ------------------ " . $filePath . " BEGIN unknown file type in "
                    . __CLASS__ . '::' . __FUNCTION__ . "() ----- */ \n";
            $supplement['suffix'] = "\n/* ------------------ " . $filePath . " END unknown file type in "
                    . __CLASS__ . '::' . __FUNCTION__ . "() ----- */ \n";
        }

        return $supplement;
    }

    private static function endsWithCaseInsensitive(string $haystack, string $needle): bool {
        $length = strlen($needle);
        if (strlen($haystack) < $length) {
            return false;
        }
        return strtolower(substr($haystack, -$length)) === strtolower($needle);
    }

    private static function logWarning(string $message): void {
        $logger = function_exists('abj_service_optional') ? abj_service_optional('logging') : null;
        if (is_object($logger) && method_exists($logger, 'warn')) {
            $logger->warn($message);
            return;
        }

        abj404_logPhpFallback('service-resolution-fallback', $message);
    }

    /** @template T
     * @param array<string, int|string|bool|null> $fields
     * @param callable(): T $work
     * @return T */
    private static function traceFileOperation(
        string $operation,
        string $path,
        array $fields,
        callable $work
    ) {
        if (!is_callable(self::$operationTracer)) {
            return $work();
        }
        return call_user_func(self::$operationTracer, $operation, $path, $fields, $work);
    }
}
