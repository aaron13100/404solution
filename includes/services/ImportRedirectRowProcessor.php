<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validates and persists one canonical redirect-import row.
 */
class ABJ_404_Solution_ImportRedirectRowProcessor {

    /** @var ABJ_404_Solution_RedirectsRepositoryInterface */
    private $redirectsRepository;

    /** @var ABJ_404_Solution_ContentRepositoryInterface */
    private $contentRepository;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_RedirectsRepositoryInterface $redirectsRepository
     * @param ABJ_404_Solution_ContentRepositoryInterface $contentRepository
     */
    function __construct($redirectsRepository, $contentRepository, ABJ_404_Solution_Logging $logger) {
        $this->redirectsRepository = $redirectsRepository;
        $this->contentRepository = $contentRepository;
        $this->logger = $logger;
    }

    /**
     * @param array<string, mixed> $dataArray
     * @param bool $dryRun
     * @param bool $overwriteExisting
     * @return array<int, string>
     */
    function loadDataArrayFromFile($dataArray, $dryRun = false, $overwriteExisting = false): array {
        $fromURL = isset($dataArray['from_url']) && is_string($dataArray['from_url']) ? $dataArray['from_url'] : '';
        if ($fromURL === 'from_url' || $fromURL === 'request') {
            return array();
        }

        $explicitRegex = $this->isExplicitRegexRow($dataArray);
        $source = $this->resolveSourceAndStatus($fromURL, $explicitRegex);
        $fromURL = $source['from_url'];
        $status = $source['status'];
        $final_dest = isset($dataArray['to_url']) && is_string($dataArray['to_url']) ? $dataArray['to_url'] : '';

        $regexIssues = $this->regexValidationIssues($dataArray, $fromURL, $explicitRegex);
        if (count($regexIssues) > 0) {
            return $regexIssues;
        }

        $existingId = $this->existingRedirectId($fromURL);
        $duplicateIssues = $this->duplicateIssues($existingId, $overwriteExisting, $fromURL);
        if (count($duplicateIssues) > 0) {
            return $duplicateIssues;
        }

        $destination = $this->resolveDestination($final_dest);
        if (count($destination['issues']) > 0) {
            return $destination['issues'];
        }

        if (!$dryRun) {
            $this->writeRedirect($dataArray, $existingId, $overwriteExisting,
                $fromURL, $status, $destination['type'], $destination['final_dest']);
        }

        return array();
    }

    /**
     * @param string $fromURL
     * @param bool $explicitRegex
     * @return array{from_url: string, status: int}
     */
    private function resolveSourceAndStatus(string $fromURL, bool $explicitRegex): array {
        $status = $explicitRegex ? ABJ404_STATUS_REGEX : ABJ404_STATUS_MANUAL;
        if (!$explicitRegex &&
                ABJ_404_Solution_RegexAutoPromote::looksLikeUnambiguousRegex($fromURL)) {
            $status = ABJ404_STATUS_REGEX;
            $glob = ABJ_404_Solution_RegexAutoPromote::applyGlobFixup($fromURL);
            $fromURL = $glob['url'];
        }
        return array('from_url' => $fromURL, 'status' => (int)$status);
    }

    /**
     * @param array<string, mixed> $dataArray
     * @param string $fromURL
     * @param bool $explicitRegex
     * @return array<int, string>
     */
    private function regexValidationIssues(array $dataArray, string $fromURL, bool $explicitRegex): array {
        if (!$explicitRegex) {
            return array();
        }
        $patternError = $this->validateRegexPattern($fromURL);
        if ($patternError === '') {
            return array();
        }

        $lineNumber = $this->intFromArray($dataArray, '__line_number');
        $msg = $lineNumber > 0
            ? sprintf(__('Invalid regex pattern at line %d: %s (%s)', '404-solution'),
                $lineNumber, $fromURL, $patternError)
            : sprintf(__('Invalid regex pattern: %s (%s)', '404-solution'),
                $fromURL, $patternError);
        $this->logger->warn($msg);
        return array($msg);
    }

