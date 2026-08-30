<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The engine-profile table: where profile rows live, and how they are read,
 * written and deleted.
 *
 * Split out of ABJ_404_Solution_EngineProfileResolver, which had grown to hold
 * three separate jobs -- deciding which engines run for a URL, memoizing that
 * decision's input per blog, and owning every query against
 * wp_abj404_engine_profiles. The first is business logic and the third is data
 * access, and this codebase keeps those in different files. The resolver now
 * asks this class for rows and never builds SQL.
 *
 * The resolver keeps two thin published accessors over this
 * (getAllProfilesForAdmin / adminProfileListWasTruncated) because the admin
 * AJAX handlers reach the profile subsystem through that singleton, which is
 * also the seam their tests inject at. They carry no SQL and no policy; the
 * table is owned here.
 */
final class ABJ_404_Solution_EngineProfileRepository {

    /**
     * Ceiling on rows one admin profile-list request may read and return.
     *
     * Sized to be unreachable by the only thing that creates profiles -- an
     * administrator saving them one at a time through the admin screen -- while
     * still keeping a single request's work bounded by a number this code
     * chooses rather than by whatever the table has grown to. See readAllForAdmin().
     */
    const MAX_ADMIN_PROFILES = 2000;

    /** Ceiling on the active-profile read that feeds matching. */
    const MAX_ACTIVE_PROFILES = 200;

    /** @var ABJ_404_Solution_DatabaseCore|null */
    private $dbCore = null;

    /** @var bool Whether the last admin read filled MAX_ADMIN_PROFILES. */
    private $adminReadTruncated = false;

    /**
     * Lazy dbCore accessor, deferring resolution until first use so unit tests
     * that exercise pure-logic methods never boot the database layer.
     *
     * @return ABJ_404_Solution_DatabaseCore
     */
    private function dbCore() {
        if ($this->dbCore === null) {
            $this->dbCore = abj_service('db_core');
        }
        return $this->dbCore;
    }

    /**
     * The fully-prefixed table name.
     *
     * Read from $wpdb->prefix on every call rather than memoized, because
     * switch_to_blog() changes the prefix and this table is genuinely per-blog.
     */
    public function tableName(): string {
        global $wpdb;
        return strtolower($wpdb->prefix) . 'abj404_engine_profiles';
    }

    /**
     * Active profiles for matching, priority first.
     *
     * @return array<int, object>
     */
    public function readActiveProfiles(): array {
        $table = $this->tableName();
        if ($this->tableIsAbsent($table)) {
            return [];
        }

        $queryResult = $this->dbCore()->queryAndGetResults(
            "SELECT `id`, `name`, `url_pattern`, `is_regex`, `enabled_engines`, `priority`
             FROM `{$table}`
             WHERE `status` = 1
             ORDER BY `priority` ASC, `id` ASC
             LIMIT %d",
            ['query_params' => [self::MAX_ACTIVE_PROFILES], 'result_type' => OBJECT]
        );
        $rows = $queryResult['rows'] ?? [];
        if (!is_array($rows)) {
            return [];
        }
        // Rebuilt as a list of objects so the declared return type is checked
        // rather than asserted: the DAO answers array<mixed>.
        $profiles = [];
        foreach ($rows as $row) {
            if (is_object($row)) {
                $profiles[] = $row;
            }
        }

        return $profiles;
    }

