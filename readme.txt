=== 404 Solution ===
Contributors: aaron13100
Website: https://www.ajexperience.com/404-solution/
Tags: 404, redirect, 404 redirect, broken links, spell check
Requires at least: 5.0
Requires PHP: 7.4
Tested up to: 6.9
Stable tag: 4.0.1
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Automatically redirect 404 errors to the right page using a 7-engine matching pipeline and spell-checking algorithm. Zero configuration required.

== Description ==

Stop losing visitors and search rankings to broken links. **404 Solution automatically redirects 404s to the right page** — not just your homepage — using a 7-engine matching pipeline that includes a spell-checking algorithm to find what visitors actually typed.

**Example:** A visitor hits `/prodcut/awesome-item` (typo). Most 404 redirect plugins send them to your homepage. 404 Solution's spell-checker finds `/product/awesome-item` and redirects them there automatically.

= Why 404 Solution Is Different =

Most redirect plugins fall into two categories: tools that require you to manually write every rule, or tools that blindly send every 404 to your homepage. 404 Solution does neither.

* **A spell-checking algorithm that actually matches typos.** Using Levenshtein distance and N-gram scoring, 404 Solution catches `/prodcut/`, `/categroy/`, and `/wooocmmerce/` and finds the real destination — something no other free plugin does.
* **7 matching engines, in sequence.** Slug match → URL fix → Title keywords → Category/tag paths → Content search → Spelling similarity → Archive fallback. The first engine with a confident match wins.
* **Automatic, from day one.** Install and activate. 404 Solution starts capturing and redirecting immediately. You can tune it; you don't have to.
* **Redirect management when you need it.** Full manual redirect editor, regex support, bulk actions, and CSV/JSON import compatible with Redirection's export format.
* **Debug mode that shows its work.** Enable debug logging and see exactly which engine chose a redirect and why — a level of transparency no competing plugin offers.
* **Built for real-world hosting.** Self-healing database tables, auto-recovery from corruption, automatic log trimming, and zero wp-admin-wide banners.
* **Ships with extras most plugins sell separately.** HTTP 410 Gone, 307/308/451 status codes, Google Search Console integration, REST API, WP-CLI support, email digest reports, and security probe detection — all free.

= Unlike plugins that blindly redirect to your homepage =

A 404-to-homepage redirect tells Google your broken URL is the same page as your homepage. That creates a soft 404 — a page Google may index as duplicate content, eroding crawl budget and ranking over time.

404 Solution redirects to the *correct* destination, or returns a proper 410 Gone when content is permanently removed. Both outcomes are better for search engines and for visitors.

= How It Works =

1. A visitor reaches a URL that returns a 404.
2. 404 Solution runs the URL through its 7-engine pipeline.
3. Each engine tries to find a confident match above its score threshold.
4. The first engine to find a match wins. The visitor is redirected to the correct page.
5. The redirect is logged. You can review, edit, or delete it from the dashboard.

The whole process adds no perceptible latency for visitors on non-404 pages. The matching pipeline only runs when a genuine 404 occurs.

= Key Features =

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

= Perfect For =

* **eCommerce sites** (WooCommerce, EDD) with changing product URLs
* **Content sites** with evolving permalink structures
* **Migrated sites** where old URLs need to map to new content
* **Large sites** with thousands of pages where manual redirects are impractical

== Installation ==

1. Unzip the files and upload the contents to `/wp-content/plugins/`.
2. Activate the plugin.
3. Use the `Settings -> 404 Solution` options page to set the options.

== Frequently Asked Questions ==

= How is this different from the Redirection plugin? =

Redirection is a manual redirect manager — you write the rules, it applies them. That is useful when you know in advance which URLs will break. 404 Solution handles the URLs you don't know about: it automatically finds the right destination using a 7-engine matching pipeline and a spell-checking algorithm.

**Example:** If a visitor types `/prodcut/awesome-item`, Redirection does nothing unless you manually created that specific rule. 404 Solution finds `/product/awesome-item` automatically.

The two plugins are complementary. Many sites run both: Redirection for planned migrations and 404 Solution for everything else.

= How is this different from 404 to 301? =

404 to 301 redirects ALL 404 errors to one fixed destination (usually the homepage). 404 Solution finds the actual page the visitor was looking for. Sending visitors to your homepage is poor UX and can create soft 404 problems in Google Search Console.

