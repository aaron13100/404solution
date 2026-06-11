<?php
/**
 * Prefix mismatch and multisite cross-prefix diagnostics for DB errors.
 */

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: covered through DatabaseErrorClassifier facade by tests/InfrastructureErrorClassificationTest.php and tests/MultisiteCrossPrefixErrorTest.php
class ABJ_404_Solution_DatabasePrefixDiagnostics {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $core;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_DatabaseCore $core
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct(ABJ_404_Solution_DatabaseCore $core, $logger) {
        $this->core = $core;
        $this->logger = $logger;
    }

    /** @return string */
    public function diagnosePrefixMismatch(): string {
        global $wpdb;
        try {
            $dbName = $wpdb->dbname ?? '';
            if ($dbName === '') {
                return '';
            }
            // @utf8-audit: opt-out - $dbname comes from $wpdb internals which WP populates
            // with a system-controlled string (the configured database name), not user input.
            $dbNameEscaped = esc_sql((string)$dbName);
            $dbNameStr = is_array($dbNameEscaped) ? '' : $dbNameEscaped;
            // LIMIT 50 bounds the scan on shared-hosting databases with tens of thousands of tables
            // (design-audit-2026-06-06 M502 / i308). The diagnostic only needs to enumerate a handful
            // of '*abj404_redirects' tables across subsite prefixes. 50 is well above any plausible
            // legitimate count and prevents an unbounded information_schema sweep on the degraded path.
            // DAO-bypass-approved: read-only information_schema prefix diagnostic when a plugin table is missing.
            $rows = $wpdb->get_results(
                "SELECT table_name FROM information_schema.tables "
                . "WHERE table_schema = '{$dbNameStr}' "
                . "AND LOWER(table_name) LIKE '%abj404\_redirects' "
                . "LIMIT 50",
                ARRAY_A
            );
            if (!is_array($rows) || empty($rows)) {
                return '';
            }
            $expectedTable = $this->core->tableNameResolver()->getLowercasePrefix() . 'abj404_redirects';
            $foundTables = [];
            foreach ($rows as $row) {
                if (!is_iterable($row)) {
                    continue;
                }
                $name = null;
                foreach ($row as $key => $value) {
                    if (strtolower((string)$key) === 'table_name') {
                        $name = (string)$value;
                        break;
                    }
                }
                if ($name !== null) {
                    $foundTables[] = $name;
                }
            }
            $mismatched = array_filter($foundTables, function ($t) use ($expectedTable) {
                return strtolower($t) !== strtolower($expectedTable);
            });
            if (empty($mismatched)) {
                return '';
            }
            $msg = ', PREFIX MISMATCH DETECTED: $wpdb->prefix is "' . ($wpdb->prefix ?? '')
                . '" (expected table: ' . $expectedTable . ') but plugin tables exist as: '
                . implode(', ', $mismatched) . '.';
            if (function_exists('is_multisite') && is_multisite()) {
                $msg .= ' This is a multisite installation; the other prefixes likely belong to other subsites (normal).';
            } else {
                $msg .= ' Check $table_prefix in wp-config.php.';
            }
            return $msg;
        } catch (Throwable $e) {
            $this->logger->debugMessage(__METHOD__ . ': prefix diagnostic failed while preserving original DB error.', $e);
            return '';
        }
    }

    /**
     * Detect whether a missing-table error references a different multisite
     * subsite's prefix.
     *
     * @param string $errorText
     * @return bool
     */
    public function isMultisiteCrossPrefixError(string $errorText): bool {
        if ($errorText === '' || !function_exists('is_multisite') || !is_multisite()) {
            return false;
        }

        global $wpdb;
        if (!preg_match("/['\x60](?:[^'\x60]+\.)?([^'\x60]*abj404_[^'\x60]+)['\x60]/i", $errorText, $matches)) {
            return false;
        }
        $referencedTable = strtolower($matches[1]);

        $currentPrefix = strtolower($wpdb->prefix ?? 'wp_');
        $basePrefix = strtolower($wpdb->base_prefix ?? 'wp_');

        if (strpos($referencedTable, $currentPrefix . 'abj404_') === 0) {
            return false;
        }

        $pattern = '/^' . preg_quote($basePrefix, '/') . '(\d+)_abj404_/';
        return preg_match($pattern, $referencedTable) === 1;
    }
}
