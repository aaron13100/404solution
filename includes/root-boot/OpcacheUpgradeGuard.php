<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Post-upgrade opcache guard.
 *
 * PHP's opcache revalidates cached bytecode per file, on its own schedule
 * (`opcache.validate_timestamps` + a nonzero `opcache.revalidate_freq`). For a
 * few seconds after a plugin update the class graph can therefore be MIXED:
 * a file whose path is new to this release compiles fresh from disk, while a
 * file whose path is unchanged is still served as the previous release's
 * bytecode. If a parent and its subclass land on opposite sides of that split
 * and a method signature changed between the two releases, PHP raises an
 * uncatchable E_ERROR at class-linking time.
 *
 * Observed in production (westcoato.kinsta.cloud, 2026-07-18, PHP 8.4/8.5,
 * upgrade 4.2.0 -> 4.3.1):
 *
 *   Declaration of ABJ_404_Solution_FunctionsPreg::substr(...) must be
 *   compatible with ABJ_404_Solution_Functions::substr(...)
 *
 * `includes/core/Functions.php` is a path that only exists in 4.3.0+, so it
 * compiled fresh; `includes/php/FunctionsPreg.php` kept its path across the
 * upgrade, so the 4.2.0 bytecode was still cached. The same log shows a second,
 * independent symptom of the same mixed state one second earlier: 4.2.0
 * bytecode asking for `includes/sql/getRedirectsForViewStaged/...`, a directory
 * the 4.3.1 tree does not contain.
 *
 * Ordering, not coverage, is what makes this fixable. The fatal happens while
 * the autoloader links classes, so any mitigation that lives inside the booted
 * plugin (as the previous `invalidateOpcacheForCriticalFiles()` did) runs
 * strictly too late for the request that dies. This file is therefore required
 * and executed by `404-solution.php` BEFORE any other plugin file is required
 * and before `spl_autoload_register()`, so the invalidated files are recompiled
 * from disk for the very request that detects the upgrade.
 *
 * The file set is DERIVED, never hand-listed: a hand-maintained list is
 * drift-prone by construction and would miss the next subclass that happens to
 * sit at a stable path. The derivation asks the opcode cache which of ITS
 * cached scripts live under the plugin directory, which is both exact (a file
 * the cache does not hold cannot be stale) and free of filesystem access. A
 * bounded directory walk is kept only as a fallback for hosts that expose
 * `opcache_invalidate()` without `opcache_get_status()`.
 *
 * What this CANNOT protect, stated plainly so nobody re-derives it: the upgrade
 * that first ships this guard. `404-solution.php` keeps its path across
 * releases, so a warm worker can be running the PREVIOUS release's bytecode of
 * the entry point itself -- and that bytecode has no call to this file. The
 * guard therefore protects every upgrade from the release that introduces it
 * onward, and the introducing upgrade still depends on opcache revalidating the
 * entry point on its own schedule. There is no way to fix that from inside the
 * plugin; code that is not running cannot invalidate itself.
 */
// allow-no-test-found: boot-time global functions executed from 404-solution.php before the autoloader exists; there is no class seam to name a same-named unit file after. Behavior is covered end-to-end by OpcacheInvalidateOnUpgradeTest (boot-order probe, derived-set coverage, gate, restrict_api guard, truncation).

/**
 * Name of the option holding the plugin version whose files were last flushed
 * from opcache. Kept separate from `abj404_settings['DB_VERSION']` so the flush
 * happens exactly once per upgrade even when the database upgrade itself is
 * failing or repeatedly deferred.
 */
if (!defined('ABJ404_OPCACHE_VERSION_OPTION')) {
    define('ABJ404_OPCACHE_VERSION_OPTION', 'abj404_opcache_version');
}

if (!function_exists('abj404_opcache_api_is_restricted')) {
    /**
     * Decide whether `opcache_invalidate()` is callable from this file.
     *
     * Hosts can set `opcache.restrict_api` to a path prefix; PHP then emits an
     * E_WARNING and refuses the call for any script outside that prefix. See the
     * note at includes/core/ErrorHandler.php about suppressed
     * `@opcache_invalidate()` warnings reaching the email reporter on such hosts.
     * Checking the prefix up front means we never make the restricted call at
     * all, instead of making it several hundred times and suppressing each one.
     *
     * @param mixed  $restrictSetting Raw value of the opcache.restrict_api ini setting.
     * @param string $callerFile      Absolute path of the file making the call.
     * @return bool True when the API must not be called from $callerFile.
     */
    function abj404_opcache_api_is_restricted($restrictSetting, $callerFile) {
        if (!is_string($restrictSetting)) {
            return false;
        }
        $prefix = trim($restrictSetting);
        if ($prefix === '') {
            return false;
        }
        if (!is_string($callerFile) || $callerFile === '') {
            return true;
        }
        // PHP compares the caller path against the prefix with a plain
        // case-sensitive prefix match on POSIX, case-insensitive on Windows.
        if (DIRECTORY_SEPARATOR === '\\') {
            return stripos($callerFile, $prefix) !== 0;
        }
        return strpos($callerFile, $prefix) !== 0;
    }
}

