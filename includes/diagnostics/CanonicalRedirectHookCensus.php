<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Whether WordPress core's own canonical redirect was still going to run, taken
 * on the front end at the moment it would have.
 *
 * WHY THIS EXISTS
 *
 * A captured 404 that core would have canonicalized away looks identical, in
 * every record the plugin keeps, to a 404 that was always broken. Telling them
 * apart needs one fact from the site itself: is `redirect_canonical` still
 * attached to `template_redirect`, and if not, what else is on that hook. The
 * alternative was asking the site owner to run curl and paste the headers back,
 * which is not something a plugin gets to ask of the person it is supposed to be
 * helping. So the plugin answers it.
 *
 * WHY IT IS TAKEN ON THE FRONT END AND NOT WHEN THE REPORT IS BUILT
 *
 * The support request is an admin-ajax request. A great many plugins strip
 * `redirect_canonical` inside an `if (!is_admin())` guard, so reading the hook
 * registry from admin-ajax would answer "attached" on exactly the sites whose
 * canonicalization is suppressed. That is a false negative in the one field this
 * class exists to produce, so the reading is taken where the answer is true: on
 * a real front-end 404, inside `template_redirect`, before core's priority 10.
 *
 * WHY IT IS BOUNDED AND NOT AMBIENT
 *
 * This runs on the 404 path of sites that take thousands of hits to a single bad
 * URL. So the hot path computes only a cheap structural fingerprint (no
 * reflection, no HTTP, no write), and the full walk plus the option write happen
 * only when that fingerprint changed or the stored reading has aged out. A
 * site's hook set is identical on every request, so the steady state is zero
 * writes.
 *
 * WHAT THIS CLASS IS NOT
 *
 * Reading WordPress's hook registry at all -- dispatch order, wrapped-callback
 * identity, and which plugin owns a callback -- belongs to
 * ABJ_404_Solution_HookCallbackRoster, which knows nothing about
 * canonicalization and would answer the same questions about any other hook.
 * What lives here is the FINDING: which callbacks matter, what counts as
 * suppression, when a reading is worth writing, and the record format.
 * Rendering that record into a support payload, inside a byte budget, belongs to
 * ABJ_404_Solution_CanonicalSuppressionSupportSection.
 */
final class ABJ_404_Solution_CanonicalRedirectHookCensus {

    /** The option holding the latest reading. Non-autoloaded; read on demand. */
    const OPTION_NAME = ABJ_404_Solution_CanonicalHookCensusStore::OPTION_NAME;

    /** The record format. Additive changes only; a reader tolerates unknown keys. */
    const RECORD_VERSION = 1;

    /**
     * How many `template_redirect` callbacks are named when core's canonical
     * redirect is gone.
     *
     * The list is a suspect roster, not an inventory: past twenty-five entries a
     * reader has the pattern, and the record has to fit inside a support payload
     * that is already at its byte ceiling. `callback_count` keeps the true size
     * visible beside the capped callback list, so the cap never disguises how
     * long the real chain was.
     */
    const MAX_CALLBACKS = 25;

    /**
     * How long a reading stays fresh before it is taken again even though
     * nothing changed.
     *
     * Without this the record would keep the timestamp of the first 404 the
     * install ever served, and a reader could not tell an observation
     * contemporaneous with the reported incident from one a year older. One day
     * costs one UPDATE per day per site.
     */
    const REFRESH_AFTER_SECONDS = 86400;

    /** The database-arbitrated mutex used only while a stale reading refreshes. */
    const REFRESH_LOCK_OPTION_NAME = 'abj404_canonical_hook_census_lock';

    /** A crashed refresher cannot suppress diagnostics indefinitely. */
    const REFRESH_LOCK_SECONDS = 60;

    /** The hook core's canonical redirect is registered on. */
    const HOOK_NAME = 'template_redirect';

    /** The core callback whose absence is the finding. */
    const CORE_CANONICAL_CALLBACK = 'redirect_canonical';

    /** This plugin's own front-end listener, so its priority can be reported. */
    const PLUGIN_LISTENER_CALLBACK = 'abj404_404listener';

    /** Core's canonical redirect is no longer attached to the hook at all. */
    const SUPPRESSION_HOOK_REMOVED = 'core-hook-removed';

    /** Something is on the `redirect_canonical` FILTER and can short-circuit it. */
    const SUPPRESSION_FILTER_HOOKED = 'canonical-filter-hooked';

    /**
     * This plugin's listener is registered ahead of core's canonical redirect,
     * so any request the plugin answers and exits on never reaches it. On a
     * default install (plugin at 9, core at 10) this is the expected state and
     * names the plugin itself rather than a third party.
     */
    const SUPPRESSION_PLUGIN_FIRST = 'plugin-runs-first';

