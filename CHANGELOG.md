# Changelog #

## Version 4.1.13 (May 2, 2026) ##

**Bug Fixes**

* Fixed "Could not load table data" timeouts on the Captured 404s and Page Redirects admin tables. On sites with a large WordPress posts table, the count query now skips unrelated JOINs when no search filter is active, and admin table caches are warmed in the background across multiple short requests instead of one long one — so the tables reliably load even when individual queries are slow.

**Improvements**

* The plugin admin footer now includes a collapsible Debug Info section showing per-stage AJAX timings, errors, and database query timings for the Captured 404s and Page Redirects views. When reporting a slow or stuck admin table, you can copy this directly without opening browser developer tools.
* SQL errors are now recorded before any automatic recovery (table repair, retry with a relaxed collation) runs, so the original cause of a failing query stays visible in the support log instead of being overwritten by the recovery attempt's own messages.

## Version 4.1.12 (May 1, 2026) ##

**Bug Fixes**

* Fixed Captured 404s and Page Redirects table AJAX requests timing out while background log-cache maintenance was running. Maintenance that is triggered by an AJAX table load now runs through scheduled background tasks instead of shutdown work tied to the same HTTP request.
* Fixed debug-log setup failing on sites with negative or fractional WordPress timezone offsets.
* Fixed diagnostic logging paths that could throw while trying to report another error, which could hide the original problem behind a secondary logging failure.

## Version 4.1.11 (May 1, 2026) ##

**Bug Fixes**

* Fixed loss of captured-404 history when the database performed automatic repair of a crashed log table. The plugin previously dropped and recreated the affected table after repeated repair failures, destroying logged hits in the process. It now leaves the table alone if repair cannot succeed, preserving your captured-404 history.
* Fixed loss of log data during repair of damaged plugin tables that were missing their primary key column. This was the underlying cause of the log-history loss reported during the 4.1.6 to 4.1.7 upgrade. The repair now preserves all existing rows instead of recreating the table from scratch.
* Fixed timeouts during the nightly log-cache rebuild on sites with aggressive log retention. The rebuild now reliably completes within shared-host time limits.

**Improvements**

* The Captured 404s list and other admin views that read log data now load substantially faster on sites with large log tables. A new indexed column replaces a slower per-row computation; existing sites are updated in the background during nightly maintenance and speed up over time.

## Version 4.1.10 (Apr 30, 2026) ##

**Bug Fixes**

* Fixed disk-full, read-only, and crashed-table conditions encountered during log flush and N-gram cron scheduling escalating to error level and triggering developer email reports. These hosting conditions are now classified as warnings — the plugin already degrades past them — so they no longer spam the admin's inbox.
* Fixed admin AJAX error notices showing only generic HTTP/textStatus information when a paginated admin view timed out or returned a 500. The notice now includes the elapsed request time, the server-side processing stage that was in flight when the failure occurred, and the redacted SQL of the failing query when available, so the cause is identifiable without a server log dump.
* Fixed a parse-time fatal on PHP 7.4 through 8.1 caused by two trait-level constants introduced with the canonical-URL backfill work. The constants are now declared on the using class so the trait file parses cleanly on every supported PHP version.

**Improvements**

* The Page Redirects and Captured 404s admin views are now significantly faster on installs with very large redirects tables. Each redirect now stores a precomputed canonical URL that is indexed and JOINed against the hits rollup, eliminating the per-row CONCAT/TRIM evaluation that could time out the admin AJAX request on sites with hundreds of thousands of captured rows. The column is backfilled in chunks during the upgrade and the nightly maintenance cron, so large sites converge across cron ticks without blocking any single request.
* The daily cron that flags dead-destination redirects now scales with URL count rather than raw log-row count. The query now JOINs against the precomputed `logs_hits` rollup with a new `failed_hits` column, completing in milliseconds even on sites with millions of log rows where it previously timed out.
* The admin AJAX timeout for explicit user actions (sorting, filtering, pagination) was raised from 15 seconds to 45 seconds. Background detect-only refreshes still use the tight 15-second budget, so the longer timeout only applies when the admin is actively waiting.
* Several catch blocks across the plugin that previously swallowed exceptions silently now emit a warning breadcrumb to the support log, so unexpected failure paths are visible in support bundles instead of vanishing.

## Version 4.1.9 (Apr 29, 2026) ##

**Bug Fixes**

