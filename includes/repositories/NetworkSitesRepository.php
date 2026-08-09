<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads the multisite network's site list for callers that walk it one site at
 * a time.
 *
 * The walk is a KEYSET walk, never a positional one. `get_sites()` can only
 * page by numeric offset, and an offset is assigned at read time: deleting a
 * site earlier in the list slides every later site down one position, so the
 * next request's offset lands one site past where it should and the site in the
 * gap is never visited. Blog ids, by contrast, are immutable and assigned in
 * ascending order, so "the smallest id greater than the last one I finished"
 * names the same site no matter what was added or removed in the meantime. That
 * question is not expressible through WP_Site_Query's arguments (it accepts an
 * offset and an id list, but no id range), so it is asked of the site table
 * directly.
 *
 * Bounded by construction: one row per call, whatever the size of the network.
 *
 * @see ABJ_404_Solution_NextNetworkSite
 */
class ABJ_404_Solution_NetworkSitesRepository {

    /** @var ABJ_404_Solution_DatabaseCore */
    private $dbCore;

    /**
     * @param ABJ_404_Solution_DatabaseCore $dbCore
     */
    public function __construct(ABJ_404_Solution_DatabaseCore $dbCore) {
        $this->dbCore = $dbCore;
    }

    /**
     * The first site whose id is greater than the one given.
     *
     * @param int $afterSiteId Last site id the caller finished; 0 to start.
     * @return ABJ_404_Solution_NextNetworkSite
     */
    public function nextSiteAfter(int $afterSiteId): ABJ_404_Solution_NextNetworkSite {
        $table = $this->blogsTableName();
        if ($table === '') {
            return ABJ_404_Solution_NextNetworkSite::unreadable(
                'this install exposes no network site table name'
            );
        }

        $query = "SELECT blog_id FROM `" . $table . "` WHERE blog_id > %d ORDER BY blog_id ASC LIMIT 1";
        $result = $this->dbCore->queryAndGetResults(
            $query,
            array('query_params' => array(max(0, $afterSiteId)))
        );

        $lastError = $this->lastErrorOf($result);
        if ($lastError !== '') {
            return ABJ_404_Solution_NextNetworkSite::unreadable($lastError);
        }
        if (!isset($result['rows']) || !is_array($result['rows'])) {
            return ABJ_404_Solution_NextNetworkSite::unreadable(
                'the site query returned no readable result rows'
            );
        }

        $rows = $result['rows'];
        if (empty($rows)) {
            // The one place an empty answer legitimately means "the network has
            // ended": the query ran, reported no error, and found no site past
            // the cursor.
            return ABJ_404_Solution_NextNetworkSite::endOfNetwork();
        }

        $siteId = $this->readSiteId(isset($rows[0]) ? $rows[0] : null);
        if ($siteId === null) {
            return ABJ_404_Solution_NextNetworkSite::unreadable(
                'the site query returned a row with no readable blog_id'
            );
        }
        return ABJ_404_Solution_NextNetworkSite::found($siteId);
    }

    /**
     * How many sites the network has.
     *
     * The null is load-bearing: it is the only thing that distinguishes "this
     * network has no sites" from "the count could not be read", and callers
     * treat the first as an answer they can act on.
     *
     * @return int|null
     */
    public function countSites(): ?int {
        $table = $this->blogsTableName();
        if ($table === '') {
            return null;
        }

        $result = $this->dbCore->queryAndGetResults(
            "SELECT COUNT(*) AS site_count FROM `" . $table . "`"
        );
        if ($this->lastErrorOf($result) !== '') {
            return null;
        }
        if (!isset($result['rows']) || !is_array($result['rows']) || empty($result['rows'])) {
            return null;
        }

        $row = $result['rows'][0];
        if (!is_array($row) || empty($row)) {
            return null;
        }
        $count = reset($row);
        if (!is_numeric($count)) {
            return null;
        }
        $count = (int)$count;
        return $count >= 0 ? $count : null;
    }

    /**
     * The physical site table, as WordPress itself addresses it.
     *
     * Read from $wpdb->blogs rather than assembled from a prefix: the site
     * table is a NETWORK-global table, so on a switched blog the current prefix
     * ($wpdb->prefix, e.g. `wp_2_`) names a table that does not exist. The name
     * is validated as a bare identifier before being interpolated, because it
     * cannot be passed as a bound parameter.
     *
     * @return string The table name, or '' when this install has none.
     */
    private function blogsTableName(): string {
        global $wpdb;

        if (!is_object($wpdb) || !isset($wpdb->blogs) || !is_string($wpdb->blogs)) {
            return '';
        }
        $table = trim($wpdb->blogs);
        return preg_match('/^[A-Za-z0-9_$]+$/', $table) === 1 ? $table : '';
    }

    /**
     * @param array<string, mixed> $result
     * @return string The driver error, or '' when the query ran cleanly.
     */
    private function lastErrorOf(array $result): string {
        $lastError = isset($result['last_error']) ? $result['last_error'] : '';
        return is_string($lastError) ? trim($lastError) : '';
    }

    /**
     * @param mixed $row
     * @return int|null The row's blog id, or null when it holds none.
     */
    private function readSiteId($row): ?int {
        if (!is_array($row) || empty($row)) {
            return null;
        }
        $siteId = array_key_exists('blog_id', $row) ? $row['blog_id'] : reset($row);
        if (!is_numeric($siteId)) {
            return null;
        }
        $siteId = (int)$siteId;
        return $siteId > 0 ? $siteId : null;
    }
}