    /**
     * Whether this PHP request has already tried to take the reading.
     *
     * The stored-fingerprint check bounds writes ACROSS requests, and it does
     * that only while the option can actually be persisted. On a read-only
     * replica, or a site whose options table is full, the write silently fails,
     * the next read still sees nothing, and every single 404 pays for the full
     * reflection walk again. This flag is the bound that survives that: at most
     * one attempt per request, whatever the storage is doing.
     *
     * @var bool
     */
    private static $attemptedThisRequest = false;

    /**
     * Test seam: forget that this request already took the reading, so a test
     * can simulate a second front-end request in one PHP process. Same seam
     * ABJ_404_Solution_SameSiteRequestCensus exposes for the same reason.
     *
     * @return void
     */
    public static function resetRequestState(): void {
        self::$attemptedThisRequest = false;
    }

    /**
     * Take the reading if it is worth taking, and store it if it is new.
     *
     * Called from the front-end 404 path. Never throws: a visitor's 404 must not
     * fail because a diagnostic could not be written, and a hook registry that
     * is missing or the wrong shape records nothing rather than a fabricated
     * census.
     *
     * @return void
     */
    public static function recordFromFrontend404(): void {
        if (self::$attemptedThisRequest) {
            return;
        }
        self::$attemptedThisRequest = true;
        try {
            $entries = ABJ_404_Solution_HookCallbackRoster::forHook(self::HOOK_NAME);
            if ($entries === null) {
                return;
            }
            $fingerprint = ABJ_404_Solution_HookCallbackRoster::fingerprint($entries);
            $stored = self::read();
            $now = self::now();
            $unchanged = isset($stored['fingerprint']) && $stored['fingerprint'] === $fingerprint;
            $fresh = ($now - self::intIn($stored, 'recorded_at', 0)) < self::REFRESH_AFTER_SECONDS;
            if ($unchanged && $fresh) {
                return;
            }

            // DESIGN-AUDIT-OK: The mutex prevents concurrent requests that all
            // observed the same stale record from each repeating the reflection
            // census and UPDATE; the fresh fast path above remains lock-free,
            // and testConcurrentRefreshLockPreventsASecondCensusWrite pins it.
            $lock = new ABJ_404_Solution_ExclusiveOptionRow();
            $claimValue = ABJ_404_Solution_ExclusiveOptionRow::uniqueClaimValue(
                (string)($now + self::REFRESH_LOCK_SECONDS)
            );
            if (!self::claimRefreshLock($lock, $claimValue, $now)) {
                return;
            }
            try {
                // The first read happened before mutex acquisition. Another
                // request may have refreshed the record while this one waited,
                // so evict this request's option-cache copy and decide again
                // from the now-current record before paying for reflection or
                // issuing an UPDATE.
                $store = self::store();
                $store->refreshReads();
                $stored = $store->read();
                $unchanged = isset($stored['fingerprint']) && $stored['fingerprint'] === $fingerprint;
                $fresh = ($now - self::intIn($stored, 'recorded_at', 0)) < self::REFRESH_AFTER_SECONDS;
                if ($unchanged && $fresh) {
                    return;
                }
                self::write(self::census($entries, $fingerprint, $now, $stored));
            } finally {
                $lock->releaseIfValueIs(array(
                    'optionName' => self::REFRESH_LOCK_OPTION_NAME,
                    'value' => $claimValue,
                ));
            }
        } catch (Throwable $e) {
            abj404_logPhpFallback('canonical-hook-census',
                'canonical hook census failed (code ' . $e->getCode() . '): ' . $e->getMessage());
        }
    }

    /**
     * The stored reading, or an empty array when nothing has been recorded.
     * Never throws; an unreadable or malformed record reports as absent rather
     * than propagating into the support request that asked for it.
     *
     * @return array<string, mixed>
     */
    public static function read(): array {
        return self::store()->read();
    }