    /**
     * Every profile, active or not, for the admin edit screen, up to a ceiling.
     *
     * The query used to have no LIMIT, so the work one authenticated admin
     * request could cause was whatever the table happened to hold: every row
     * materialised into PHP and encoded into a single JSON response. Profiles
     * are only ever created one at a time through the admin screen, so no real
     * site is near this -- but "nobody would do that" is not a bound, and this
     * plugin has already shipped an out-of-memory failure from an unbounded
     * load (4.3.0/4.3.1).
     *
     * A CEILING, deliberately not a page. This list feeds the screen that edits
     * these rows, so quietly returning the first N would leave profiles that are
     * live in matching but invisible and uneditable -- a worse failure than a
     * slow query, and exactly the kind of silent partial answer this codebase
     * treats as a defect elsewhere. Reaching the ceiling is recorded rather than
     * absorbed: see lastAdminReadWasTruncated(), which the caller reports.
     *
     * @return array<int, array<string, mixed>>
     */
    public function readAllForAdmin(): array {
        $this->adminReadTruncated = false;
        $table = $this->tableName();

        if ($this->tableIsAbsent($table)) {
            return [];
        }

        $queryResult = $this->dbCore()->queryAndGetResults(
            "SELECT `id`, `name`, `url_pattern`, `is_regex`, `enabled_engines`, `priority`, `status`
             FROM `{$table}`
             ORDER BY `priority` ASC, `id` ASC
             LIMIT " . self::MAX_ADMIN_PROFILES
        );
        $rows = $queryResult['rows'] ?? [];
        if (!is_array($rows)) {
            return [];
        }
        $this->adminReadTruncated = count($rows) >= self::MAX_ADMIN_PROFILES;

        // Rebuilt as a list of row arrays rather than returned as-is: the DAO
        // answers array<mixed>, so handing it straight back made the declared
        // return type a claim nothing checked.
        $profiles = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $profiles[] = $row;
            }
        }

        return $profiles;
    }

    /**
     * Whether the last readAllForAdmin() call filled its ceiling.
     *
     * A truncated list is indistinguishable from a short one, so an admin whose
     * profiles stopped appearing could not tell that from having deleted them.
     */
    public function lastAdminReadWasTruncated(): bool {
        return $this->adminReadTruncated;
    }

    /**
     * Insert or update a profile row.
     *
     * @param array<string, mixed> $data
     * @return int|false Inserted/updated row ID, or false on failure.
     */
    public function insertOrUpdate(array $data) {
        $table = $this->tableName();

        $id = isset($data['id']) && is_numeric($data['id']) ? (int)$data['id'] : 0;
        [$name, $urlPattern, $isRegex, $enabledEngines, $priority, $status]
            = $this->normalizedColumns($data);

        if ($id > 0) {
            $queryResult = $this->dbCore()->queryAndGetResults(
                "UPDATE `{$table}`
                    SET `name` = %s, `url_pattern` = %s, `is_regex` = %d,
                        `enabled_engines` = %s, `priority` = %d, `status` = %d
                  WHERE `id` = %d",
                ['query_params' => [$name, $urlPattern, $isRegex, $enabledEngines, $priority, $status, $id]]
            );
            $updateError = isset($queryResult['last_error']) && is_string($queryResult['last_error']) ? $queryResult['last_error'] : '';
            return $updateError === '' ? $id : false;
        }

        $queryResult = $this->dbCore()->queryAndGetResults(
            "INSERT INTO `{$table}` (`name`, `url_pattern`, `is_regex`, `enabled_engines`, `priority`, `status`)
                  VALUES (%s, %s, %d, %s, %d, %d)",
            ['query_params' => [$name, $urlPattern, $isRegex, $enabledEngines, $priority, $status]]
        );
        $lastError = isset($queryResult['last_error']) && is_string($queryResult['last_error']) ? $queryResult['last_error'] : '';
        if ($lastError !== '') {
            return false;
        }
        $insertId = isset($queryResult['insert_id']) && is_scalar($queryResult['insert_id']) ? (int)$queryResult['insert_id'] : 0;
        return $insertId > 0 ? $insertId : false;
    }

    /**
     * Coerce one untrusted profile payload into the six column values.
     *
     * Lifted out of insertOrUpdate() because it was the whole of that method's
     * branching: six independent isset/type ternaries plus the JSON check,
     * which is what pushed one INSERT-or-UPDATE decision past the complexity
     * ceiling. Returned positionally and immediately destructured at the single
     * call site rather than as a keyed array, so the column ORDER stays stated
     * once, next to the SQL that consumes it.
     *
     * @param array<string, mixed> $data
     * @return array{0: string, 1: string, 2: int, 3: string, 4: int, 5: int}
     */
    private function normalizedColumns(array $data): array {
        $name           = isset($data['name']) ? sanitize_text_field(is_string($data['name']) ? $data['name'] : '') : '';
        $urlPattern     = isset($data['url_pattern']) ? wp_unslash(is_string($data['url_pattern']) ? $data['url_pattern'] : '') : '';
        $isRegex        = isset($data['is_regex']) ? (int)(bool)$data['is_regex'] : 0;
        $enabledEngines = isset($data['enabled_engines']) && is_string($data['enabled_engines'])
            ? $data['enabled_engines'] : '[]';
        $priority       = isset($data['priority']) && is_numeric($data['priority']) ? (int)$data['priority'] : 0;
        $status         = isset($data['status']) ? (int)(bool)$data['status'] : 1;

        // enabled_engines is stored as a JSON array; anything else becomes one.
        if (!is_array(json_decode($enabledEngines, true))) {
            $enabledEngines = '[]';
        }

        return [(string)$name, (string)$urlPattern, $isRegex, $enabledEngines, $priority, $status];
    }

    /** Delete a profile by ID. */
    public function delete(int $id): bool {
        $table = $this->tableName();
        $queryResult = $this->dbCore()->queryAndGetResults(
            "DELETE FROM `{$table}` WHERE `id` = %d",
            ['query_params' => [$id]]
        );
        $deleteError = isset($queryResult['last_error']) && is_string($queryResult['last_error']) ? $queryResult['last_error'] : '';
        return $deleteError === '';
    }

    /**
     * Whether the engine profiles table is known NOT to be there.
     *
     * SHOW TABLES LIKE returns no rows both for a table that is not there and
     * for a probe that never ran, and the two answers must not share a code
     * path: a false "absent" caches an empty profile set for the rest of the
     * request, so one failed probe silently drops every custom search-engine
     * profile out of matching (and out of the admin list) until the next
     * request. last_error is what separates them, and an unanswerable probe
     * lets the SELECT run so the centralized handler reports the fault.
     */
    private function tableIsAbsent(string $table): bool {
        $queryResult = $this->dbCore()->queryAndGetResults(
            'SHOW TABLES LIKE %s',
            ['query_params' => [$table]]
        );
        $lastError = isset($queryResult['last_error']) && is_string($queryResult['last_error'])
            ? trim($queryResult['last_error']) : '';
        if ($lastError !== '' || !empty($queryResult['timed_out'])) {
            return false;
        }
        $rows = isset($queryResult['rows']) && is_array($queryResult['rows']) ? $queryResult['rows'] : [];
        $first = $rows[0] ?? null;
        if (!is_array($first)) {
            return true;
        }
        $firstValue = reset($first);
        return $firstValue !== $table;
    }
}
