<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: covered through public WP-CLI command entry points in tests/WPCLICommandsTest.php

/**
 * Application service for WP-CLI redirect commands.
 */
class ABJ_404_Solution_WPCLIRedirectCommandService {

    /** @return array<int, string> */
    public function validStatusLabels(): array {
        return array('manual', 'auto', 'captured', 'regex', 'ignored', 'later');
    }

    /**
     * @param string $status
     * @param string $format
     * @return array<string, mixed>
     */
    public function listRedirects(string $status, string $format): array {
        if ($status !== '' && !in_array($status, $this->validStatusLabels(), true)) {
            return $this->error('Invalid --status value. Choose one of: ' . implode(', ', $this->validStatusLabels()));
        }

        $rows = $this->fetchRedirectRows(abj_service('db_core'), $this->statusStringToTypes($status), 2000);
        if (empty($rows)) {
            return $this->line('No redirects found.');
        }

        foreach ($rows as &$row) {
            $rawStatus = $row['status'] ?? 0;
            $row['status'] = $this->statusIntToLabel(is_numeric($rawStatus) ? (int)$rawStatus : 0);
        }
        unset($row);

        return array(
            'type' => 'format',
            'format' => $format,
            'rows' => $rows,
            'fields' => array('id', 'url', 'status', 'type', 'final_dest', 'code', 'disabled', 'timestamp'),
        );
    }

    /**
     * @param string $from
     * @param string $to
     * @param int $code
     * @param bool $regex
     * @return array<string, mixed>
     */
    public function createRedirect(string $from, string $to, int $code, bool $regex): array {
        if ($from === '') {
            return $this->error('--from is required.');
        }

        $warnings = array();
        if ($from[0] !== '/' && !preg_match('#^https?://#i', $from)) {
            $warnings[] = "--from '{$from}' does not start with '/'. Incoming requests are matched against the path (e.g. /old-page), so this redirect may never fire.";
        }

        $validCodes = array(301, 302, 307, 308, 410, 451);
        if (!in_array($code, $validCodes, true)) {
            $warnings[] = 'Invalid redirect code; defaulting to 301. Valid codes: ' . implode(', ', $validCodes);
            $code = 301;
        }

        $isTerminalCode = in_array($code, array(410, 451), true);
        if ($to === '' && !$isTerminalCode) {
            return $this->error('--to is required (omit only when --code is 410 or 451).', $warnings);
        }
        if (!$isTerminalCode && !$this->isValidDestination($to)) {
            return $this->error('Invalid --to value. Use an absolute http(s) URL or a site-relative path starting with /.', $warnings);
        }

        if ($isTerminalCode) {
            $dest = '0';
            $type = (string)ABJ404_TYPE_404_DISPLAYED;
        } else {
            $resolved = $this->resolveDestinationType($to);
            $type = $resolved['type'];
            $dest = $resolved['dest'];
        }

        $status = $regex ? (string)ABJ404_STATUS_REGEX : (string)ABJ404_STATUS_MANUAL;
        $insertedId = abj_service('redirects_repository')->setupRedirect(
            ABJ_404_Solution_RedirectSpec::create($from, $status, $type, $dest, (string)$code, 0, 'wp-cli')
        );
        if (!$insertedId) {
            return $this->error('Failed to create redirect. Check that the source URL is unique.', $warnings);
        }

        $displayDest = $isTerminalCode ? '(none ' . "\xE2\x80\x94" . " {$code})" : $to;
        return $this->success("Redirect created (ID: {$insertedId}): {$from} " . "\xE2\x86\x92" . " {$displayDest} [{$code}]", $warnings);
    }

    /**
     * @param string $idOrUrl
     * @return array<string, mixed>
     */
    public function deleteRedirect(string $idOrUrl): array {
        if ($idOrUrl === '') {
            return $this->error('Please provide a redirect ID or source URL.');
        }

        $redirectsRepository = abj_service('redirects_repository');
        $messages = array();
        if (ctype_digit($idOrUrl)) {
            $id = (int)$idOrUrl;
            if ($id === 0) {
                return $this->error('Invalid redirect ID.');
            }
        } else {
            $redirect = $redirectsRepository->getExistingRedirectForURL($idOrUrl);
            if (!isset($redirect['id']) || (int)(is_scalar($redirect['id']) ? $redirect['id'] : 0) === 0) {
                return $this->error("No redirect found for URL: {$idOrUrl}");
            }
            $id = (int)(is_scalar($redirect['id']) ? $redirect['id'] : 0);
            $messages[] = "Resolved '{$idOrUrl}' to redirect ID {$id}.";
        }

        $error = $redirectsRepository->moveRedirectsToTrash($id, 1);
        if ($error !== '') {
            return $this->error("No redirect with ID {$id} found, or database error: {$error}", array(), $messages);
        }

        return $this->success("Redirect ID {$id} moved to trash.", array(), $messages);
    }

