# 404 Solution #

Automatically redirect 404 errors to the right page using a 7-engine matching pipeline and spell-checking algorithm. Zero configuration required.

## Description ##

Stop losing visitors and search rankings to broken links. **404 Solution automatically redirects 404s to the right page** — not just your homepage — using a 7-engine matching pipeline that includes a spell-checking algorithm to find what visitors actually typed.

**Example:** A visitor hits `/prodcut/awesome-item` (typo). Most 404 redirect plugins send them to your homepage. 404 Solution's spell-checker finds `/product/awesome-item` and redirects them there automatically.

### Why 404 Solution Is Different ###

Most redirect plugins fall into two categories: tools that require you to manually write every rule, or tools that blindly send every 404 to your homepage. 404 Solution does neither.

* **A spell-checking algorithm that actually matches typos.** Using Levenshtein distance and N-gram scoring, 404 Solution catches `/prodcut/`, `/categroy/`, and `/wooocmmerce/` and finds the real destination — something no other free plugin does.
* **7 matching engines, in sequence.** Slug match > URL fix > Title keywords > Category/tag paths > Content search > Spelling similarity > Archive fallback. The first engine with a confident match wins.
* **Automatic, from day one.** Install and activate. 404 Solution starts capturing and redirecting immediately. You can tune it; you don't have to.
* **Redirect management when you need it.** Full manual redirect editor, regex support, bulk actions, and CSV/JSON import compatible with Redirection's export format.
* **Debug mode that shows its work.** Enable debug logging and see exactly which engine chose a redirect and why — a level of transparency no competing plugin offers.
* **Built for real-world hosting.** Self-healing database tables, auto-recovery from corruption, automatic log trimming, and zero wp-admin-wide banners.
* **Ships with extras most plugins sell separately.** HTTP 410 Gone, 307/308/451 status codes, Google Search Console integration, REST API, WP-CLI support, email digest reports, and security probe detection — all free.

### Unlike plugins that blindly redirect to your homepage ###

A 404-to-homepage redirect tells Google your broken URL is the same page as your homepage. That creates a soft 404 — a page Google may index as duplicate content, eroding crawl budget and ranking over time.

404 Solution redirects to the *correct* destination, or returns a proper 410 Gone when content is permanently removed. Both outcomes are better for search engines and for visitors.

### How It Works ###

1. A visitor reaches a URL that returns a 404.
2. 404 Solution runs the URL through its 7-engine pipeline.
3. Each engine tries to find a confident match above its score threshold.
4. The first engine to find a match wins. The visitor is redirected to the correct page.
5. The redirect is logged. You can review, edit, or delete it from the dashboard.

The whole process adds no perceptible latency for visitors on non-404 pages. The matching pipeline only runs when a genuine 404 occurs.

### Key Features ###

**Intelligent Automatic Matching**

* 7-engine matching pipeline (slug, URL fix, title, category/tag, content, spelling, archive)
* Levenshtein distance + N-gram scoring catches genuine typos
* Per-engine confidence thresholds — tune aggressiveness per engine
* Conditional engine groups — override the matching strategy for specific URL patterns
* Slug-change auto-detection — redirects created automatically when you rename a post
* Trash/deletion monitoring — redirect created automatically when a post is deleted

**Redirect Management**

* Manual redirect editor with bulk actions
* Full regular expression support
* CSV and JSON import/export (compatible with Redirection plugin format)
* .htaccess and Nginx server-level export
* Per-post and per-term exclusion via meta box
* HTTP status codes: 301, 302, 307 (method-preserving temporary), 308 (method-preserving permanent), 410 Gone, 451 Unavailable For Legal Reasons, and Meta Refresh
* GDPR-compliant: IP addresses are hashed before storage — never written to disk in plain text

**404 Monitoring and Logging**

* Captures every 404 hit with referrer, user agent, and timestamp
* Automatic log trimming with configurable disk usage limits
* Security monitoring — flags vulnerability scanner probes (.env, /wp-config.php, phpMyAdmin, etc.)

**Reporting and Diagnostics**

* Stats dashboard with traffic trend charts (404s, redirects, captures over time)
* Email digest reports
* Debug logging — see which engine fired and why
* Google Search Console integration — import crawl errors and push fixes
* Internal link scanner to find broken links before visitors do

