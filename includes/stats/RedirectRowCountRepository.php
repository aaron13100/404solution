<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Reads uncached row totals from the redirects table. */
class ABJ_404_Solution_RedirectRowCountRepository {

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

    /** Return the uncached number of captured redirects. */
    public function getCapturedCount(): int {
        if ($this->readiness->isKnownAbsent('{wp_abj404_redirects}')) {
            return 0;
        }

        // allow-unbounded-select: COUNT aggregate; returns a single row
        $query = "select count(id) from {wp_abj404_redirects} where status = " . absint(ABJ404_STATUS_CAPTURED);
        $result = $this->dbCore->queryAndGetResults($query);
        if (!empty($result['timed_out']) || (isset($result['last_error']) && $result['last_error'] != '')) {
            return 0;
        }
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows)) {
            return 0;
        }
        $first = $rows[0];
        $value = is_array($first) ? reset($first) : $first;
        return self::scalarToInt($value);
    }

    /**
     * Return the uncached number of live or trashed redirects in the statuses.
     *
     * @param array<int, int> $types Status codes to include.
     * @param int $trashed 0 for live rows, 1 for trashed rows.
     */
    public function getRecordCount(array $types = array(), $trashed = 0): int {
        if (count($types) < 1) {
            return 0;
        }
        if ($this->readiness->isKnownAbsent('{wp_abj404_redirects}')) {
            return 0;
        }
        $filteredTypes = array_map('absint', $types);
        $typesForSQL = implode(', ', $filteredTypes);
        // allow-unbounded-select: COUNT aggregate; returns a single row
        $query = "select count(id) as count from {wp_abj404_redirects} where 1 and (status in ("
            . $typesForSQL . "))"
            . " and disabled = " . absint($trashed);

        $result = $this->dbCore->queryAndGetResults($query);
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : array();
        if (empty($rows)) {
            return 0;
        }
        $row = is_array($rows[0] ?? null) ? $rows[0] : array();
        return isset($row['count']) && is_scalar($row['count']) ? intval($row['count']) : 0;
    }

    /** @param mixed $value */
    private static function scalarToInt($value): int {
        return is_scalar($value) ? intval($value) : 0;
    }
}