    /**
     * The whole reading, ready to store.
     *
     * @param array<int, array{priority: int, index: string, callback: string, function: mixed}> $entries
     *   the `template_redirect` roster, in dispatch order.
     * @param array<string, mixed> $previous the record being replaced, so the
     *   first-observed timestamp survives a re-reading.
     * @return array<string, mixed>
     */
    private static function census(array $entries, string $fingerprint, int $now, array $previous): array {
        $corePriority = ABJ_404_Solution_HookCallbackRoster::priorityOf($entries, self::CORE_CANONICAL_CALLBACK);
        $pluginPriority = ABJ_404_Solution_HookCallbackRoster::priorityOf($entries, self::PLUGIN_LISTENER_CALLBACK);
        // The SECOND way canonicalization stops. A plugin can leave the hook
        // attached and return false from the `redirect_canonical` filter, which
        // a hook-attachment reading alone would report as a healthy site.
        $filterEntries = ABJ_404_Solution_HookCallbackRoster::forHook(self::CORE_CANONICAL_CALLBACK);
        if ($filterEntries === null) {
            throw new UnexpectedValueException(
                'The WordPress hook registry became unreadable while inspecting '
                . self::CORE_CANONICAL_CALLBACK . '.'
            );
        }

        $suppression = array();
        if ($corePriority === null) {
            $suppression[] = self::SUPPRESSION_HOOK_REMOVED;
        }
        if ($filterEntries !== array()) {
            $suppression[] = self::SUPPRESSION_FILTER_HOOKED;
        }
        if ($corePriority !== null && $pluginPriority !== null && $pluginPriority < $corePriority) {
            $suppression[] = self::SUPPRESSION_PLUGIN_FIRST;
        }

        $record = array(
            'version' => self::RECORD_VERSION,
            'recorded_at' => $now,
            'first_recorded_at' => self::intIn($previous, 'first_recorded_at', $now),
            'fingerprint' => $fingerprint,
            'plugin_version' => defined('ABJ404_VERSION') ? ABJ404_VERSION : '',
            'hook' => self::HOOK_NAME,
            'core_canonical' => $corePriority === null ? 'detached' : 'attached',
            'core_canonical_priority' => $corePriority,
            'plugin_listener_priority' => $pluginPriority,
            'callback_count' => count($entries),
            'suppression' => $suppression,
            'canonical_filter' => ABJ_404_Solution_HookCallbackRoster::describeEntriesWithOrigins(
                $filterEntries,
                self::MAX_CALLBACKS
            ),
        );

        // The full roster is recorded ONLY when core's callback is gone. That is
        // the one branch where a reader has a culprit to find; on an intact hook
        // the same list names nobody and would spend the payload's bytes saying
        // so on every healthy install.
        if ($corePriority === null) {
            $record['callbacks'] = ABJ_404_Solution_HookCallbackRoster::describeEntriesWithOrigins(
                $entries,
                self::MAX_CALLBACKS
            );
        }
        return $record;
    }

    /**
     * Store the reading. Non-autoloaded on purpose: it is read when a support
     * payload is assembled, not on every page view, and the front-end check that
     * decides whether to write it is already paying for one read.
     *
     * @param array<string, mixed> $record
     * @return void
     */
    private static function write(array $record): void {
        self::store()->write($record);
    }

    /**
     * Acquire the stale-reading refresh mutex, recovering a claim left behind
     * by a request that terminated before its finally block ran.
     */
    private static function claimRefreshLock(
        ABJ_404_Solution_ExclusiveOptionRow $lock,
        string $claimValue,
        int $now
    ): bool {
        $claim = array('optionName' => self::REFRESH_LOCK_OPTION_NAME, 'value' => $claimValue);
        if ($lock->claim($claim)) {
            return true;
        }

        $holder = $lock->valueOf(self::REFRESH_LOCK_OPTION_NAME);
        $separator = strpos($holder, ':');
        $expiresAt = (int)($separator === false ? $holder : substr($holder, 0, $separator));
        if ($holder === '' || $expiresAt > $now) {
            return false;
        }

        $lock->releaseIfValueIs(array(
            'optionName' => self::REFRESH_LOCK_OPTION_NAME,
            'value' => $holder,
        ));
        return $lock->claim($claim);
    }

    /** Persistence adapter for the census record. */
    private static function store(): ABJ_404_Solution_CanonicalHookCensusStore {
        return new ABJ_404_Solution_CanonicalHookCensusStore();
    }

    /**
     * One integer field out of a decoded record, or $fallback when the field is
     * absent or is not something an integer can be read from. The record came
     * out of an options row that any other code, or a hand edit, could have
     * left in any shape, so it is validated rather than trusted.
     *
     * @param array<string, mixed> $record
     */
    private static function intIn(array $record, string $field, int $fallback): int {
        $value = $record[$field] ?? null;
        return is_scalar($value) ? (int)$value : $fallback;
    }

    /** Epoch seconds, or 0 when no clock is reachable (a boot-order edge). */
    private static function now(): int {
        if (function_exists('abj_clock')) {
            return (int)abj_clock()->now();
        }
        return function_exists('abj404_now') ? abj404_now() : 0;
    }
}