if (!function_exists('abj404_opcache_scripts_under')) {
    /**
     * Select the cached scripts that live under the plugin directory.
     *
     * `opcache_get_status(true)` reports every script currently held in the
     * shared opcode cache, keyed by absolute path. That list IS the set of
     * files that can serve stale bytecode: a file the cache does not hold gets
     * compiled from disk on first include and cannot be stale by definition.
     * Filtering it by the plugin root therefore derives the exact target set
     * with no filesystem access at all, which is what makes this affordable to
     * run from the boot path.
     *
     * @param mixed  $scripts The `scripts` member of an opcache_get_status(true) result.
     * @param string $root    Absolute plugin directory, trailing separator optional.
     * @return string[] Absolute paths, sorted.
     */
    function abj404_opcache_scripts_under($scripts, $root) {
        if (!is_array($scripts) || !is_string($root) || $root === '') {
            return array();
        }
        // Compare with a single trailing separator on both sides so a sibling
        // plugin directory sharing our prefix (`404-solution-pro/`) cannot be
        // swept into the flush.
        $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $caseInsensitive = (DIRECTORY_SEPARATOR === '\\');

        $matched = array();
        foreach ($scripts as $path => $ignored) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            $normalized = str_replace('\\', '/', $path);
            $isMatch = $caseInsensitive
                ? (stripos($normalized, $prefix) === 0)
                : (strpos($normalized, $prefix) === 0);
            if ($isMatch) {
                $matched[] = $path;
            }
        }
        sort($matched);

        return $matched;
    }
}

if (!function_exists('abj404_opcache_collect_plugin_php_files')) {
    /**
     * FALLBACK derivation: every PHP file the plugin can include at runtime.
     *
     * Only used when `opcache_get_status()` is unavailable (a host that put it
     * in `disable_functions` while leaving `opcache_invalidate()` callable).
     * The cached-script list is preferred because it is both exact and free;
     * this walk costs a stat per directory entry, which is a few milliseconds
     * on a release tree (~520 PHP files) but grows with whatever else the site
     * owner has left inside the plugin directory. Hence the caps.
     *
     * Walks $root breadth-first, skipping dot-directories (`.git`, `.queue`,
     * ...) and `node_modules`. Neither can hold PHP this plugin includes, and
     * both are excluded from the release package, so pruning them is a property
     * of the tree rather than a maintained exclusion list. Everything else is
     * included, so a future top-level source directory is covered without
     * anyone remembering to add it here.
     *
     * Symlinked directories are not followed. A self-referential link inside
     * the plugin directory would otherwise make this walk run forever at boot
     * (this repository has one: `vendor/vendor`), and code reached only through
     * a link out of the plugin directory is not this plugin's bytecode to flush.
     *
     * @param string $root       Absolute directory to walk.
     * @param int    $maxFiles   Runaway guard; a plugin release ships ~520 PHP files.
     * @param float  $maxSeconds Wall-clock budget. A stray backup or media
     *                           directory inside the plugin folder can hold tens
     *                           of thousands of entries on slow shared storage;
     *                           a boot-path walk must give up rather than spend
     *                           the request's whole execution budget. Reported as
     *                           truncated, never as full coverage.
     * @return array{files: string[], truncated: bool}
     */
    function abj404_opcache_collect_plugin_php_files($root, $maxFiles = 20000, $maxSeconds = 2.0) {
        /** @var string[] $files */
        $files = array();
        $truncated = false;

        if (!is_string($root) || $root === '' || !is_dir($root) || $maxFiles < 1) {
            return array('files' => $files, 'truncated' => false);
        }

        // No clock adapter exists this early in the boot: the autoloader has
        // not been registered yet, by design. microtime() is the only option.
        $deadline = ($maxSeconds > 0) ? (microtime(true) + $maxSeconds) : null;

        /** @var string[] $pending */
        $pending = array(rtrim($root, '/\\'));
        while ($pending !== array()) {
            if ($deadline !== null && microtime(true) > $deadline) {
                $truncated = true;
                break;
            }
            $directory = array_pop($pending);
            $entries = @scandir($directory);
            if (!is_array($entries)) {
                // Unreadable directory (permissions, open_basedir). Skipping it
                // costs coverage of that subtree only; opcache's own timestamp
                // revalidation remains the backstop there.
                continue;
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || $entry === '') {
                    continue;
                }
                $path = $directory . DIRECTORY_SEPARATOR . $entry;
                if (is_link($path)) {
                    continue;
                }
                if (is_dir($path)) {
                    if ($entry[0] === '.' || $entry === 'node_modules') {
                        continue;
                    }
                    $pending[] = $path;
                    continue;
                }
                if (substr($entry, -4) !== '.php') {
                    continue;
                }
                if (count($files) >= $maxFiles) {
                    $truncated = true;
                    break 2;
                }
                $files[] = $path;
            }
        }

        sort($files);

        return array('files' => $files, 'truncated' => $truncated);
    }
}