= How is this different from WP 404 Auto Redirect to Similar Post? =

WP 404 Auto Redirect uses keyword matching, which works when the URL contains recognizable words from a post title. It fails on character-level typos — a URL like `/prodcut/` has no keyword that matches "product."

404 Solution adds Levenshtein spell-checking, which catches transpositions and typos regardless of whether the keywords match. It also includes: full 404 logging, manual redirect management, CSV import/export, 410/451 status codes, GDPR IP hashing, debug logging, REST API, and WP-CLI support.

= What HTTP status codes are supported? =

**301** (permanent redirect), **302** (temporary redirect), **307** (temporary, method-preserving), **308** (permanent, method-preserving), **410 Gone** (correct response for permanently deleted content — better for SEO than a 301 to homepage), **451 Unavailable For Legal Reasons**, and **Meta Refresh** (client-side HTML redirect).

Using 410 instead of redirecting deleted content to your homepage produces better results in Google Search Console and prevents soft-404 indexing.

= Will this slow down my site? =

No. The matching pipeline only runs when a genuine 404 occurs — normal page loads are unaffected. For large sites, 404 Solution uses N-gram indexing and caching tables to keep matching fast. Once a redirect is established, subsequent hits are served from the redirect table directly, bypassing the pipeline.

= Does it work with WooCommerce? =

Yes. 404 Solution indexes WooCommerce products, product variations, product categories, and tags. When a product URL changes or a product is deleted, the appropriate redirect is created automatically.

= What happens when a redirect destination is deleted? =

404 Solution detects when a destination post is trashed or deleted and flags the redirect. Redirects pointing to deleted content are highlighted in the redirect table so nothing slips through unnoticed. The daily maintenance cron automatically removes orphaned auto-redirects.

= Can I import redirects from another plugin? =

Yes. 404 Solution imports CSV and JSON in the Redirection plugin's export format. It can also directly import from Rank Math, Yoast SEO, AIOSEO, and Safe Redirect Manager's database tables from the Tools tab.

= Is it GDPR compliant? =

Yes. IP addresses are hashed using a one-way algorithm before storage — the original IP is never written to disk. Log retention limits are configurable. No data is transmitted to external servers.

= Does it support regex? =

Yes. Manual redirect rules support full regular expression syntax for source URLs.

= Does it work with WPML, Polylang, or TranslatePress? =

Yes. 404 Solution detects the active language from the multilingual plugin's API and resolves redirect destinations to the correct translated version automatically.

= Can I use WP-CLI to manage redirects? =

Yes. 404 Solution includes WP-CLI subcommands for listing, creating, deleting, importing, and exporting redirects — useful for scripted migrations and DevOps workflows.

= Does it work after a site migration? =

Yes. 404 Solution is ideal for site migrations: slug-change auto-detection, URL typo correction (strips `.html`, `.php`, `.asp`), hierarchical category path resolution, and regex redirects for bulk pattern changes.

= Can I redirect all 404s to a specific page? =

Yes. Go to **Settings → 404 Solution → Redirect all unhandled 404s to** and select your preferred fallback page.

= How do I manage log files and disk usage? =

Log cleanup is automatic. Configure maximum log size under **Options → General Settings → Maximum log disk usage**. You can also manually clear logs from the Logs page.

= Can I exclude certain URLs from being processed? =

Yes. Use **Options → Advanced Settings → Files and Folders Ignore Strings** to add paths to ignore. Per-post and per-term exclusion is also available via the edit screen checkbox.

= How do I exclude a specific post or page? =

Edit the post/page and check the "Exclude from 404 Solution redirects" checkbox in the sidebar. Category and tag terms can also be excluded via term meta on the edit screen.

= Have you written any other programs? =

