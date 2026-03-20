=== 404 Solution ===
Contributors: aaron13100
Website: https://www.ajexperience.com/404-solution/
Tags: 404, redirect, auto redirect, broken links, similar post
Requires at least: 5.0
Requires PHP: 7.4
Tested up to: 6.9
Stable tag: 3.3.7
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Automatically redirect 404 errors to similar posts and pages using intelligent matching, spell-checking, and a 7-engine pipeline.

== Description ==

Stop losing visitors to broken links and 404 errors. **404 Solution automatically redirects 404s to similar posts and pages** using advanced spell-checking and a 7-engine intelligent matching pipeline to **find the actual page your visitor was trying to reach**.

When a visitor hits a broken link like `/prodcut/awesome-item` (typo), most 404 redirect plugins send them to your homepage where they get lost and leave. 404 Solution is different – it **automatically finds `/product/awesome-item`** and redirects them to the right place. It handles typos, changed URLs, deleted pages, moved content, and restructured permalinks.

= Why 404 Solution is Different =

**Intelligent URL Matching** – Uses sophisticated algorithms (N-gram similarity, Levenshtein distance, multi-word matching) to find the closest existing page, not just blindly redirect to homepage.

**Spell-Checking Technology** – Automatically handles typos and URL variations so visitors find what they want even when they misspell URLs.

**Zero Configuration** – Works perfectly out of the box with smart defaults. Advanced users have full control over every aspect.

**WooCommerce Optimized** – Specifically designed to work with products, categories, variations, and custom post types.

= Core Features =

* **Automatic intelligent redirects** based on the best possible match for the URL
* **7-engine matching pipeline** — slug matching, URL typo correction, title keywords, category/tag path matching, content keywords, spelling, and archive fallback
* **Title keyword matching** — finds posts whose title words appear in the broken URL
* **Content keyword matching** — searches post body text for URL keywords
* **Category/tag matching** with hierarchical path resolution (e.g. `/shop/electronics` maps to the right category)
* **URL typo correction** — strips `.html`, `.php`, trailing numbers, and common suffixes to recover the real slug
* **Post type archive fallback** — when nothing else matches, redirects to the appropriate archive page
* **Per-engine score thresholds** — fine-tune how aggressive each matching strategy is
* **404 error logging** with detailed visitor data and referrer information
* **Manual redirect creation** for specific URLs to any existing page
* **Page suggestions shortcode** to display matches on custom 404 pages
* **Automatic cleanup** removes redirects when URLs match new pages, destinations are deleted, or redirects are no longer used
* **Regular expression support** for advanced redirect patterns
* **Debug logging** to troubleshoot redirect behavior
* **Performance optimized** for sites with 10,000+ pages
* **Multilingual-friendly redirects** (TranslatePress, WPML, Polylang) to keep redirects in the request language

= How It Works =

1. Visitor hits a broken link (404 error)
2. 404 Solution analyzes the URL and runs it through a 7-engine matching pipeline: slug matching, URL typo fixing, title keywords, category/tag path matching, content keywords, spelling similarity, and archive fallback
3. The first engine to find a confident match wins — each engine has its own tunable score threshold
4. Visitor is automatically redirected to the correct page
5. You can review all 404s and create custom redirects as needed

= Perfect For =

* **eCommerce sites** (WooCommerce, Easy Digital Downloads) with changing product URLs
* **Content sites** with evolving permalink structures
* **Migrated sites** where old URLs need to map to new content
* **Large sites** with thousands of pages where manual redirects are impractical
* **Any WordPress site** that wants to provide better user experience

= What Makes This Different From Other Redirect Plugins? =

**vs. Redirection** – Redirection requires manual redirect rules. 404 Solution automatically finds matches using intelligent algorithms.

**vs. 404 to 301** – 404 to 301 redirects everything to your homepage. 404 Solution finds the actual page visitors want.

**vs. Simple 301 Redirects** – Simple 301 only does manual redirects. 404 Solution creates intelligent automatic redirects based on URL similarity.

**vs. WP 404 Auto Redirect to Similar Post** – WP 404 Auto Redirect uses keyword matching only. 404 Solution adds Levenshtein spell-checking to catch typos, content body search, hierarchical category/tag path resolution, per-engine score thresholds, full 404 logging with admin dashboard, and manual redirect management — all in one plugin.