if (!function_exists('abj404_opcache_read_version_stamp')) {
    /**
     * Read the plugin version whose files were last flushed from opcache.
     *
     * @return string|null Null when the options API is not available yet, which
     *                     means the change cannot be gated and must be skipped.
     */
    function abj404_opcache_read_version_stamp() {
        if (function_exists('get_site_option')) {
            $stamp = get_site_option(ABJ404_OPCACHE_VERSION_OPTION, '');
        } elseif (function_exists('get_option')) {
            $stamp = get_option(ABJ404_OPCACHE_VERSION_OPTION, '');
        } else {
            return null;
        }
        return is_string($stamp) ? $stamp : '';
    }
}

if (!function_exists('abj404_opcache_write_version_stamp')) {
    /**
     * Record the plugin version whose files have now been flushed.
     *
     * Uses the network-wide option on multisite: opcache is per PHP worker, not
     * per blog, so one flush per network per release is the correct scope.
     *
     * @param string $version
     * @return bool
     */
    function abj404_opcache_write_version_stamp($version) {
        if (function_exists('update_site_option')) {
            return (bool) update_site_option(ABJ404_OPCACHE_VERSION_OPTION, $version);
        }
        if (function_exists('update_option')) {
            return (bool) update_option(ABJ404_OPCACHE_VERSION_OPTION, $version);
        }
        return false;
    }
}

if (!function_exists('abj404_opcache_scripts_from_status')) {
    /**
     * Decide whether an opcache_get_status() result can name our target files.
     *
     * Split out from the caller so this judgement is directly testable against
     * crafted status arrays; the shapes below are configurations a test process
     * cannot enter and leave at will.
     *
     * Returns null (meaning "ask the filesystem instead") when:
     *
     *  - the call failed, or the host reports opcache disabled for this SAPI;
     *  - `opcache.file_cache_only` is on. PHP omits the `scripts` key entirely
     *    then, because there is no shared-memory table to report. Treating
     *    "no scripts reported" as "nothing is cached" would be wrong, so we
     *    look at the disk instead. Measured caveat, so nobody re-derives it:
     *    on a PURE file-cache-only host neither `opcache_invalidate()` nor
     *    `opcache_reset()` can evict an entry (both operate on the shared
     *    memory table that does not exist there), so the flush is a no-op and
     *    opcache's own timestamp revalidation remains the only recovery. The
     *    fallback still matters for mixed setups, where a file cache backs a
     *    live shared-memory cache that this branch would otherwise skip;
     *  - the script list is present but empty. Either the cache is genuinely
     *    cold (the walk then invalidates nothing, costing a few milliseconds
     *    once) or the host is reporting something we do not understand. Both
     *    are better served by looking at the disk than by concluding there is
     *    no work to do.
     *
     * @param mixed  $status Return value of opcache_get_status(true).
     * @param string $root   Absolute plugin directory.
     * @return string[]|null Absolute paths, or null when the status cannot answer.
     */
    function abj404_opcache_scripts_from_status($status, $root) {
        if (!is_array($status)) {
            return null;
        }
        if (array_key_exists('opcache_enabled', $status) && !$status['opcache_enabled']) {
            return null;
        }
        if (!empty($status['file_cache_only'])) {
            return null;
        }
        if (!isset($status['scripts']) || !is_array($status['scripts']) || $status['scripts'] === array()) {
            return null;
        }
        return abj404_opcache_scripts_under($status['scripts'], $root);
    }
}