    /**
     * @param string $type
     * @return array<string, mixed>
     */
    public function preparePurge(string $type): array {
        if ($type !== 'captured') {
            return $this->error('Only "captured" is a valid purge target. Usage: wp abj404 purge captured');
        }

        $count = $this->countCapturedRows();
        if ($count === 0) {
            return $this->line('No captured 404 entries to purge.');
        }

        return array('type' => 'confirm', 'count' => $count);
    }

    /**
     * @param int $count
     * @return array<string, mixed>
     */
    public function purgeCaptured(int $count): array {
        $deleteResult = abj_service('db_core')->queryAndGetResults(
            "DELETE FROM `{$this->redirectsTable()}` WHERE status IN ({$this->capturedStatusSql()}) AND disabled = 0"
        );

        $deleteError = isset($deleteResult['last_error']) && is_string($deleteResult['last_error']) ? $deleteResult['last_error'] : '';
        if ($deleteError !== '') {
            return $this->error('Database error: ' . $deleteError);
        }

        $deleted = isset($deleteResult['rows_affected']) && is_scalar($deleteResult['rows_affected'])
            ? (int)$deleteResult['rows_affected']
            : $count;
        return $this->success("Purged {$deleted} captured 404 entries.");
    }

    /**
     * @param string $url
     * @return array<string, mixed>
     */
    public function testRedirect(string $url): array {
        if ($url === '') {
            return $this->error('Please provide a URL to test. Usage: wp abj404 test <url>');
        }

        $exactResult = $this->findExactRedirectMatch($url);
        if ($exactResult !== null) {
            return $exactResult;
        }

        $regexResult = $this->findRegexRedirectMatch($url);
        if ($regexResult !== null) {
            return $regexResult;
        }

        return $this->line("No redirect found for: {$url}");
    }

    /**
     * @param string $url
     * @return array{type: string, message: string, warnings: array<int, string>, lines: array<int, string>}|null
     */
    private function findExactRedirectMatch(string $url) {
        $exact = abj_service('redirects_repository')->getExistingRedirectForURL($url);
        if (isset($exact['id']) && (int)(is_scalar($exact['id']) ? $exact['id'] : 0) !== 0) {
            $dest = isset($exact['final_dest']) && is_scalar($exact['final_dest']) ? (string)$exact['final_dest'] : '';
            $code = isset($exact['code']) && is_scalar($exact['code']) ? (string)$exact['code'] : '301';
            $exactId = is_scalar($exact['id']) ? (string)$exact['id'] : '?';
            return $this->success("Exact match found (ID: {$exactId}): {$url} " . "\xE2\x86\x92" . " {$dest} [{$code}]");
        }
        return null;
    }

    /**
     * @param string $url
     * @return array{type: string, message: string, warnings: array<int, string>, lines: array<int, string>}|null
     */
    private function findRegexRedirectMatch(string $url) {
        $f = abj_service('functions');
        foreach (abj_service('view_read_service')->getRedirectsWithRegEx() as $row) {
            $pattern = isset($row['url']) && is_scalar($row['url']) ? (string)$row['url'] : '';
            if ($pattern === '') {
                continue;
            }
            $matches = array();
            if ($f->regexMatch($pattern, $url, $matches)) {
                $dest = isset($row['final_dest']) && is_scalar($row['final_dest']) ? (string)$row['final_dest'] : '';
                $code = isset($row['code']) && is_scalar($row['code']) ? (string)$row['code'] : '301';
                $id = isset($row['id']) && is_scalar($row['id']) ? (string)$row['id'] : '?';
                return $this->success("Regex match found (ID: {$id}, pattern: {$pattern}): {$url} " . "\xE2\x86\x92" . " {$dest} [{$code}]");
            }
        }
        return null;
    }