= Technical Details =

* Supports 301 (permanent) and 302 (temporary) redirects
* 7-engine matching pipeline with configurable score thresholds
* N-gram similarity scoring for fast candidate narrowing
* Spell-checking with Levenshtein distance calculation for typo correction
* Title, content, and taxonomy keyword matching
* Custom post type support (products, events, portfolios, etc.)
* Taxonomy-aware (categories, tags, custom taxonomies) with hierarchical path resolution
* Per-post and per-term exclusion via WordPress meta
* Query parameter preservation
* Referrer tracking and logging
* IP address logging (GDPR-compliant hashing available)
* Automatic performance optimization for large sites

**Note:** For high-traffic sites with thousands of simultaneous users, disable "Create automatic redirects" and avoid using the shortcode to ensure optimal performance.

== Installation ==

1. Unzip the files and upload the contents to `/wp-content/plugins/`.
2. Activate the plugin.
3. Use the `Settings -> 404 Solution` options page to set the options.

== Frequently Asked Questions ==

= How is this different from Redirection plugin? =

**Redirection** requires you to manually create redirect rules for each broken URL. **404 Solution automatically finds the best matching page** using intelligent algorithms.

**Example:** If a visitor tries `/prodcut/awesome-item` (typo), Redirection won't do anything unless you manually created that specific redirect. 404 Solution automatically finds `/product/awesome-item` and redirects them there.

**Use Redirection when:** You need complete manual control over every redirect.
**Use 404 Solution when:** You want automatic intelligent redirects that understand typos and URL variations.

= How is this different from 404 to 301? =

**404 to 301** redirects ALL 404 errors to your homepage (or one specific page). **404 Solution finds the actual page** the visitor was looking for.

**Example:** A visitor tries to access `/category/electronics` which doesn't exist. 404 to 301 sends them to your homepage where they're lost. 404 Solution finds `/shop/electronics` and redirects them there.

**404 to 301** is simpler but provides a worse user experience. **404 Solution** is smarter and keeps visitors engaged.

= How is this different from WP 404 Auto Redirect to Similar Post? =

**WP 404 Auto Redirect to Similar Post** uses a single matching approach. **404 Solution** runs a 7-engine pipeline — slug matching, URL typo correction, title keywords, category/tag paths, content keywords, spelling similarity, and archive fallback — so it finds the right page in more situations.

404 Solution also gives you per-engine score thresholds, per-post/per-term exclusion controls, debug logging, and automatic cleanup of orphaned redirects.

= Will this slow down my site? =

No, 404 Solution is highly optimized and has minimal performance impact for most sites. The plugin uses:
* N-gram indexing for fast URL matching
* Performance optimizations for large sites (10,000+ pages)
* Efficient database queries with proper caching

**Important:** For very high-traffic sites with thousands of simultaneous users, disable "Create automatic redirects" and don't use the shortcode to ensure optimal performance.

= Does it work with WPML or Polylang? =

Yes. 404 Solution keeps redirects in the same language as the request when WPML or Polylang is active. TranslatePress is also supported. Language is detected using the multilingual plugin’s URL/language APIs, and the matched redirect destination is translated to that language without changing the matching logic.

= Does it work with WooCommerce? =

Yes! 404 Solution is specifically optimized for WooCommerce and handles:
* Products and product variations
* Product categories and tags
* Custom product URLs
* Shop pages and archives

When products are renamed or URLs change, 404 Solution automatically redirects old URLs to the new product pages.

= How does the intelligent matching work? =

404 Solution runs the broken URL through a 7-engine pipeline, in order:

1. **Slug matching** – Looks for an existing page whose slug is an exact or near-exact match
2. **URL typo correction** – Strips common suffixes (.html, .php, trailing numbers) and retries
3. **Title keyword matching** – Finds posts whose title words appear in the broken URL
4. **Category/tag matching** – Resolves hierarchical paths like `/shop/electronics` to the right taxonomy term
5. **Content keyword matching** – Searches post body text for keywords from the URL
6. **Spelling similarity** – Uses Levenshtein distance and N-gram scoring to find the closest URL
7. **Archive fallback** – Redirects to the post type archive when no single-post match is found

