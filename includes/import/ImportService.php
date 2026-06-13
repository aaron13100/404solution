<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Owns upload-level redirect-import orchestration.
 *
 * This service validates the uploaded file, streams it through
 * ImportCsvParser, delegates each canonical row to ImportRedirectRowProcessor,
 * and coordinates ImportProgressStore checkpoints so interrupted imports can
 * resume by re-uploading the same file content.
 */
class ABJ_404_Solution_ImportService {

    const IMPORT_PROGRESS_OPTION = 'abj404_import_progress';
    const IMPORT_PROGRESS_CHECKPOINT_INTERVAL = 50;

    /** @var ABJ_404_Solution_RedirectsRepositoryInterface */
    private $redirectsRepository;

    /** @var ABJ_404_Solution_ContentRepositoryInterface */
    private $contentRepository;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_ImportCsvParser */
    private $parser;

    /** @var ABJ_404_Solution_ImportProgressStore */
    private $progressStore;

    /** @var ABJ_404_Solution_ImportUploadValidator */
    private $uploadValidator;

    /** @var ABJ_404_Solution_ImportRedirectRowProcessor */
    private $rowProcessor;

    /**
     * Constructor supports two signatures for backward compatibility:
     *   (1) New: (RedirectsRepository, ContentRepository, Logging)
     *   (2) Legacy: (DataAccess, Logging) -- DataAccess delegates to modules
     *
     * @param mixed $redirectsRepoOrDataAccess RedirectsRepository or legacy DataAccess facade
     * @param mixed $contentRepoOrLogging ContentRepository or legacy Logging
     * @param ABJ_404_Solution_Logging|null $logging
     */
    function __construct($redirectsRepoOrDataAccess, $contentRepoOrLogging, $logging = null) {
        if ($logging === null) {
            /** @var ABJ_404_Solution_RedirectsRepositoryInterface $redirectsRepoOrDataAccess */
            $this->redirectsRepository = $redirectsRepoOrDataAccess;
            $this->contentRepository = (is_object($redirectsRepoOrDataAccess) && method_exists($redirectsRepoOrDataAccess, 'getContentRepo'))
                ? $redirectsRepoOrDataAccess->getContentRepo()
                : $redirectsRepoOrDataAccess;
            /** @var ABJ_404_Solution_Logging $contentRepoOrLogging */
            $this->logger = $contentRepoOrLogging;
        } else {
            /** @var ABJ_404_Solution_RedirectsRepositoryInterface $redirectsRepoOrDataAccess */
            $this->redirectsRepository = $redirectsRepoOrDataAccess;
            /** @var ABJ_404_Solution_ContentRepositoryInterface $contentRepoOrLogging */
            $this->contentRepository = $contentRepoOrLogging;
            $this->logger = $logging;
        }

        $this->parser = new ABJ_404_Solution_ImportCsvParser();
        $this->progressStore = new ABJ_404_Solution_ImportProgressStore(self::IMPORT_PROGRESS_OPTION);
        $this->uploadValidator = new ABJ_404_Solution_ImportUploadValidator();
        $this->rowProcessor = new ABJ_404_Solution_ImportRedirectRowProcessor(
            $this->redirectsRepository,
            $this->contentRepository,
            $this->logger
        );
    }

