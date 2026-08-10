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
 *
 * THE VOLUME IS CAPPED, and measurement is why. Template I/O is the one
 * diagnostic family whose record count scales with the RENDERED ROW COUNT: on
 * the owner's localhost on 2026-08-10, one part=all table AJAX request wrote
 * 872 of its 3,135 checkpoint records here at rowsPerPage=25, and 3,212 of
 * 5,476 at rowsPerPage=100, while every other family stayed flat. That is what
 * turned debug_mode from a fixed surcharge into one that grows with the table,
 * and a diagnostic a user cannot afford to switch on does not diagnose
 * anything.
 *
 * Past the cap an operation is still announced on the active-operations
 * channel, so the decisive evidence survives: a stalled read is exactly an
 * operation that went 'active' and never went 'complete', which is how a
 * blocked stat is recognised whether or not its journal record was written.
 * What is lost past the cap is the per-operation elapsed_ms and byte counts of
 * later reads, which is bounded-detail loss, not blindness.
 */
final class ABJ_404_Solution_TemplateFileReadTracer {

    /**
     * Journal records this request may spend here. Matched to the sibling
     * tracers' budgets (DatabaseQueryFilterTracer, TableRenderTranslationTracer)
     * so one family cannot crowd the others out of a bounded support excerpt.
     */
    const MAX_RECORDS = 64;

    /** @var int */
    private static $operationSequence = 0;

    /** @var int Journal records written this request. */
    private static $recordCount = 0;

    /** @var bool Whether the cap notice has been written for this request. */
    private static $capRecorded = false;

    /**
     * Test seam: a PHPUnit worker never gets the end-of-request that would
     * otherwise reset this budget, so one test's template reads would spend
     * the next test's. Registered in ABJ404_RequestScopedStateReset.
     */
    public static function resetForTests(): void {
        self::$operationSequence = 0;
        self::$recordCount = 0;
        self::$capRecorded = false;
    }

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
        // Both records of a pair are budgeted together: a start whose end
        // cannot be afforded is what a stall looks like, so spending the last
        // slot on one would manufacture a phantom stall in the evidence.
        $journalled = self::$recordCount + 2 <= self::MAX_RECORDS;
        if ($journalled) {
            self::$recordCount++;
            ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent(
                $requestId,
                'template_file_operation_start',
                $identity
            );
        } else {
            self::recordCapOnce($requestId);
            ABJ_404_Solution_AjaxCheckpointLogger::recordActiveOperation(
                $requestId,
                'template_file_operation',
                'active',
                $identity
            );
        }
        $startedAt = self::nowFloat();
        try {
            $result = $work();
        } catch (Throwable $error) {
            self::writeEnd($requestId, $journalled, array_merge($identity, array(
                'status' => 'error',
                'elapsed_ms' => self::elapsedMilliseconds($startedAt),
                'result' => array('error' => true),
                'bytes' => 0,
                'error' => self::errorSummary($error),
            )));
            throw $error;
        }

        $summary = self::resultSummary($operation, $result, $fields);
        self::writeEnd($requestId, $journalled, array_merge($identity, array(
            'status' => 'complete',
            'elapsed_ms' => self::elapsedMilliseconds($startedAt),
            'result' => $summary,
            'bytes' => $summary['bytes'] ?? 0,
        )));
        return $result;
    }

    /**
     * Close an operation on the channel its start was written to. Mixing them
     * would leave an active operation that never completes, which is the exact
     * signature of a stalled read.
     *
     * @param array<string, mixed> $fields
     */
    private static function writeEnd(string $requestId, bool $journalled, array $fields): void {
        if ($journalled) {
            self::$recordCount++;
            ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent(
                $requestId,
                'template_file_operation_end',
                $fields
            );
            return;
        }
        ABJ_404_Solution_AjaxCheckpointLogger::recordActiveOperation(
            $requestId,
            'template_file_operation',
            'complete',
            $fields
        );
    }

    /** Name the cap in the journal once, so a truncated family is never silent. */
    private static function recordCapOnce(string $requestId): void {
        if (self::$capRecorded) {
            return;
        }
        self::$capRecorded = true;
        ABJ_404_Solution_AjaxCheckpointLogger::recordFrequent(
            $requestId,
            'template_file_operation_capped',
            array(
                'recorded' => self::$recordCount,
                'max_records' => self::MAX_RECORDS,
            )
        );
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