* Fixed potential over-deletion of log history during the daily cron cleanup. The destructive DELETE was sized using an approximate row count from MySQL metadata, which can drift by orders of magnitude on InnoDB. The cron now gates the delete on an exact byte-size check before deciding what to remove.
* Fixed captured-URL counts undercounting when the same URL was logged with and without leading or trailing slashes (e.g. `/foo`, `foo`, `/foo/`). URL variants now collapse to a single canonical row in the hits rollup, restoring slash-tolerant matching that regressed in 4.1.7.
* Fixed Email Digest dropping captured URLs that had zero recent hits. The "Top Captured 404s" section now lists captured rows with no hits as well as those with hits, matching the on-screen behavior.
* Fixed Email Digest rendering a misleading "No captured 404s in this period" notice while the hits rollup was being rebuilt. The digest now ships with an explicit "Top URLs unavailable: log rollup is being rebuilt" message instead.
* Fixed the high-impact captured-count cache holding a value of zero for 24 hours when the rollup was unavailable or the query errored. The result is now cached only on a successful query, so the count recovers on the next page load.
* Fixed the Stats trend chart cache being polluted with empty results when the trend query errored or timed out.
* Fixed admin search inputs on Page Redirects and Captured 404s rendering a stray "O"-shaped SVG glyph above the search box.
* Fixed the health bar "unavailable" indicator rendering as a transparent dot during rollup rebuild — it now displays as a muted gray dot to communicate the transient state.
* Fixed every frontend 404 falling through to the theme 404 page on installs where the database upgrade could not complete (corrupted DDL files, opcache divergence, restrictive shared hosting). The redirect lookup now falls back to a schema-tolerant query against the existing redirects table, so manual redirects keep firing while the upgrade is stuck. Trade-off: scheduled redirects briefly stop honoring their start/end windows during the degraded window.
* Fixed asynchronous page suggestions failing to surface for long or low-overlap 404 URLs. The async worker now prioritizes recall over worst-case latency, since it already runs out-of-band and is rate-limited.
* Fixed admin tables remaining stuck on "Loading…" indefinitely when the AJAX request to fetch table data hangs (for example, while the plugin is recreating a missing database table on the slow path). The AJAX call now has a 15-second client-side timeout, the retry/fallback path replaces the loading rows with a clear error message instead of just stripping the placeholder attribute, and AJAX errors render a non-blocking admin notice instead of a native browser `alert()` dialog.

**Improvements**

* The Captured 404s and Page Redirects admin tables now render quickly even on sites with millions of log rows. The high-impact captured count, the per-row hit population query, the Email Digest top-captured query, and the Stats trend chart now all read from the pre-aggregated `logs_hits` rollup table, eliminating multi-second full-log scans that could time out behind Cloudflare and other reverse proxies.
* Health bar AJAX is now decoupled from pagination — the redirects table renders immediately while the health bar hydrates in a separate request, so a slow rollup query no longer blocks first paint.
* The daily cron now reads log row counts from MySQL `information_schema` metadata instead of running a full index scan on every nightly tick.
* Permalink keyword cron updates are now issued as a single bulk SQL query instead of up to 500 single-row UPDATEs per cycle, sharply reducing database load on busy sites.
* The anonymous suggestion-compute endpoint now enforces a per-IP rate limit and rejects unauthorized requests before any plugin classes are loaded.

## Version 4.1.8 (Apr 28, 2026) ##

**Bug Fixes**

* Fixed a critical 4.1.7 regression where the `wp_abj404_logs_hits` cache table was dropped during the 4.1.6 → 4.1.7 upgrade and never recreated. Affected sites saw "Table doesn't exist" errors flooding the debug log and triggering email reports. Sites that already upgraded to 4.1.7 will have the table automatically recreated when they upgrade to 4.1.8 — the cache repopulates on the next scheduled rebuild. The destructive table-recreation step that caused this regression has been hardened to require positive evidence before dropping any table.
* Fixed `mysqli_num_fields()` TypeError on PHP 8.1+ MariaDB sites caused by the new query timeout wrapper (4.1.7) being passed to `wpdb::get_results()` for non-SELECT queries. INSERT/UPDATE/DELETE/DDL queries now route through `wpdb::query()` so the wrapper no longer confuses WordPress's query classifier.
* Fixed view-cache table self-repair only running on the upgrade path. Activation, scheduled cron runs, and upgrade now all repair a stripped view-cache table before any further table maintenance, so broken installs heal themselves regardless of how the plugin loads.
* Fixed spurious "Table doesn't exist" debug-log noise and a stale `.notice-error` admin banner during fresh installs. Pre-create maintenance queries are now silent when their target tables don't yet exist.

**Improvements**

* Collation mismatches between database tables are now repaired automatically and silently. Previously these surfaced as admin notices; the plugin now runs collation correction on detection (rate-limited to once per hour), retries the original query, and only logs at debug level — no user action required.

## Version 4.1.7 (Apr 25, 2026) ##

**Bug Fixes**

* Fixed HTTP 524 timeout error on the Captured 404s admin tab for sites behind Cloudflare or other reverse proxies. The hits table rebuild query (which joins the logs table with redirects) is now split into a fast chunked pre-aggregation that scales to millions of log rows.
* Fixed AJAX responses not being flushed before shutdown hooks, which could cause proxy timeouts even when the response data was ready.
* Fixed ANALYZE TABLE running on every Settings page load, causing unnecessary database overhead.

