<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Query failure for the privacy-bucketed redirect hit-count histogram. */
class ABJ_404_Solution_RedirectHitCountHistogramQueryException extends RuntimeException {
}

/**
 * Reads the privacy-preserving redirect hit-count telemetry histogram.
 *
 * This repository deliberately returns only fixed aggregate buckets. It must
 * never expose redirect URLs or per-row traffic patterns. Query failures throw
 * so the diagnostics collector omits the field instead of publishing a false
 * zero histogram.
 */
class ABJ_404_Solution_RedirectHitCountHistogramRepository {

    /** @var ABJ_404_Solution_DatabaseQueryInterface */
    private $dbCore;

    /** @var ABJ_404_Solution_TableReadinessGate */
    private $readiness;

    public function __construct(
        ABJ_404_Solution_DatabaseQueryInterface $dbCore,
        ABJ_404_Solution_TableReadinessGate $readiness
    ) {
        $this->dbCore = $dbCore;
        $this->readiness = $readiness;
    }

    /**
     * Aggregate active redirects into fixed denormalized hit-count buckets.
     *
     * @return array<string, int>
     * @throws ABJ_404_Solution_RedirectHitCountHistogramQueryException
     */
    public function getRedirectHitCountHistogram(): array {
        $emptyHistogram = array(
            'zero_hits' => 0,
            'one_to_ten_hits' => 0,
            'eleven_to_hundred_hits' => 0,
            'over_hundred_hits' => 0,
        );
        if ($this->readiness->isKnownAbsent('{wp_abj404_redirects}')) {
            return $emptyHistogram;
        }

        $redirectStatuses = ABJ404_STATUS_MANUAL . ", " . ABJ404_STATUS_AUTO . ", " . ABJ404_STATUS_REGEX;
        $query = "SELECT
            SUM(CASE WHEN disabled = 0 AND status IN (" . $redirectStatuses . ") AND logshits <= 0 THEN 1 ELSE 0 END) as zero_hits,
            SUM(CASE WHEN disabled = 0 AND status IN (" . $redirectStatuses . ") AND logshits BETWEEN 1 AND 10 THEN 1 ELSE 0 END) as one_to_ten_hits,
            SUM(CASE WHEN disabled = 0 AND status IN (" . $redirectStatuses . ") AND logshits BETWEEN 11 AND 100 THEN 1 ELSE 0 END) as eleven_to_hundred_hits,
            SUM(CASE WHEN disabled = 0 AND status IN (" . $redirectStatuses . ") AND logshits > 100 THEN 1 ELSE 0 END) as over_hundred_hits
            FROM {wp_abj404_redirects}
            WHERE status IN (" . $redirectStatuses . ")";
        $query = $this->dbCore->doTableNameReplacements($query);

        $result = $this->dbCore->queryAndGetResults($query);
        if (!empty($result['last_error']) || !empty($result['timed_out'])) {
            $lastError = $result['last_error'] ?? 'unknown';
            $lastErrorText = is_scalar($lastError)
                ? (string)$lastError
                : (is_object($lastError) ? get_class($lastError) : gettype($lastError));
            $context = !empty($result['timed_out'])
                ? 'timed_out=true'
                : 'last_error=' . $lastErrorText;
            throw new ABJ_404_Solution_RedirectHitCountHistogramQueryException(
                'Redirect hit histogram query failed (' . $context . ')'
            );
        }
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        $row = !empty($rows) && is_array($rows[0] ?? null) ? $rows[0] : array();

        return array(
            'zero_hits' => self::scalarToInt($row['zero_hits'] ?? 0),
            'one_to_ten_hits' => self::scalarToInt($row['one_to_ten_hits'] ?? 0),
            'eleven_to_hundred_hits' => self::scalarToInt($row['eleven_to_hundred_hits'] ?? 0),
            'over_hundred_hits' => self::scalarToInt($row['over_hundred_hits'] ?? 0),
        );
    }

    /** @param mixed $value */
    private static function scalarToInt($value): int {
        return is_scalar($value) ? intval($value) : 0;
    }
}