    /**
     * @param string $fromURL
     * @return int
     */
    private function existingRedirectId(string $fromURL): int {
        $existing = $this->redirectsRepository->getExistingRedirectForURL($fromURL);
        if (!is_array($existing) || !isset($existing['id']) || !is_numeric($existing['id'])) {
            return 0;
        }
        return (int)$existing['id'];
    }

    /**
     * @param int $existingId
     * @param bool $overwriteExisting
     * @param string $fromURL
     * @return array<int, string>
     */
    private function duplicateIssues(int $existingId, bool $overwriteExisting, string $fromURL): array {
        if ($existingId === 0 || $overwriteExisting) {
            return array();
        }
        $msg = __('Ignored importing redirect because a redirect with the same from URL already exists. URL:', '404-solution') . ' ' . $fromURL;
        $this->logger->warn($msg);
        return array($msg);
    }

    /**
     * @param string $finalDest
     * @return array{type: int, final_dest: string|int, issues: array<int, string>}
     */
    private function resolveDestination(string $finalDest): array {
        if ($finalDest === '') {
            return array('type' => ABJ404_TYPE_404_DISPLAYED, 'final_dest' => ABJ404_TYPE_404_DISPLAYED, 'issues' => array());
        }
        if ($finalDest === '5') {
            return array('type' => ABJ404_TYPE_HOME, 'final_dest' => ABJ404_TYPE_HOME, 'issues' => array());
        }
        if (strpos($finalDest, 'http') !== false) {
            return array('type' => ABJ404_TYPE_EXTERNAL, 'final_dest' => $finalDest, 'issues' => array());
        }
        if (strpos($finalDest, '/') !== 0) {
            $msg = __('Unrecognized destination type while importing file. Destination:', '404-solution') . ' ' . $finalDest;
            $this->logger->warn($msg);
            return array('type' => ABJ404_TYPE_EXTERNAL, 'final_dest' => $finalDest, 'issues' => array($msg));
        }

        return $this->resolveSlugDestination($finalDest);
    }

    /**
     * @param string $finalDest
     * @return array{type: int, final_dest: string|int, issues: array<int, string>}
     */
    private function resolveSlugDestination(string $finalDest): array {
        $slug = trim($finalDest, '/');
        $postsFromSlugRows = $this->contentRepository->getPublishedPagesAndPostsIDs($slug);
        $postsFromCategoryRows = $this->contentRepository->getPublishedCategories(null, $slug);
        $postsFromTagRows = $this->contentRepository->getPublishedTags($slug);

        /** @var object{id?: int|string, term_id?: int|string}|null $postFromSlug */
        $postFromSlug = isset($postsFromSlugRows[0]) ? $postsFromSlugRows[0] : null;
        /** @var object{term_id?: int|string}|null $postFromCategory */
        $postFromCategory = isset($postsFromCategoryRows[0]) ? $postsFromCategoryRows[0] : null;
        /** @var object{term_id?: int|string}|null $postFromTag */
        $postFromTag = isset($postsFromTagRows[0]) ? $postsFromTagRows[0] : null;

        if ($postFromSlug && isset($postFromSlug->id)) {
            return array('type' => $this->typeConstant('ABJ404_TYPE_POST', 1),
                'final_dest' => (string)$postFromSlug->id, 'issues' => array());
        }
        if ($postFromCategory && isset($postFromCategory->term_id)) {
            return array('type' => $this->typeConstant('ABJ404_TYPE_CAT', 2),
                'final_dest' => (string)$postFromCategory->term_id, 'issues' => array());
        }
        if ($postFromTag && isset($postFromTag->term_id)) {
            return array('type' => $this->typeConstant('ABJ404_TYPE_TAG', 3),
                'final_dest' => (string)$postFromTag->term_id, 'issues' => array());
        }

        $this->logger->warn(__("Couldn't find post from slug. slug:", '404-solution') . ' ' . $slug);
        return array('type' => ABJ404_TYPE_EXTERNAL, 'final_dest' => $finalDest, 'issues' => array());
    }

