<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Durable attribution for local template-file I/O during the table AJAX path.
 *
 * Absolute paths and warning text never enter the checkpoint journal. A stable
 * identifier combines the file extension with a hash of the basename, which
 * is enough to correlate repeated reads without disclosing the installation
 * layout. Each platform boundary writes its start before entering the call so
 * a blocked stat, read, retry wait, warning logger, or cURL fallback remains
 * visible in bounded support evidence.
 */
final class ABJ_404_Solution_TemplateFileReadTracer {

    /** @var int */
    private static $operationSequence = 0;

    /**
     * @template T
     * @param array<string, int|string|bool|null> $fields
     * @param callable(): T $work
     * @return T
     */
    public static function trace(
        string $operation,
        string $path,
        array $fields,
        callable $work
    ) {
        $requestId = self::requestId();
        if ($requestId === '') {
            return $work();
        }

        $operationId = self::operationId($requestId, $operation);
        $identity = array_merge(array(
            'operation_id' => $operationId,
            'operation' => self::safeOperation($operation),
            'template_id' => self::templateId($path),
        ), $fields);
        ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent(
            $requestId,
            'template_file_operation_start',
            $identity
        );
        $startedAt = self::nowFloat();
        try {
            $result = $work();
        } catch (Throwable $error) {
            ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent(
                $requestId,
                'template_file_operation_end',
                array_merge($identity, array(
                    'status' => 'error',
                    'elapsed_ms' => self::elapsedMilliseconds($startedAt),
                    'result' => array('error' => true),
                    'bytes' => 0,
                    'error' => self::errorSummary($error),
                ))
            );
            throw $error;
        }

        $summary = self::resultSummary($operation, $result, $fields);
        ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent(
            $requestId,
            'template_file_operation_end',
            array_merge($identity, array(
                'status' => 'complete',
                'elapsed_ms' => self::elapsedMilliseconds($startedAt),
                'result' => $summary,
                'bytes' => $summary['bytes'] ?? 0,
            ))
        );
        return $result;
    }

    private static function requestId(): string {
        if (!class_exists('ABJ_404_Solution_AjaxRequestLedger')) {
            return '';
        }
        return ABJ_404_Solution_AjaxRequestLedger::instrumentedRequestIdFromGlobalContext();
    }

    private static function operationId(string $requestId, string $operation): string {
        self::$operationSequence++;
        return substr(hash(
            'sha256',
            $requestId . '|' . $operation . '|' . self::$operationSequence
        ), 0, 12);
    }

    private static function safeOperation(string $operation): string {
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/', $operation) === 1
            ? $operation
            : 'operation#' . substr(hash('sha256', $operation), 0, 12);
    }

    private static function templateId(string $path): string {
        $basename = basename(str_replace('\\', '/', $path));
        $extension = strtolower((string)pathinfo($basename, PATHINFO_EXTENSION));
        $kind = preg_match('/^[a-z0-9]{1,12}$/', $extension) === 1
            ? $extension
            : 'file';
        return $kind . '#' . substr(hash('sha256', $basename), 0, 12);
    }

    /**
     * @param mixed $result
     * @param array<string, int|string|bool|null> $fields
     * @return array<string, int|string|bool|null>
     */
    private static function resultSummary(string $operation, $result, array $fields): array {
        if ($operation === 'stat') {
            return array('exists' => (bool)$result, 'bytes' => 0);
        }
        if ($operation === 'read_attempt' || $operation === 'curl_fallback') {
            return array(
                'success' => is_string($result),
                'bytes' => is_string($result) ? strlen($result) : 0,
            );
        }
        if ($operation === 'retry_wait') {
            return array(
                'delay_us' => isset($fields['delay_us']) ? (int)$fields['delay_us'] : 0,
                'bytes' => 0,
            );
        }
        return array('type' => gettype($result), 'bytes' => 0);
    }

    /** @return array{class:string,code:int,message:string,message_length:int} */
    private static function errorSummary(Throwable $error): array {
        $message = $error->getMessage();
        $class = get_class($error);
        return array(
            'class' => preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]{0,159}$/', $class) === 1
                ? $class
                : 'class#' . substr(hash('sha256', $class), 0, 12),
            'code' => is_int($error->getCode()) ? $error->getCode() : 0,
            'message' => 'message#' . substr(hash('sha256', $message), 0, 12),
            'message_length' => strlen($message),
        );
    }

    private static function nowFloat(): ?float {
        if (function_exists('abj_clock')) {
            return abj_clock()->nowFloat();
        }
        if (class_exists('ABJ_404_Solution_SystemClock')) {
            return (new ABJ_404_Solution_SystemClock())->nowFloat();
        }
        return null;
    }

    private static function elapsedMilliseconds(?float $startedAt): ?int {
        if ($startedAt === null) {
            return null;
        }
        $finishedAt = self::nowFloat();
        return $finishedAt === null
            ? null
            : max(0, (int)round(($finishedAt - $startedAt) * 1000));
    }
}