Check out [AJ Experience](https://www.ajexperience.com/) for other useful tools and resources.

== Screenshots ==

1. **Redirect Dashboard** — Active redirects with color-coded status badges (301, 302, 307, 308, 410, 451), match confidence scores, and engine names. Filter by manual, automatic, or regex. Sort by any column.
2. **Statistics** — Summary cards for redirects, captured URLs, and daily stats. Match confidence donut chart and 30/90-day trend analytics for 404 hits.
3. **Debug Log** — Real-time debug output showing engine processing, spell-check scoring, candidate evaluation, and redirect decisions for every 404 request.
4. **Captured 404s** — Every unhandled 404 logged with URL, hit count, first-seen and last-seen timestamps. Filter by captured, ignored, or later status.
5. **Settings** — Configure automatic redirect behavior, custom 404 page, auto-deletion rules, default redirect type, and notification preferences.
6. **Email Digest** — Weekly HTML email summarizing captured 404s, resolution rate, and a ranked table of top 404 URLs with color-coded hit badges.

== Changelog ==

= Version 4.0.1 (Mar 25, 2026) =

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

= Version 4.0.0 (Mar 24, 2026) =

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

= Version 3.3.7 (Mar 20, 2026) =
* Improvement: The plugin now automatically repairs corrupted plugin tables (MySQL errno 1034 "Incorrect key file") and retries the failed operation, so transient disk-level corruption no longer causes user-visible errors.
* Improvement: The activity log table is now stored on InnoDB, eliminating the "table is full" (errno 1114) failure mode that affected MyISAM tables. If the log table fills up, the plugin automatically trims the oldest 1,000 entries to free space and retries.
* Improvement: Admin notices for database problems now only appear on the plugin's own settings page rather than on every WordPress admin screen.
* Fix: Corrected a latent PHP error in the "Incorrect key file" recovery path that would have triggered a fatal error instead of attempting repair.

= Version 3.3.6 (Mar 20, 2026) =
* Fix: Prevented a spurious database error during schema upgrades where a comment in an internal SQL file was mistakenly interpreted as a column definition, causing a malformed ALTER TABLE statement to be logged. No data was affected.

= Version 3.3.5 (Mar 20, 2026) =
* Improvement: Large source files refactored into focused trait files — no functional changes, but the codebase is easier to navigate and maintain.
* Improvement: Strict type checking (PHPStan level 9) enforced throughout the codebase, catching and fixing potential type-mismatch errors before they could affect users.

= Version 3.3.4 (Mar 19, 2026) =
* Fix: Fixed an upgrade bug introduced in 3.3.3 that accidentally cleared the admin page view cache. No redirect data was affected — the cache rebuilds automatically on the next page load.
* Fix: Added a safety check to prevent future upgrades from accidentally wiping plugin caches.
* Fix: Sites affected by the 3.3.3 bug will have their cache table automatically repaired on the first admin page load after updating.

= Version 3.3.3 (Mar 19, 2026) =
* Fix: Uninstaller no longer produces a PHPStan type error when `$wpdb->get_results()` returns null on edge-case database configurations.
* Improvement: Plugin table cleanup on blog deletion, uninstall, and collation repair now uses dynamic discovery, ensuring any future tables are automatically included.

= Version 3.3.1 (Mar 18, 2026) =
* Fix: Resolved "Table wp_abj404_view_cache doesn't exist" errors on some v3.3.0 upgrades where the cache table was not created.
* Fix: Reduced debug log noise when WP_DEBUG is enabled.
* Fix: Orphaned-redirect cleanup cron no longer triggers errors on sites with incomplete database installs.
* Fix: Plugin upgrades no longer perform unnecessary database ALTER TABLE operations.
* Fix: Bot requests with malformed character encodings no longer cause database errors.

= Version 3.3.0 (Mar 15, 2026) =
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

= Version 3.2.2 (Mar 14, 2026) =
* FIX: Spell-checker could produce inaccurate match scores on sites with many pages due to an internal data-type mismatch in the large-candidate optimization path.
* Improvement: Improved compatibility with PHP 8.5 and future PHP versions.

= Version 3.2.1 (Mar 2, 2026) =
* FIX: 404 page suggestions now show category names instead of full URLs for WooCommerce product categories and other custom taxonomies.
* Improvement: Database error admin notices now show actionable guidance and the raw MySQL error in an expandable details section.
* Improvement: Database error notices auto-clear once the issue resolves.
* FIX: Disk-full detection now recognizes MySQL error 1114 ("The table '...' is full") format.

= Version 3.2.0 (Feb 17, 2026) =
* Improvement: Faster loading on key admin pages, especially for larger sites.
* Improvement: Clearer in-page status messages while data refreshes, so admins know what is happening.
* FIX: Better handling of database charset/collation differences to reduce SQL errors on some hosts.
* FIX: Improved resilience when database limits or transient DB issues occur, with safer fallback behavior.
* Improvement: Broader CSV import compatibility with common redirect export formats.
* Improvement: General backend and coding maintenance improvements.

= Version 3.1.10 (Jan 21, 2026) =
* Improvement: Add WPML and Polylang-aware redirect translation based on the request language.

= Version 3.1.9 (Jan 20, 2026) =
* FIX: Manual and external redirects now store and match Unicode paths consistently.
* Improvement: URL normalization is now unified across redirects, suggestions, and logs.
* Improvement: Row action hover links stay clickable without resizing rows.

= Version 3.1.8 (Jan 19, 2026) =
* FIX: Preserve Unicode slugs during redirect lookups and allow manual redirect source paths with non-ASCII characters.
* FIX: TranslatePress-aware redirect translation for localized paths with a filter hook for other multilingual plugins.

= Version 3.1.7 (Dec 19, 2025) =
* FIX: Prevent invalid SQL during missing-index creation by parsing index definitions from the plugin SQL templates and emitting structured `ALTER TABLE ... ADD INDEX ...` statements.

= Version 3.1.6 (Dec 18, 2025) =
* FIX: Redirects table pagination/search no longer fails on some MariaDB versions with a SQL syntax error while updating the table.

= Version 3.1.5 (Dec 18, 2025) =
* FIX: Resolve Page Redirects / Captured 404s table search failing on some databases with "Illegal mix of collations ... for operation 'replace'".
* Improvement: Daily maintenance insurance now verifies and repairs plugin table collations (including detecting column-level collation drift) and ensures required indexes exist.

= Version 3.1.4 (Dec 18, 2025) =
* FIX: Page Redirects search AJAX errors now return actionable diagnostics to plugin admins (including PHP fatal/exception details) instead of only a generic WordPress "critical error" message.
* Improvement: AJAX failures are always written to the 404 Solution debug log (with a safe fallback log file if the normal debug log cannot be written).
* Improvement: When a fatal is triggered by another plugin/theme during the 404 Solution table AJAX call, details are captured only when the request originated from the 404 Solution admin screens (reduces unrelated log noise).

= Version 3.1.3 (Dec 17, 2025) =
* FIX: Logs tab dropdown search now returns matching log URLs (instead of always reporting no matches).
* FIX: Page Redirects / Captured 404s table search (press Enter) no longer fails on some environments due to admin-ajax URL/action handling.
* Improvement: When a table AJAX refresh fails, the alert now includes HTTP status + response preview and logs full details to the browser console for easier debugging.

= Version 3.1.2 (Dec 16, 2025) =
* FIX: Captured 404 actions (Ignore/Trash/Restore) now work reliably even when hosts/browsers strip the Referer header (thanks to Larry K for reporting this).
* FIX: Restoring a captured URL from Trash returns it to Captured status (not Ignored).
* FIX: MariaDB index creation no longer fails with a SQL syntax error when adding missing indexes (correct `ADD INDEX IF NOT EXISTS` DDL generation).
* Improvement: "Later" action now preserves current table sorting (orderby/order) when clicked.
* Improvement: Backend treats `abj404action` as an alias for `action` for consistent bulk/action handling.

= Version 3.1.1 (Dec 11, 2025) =
* FIX: Make index creation idempotent for `idx_requested_url_timestamp` (skip existing index, use IF NOT EXISTS when supported) to stop duplicate-key errors during upgrades.
* FIX: Harden log queue flushing with validation/sanitization, duplicate-tolerant inserts, and better error reporting to avoid lost 404 log entries.
* Compatibility: Explicit `str_getcsv` escape parameter for PHP 8.4+ to silence deprecation notices.
* Security: Escaped `filterText` SQL path in ajax pagination to block the reported SQL injection vector (only exploitable by authenticated admin users).

= Version 3.1.0 (Dec 6, 2025) =
* Feature: Async 404 page suggestions - Custom 404 pages sometimes load instantly while suggestions compute in the background.
* Feature: Per-post redirect toggle - Control automatic slug-change redirects on individual posts/pages in Classic Editor, Gutenberg, and Quick Edit.
* Feature: Add Arabic language and RTL layout support.
* Improvement: Optimize category/tag queries for better performance.
* Improvement: Accessibility - WCAG 2.1 AA compliance with table headers, focus indicators, ARIA labels, modal focus trapping, and reduced motion support.
* Improvement: Performance optimization for spell-checking on large sites (N-gram indexing, reduced database queries, memory optimization).
* FIX: Handle corrupted database records gracefully without PHP warnings.

= Version 3.0.8 (Nov 29, 2025) =
* Improvement: Feedback emails now include database collation info even on locked-down hosts (fallback chain for information_schema restrictions).
* FIX: Tooltip z-index issue where destination tooltips appeared behind sticky table header.

= Version 3.0.7 (Nov 28, 2025) =
* Improvement: Load admin pages faster. Load redirects faster.
* Improvement: Add a two question wizard/setup screen for new users.
* Improvement: Add warning icon when a redirect URL looks like regex but isn't marked as one.
* Improvement: Add loading spinner when searching for redirect destinations.
* Security: Multiple security hardening improvements including CSRF protection and XSS prevention.
* FIX: Save settings correctly when using simple mode on the options page.
* FIX: Simple page allows changing the default 404 page destination now.
* FIX: Dark mode checkbox no longer flashes on page load.
* FIX: Setup wizard form submission now works correctly.

= Version 3.0.6 (Nov 27, 2025) =
* FIX: Resolve fatal error "Class ABJ_404_Solution_DataAccess not found" during plugin uninstallation. The Uninstaller now works standalone without requiring the plugin's autoloader.

= Version 3.0.5 (Nov 27, 2025) =
* Improvement: Options page has Simple/Advanced mode.
* Improvement: Made the plugin prettier in general.

= Version 3.0.4 (Nov 24, 2025) =
* FIX: Resolve SQL error "Could not perform query because it contains invalid data" caused by invalid UTF-8 byte sequences in URLs. Added sanitization to strip invalid UTF-8 characters before database storage.
* FIX: Resolve "Table doesn't exist" errors on case-sensitive MySQL installations (lower_case_table_names=0) with mixed-case WordPress prefixes. All plugin table references now use normalized lowercase prefixes to match table creation behavior.

= Version 3.0.3 (Nov 23, 2025) =
* Improved: GDPR compliance in log files (just in case).
* Improved: Some missing translation keys.
* Improved: Deactivation feedback.

= Version 3.0.2 (Nov 22, 2025) =
* FIX: Table creation issues on multisite. (Thanks to debug file participants!)

= Version 3.0.1 (Nov 20, 2025) =
* Improvement: Use accordions on the settings screen instead of chips.
* FIX: Division by 0 in the new ngram filter.
* FIX: Language file format issues for various languages.
* FIX: Correct the deactivate feedback trigger.
* FIX: Log slow query content correctly.
* FIX: Table creation issues on multisites.

= Version 3.0.0 (Nov 17, 2025) =
* Improvement: Add themes. Add automatic dark mode detection for WordPress admin.
* Improvement: Better support and faster suggestions for sites with 10k+ pages.
* FIX: Resolve MAX_JOIN_SIZE errors for large sites during maintenance operations by using SQL_BIG_SELECTS for cleanup queries.
* FIX: Resolve MySQL Server Gone Away errors during long-running operations by checking and restoring database connections.
* FIX: Resolve PHP 8.2 deprecation error when mb_substr receives null for domain root URLs (e.g., http://example.com).
* FIX: Fix duplicate subpage parameters in admin URLs that were causing URLs like '?page=x&subpage=y&subpage=y'.
* FIX: Add isset() checks before accessing $_POST array elements to prevent PHP 8.0+ warnings.

= Version 2.36.10 (April 29, 2025) =
* FIX: Fix the '_load_textdomain_just_in_time was called incorrectly' issue again, this time for @apos37.

= Version 2.36.9 (April 25, 2025) =
* FIX: Avoid throwing an error when releasing a synchronization lock not owned by the current process, for leehodson.

= Version 2.36.8 (April 25, 2025) =
* FIX: Avoid a logging issue while logging a DB error for leehodson.

= Version 2.36.6 (April 15, 2025) =
* Improvement: Page suggestions: when an admin user clicks the score after a suggestion link it gives them some more information about the link like author, post date, etc.
* Improvement: Page suggestions: add an option to exclude certain URLs from the list of suggested pages on a custom 404 page based on user defined regex patterns.

= Version 2.36.5 (February 3, 2025) =
* FIX: Use SHOW CREATE TABLE instead of select from information_schema to get collation data.

= Version 2.36.4 (December 13, 2024) =
* FIX: Ensure parent classes are loaded before their children to possibly resolve inheritance issues with autoloading. (thanks debug file participants)

= Version 2.36.3 (November 28, 2024) =
* FIX: Handle arrays in query parameters without logging an error.