The first engine to find a match above its score threshold wins. Each engine threshold is independently tunable.

= Will it redirect existing pages by mistake? =

No. 404 Solution ONLY processes actual 404 errors (pages that don't exist). It will never redirect an existing page.

If you're experiencing unexpected redirects, check:
1. Did the page exist when the redirect was created?
2. Are other plugins causing conflicts?
3. Enable debug logging (Options page) to see exactly what's happening

= How long does it take for 404 URLs to start showing up? =

Immediately! As long as "Capture incoming 404 URLs" is enabled, 404 errors appear in the captured list as soon as a visitor hits a 404 page.

= Will this plugin handle permalink changes? =

Yes! 404 Solution records the page/post ID number and looks up the most current permalink before redirecting. This means:
* If you change your permalink structure, redirects automatically update
* If you rename a page, redirects point to the new URL
* No manual updates needed when URLs change

= Can I redirect all 404s to a specific page? =

Yes. Go to **404 Solution → Options → Redirect all unhandled 404s to** and select your preferred page. This is useful for:
* Custom 404 pages with special messaging
* Fallback pages when no good match is found
* Temporary catch-all during site migrations

= How do I manage log files and disk usage? =

Log cleanup is automatic! Configure it under **Options → General Settings → Maximum log disk usage**. You can:
* Set maximum log size (as low as 1MB)
* Auto-delete old entries when limit is reached
* Manually clear logs anytime from the Logs page

= What does "captured 404 URLs to be processed" mean? =

This just means visitors tried to access pages that don't exist – completely normal! You can:

**Option 1:** Ignore the message by adjusting **Options → General Settings → Admin notification level**

**Option 2:** Process them by going to **Captured 404 URLs** page and either:
* Let automatic redirects handle them
* Create manual redirects to specific pages
* Mark them to ignore

= Can I exclude certain URLs from being processed? =

Yes! Go to **Options → Advanced Settings → Files and Folders Ignore Strings** and add the paths you want to ignore. Common exclusions:
* `/wp-admin/*` – Admin pages
* `/wp-content/*` – Media files
* `*.jpg`, `*.png` – Image files
* `/feed/*` – RSS feeds

= How do I see IP addresses in the logs? =

Enable **"Log raw IPs"** in the settings. Note: For GDPR compliance, consider using hashed IPs instead of raw IP addresses.

= Does this work with custom post types? =

Yes! 404 Solution supports ALL custom post types including:
* WooCommerce products
* Events (The Events Calendar, Event Espresso)
* Portfolios
* Team members
* Custom content types

= Can I use regular expressions for redirects? =

Yes! 404 Solution supports regex patterns for advanced redirect rules. This is useful for:
* Redirecting multiple similar URLs with one rule
* Pattern-based matching
* Complex URL transformations

= Is this GDPR compliant? =

Yes. 404 Solution includes GDPR-friendly features:
* Option to hash IP addresses before storage
* Automatic log cleanup/retention limits
* No external data transmission
* Full control over what data is logged

= What kind of support is available? =

404 Solution is actively maintained with:
* WordPress.org support forum (free community support)
* Regular updates and bug fixes
* Debug logging to troubleshoot issues
* Comprehensive documentation

For urgent issues, enable debug logging and post in the support forum with details.

= How do I exclude a specific post or page from automatic redirects? =

Two ways:

1. **Per-post exclusion (recommended):** Edit the post/page and check the "Exclude from 404 Solution redirects" checkbox in the sidebar. This uses post meta and works for any post type.
2. **Global exclusion list:** Go to **Options → Advanced Settings → Exclude Specific Pages** and add individual items.

Category and tag terms can also be excluded via term meta on the edit screen.

= Does 404 Solution work after a site migration? =

Yes! 404 Solution is ideal for site migrations. It handles:

* **Changed permalink structures** — old URLs automatically redirect to new ones
* **Renamed pages and posts** — redirects update automatically because they store post IDs, not URLs
* **Moved content between categories** — the category/tag engine resolves hierarchical paths
* **Domain changes** — use regex redirects for bulk URL pattern changes
* **Platform migrations** — the URL typo engine strips old file extensions like `.html` and `.php`

= Have you written any other programs? =

Check out [AJ Experience](https://www.ajexperience.com/) for other useful tools and resources.

== Screenshots ==

1. **Intelligent Redirect Dashboard** - See automatic matches in action with confidence scores
2. **404 Error Logs** - Track all 404 errors with visitor data, referrers, and timestamps
3. **Easy Redirect Creation** - Create manual redirects with autocomplete and validation
4. **Captured URLs Management** - Review and process 404s with one-click redirect creation
5. **Performance Statistics** - Monitor redirect effectiveness and site performance
6. **Advanced Options** - Fine-tune intelligent matching, logging, and behavior

== Changelog ==

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
* FIX: Fixed an upgrade bug introduced in 3.3.3 that accidentally cleared the admin page view cache. No redirect data was affected — the cache rebuilds automatically on the next page load.
* FIX: Added a safety check to prevent future upgrades from accidentally wiping plugin caches.
* FIX: Sites affected by the 3.3.3 bug will have their cache table automatically repaired on the first admin page load after updating.

= Version 3.3.3 (Mar 19, 2026) =
* FIX: Uninstaller no longer produces a PHPStan type error when `$wpdb->get_results()` returns null on edge-case database configurations.
* Improvement: Plugin table cleanup on blog deletion, uninstall, and collation repair now uses dynamic discovery, ensuring any future tables are automatically included.

= Version 3.3.1 (Mar 18, 2026) =
* FIX: Resolved "Table wp_abj404_view_cache doesn't exist" errors on some v3.3.0 upgrades where the cache table was not created.
* FIX: Reduced debug log noise when WP_DEBUG is enabled.
* FIX: Orphaned-redirect cleanup cron no longer triggers errors on sites with incomplete database installs.
* FIX: Plugin upgrades no longer perform unnecessary database ALTER TABLE operations.
* FIX: Bot requests with malformed character encodings no longer cause database errors.

= Version 3.3.0 (Mar 15, 2026) =
* NEW: 7-engine matching pipeline — slug matching, URL typo correction, title keywords, category/tag path matching, content keywords, spelling similarity, and archive fallback.
* NEW: Title keyword matching engine — finds posts whose title words appear in the broken URL, with fuzzy Levenshtein scoring for near-matches.
* NEW: Content keyword matching engine — searches post body text when title and slug matching fail.
* NEW: Category/tag matching engine — resolves hierarchical paths like `/shop/electronics` to the right taxonomy term or finds posts within a category.
* NEW: URL typo correction engine — strips `.html`, `.php`, `.asp` and other file extensions, plus trailing punctuation from copy-paste errors.
* NEW: Post type archive fallback engine — redirects to archive pages when no single-post match is found.
* NEW: Per-engine score thresholds — fine-tune how aggressive each matching strategy is (Advanced Settings).
* NEW: Per-post and per-term exclusion — exclude individual posts, pages, or taxonomy terms from automatic redirects via edit screen checkbox.
* NEW: Orphaned redirect cleanup — daily cron automatically removes auto-redirects whose destination post was deleted or unpublished.
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

= Version 2.36.7 (April 25, 2025) =
* FIX: Avoid a logging issue while logging a DB error for leehodson.
* Improvement: Fix some warnings and code issues brought up by intelephense.

= Version 2.36.6 (April 15, 2025) =
* Improvement: Page suggestions: when an admin user clicks the score after a suggestion link it gives them some more information about the link like author, post date, etc.
* Improvement: Page suggestions: add an option to exclude certain URLs from the list of suggested pages on a custom 404 page based on user defined regex patterns.

= Version 2.36.5 (February 3, 2025) =
* FIX: Use SHOW CREATE TABLE instead of select from information_schema to get collation data.

= Version 2.36.4 (December 13, 2024) =
* FIX: Ensure parent classes are loaded before their children to possibly resolve inheritance issues with autoloading. (thanks debug file participants)

= Version 2.36.3 (November 28, 2024) =
* FIX: Handle arrays in query parameters without logging an error.