    /** @return int */
    private function countCapturedRows(): int {
        return abj_service('db_core')->queryScalarInt(
            "SELECT COUNT(*) AS c FROM `{$this->redirectsTable()}` WHERE status IN ({$this->capturedStatusSql()}) AND disabled = 0"
        );
    }

    /**
     * @param ABJ_404_Solution_DatabaseQueryInterface $dbCore
     * @param array<int, int> $types
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    private function fetchRedirectRows($dbCore, array $types, int $limit): array {
        $where = '';
        if (!empty($types)) {
            $where = 'WHERE status IN (' . implode(', ', array_map('absint', $types)) . ')';
        }

        $result = $dbCore->queryAndGetResults(
            "SELECT id, url, status, type, final_dest, code, disabled, timestamp
                  FROM `{$dbCore->doTableNameReplacements('{wp_abj404_redirects}')}`
                  {$where}
                  ORDER BY id DESC
                  LIMIT " . absint($limit)
        );

        $rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();
        $output = array();
        foreach ($rows as $row) {
            if (is_array($row)) {
                $output[] = $row;
            }
        }
        return $output;
    }

    /**
     * @param string $status
     * @return array<int, int>
     */
    private function statusStringToTypes(string $status): array {
        $map = array(
            'manual' => ABJ404_STATUS_MANUAL,
            'auto' => ABJ404_STATUS_AUTO,
            'captured' => ABJ404_STATUS_CAPTURED,
            'ignored' => ABJ404_STATUS_IGNORED,
            'later' => ABJ404_STATUS_LATER,
            'regex' => ABJ404_STATUS_REGEX,
        );
        return isset($map[$status]) ? array($map[$status]) : array();
    }

    private function statusIntToLabel(int $status): string {
        $map = array(
            ABJ404_STATUS_MANUAL => 'manual',
            ABJ404_STATUS_AUTO => 'auto',
            ABJ404_STATUS_CAPTURED => 'captured',
            ABJ404_STATUS_IGNORED => 'ignored',
            ABJ404_STATUS_LATER => 'later',
            ABJ404_STATUS_REGEX => 'regex',
        );
        return isset($map[$status]) ? $map[$status] : (string)$status;
    }

    /** @return array{type: string, dest: string} */
    private function resolveDestinationType(string $to): array {
        if (preg_match('#^https?://#i', $to)) {
            return array('type' => (string)ABJ404_TYPE_EXTERNAL, 'dest' => $to);
        }

        $trimmed = trim($to, '/ ');
        if ($trimmed === '') {
            return array('type' => (string)ABJ404_TYPE_HOME, 'dest' => (string)ABJ404_TYPE_HOME);
        }

        if (function_exists('url_to_postid') && function_exists('home_url')) {
            $postId = url_to_postid(home_url($to));
            if ($postId > 0) {
                return array('type' => (string)ABJ404_TYPE_POST, 'dest' => (string)$postId);
            }
        }

        return array('type' => (string)ABJ404_TYPE_EXTERNAL, 'dest' => $to);
    }

    private function isValidDestination(string $to): bool {
        if (preg_match('#^https?://#i', $to)) {
            return filter_var($to, FILTER_VALIDATE_URL) !== false;
        }
        return isset($to[0]) && $to[0] === '/' && (!isset($to[1]) || $to[1] !== '/');
    }

    private function redirectsTable(): string {
        return abj_service('db_core')->doTableNameReplacements('{wp_abj404_redirects}');
    }

    private function capturedStatusSql(): string {
        return implode(', ', array(ABJ404_STATUS_CAPTURED, ABJ404_STATUS_IGNORED, ABJ404_STATUS_LATER));
    }

    /**
     * @param array<int, string> $warnings
     * @param array<int, string> $lines
     * @return array{type: string, message: string, warnings: array<int, string>, lines: array<int, string>}
     */
    private function error(string $message, array $warnings = array(), array $lines = array()): array {
        return array('type' => 'error', 'message' => $message, 'warnings' => $warnings, 'lines' => $lines);
    }

    /** @return array<string, mixed> */
    private function line(string $message): array {
        return array('type' => 'line', 'message' => $message);
    }

    /**
     * @param array<int, string> $warnings
     * @param array<int, string> $lines
     * @return array{type: string, message: string, warnings: array<int, string>, lines: array<int, string>}
     */
    private function success(string $message, array $warnings = array(), array $lines = array()): array {
        return array('type' => 'success', 'message' => $message, 'warnings' => $warnings, 'lines' => $lines);
    }
}