    /**
     * Expected formats:
     * - from_url,status,type,to_url,wp_type
     * - from_url,to_url
     *
     * @return string
     */
    function doImportFile(): string {
        $uploadFile = $_FILES['import_file'] ?? null;
        if (!is_array($uploadFile) ||
                !isset($uploadFile['error']) ||
                !is_numeric($uploadFile['error']) ||
                (int)$uploadFile['error'] !== UPLOAD_ERR_OK) {
            return __('File upload error.', '404-solution');
        }

        $dryRun = isset($_POST['dry_run']) && sanitize_text_field((string)$_POST['dry_run']) === '1';
        $overwriteExisting = isset($_POST['overwrite_existing']) && sanitize_text_field((string)$_POST['overwrite_existing']) === '1';

        $validationError = $this->uploadValidator->validate($uploadFile);
        if ($validationError !== '') {
            return $validationError;
        }

        $tmpName = $this->uploadValidator->tmpName($uploadFile);
        $file_handle = fopen($tmpName, 'r');
        if (!$file_handle) {
            return __('Error opening the file.', '404-solution');
        }

        $hashResult = hash_file('sha256', $tmpName);
        $contentHash = ($dryRun || !is_string($hashResult)) ? '' : $hashResult;
        $runState = $this->applyResumeProgress($contentHash, $dryRun, $this->emptyRunState());

        $delimiter = $this->parser->detectCsvDelimiterFromFile($file_handle);
        rewind($file_handle);
        $runState = $this->processFileRows(
            $file_handle,
            $delimiter,
            $this->stateInt($runState, 'resume_from'),
            $dryRun,
            $overwriteExisting,
            $contentHash,
            $runState
        );
        fclose($file_handle);

        if (isset($runState['abort_message']) && is_string($runState['abort_message'])) {
            return $runState['abort_message'];
        }

        if (!$dryRun) {
            $this->progressStore->clearImportProgress();
        }

        return $this->formatImportResult($runState, $dryRun, $overwriteExisting);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyRunState(): array {
        return array(
            'issues' => array(),
            'processed' => 0,
            'valid' => 0,
            'invalid' => 0,
            'overwritten' => 0,
            'resume_from' => 0,
        );
    }

    /**
     * @param string $contentHash
     * @param bool $dryRun
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function applyResumeProgress(string $contentHash, bool $dryRun, array $state): array {
        if ($dryRun) {
            return $state;
        }

        $existingProgress = $this->progressStore->getResumeProgress($contentHash);
        if ($existingProgress === null) {
            return $state;
        }

        $resumeFrom = ABJ_404_Solution_ImportProgressStore::progressInt($existingProgress, 'rows_processed', 0);
        $state['resume_from'] = $resumeFrom;
        $state['processed'] = ABJ_404_Solution_ImportProgressStore::progressInt($existingProgress, 'processed_count', $resumeFrom);
        $state['valid'] = ABJ_404_Solution_ImportProgressStore::progressInt($existingProgress, 'valid_count', 0);
        $state['invalid'] = ABJ_404_Solution_ImportProgressStore::progressInt($existingProgress, 'invalid_count', 0);
        $state['overwritten'] = ABJ_404_Solution_ImportProgressStore::progressInt($existingProgress, 'overwritten_count', 0);
        if (isset($existingProgress['issues']) && is_array($existingProgress['issues'])) {
            /** @var array<int, string> $persistedIssues */
            $persistedIssues = $existingProgress['issues'];
            $state['issues'] = $persistedIssues;
        }
        return $state;
    }

    /**
     * @param resource $fileHandle
     * @param string $delimiter
     * @param int $resumeFromDataRow
     * @param bool $dryRun
     * @param bool $overwriteExisting
     * @param string $contentHash
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function processFileRows($fileHandle, string $delimiter, int $resumeFromDataRow,
                                     bool $dryRun, bool $overwriteExisting, string $contentHash,
                                     array $state): array {
        $headerColumns = null;
        $dataRowsSeen = 0;
        while (($row = fgetcsv($fileHandle, 0, $delimiter, '"', '\\')) !== false) {
            $data = $this->normalizeCsvRow($row);

            if ($this->isBlankCsvRow($data)) {
                continue;
            }

            if ($headerColumns === null && $this->parser->isCompatibleImportHeaderRow($data)) {
                $headerColumns = $this->parser->normalizeImportHeaders($data);
                continue;
            }

            if ($dataRowsSeen < $resumeFromDataRow) {
                $dataRowsSeen++;
                continue;
            }

            $dataArray = $this->mapCsvDataRow($data, $headerColumns);
            if (isset($dataArray['error'])) {
                $state['abort_message'] = $dataArray['error'];
                return $state;
            }

            if ($this->isRepeatedHeaderDataRow($dataArray)) {
                $dataRowsSeen++;
                continue;
            }

            $state = $this->processDataArray(
                $dataArray, $dataRowsSeen, $dryRun, $overwriteExisting, $contentHash, $state
            );
            $dataRowsSeen++;

            if (!$dryRun && ($dataRowsSeen % self::IMPORT_PROGRESS_CHECKPOINT_INTERVAL) === 0) {
                $this->persistCheckpoint($contentHash, $dataRowsSeen, $state);
            }
            if (isset($state['abort_message'])) {
                return $state;
            }
        }

        return $state;
    }

    /**
     * @param array<int, string|null>|false|null $row
     * @return array<int, string>
     */
    private function normalizeCsvRow($row): array {
        if (!is_array($row)) {
            return array();
        }
        return array_map(function($v) {
            return trim((string)$v);
        }, $row);
    }

    /** @param array<int, string> $data @return bool */
    private function isBlankCsvRow(array $data): bool {
        return count($data) === 1 && $data[0] === '';
    }

    /**
     * @param array<int, string> $data
     * @param array<int, string|null>|null $headerColumns
     * @return array<string, string>
     */
    private function mapCsvDataRow(array $data, $headerColumns): array {
        return $headerColumns !== null
            ? $this->parser->mapImportRowByHeaders($data, $headerColumns)
            : $this->parser->mapImportRowWithoutHeaders($data);
    }

    /**
     * @param array<string, string> $dataArray
     * @return bool
     */
    private function isRepeatedHeaderDataRow(array $dataArray): bool {
        return isset($dataArray['from_url']) &&
            ($dataArray['from_url'] === 'from_url' || $dataArray['from_url'] === 'request');
    }

    /**
     * @param array<string, string> $dataArray
     * @param int $dataRowsSeen
     * @param bool $dryRun
     * @param bool $overwriteExisting
     * @param string $contentHash
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function processDataArray(array $dataArray, int $dataRowsSeen, bool $dryRun,
                                      bool $overwriteExisting, string $contentHash,
                                      array $state): array {
        try {
            $state['processed'] = $this->stateInt($state, 'processed') + 1;
            $wasOverwrite = $this->wouldOverwriteExisting($dataArray, $overwriteExisting);
            $dataArray['__line_number'] = (string)($dataRowsSeen + 1);
            $issues = $this->rowProcessor->loadDataArrayFromFile($dataArray, $dryRun, $overwriteExisting);
            return $this->accountForRowIssues($state, $issues, $wasOverwrite);
        } catch (\Throwable $e) {
            $this->recordPausedImport($contentHash, $dataRowsSeen, $state, $dryRun, $e);
            $state['abort_message'] = sprintf(
                __('Import paused at row %1$d. %2$d redirect(s) imported so far. Re-upload the same file to resume from row %1$d.', '404-solution'),
                $dataRowsSeen + 1,
                $this->stateInt($state, 'valid')
            );
            return $state;
        }
    }

    /**
     * @param array<string, string> $dataArray
     * @param bool $overwriteExisting
     * @return bool
     */
    private function wouldOverwriteExisting(array $dataArray, bool $overwriteExisting): bool {
        if (!$overwriteExisting || !isset($dataArray['from_url']) || !is_string($dataArray['from_url'])) {
            return false;
        }
        $existing = $this->redirectsRepository->getExistingRedirectForURL($dataArray['from_url']);
        return is_array($existing) && isset($existing['id']) && is_numeric($existing['id']) && (int)$existing['id'] !== 0;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<int, string> $issues
     * @param bool $wasOverwrite
     * @return array<string, mixed>
     */
    private function accountForRowIssues(array $state, array $issues, bool $wasOverwrite): array {
        if (count($issues) > 0) {
            $state['invalid'] = $this->stateInt($state, 'invalid') + 1;
        } else {
            $state['valid'] = $this->stateInt($state, 'valid') + 1;
            if ($wasOverwrite) {
                $state['overwritten'] = $this->stateInt($state, 'overwritten') + 1;
            }
        }
        $state['issues'] = array_merge($this->stateIssues($state), $issues);
        return $state;
    }

    /**
     * @param string $contentHash
     * @param int $dataRowsSeen
     * @param array<string, mixed> $state
     * @param bool $dryRun
     * @param Throwable $e
     * @return void
     */
    private function recordPausedImport(string $contentHash, int $dataRowsSeen, array $state,
                                        bool $dryRun, \Throwable $e): void {
        if (!$dryRun) {
            $this->progressStore->persistImportProgress($contentHash, array(
                'rows_processed'    => $dataRowsSeen,
                'processed_count'   => max(0, $this->stateInt($state, 'processed') - 1),
                'valid_count'       => $this->stateInt($state, 'valid'),
                'invalid_count'     => $this->stateInt($state, 'invalid'),
                'overwritten_count' => $this->stateInt($state, 'overwritten'),
                'issues'            => $this->stateIssues($state),
                'last_error'        => $e->getMessage(),
                'paused_at'         => abj_clock()->now(),
            ));
        }
        $this->logger->warn(sprintf(
            'Import paused at row %d of %d due to: %s',
            $dataRowsSeen + 1,
            $dataRowsSeen + 1,
            $e->getMessage()
        ));
    }

    /**
     * @param string $contentHash
     * @param int $dataRowsSeen
     * @param array<string, mixed> $state
     * @return void
     */
    private function persistCheckpoint(string $contentHash, int $dataRowsSeen, array $state): void {
        $this->progressStore->persistImportProgress($contentHash, array(
            'rows_processed'    => $dataRowsSeen,
            'processed_count'   => $this->stateInt($state, 'processed'),
            'valid_count'       => $this->stateInt($state, 'valid'),
            'invalid_count'     => $this->stateInt($state, 'invalid'),
            'overwritten_count' => $this->stateInt($state, 'overwritten'),
            'issues'            => $this->stateIssues($state),
        ));
    }

    /**
     * @param array<string, mixed> $state
     * @param bool $dryRun
     * @param bool $overwriteExisting
     * @return string
     */
    private function formatImportResult(array $state, bool $dryRun, bool $overwriteExisting): string {
        $issues = $this->stateIssues($state);
        if ($dryRun) {
            $msg = sprintf(
                __('Dry run complete. Valid redirects: %d. Invalid rows: %d. Total rows processed: %d.', '404-solution'),
                $this->stateInt($state, 'valid'),
                $this->stateInt($state, 'invalid'),
                $this->stateInt($state, 'processed')
            );
            return count($issues) > 0
                ? $msg . ' ' . __('Preview issues:', '404-solution') . ' ' . implode(", <BR/>\n", array_slice($issues, 0, 20))
                : $msg;
        }
        if (count($issues) > 0) {
            return __('Error:', '404-solution') . ' ' . implode(", <BR/>\n", $issues);
        }
        if ($overwriteExisting && $this->stateInt($state, 'overwritten') > 0) {
            return sprintf(
                __('The file seems to have loaded okay. %d existing redirect(s) were overwritten. Please check the redirects page.', '404-solution'),
                $this->stateInt($state, 'overwritten')
            );
        }
        return __('The file seems to have loaded okay. Please check the redirects page.', '404-solution');
    }

    /**
     * @param array<string, mixed> $state
     * @param string $key
     * @return int
     */
    private function stateInt(array $state, string $key): int {
        $value = $state[$key] ?? 0;
        return is_numeric($value) ? (int)$value : 0;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<int, string>
     */
    private function stateIssues(array $state): array {
        $issues = $state['issues'] ?? array();
        return is_array($issues) ? array_values(array_filter($issues, 'is_string')) : array();
    }

    /**
     * @param array<string, mixed> $dataArray
     * @param bool $dryRun
     * @param bool $overwriteExisting
     * @return array<int, string>
     */
    function loadDataArrayFromFile($dataArray, $dryRun = false, $overwriteExisting = false): array {
        return $this->rowProcessor->loadDataArrayFromFile($dataArray, $dryRun, $overwriteExisting);
    }

    /**
     * @param mixed $line
     * @return array<string, string>
     */
    function splitCsvLine($line): array {
        return $this->parser->splitCsvLine($line);
    }

    /** @param array<int, string> $columns @return bool */
    function isCompatibleImportHeaderRow($columns): bool {
        return $this->parser->isCompatibleImportHeaderRow($columns);
    }

    /**
     * @param array<int, string> $columns
     * @return array<int, string|null>
     */
    function normalizeImportHeaders($columns): array {
        return $this->parser->normalizeImportHeaders($columns);
    }

    /** @param array<int, string> $columns @return string */
    function detectImportFormatFromHeaders($columns): string {
        return $this->parser->detectImportFormatFromHeaders($columns);
    }

    /**
     * @param array<int, string> $row
     * @param array<int, string|null> $normalizedHeaders
     * @return array<string, string>
     */
    function mapImportRowByHeaders($row, $normalizedHeaders): array {
        return $this->parser->mapImportRowByHeaders($row, $normalizedHeaders);
    }

    /**
     * @param array<int, string> $columns
     * @return array<string, string>
     */
    function mapImportRowWithoutHeaders($columns): array {
        return $this->parser->mapImportRowWithoutHeaders($columns);
    }

    /** @param resource $fileHandle @return string */
    function detectCsvDelimiterFromFile($fileHandle): string {
        return $this->parser->detectCsvDelimiterFromFile($fileHandle);
    }
}