if (!function_exists('abj404_opcache_target_files')) {
    /**
     * Derive the set of plugin files whose bytecode may be stale.
     *
     * Preferred source is the shared opcode cache's own script list, which is
     * exact (only cached files can be stale) and costs no filesystem access.
     * The directory walk is the fallback for every configuration where that
     * list cannot answer; see abj404_opcache_scripts_from_status().
     *
     * @param string $root Absolute plugin directory.
     * @return array{files: string[], source: string, truncated: bool}
     */
    function abj404_opcache_target_files($root) {
        if (function_exists('opcache_get_status')) {
            // Suppressed because a host with opcache compiled in but disabled
            // for this SAPI raises a warning here rather than returning false.
            $fromStatus = abj404_opcache_scripts_from_status(@opcache_get_status(true), $root);
            if ($fromStatus !== null) {
                return array(
                    'files' => $fromStatus,
                    'source' => 'opcache-script-list',
                    'truncated' => false,
                );
            }
        }

        $collected = abj404_opcache_collect_plugin_php_files($root);

        return array(
            'files' => $collected['files'],
            'source' => 'directory-walk',
            'truncated' => $collected['truncated'],
        );
    }
}

if (!function_exists('abj404_opcache_refresh_after_upgrade')) {
    /**
     * Flush stale plugin bytecode when the on-disk plugin version has changed.
     *
     * Must be called from `404-solution.php` before any other plugin file is
     * required. Returns a result record rather than a bare list so the boot path
     * can stash it for diagnostics without this function needing a logger (none
     * exists this early in the boot).
     *
     * Known limit, deliberately not engineered around: the version stamp lives
     * in the database, which is shared across a multi-server deployment, while
     * the opcode cache is per PHP master process. On such a deployment the node
     * that first observes the version change flushes; the others skip and fall
     * back to opcache's own timestamp revalidation, i.e. the behaviour every
     * node had before this guard existed. Making the stamp per-node would mean
     * inventing a node identity that survives restarts and is stable behind a
     * load balancer, which is a larger and more fragile problem than the
     * seconds-wide window it would close.
     *
     * @return array{ran: bool, reason: string, invalidated: string[], scanned: int, truncated: bool, source: string}
     */
    function abj404_opcache_refresh_after_upgrade() {
        $result = array(
            'ran' => false,
            'reason' => '',
            'invalidated' => array(),
            'scanned' => 0,
            'truncated' => false,
            'source' => '',
        );

        if (!defined('ABJ404_PATH') || !defined('ABJ404_VERSION')) {
            $result['reason'] = 'boot-constants-missing';
            return $result;
        }

        $stamp = abj404_opcache_read_version_stamp();
        if ($stamp === null) {
            // Without a persistent stamp there is no way to make this one-time,
            // and flushing the plugin's whole bytecode set on every single
            // request is a worse bug than the one being fixed. opcache's own
            // timestamp revalidation stays the backstop.
            $result['reason'] = 'options-api-unavailable';
            return $result;
        }
        if ($stamp === (string) ABJ404_VERSION) {
            $result['reason'] = 'version-unchanged';
            return $result;
        }

        // Claim the upgrade BEFORE doing any work, not after. The stamp is what
        // makes this one-time, so if it cannot be persisted -- read-only replica,
        // full disk, options table missing -- then doing the work anyway means
        // repeating it on every single request from now on. On a host that falls
        // back to the directory walk that is seconds of boot time per request:
        // far worse than the seconds-wide staleness window being closed. Claim
        // first, and if the claim does not stick, do nothing at all.
        //
        // The cost of claiming first is that a request killed between the claim
        // and the flush leaves the flush undone. That degrades to exactly the
        // pre-guard behaviour (opcache revalidates on its own schedule), so the
        // trade is strictly in favour of claiming first.
        $result['ran'] = true;

        if (!abj404_opcache_write_version_stamp((string) ABJ404_VERSION)) {
            // update_option() also reports false when the stored value already
            // equals the new one, which here means a concurrent request claimed
            // this upgrade between our read and our write and is doing the flush.
            // Both outcomes mean "do nothing", but they are different facts and
            // the reason string is the only record either one leaves.
            $result['reason'] = (abj404_opcache_read_version_stamp() === (string) ABJ404_VERSION)
                ? 'claimed-by-concurrent-request'
                : 'stamp-write-failed';
            return $result;
        }

        if (!function_exists('opcache_invalidate')) {
            $result['reason'] = 'opcache-unavailable';
            return $result;
        }
        if (abj404_opcache_api_is_restricted(ini_get('opcache.restrict_api'), __FILE__)) {
            $result['reason'] = 'opcache-api-restricted';
            return $result;
        }

        $collected = abj404_opcache_target_files(ABJ404_PATH);
        $result['scanned'] = count($collected['files']);
        $result['truncated'] = $collected['truncated'];
        $result['source'] = $collected['source'];

        foreach ($collected['files'] as $file) {
            // A file that is not currently cached returns false; that is a
            // normal outcome here, not an error, so only successes are recorded.
            if (@opcache_invalidate($file, true)) {
                $result['invalidated'][] = $file;
            }
        }

        $result['reason'] = 'version-changed';

        return $result;
    }
}