    /**
     * @param array<string, mixed> $dataArray
     * @param int $existingId
     * @param bool $overwriteExisting
     * @param string $fromURL
     * @param int $status
     * @param int $type
     * @param string|int $finalDest
     * @return void
     */
    private function writeRedirect(array $dataArray, int $existingId, bool $overwriteExisting,
                                   string $fromURL, int $status, int $type, $finalDest): void {
        $engine = isset($dataArray['engine']) && is_string($dataArray['engine']) && $dataArray['engine'] !== ''
            ? $dataArray['engine'] : 'import';
        $code = $this->redirectCode($dataArray);

        if ($existingId !== 0 && $overwriteExisting) {
            $this->redirectsRepository->updateRedirect(ABJ_404_Solution_RedirectUpdate::fromArray(array(
                'id' => $existingId,
                'type' => $type,
                'fromUrl' => $fromURL,
                'destination' => (string)$finalDest,
                'code' => $code,
                'statusType' => (string)$status,
            )));
            return;
        }

        $this->redirectsRepository->setupRedirect(ABJ_404_Solution_RedirectSpec::fromArray(array(
            'fromURL' => $fromURL,
            'status' => (string)$status,
            'type' => (string)$type,
            'finalDest' => (string)$finalDest,
            'code' => $code,
            'disabled' => 0,
            'engine' => $engine,
        )));
    }

    /**
     * @param array<string, mixed> $dataArray
     * @return string
     */
    private function redirectCode(array $dataArray): string {
        $rawCode = $dataArray['code'] ?? null;
        return is_scalar($rawCode) && is_numeric($rawCode) ? (string)(int)$rawCode : '301';
    }

    /**
     * @param array<string, mixed> $dataArray
     * @param string $key
     * @return int
     */
    private function intFromArray(array $dataArray, string $key): int {
        $value = $dataArray[$key] ?? 0;
        return is_numeric($value) ? (int)$value : 0;
    }

    /**
     * @param string $name
     * @param int $default
     * @return int
     */
    private function typeConstant(string $name, int $default): int {
        $value = defined($name) ? constant($name) : $default;
        return is_numeric($value) ? (int)$value : $default;
    }

    /**
     * @param string $fromUrl
     * @return string
     */
    private function validateRegexPattern(string $fromUrl): string {
        if ($fromUrl === '') {
            return __('pattern is empty', '404-solution');
        }

        $prepared = str_replace('/', '\/', $fromUrl);
        $delimA = '{';
        $delimB = '}';
        if (strpos($prepared, '}') !== false) {
            $candidates = array('`', '^', '|', '~', '!', ';', ':', ',', '@', "'", '/');
            $picked = null;
            foreach ($candidates as $c) {
                if (strpos($prepared, $c) === false) {
                    $picked = $c;
                    break;
                }
            }
            if ($picked === null) {
                return __('cannot find a safe delimiter character', '404-solution');
            }
            $delimA = $delimB = $picked;
        }

        $compiled = $delimA . $prepared . $delimB;
        $result = @preg_match($compiled, '');
        if ($result === false) {
            $errMsg = function_exists('preg_last_error_msg')
                ? preg_last_error_msg()
                : 'preg_match compilation failed';
            return $errMsg;
        }
        return '';
    }

    /**
     * @param array<string, mixed> $dataArray
     * @return bool
     */
    private function isExplicitRegexRow(array $dataArray): bool {
        if (isset($dataArray['status']) && is_scalar($dataArray['status'])) {
            $raw = strtolower(trim((string)$dataArray['status']));
            if ($raw === 'regex') {
                return true;
            }
            if (is_numeric($raw) && (int)$raw === (int)ABJ404_STATUS_REGEX) {
                return true;
            }
        }
        if (isset($dataArray['regex']) && is_scalar($dataArray['regex'])) {
            $raw = strtolower(trim((string)$dataArray['regex']));
            if ($raw === '1' || $raw === 'true' || $raw === 'yes') {
                return true;
            }
        }
        return false;
    }
}