**Developer and Integrator Tools**

* REST API for redirect management
* WP-CLI subcommands (list, create, delete, import, export)
* Scheduled maintenance cron
* Full WordPress Multisite support

**Compatibility**

* WooCommerce (products, variations, categories, custom URLs)
* Yoast SEO, Rank Math
* WPML, Polylang, TranslatePress
* Easy Digital Downloads
* All custom post types and custom taxonomies

### Perfect For ###

* **eCommerce sites** (WooCommerce, EDD) with changing product URLs
* **Content sites** with evolving permalink structures
* **Migrated sites** where old URLs need to map to new content
* **Large sites** with thousands of pages where manual redirects are impractical

## Installation ##

1. Unzip the files and upload the contents to `/wp-content/plugins/`.
2. Activate the plugin.
3. Use the `Settings -> 404 Solution` options page to set the options.

## Frequently Asked Questions ##

### How is this different from the Redirection plugin? ###

Redirection is a manual redirect manager — you write the rules, it applies them. That is useful when you know in advance which URLs will break. 404 Solution handles the URLs you don't know about: it automatically finds the right destination using a 7-engine matching pipeline and a spell-checking algorithm.

**Example:** If a visitor types `/prodcut/awesome-item`, Redirection does nothing unless you manually created that specific rule. 404 Solution finds `/product/awesome-item` automatically.

The two plugins are complementary. Many sites run both: Redirection for planned migrations and 404 Solution for everything else.

### How is this different from 404 to 301? ###

404 to 301 redirects ALL 404 errors to one fixed destination (usually the homepage). 404 Solution finds the actual page the visitor was looking for. Sending visitors to your homepage is poor UX and can create soft 404 problems in Google Search Console.

### How is this different from WP 404 Auto Redirect to Similar Post? ###

WP 404 Auto Redirect uses keyword matching, which works when the URL contains recognizable words from a post title. It fails on character-level typos — a URL like `/prodcut/` has no keyword that matches "product."

404 Solution adds Levenshtein spell-checking, which catches transpositions and typos regardless of whether the keywords match. It also includes: full 404 logging, manual redirect management, CSV import/export, 410/451 status codes, GDPR IP hashing, debug logging, REST API, and WP-CLI support.

### What HTTP status codes are supported? ###

**301** (permanent redirect), **302** (temporary redirect), **307** (temporary, method-preserving), **308** (permanent, method-preserving), **410 Gone** (correct response for permanently deleted content — better for SEO than a 301 to homepage), **451 Unavailable For Legal Reasons**, and **Meta Refresh** (client-side HTML redirect).

Using 410 instead of redirecting deleted content to your homepage produces better results in Google Search Console and prevents soft-404 indexing.

### Will this slow down my site? ###

No. The matching pipeline only runs when a genuine 404 occurs — normal page loads are unaffected. For large sites, 404 Solution uses N-gram indexing and caching tables to keep matching fast. Once a redirect is established, subsequent hits are served from the redirect table directly, bypassing the pipeline.

### Does it work with WooCommerce? ###

Yes. 404 Solution indexes WooCommerce products, product variations, product categories, and tags. When a product URL changes or a product is deleted, the appropriate redirect is created automatically.

### What happens when a redirect destination is deleted? ###

404 Solution detects when a destination post is trashed or deleted and flags the redirect. Redirects pointing to deleted content are highlighted in the redirect table so nothing slips through unnoticed. The daily maintenance cron automatically removes orphaned auto-redirects.

### Can I import redirects from another plugin? ###

Yes. 404 Solution imports CSV and JSON in the Redirection plugin's export format. It can also directly import from Rank Math, Yoast SEO, AIOSEO, and Safe Redirect Manager's database tables from the Tools tab.

### Is it GDPR compliant? ###

Yes. IP addresses are hashed using a one-way algorithm before storage — the original IP is never written to disk. Log retention limits are configurable. No data is transmitted to external servers.

### Does it support regex? ###

Yes. Manual redirect rules support full regular expression syntax for source URLs.

### Does it work with WPML, Polylang, or TranslatePress? ###

Yes. 404 Solution detects the active language from the multilingual plugin's API and resolves redirect destinations to the correct translated version automatically.