**Improvements**

* All database queries now have automatic execution time limits (not just SELECT queries). INSERT...SELECT, UPDATE, DELETE, and DDL queries are also protected on MariaDB; INSERT...SELECT is also protected on MySQL.

## Version 4.1.6 (Apr 23, 2026) ##

**New Features**

* Automatic query timeout — all SELECT queries now include a server-side execution time limit, preventing runaway queries from locking the database on shared hosting.
* WordPress URL-guess fallback — when no matching engine finds a redirect, the plugin now tries WordPress's built-in URL guessing as a last-resort redirect before returning a 404.

**Improvements**

* Admin pages now load instantly — all heavy database queries on the Redirects, Captured 404s, Logs, and Settings pages are deferred to AJAX, eliminating blocking page loads on large sites.
* Optimized three slow view queries that could cause timeouts on sites with large redirect and log tables.

**Bug Fixes**

* Fixed AJAX error state leaving placeholder loading attributes on admin tables, preventing interaction until page reload.
* Fixed duplicate bottom table separator appearing after AJAX table hydration.
* Fixed Logs page not loading via AJAX due to missing `.perpage` class on the per-page select element.
* Fixed stray search filter and unwanted top pagination placeholders appearing on admin pages.
* Fixed SQL query referencing a non-existent column in the logs-hits join query.

## Version 4.1.5 (Apr 22, 2026) ##

**New Features**

* Simple mode Phase 3 — streamlined UI with contextual guidance, suggested destinations for captured 404s, and auto-created redirects for first-time users. Hides advanced columns and options in Simple mode for a cleaner experience.
* Centralized Google OAuth — one-click Google Search Console connection via a Cloudflare Worker relay, replacing the manual client-ID setup.

**Bug Fixes**

* Fixed "Illegal mix of collations" errors when WordPress core tables use `utf8mb3` and plugin tables use `utf8mb4`. Core columns are now wrapped with `CONVERT(...USING utf8mb4)` before applying `COLLATE`.
* Fixed collation mismatch in `getPublishedImageIDs` query when comparing term names across tables with different collations.
* Fixed `opcache_invalidate()` emitting a PHP warning on hosts that restrict the function via `disable_functions` (e.g. some WP Engine/Flywheel configurations).
* Fixed potential out-of-memory crash when the `logs_hits` fallback query ran without a row limit — now capped at 5,000 rows.
* Fixed four small correctness issues in redirect filtering, view queries, settings save, and redirect-table rendering.

**Improvements**

* Hardened admin page rendering against out-of-memory errors, corrupted options, and hook failures — the plugin now always shows its admin menu even when dependencies fail, and catches fatal errors with a shutdown handler fallback.
* Migrated four redirect query methods to the centralized `queryAndGetResults()` handler for consistent error handling and retry logic.

**Internationalization**

* Added Simple mode Phase 3 strings to all 17 locale PO files.
* Added missing admin error message translations across all locale PO files.

## Version 4.1.4 (Apr 20, 2026) ##

**Bug Fixes**

* Fixed "Illegal mix of collations" errors on spell-checker and permalink-cache queries when plugin tables and WordPress core tables use different collations (e.g. `utf8mb4_unicode_520_ci` vs `utf8mb4_unicode_ci`). Extended the `COLLATE` protection pattern to `updatePermalinkCache.sql`, `getPublishedPagesAndPostsIDs.sql`, and `getIDsNeededForPermalinkCache.sql`.
* Fixed redirect edit form rejecting type=0 (Default 404 Page) with "Data not formatted properly" error. The validation was unable to distinguish "no type provided" from "type is 0" after int-casting.
* Fixed plugin tables not being detected after a hosting migration or `$table_prefix` change in `wp-config.php`. The daily maintenance cron now triggers prefix adoption so orphaned tables are auto-recovered without requiring a manual deactivate/reactivate cycle.
* Fixed transient PHP fatal errors during plugin upgrades on hosts with aggressive opcache settings (WP Engine, Flywheel, etc.) caused by stale bytecode from the previous version. Critical class files are now invalidated via `opcache_invalidate()` at the start of the upgrade.

**Improvements**

* The daily maintenance cron now checks and converts table engines back to InnoDB, fixing persistent MyISAM reversions caused by hosting environments that reset the storage engine between plugin upgrades.

## Version 4.1.3 (Apr 17, 2026) ##

**Bug Fixes**

* Fixed spell checker throwing `get_object_vars()` TypeError on PHP 8+ when `get_term()` returns a non-object value (e.g. from a corrupted object cache). The tag and category matching branches now use `is_object()` guards before accessing term properties.
* Fixed Google Search Console integration returning HTTP 400 errors — the API does not support `groupType: 'or'` in dimension filter groups. Each URL is now queried individually.
