<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only metadata queries against the database engine and WordPress content.
 *
 * Owns the three "ask the DB what it can do" reads previously inlined in
 * ViewReadService: per-table engine name, MyISAM support flag, and the
 * distinct post-type list from wp_posts. Extracted in the i805 ViewReadService
 * decomposition so the staged view-read service stays focused on its
 * pipeline.
 */
class ABJ_404_Solution_DatabaseMetadataReader {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     */
    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore) {
        $this->dbCore = $dbCore;
    }

    /**
     * Run the canned selectTableEngines.sql query and return the raw
     * queryAndGetResults envelope (rows + last_error + timed_out).
     *
     * @return array<string, mixed>
     */
    public function getTableEngines(): array {
        $query = ABJ_404_Solution_FileSystemService::readFileContents(__DIR__ . "/sql/selectTableEngines.sql");
        return $this->dbCore->queryAndGetResults($query);
    }

    /**
     * @return bool True if information_schema.ENGINES reports SUPPORT=YES for MyISAM.
     */
    public function isMyISAMSupported(): bool {
        $supportResults = $this->dbCore->queryAndGetResults(
            "SELECT ENGINE, SUPPORT FROM information_schema.ENGINES WHERE lower(ENGINE) = 'myisam'",
            array('log_errors' => false)
        );

        if (!empty($supportResults) && !empty($supportResults['rows']) && is_array($supportResults['rows'])) {
            $rows = $supportResults['rows'];
            $row = is_array($rows[0] ?? null) ? $rows[0] : array();
            $supportValue = array_key_exists('support', $row) ? (string)($row['support'] ?? '')
                : (array_key_exists('SUPPORT', $row) ? (string)($row['SUPPORT'] ?? '') : 'nope');
            return strtolower($supportValue) == 'yes';
        }
        return false;
    }

    /**
     * @return array<int, string> Distinct post_type values from wp_posts in alphabetical order.
     */
    public function getAllPostTypes(): array {
        $query = "SELECT DISTINCT post_type FROM {wp_posts} order by post_type";
        $results = $this->dbCore->queryAndGetResults($query);
        $rows = $results['rows'];

        $postType = array();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                array_push($postType, $row['post_type']);
            }
        }
        return $postType;
    }
}