### Can I use WP-CLI to manage redirects? ###

Yes. 404 Solution includes WP-CLI subcommands for listing, creating, deleting, importing, and exporting redirects — useful for scripted migrations and DevOps workflows.

### Does it work after a site migration? ###

Yes. 404 Solution is ideal for site migrations: slug-change auto-detection, URL typo correction (strips `.html`, `.php`, `.asp`), hierarchical category path resolution, and regex redirects for bulk pattern changes.

### Can I redirect all 404s to a specific page? ###

Yes. Go to **Settings > 404 Solution > Redirect all unhandled 404s to** and select your preferred fallback page.

### How do I manage log files and disk usage? ###

Log cleanup is automatic. Configure maximum log size under **Options > General Settings > Maximum log disk usage**. You can also manually clear logs from the Logs page.

### Can I exclude certain URLs from being processed? ###

Yes. Use **Options > Advanced Settings > Files and Folders Ignore Strings** to add paths to ignore. Per-post and per-term exclusion is also available via the edit screen checkbox.

### How do I exclude a specific post or page? ###

Edit the post/page and check the "Exclude from 404 Solution redirects" checkbox in the sidebar. Category and tag terms can also be excluded via term meta on the edit screen.

### Have you written any other programs? ###

Check out [AJ Experience](https://www.ajexperience.com/) for other useful tools and resources.

## Screenshots ##

1. **Redirect Dashboard** — Active redirects with status code badges (301, 302, 307, 308, 410, 451) and match confidence scores. Sort, search, and bulk-edit without leaving the page.
![1. Redirect Dashboard](https://plugins.svn.wordpress.org/404-solution/trunk/assets/screenshot-1.jpg)

2. **Stats and Trends** — Traffic trend charts plotting 404 hits, redirects, and captures over time. See which URLs break most often.
![2. Stats and Trends](https://plugins.svn.wordpress.org/404-solution/trunk/assets/screenshot-2.jpg)

3. **Debug Mode** — Enable debug logging to see exactly which of the 7 engines fired, what score it assigned, and why it chose that destination.
![3. Debug Mode](https://plugins.svn.wordpress.org/404-solution/trunk/assets/screenshot-3.jpg)

4. **Captured 404s** — Every 404 logged with URL, referrer, user agent, and timestamp. Create a redirect for any row with one click.
![4. Captured 404s](https://plugins.svn.wordpress.org/404-solution/trunk/assets/screenshot-4.jpg)

5. **Settings Page** — Enable/disable individual matching engines, set per-engine confidence thresholds, configure fallback behavior.
![5. Settings Page](https://plugins.svn.wordpress.org/404-solution/trunk/assets/screenshot-5.jpg)

6. **Email Digest** — Weekly email digest with top 404 URLs and hit counts.
![6. Email Digest](https://plugins.svn.wordpress.org/404-solution/trunk/assets/screenshot-6.jpg)

## Changelog ##

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

## Version 4.1.2 (Apr 16, 2026) ##

**Bug Fixes**

* Fixed spell checker consuming ~61MB of memory on large sites — restructured the algorithm to use ~16KB regardless of site size.
* Fixed a race condition in the `start_ts` column migration that could cause errors when multiple processes triggered the upgrade simultaneously.
* Fixed HOME type pages displaying the wrong title in the suggestion results.
* Fixed admin settings page showing a blank page instead of a visible error when the current user lacks the required permission.

**Improvements**

* Added all SQL files to the boot integrity check, ensuring corrupted or missing schema files are detected during plugin startup.

## Version 4.1.1 (Apr 9, 2026) ##

**Bug Fixes**

* Fixed statistics page showing all zeros even when the logs page had data. Three separate bugs combined to cause this: the trend chart SQL was comparing `dest_url IS NULL` instead of `dest_url = '404'` (never matching real 404 entries); the stats dashboard returned an empty placeholder on first load instead of computing real data; and `getStatsCount()` threw an exception on empty query results, causing cascading failures that zeroed out all stats.

**New Features**

* Added heartbeat debug log emails for opted-in sites. Sites with the "send error logs" option enabled now have a 1-in-100 daily chance of sending their full debug zip even when no errors are detected, confirming the error-reporting pipeline is working. Subject line reads "heartbeat" instead of "error" for easy filtering.

## Version 4.1.0 (Apr 4, 2026) ##

**New Features**

* Added built-in 404 suggestion page with one-click setup — selecting "Suggest similar pages" automatically creates a page with the `[abj404_solution_page_suggestions]` shortcode. No manual page creation or shortcode knowledge required.
* Simplified 404 behavior setting with visual tile picker — choose between "Suggest similar pages" (recommended), "Redirect to homepage", "Custom page", or "Theme default 404" with a single click.
* Block editor notice when editing the system suggestion page — warns editors that the page is managed by 404 Solution to prevent accidental shortcode removal.

**Bug Fixes**

* Fixed infrastructure database errors (disk full, read-only, crashed tables, connection lost) being logged at ERROR level from direct query call sites, triggering unnecessary developer email reports. All 17 direct-$wpdb error sites now use centralized infrastructure error classification and log as WARN.
* Fixed multisite cross-prefix missing-table errors being logged at ERROR level — when wp-cron references another subsite's table, the error is now correctly classified as WARN since it's not actionable from the current site's context.
* Fixed N-gram index creation failing when expected columns were missing from the logs table — now guards against missing columns before attempting index creation.
* Fixed N-gram cache rebuild progress exceeding 100% in some edge cases.
* Fixed "required" attribute remaining on custom page picker input when switching away from "Custom page" behavior tile.
* Improved prefix mismatch diagnostic message to distinguish multisite installations (normal cross-subsite references) from single-site prefix mismatches (wp-config.php issue).

## Version 4.0.4 (Mar 29, 2026) ##

**Bug Fixes**

* Fixed multisite activation/upgrade skipping orphaned table adoption — batch methods now run adoption after table creation, ensuring migrated data is recovered on multisite networks.
* Fixed DDL schema comparison failing on MariaDB servers that use `COLLATE=utf8mb4_unicode_ci` (equals sign) instead of MySQL's `COLLATE utf8mb4_unicode_ci` (space), causing unnecessary schema rebuild loops.
* Fixed `doEmptyTrash()` executing an empty SQL query when called with an unrecognized sub-page parameter — now early-returns after logging the error.
* Fixed logs URL filter failing when `logsid` was passed as a non-string type, causing a type error in `sanitizeForSQL()`.
* Fixed invalid UTF-8 byte sequences in captured URLs corrupting log INSERT queries — added `mb_convert_encoding()` sanitization at the log entry boundary.
* Fixed DDL normalization not stripping `DEFAULT NULL` (which is implicit for nullable columns), causing infinite schema diff loops on some MySQL versions.
* Fixed orphaned table adoption running on every page load instead of once — adopted prefixes are now recorded in `wp_options` to prevent re-detection.
* Fixed table adoption running before target tables existed — adoption now executes after `runInitialCreateTables()`.

**Internationalization**

* Completed translations for all 204 pending strings across 12 languages.
* Added proper `_n()` plural forms for redirect count strings.
* Fixed culturally inappropriate translations and religious idioms in warning messages.
* Fixed fuzzy flags, broken format specifiers, and missing slug translations in PO files.

## Version 4.0.3 (Mar 28, 2026) ##

**Bug Fixes**

* Fixed filter bar inputs and selects overflowing below their container's bottom border due to WordPress admin styles setting an inflated line-height on form controls.
* Fixed Logs tab URL search input being too narrow — it now dynamically grows to fill available space.
* Fixed database error notices not appearing on the plugin admin page when a table is missing or cannot be created.

## Version 4.0.2 (Mar 28, 2026) ##

**New Features**

* Orphaned table adoption — automatically detects and recovers plugin data left behind by site migrations or table prefix changes. Uses slug-matching verification to confirm data ownership before adopting.
* Graceful admin screen when plugin files are missing or corrupt — instead of a white screen, shows a diagnostic page listing missing files with reinstall instructions (Error 18).
* Improved pipeline trace display with color-coded status badges for easier log reading.

**Bug Fixes**

* Fixed table prefix normalization — centralized 15+ direct `$wpdb->prefix` usages to go through the lowercased prefix helper, preventing "Table doesn't exist" errors on case-sensitive MySQL servers after table rename.
* Fixed `renameAbj404TablesToLowerCase()` running unnecessarily on MySQL servers with `lower_case_table_names >= 1` (where MySQL already handles table name casing).
* Fixed orphaned redirect cleanup failing on sites with non-default database table prefixes.
* Fixed N-gram cache race condition where concurrent TRUNCATE and INSERT operations could corrupt the spelling cache.
* Fixed timezone handling — replaced `date_default_timezone_set()` with WordPress `wp_date()` for timezone-safe date formatting.
* Fixed ErrorHandler switch statement bug that could route errors to the wrong handler.
* Fixed `deleteSpecifiedRedirects` type filter not correctly constraining purge operations.
* Fixed DDL schema comparison incorrectly flagging non-zero quoted integer defaults (e.g. `DEFAULT '1'` vs `DEFAULT 1`) as schema differences.

**Security**

* Added defense-in-depth guard that prevents schema migrations from accidentally dropping all columns from a table.

**Internal**

* Column-matched INSERT for table adoption — uses `SHOW COLUMNS` and `array_intersect` to handle schema drift between plugin versions when adopting data from old-prefix tables.
* Major codebase refactoring: consolidated duplicated DataAccess patterns, unified AJAX security boilerplate into a shared trait, extracted shared helpers for multisite batch processing and DDL normalization.
* Replaced `$_REQUEST[ABJ404_PP]` message bus with a typed `RequestContext` object.
* Removed dead code: unused settings method, stale DDL builders, and redundant index verification logic.

## Version 4.0.1 (Mar 25, 2026) ##

**Bug Fixes**

* Fixed perpetual "still differences" schema errors on the engine_profiles table caused by MySQL quoting numeric defaults differently than the goal DDL (e.g. `default '1'` vs `default 1`).
* Fixed missing-table auto-repair flooding admins with error emails even when repair succeeded. Now only sends an email if repair actually fails.
* Fixed blank screen after dismissing the review notice.
* Fixed GSC integration sending unbounded API requests — added URL cap, corrected chunk size, and added a circuit-breaker.
* Fixed full-table scan in log ID/URL query by adding LIMIT 500.
* Fixed PHP 8.4 deprecation warning in CSV export (`fputcsv()` missing `$escape` parameter).
* Removed dead PDF email attachment feature.
* Fixed Logs table layout — long URLs no longer overflow into adjacent columns, and the Date column is no longer truncated.
* Fixed redirect not firing on WordPress 6.9+ when `class-wp-font-face.php` sends output before headers.

**Improvements**

* Added pipeline trace for per-request detail logging in the Logs tab — click the arrow on any log row to see every step of the redirect decision process.

## Version 4.0.0 (Mar 24, 2026) ##

**New Features**

* Conditional engine groups (engine profiles) — override the matching strategy for specific URL patterns.
* Google Search Console integration with guided setup wizard — import crawl errors and push fixes.
* Email digest reports — weekly summary of top 404s and redirect activity.
* REST API for redirect management.
* WP-CLI overhaul — list, create, delete, import, export, and test subcommands.
* Cross-plugin importer — import redirects from Rank Math, Yoast SEO, AIOSEO, and Safe Redirect Manager with a preview step.
* HTTP 410 Gone, 451 Unavailable For Legal Reasons, 307/308, and Meta Refresh redirect types.
* Match confidence column, filter, and stats card — see how confident the engine was for each redirect.
* Auto-redirect when published posts are trashed or permanently deleted.
* Trend analytics dashboard — traffic trend charts plotting 404s, redirects, and captures over time.
* Server config export for nginx, Cloudflare Worker, Netlify, and Vercel.
* Stale cache detection and dead destination suspension.
* Send Feedback link on the plugins page.

**Bug Fixes**

* Fixed mass redirect deletion deleting ALL redirects when threshold was empty.
* Fixed category/tag type constants being swapped — category redirects pointed to tags and vice versa.
* Fixed CSV export/import round-trip losing redirect codes (always stored as 301).
* Fixed Redirection-format export hardcoding 301, losing actual redirect codes.
* Fixed regex redirects ignoring per-redirect code and always using the global default.
* Fixed AMP stripping corrupting multibyte URLs.
* Fixed spell checker byte/character mismatch on multibyte strings.
* Fixed log hits stripping valid multibyte Unicode from URLs.
* Fixed spelling cache returning wrong data type, breaking suggestions.
* Fixed database error storms flooding site admin emails.

## Version 3.3.7 (Mar 20, 2026) ##
* Improvement: The plugin now automatically repairs corrupted plugin tables (MySQL errno 1034 "Incorrect key file") and retries the failed operation, so transient disk-level corruption no longer causes user-visible errors.
* Improvement: The activity log table is now stored on InnoDB, eliminating the "table is full" (errno 1114) failure mode that affected MyISAM tables. If the log table fills up, the plugin automatically trims the oldest 1,000 entries to free space and retries.
* Improvement: Admin notices for database problems now only appear on the plugin's own settings page rather than on every WordPress admin screen.
* Fix: Corrected a latent PHP error in the "Incorrect key file" recovery path that would have triggered a fatal error instead of attempting repair.

## Version 3.3.6 (Mar 20, 2026) ##
* Fix: Prevented a spurious database error during schema upgrades where a comment in an internal SQL file was mistakenly interpreted as a column definition, causing a malformed ALTER TABLE statement to be logged. No data was affected.

## Version 3.3.5 (Mar 20, 2026) ##
* Improvement: Large source files refactored into focused trait files — no functional changes, but the codebase is easier to navigate and maintain.
* Improvement: Strict type checking (PHPStan level 9) enforced throughout the codebase, catching and fixing potential type-mismatch errors before they could affect users.

## Version 3.3.4 (Mar 19, 2026) ##
* FIX: Fixed an upgrade bug introduced in 3.3.3 that accidentally cleared the admin page view cache. No redirect data was affected — the cache rebuilds automatically on the next page load.
* FIX: Schema comparison now refuses to drop columns when the target schema parses to zero columns, preventing a whole class of accidental data-wipe bugs.
* FIX: One-time repair detects and drops any `view_cache` table left in a stripped state by the 3.3.3 bug, so it is cleanly recreated on the next plugin load.

## Version 3.3.3 (Mar 19, 2026) ##
* Fix: Uninstaller no longer produces a PHPStan type error when `$wpdb->get_results()` returns null on edge-case database configurations.
* Improvement: Plugin table cleanup on blog deletion, uninstall, and collation repair now uses dynamic discovery, ensuring any future tables are automatically included.

## Version 3.3.1 (Mar 18, 2026) ##
* Fix: Resolved "Table wp_abj404_view_cache doesn't exist" errors on some v3.3.0 upgrades where the cache table was not created.
* Fix: Reduced debug log noise when WP_DEBUG is enabled.
* Fix: Orphaned-redirect cleanup cron no longer triggers errors on sites with incomplete database installs.
* Fix: Plugin upgrades no longer perform unnecessary database ALTER TABLE operations.
* Fix: Bot requests with malformed character encodings no longer cause database errors.

## Version 3.3.0 (Mar 15, 2026) ##
* Add: 7-engine matching pipeline — slug matching, URL typo correction, title keywords, category/tag path matching, content keywords, spelling similarity, and archive fallback.
* Add: Title keyword matching engine — finds posts whose title words appear in the broken URL, with fuzzy Levenshtein scoring for near-matches.
* Add: Content keyword matching engine — searches post body text when title and slug matching fail.
* Add: Category/tag matching engine — resolves hierarchical paths like `/shop/electronics` to the right taxonomy term or finds posts within a category.
* Add: URL typo correction engine — strips `.html`, `.php`, `.asp` and other file extensions, plus trailing punctuation from copy-paste errors.
* Add: Post type archive fallback engine — redirects to archive pages when no single-post match is found.
* Add: Per-engine score thresholds — fine-tune how aggressive each matching strategy is (Advanced Settings).
* Add: Per-post and per-term exclusion — exclude individual posts, pages, or taxonomy terms from automatic redirects via edit screen checkbox.
* Add: Orphaned redirect cleanup — daily cron automatically removes auto-redirects whose destination post was deleted or unpublished.
* Improvement: WordPress.org discoverability — updated plugin description, tags, and FAQ for better search visibility.
