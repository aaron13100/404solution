=== 404 Solution ===
Contributors: aaron13100
Website: https://www.ajexperience.com/404-solution/
Tags: 404, redirect, 301, 302
Requires at least: 5.0
Requires PHP: 7.4
Tested up to: 6.8
Stable tag: 3.0.1
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

The smartest 404 plugin for WordPress - finds what your visitors were actually looking for.

== Description ==

Stop losing visitors to broken links. **404 Solution doesn't just redirect errors to your homepage** – it uses advanced spell-checking and intelligent matching algorithms to **find the actual page your visitor was trying to reach**.

When a visitor hits a broken link like `/prodcut/awesome-item` (typo), most plugins redirect them to your homepage where they get lost and leave. 404 Solution is different – it **automatically finds `/product/awesome-item`** and redirects them to the right place.

= Why 404 Solution is Different =

**Intelligent URL Matching** – Uses sophisticated algorithms (N-gram similarity, Levenshtein distance, multi-word matching) to find the closest existing page, not just blindly redirect to homepage.

**Spell-Checking Technology** – Automatically handles typos and URL variations so visitors find what they want even when they misspell URLs.

**Zero Configuration** – Works perfectly out of the box with smart defaults. Advanced users have full control over every aspect.

**WooCommerce Optimized** – Specifically designed to work with products, categories, variations, and custom post types.

**5-Star Rated** – Trusted by 10,000+ WordPress sites with a high rating.

= Core Features =

* **Automatic intelligent redirects** based on the best possible match for the URL
* **404 error logging** with detailed visitor data and referrer information
* **Manual redirect creation** for specific URLs to any existing page
* **Page suggestions shortcode** to display matches on custom 404 pages
* **Automatic cleanup** removes redirects when URLs match new pages or are no longer used
* **Regular expression support** for advanced redirect patterns
* **Debug logging** to troubleshoot redirect behavior
* **Performance optimized** for sites with 10,000+ pages

= How It Works =

1. Visitor hits a broken link (404 error)
2. 404 Solution analyzes the URL and compares it to all your existing pages
3. Intelligent matching finds the closest match using spell-checking algorithms
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

= Technical Details =

* Supports 301 (permanent) and 302 (temporary) redirects
* N-gram similarity scoring for fast matching
* Spell-checking with Levenshtein distance calculation
* Custom post type support (products, events, portfolios, etc.)
* Taxonomy-aware (categories, tags, custom taxonomies)
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

= Will this slow down my site? =

No, 404 Solution is highly optimized and has minimal performance impact for most sites. The plugin uses:
* N-gram indexing for fast URL matching
* Performance optimizations for large sites (10,000+ pages)
* Efficient database queries with proper caching

**Important:** For very high-traffic sites with thousands of simultaneous users, disable "Create automatic redirects" and don't use the shortcode to ensure optimal performance.

= Does it work with WooCommerce? =

Yes! 404 Solution is specifically optimized for WooCommerce and handles:
* Products and product variations
* Product categories and tags
* Custom product URLs
* Shop pages and archives

When products are renamed or URLs change, 404 Solution automatically redirects old URLs to the new product pages.

= How does the intelligent matching work? =

404 Solution uses multiple algorithms to find the best match:

1. **Spell-checking** – Uses Levenshtein distance to find pages with similar spelling
2. **N-gram similarity** – Compares character sequences to find similar URLs
3. **Word matching** – Identifies pages with similar words in the URL
4. **URL structure** – Considers the structure and length of URLs

These algorithms work together to find the most likely page the visitor intended to reach.

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

= Version 3.0.1 (Nov 20, 2025) =
* Improvement: Use accordions on the settings screen instead of chips.
* FIX: Division by 0 in the new ngram filter.
* FIX: Language file format issues for various languages. 
* FIX: Correct the deactivate feedback trigger.
* FIX: Log slow query content correctly. 
* FIX: Table creation issues on "multisites.""

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

= Version 2.36.2 (November 25, 2024) =
* FIX: Avoid various SQL errors when unprintable characters are included in a URL.
* FIX: Move the load_plugin_textdomain function to the init() hook. (for @reallydeej)

= Version 2.36.1 (November 23, 2024) =
* FIX: Finalize fixes for case sensitive lower_case_table_names settings.

= Version 2.35.25 (November 22, 2024) =
* FIX: Hopefully fix the "Could not perform query because it contains invalid data" issue.
* FIX: Account for arrays when escaping parts of a URL.

= Version 2.35.24 (November 22, 2024) =
* FIX: Hopefully fix a case issue with MySQL and the lower_case_table_names setting. (thanks lonesync)

= Version 2.35.23 (November 20, 2024) =
* FIX: Hopefully avoid a requested_url cannot be null for query error.

= Version 2.35.22 (November 20, 2024) =
* FIX: Don't load classes unless they're necessary to save memory and execution time on all pages.

= Version 2.35.21 (November 19, 2024) =
* FIX: Make sure initializing files are included when necessary in PluginLogic.php since JetPack seems to be including that file early, by itself, without loading the plugin correctly for some reason.

= Version 2.35.20 (November 18, 2024) =
* FIX: Resolved a security issue by no longer decoding query parameters when redirecting to a 404 page.

= Version 2.35.19 (November 18, 2024) =
* FIX: Try to fix logging issues caused by people that use latin1 as their database encoding (urlencode utf8mb4 characters when storing to the logs table and warn about it). (thanks to debug log file participants)
* FIX: Avoid error messages when trying to assure that table names are lower case (probably introduced in 2.35.16).

= Version 2.35.18 (November 14, 2024) =
* FIX: Make sure only admin users can export redirects using the Tools page export function.
* Update: Avoid some of the issues listed in the Plugin Check feature of WordPress.

= Version 2.35.17 (October 15, 2024) =
* FIX: Don't throw an exception on the Options page when there are no log entries (for crzyhrse).

= Version 2.35.16 (October 9, 2024) =
* FIX: Try to fix a case issue with MySQL and the lower_case_table_names setting.

= Version 2.35.15 (September 30, 2024) =
* FIX: Try to fix a table collation issue for atlet.

= Version 2.35.14 (September 27, 2024) =
* Improvement: Include WooCommerce categories also.
* FIX: Avoid an out of memory issue during spellcheck when there were too many posts or pages (> 10,000) (caused by debug_backtrace() apparently).

= Version 2.35.13 (August 7, 2024) =
* FIX: Fix the 'Files and Folders Ignore Strings - Do Not Process' functionality.

= Version 2.35.12 (August 3, 2024) =
* FIX: Fix an undefined array key due to the new template redirect priority option.

= Version 2.35.11 (August 2, 2024) =
* FIX: Allow users to set the template_redirect priority which allows other plugins or other "things" to handle 404s before this plugin handles it. Hopefully this will fix an issue where some payment systems purposefully direct to non-existent pages and then handle them.
* Improvement: Try to fix some sql "contains invalid data" issues when logging redirects.

= Version 2.35.10 (July 11, 2024) =
* FIX: Avoid an Undefined array key warning in PHP 8. Thanks @peterbp.

= Version 2.35.9 (April 17, 2024) =
* FIX: Fix an undefined constant warning for PHP 7 (and probably 8).
* FIX: Don't esc_url() before redirecting, because it escapes things like & when it shouldn't (thanks @wordknowledge).
* Update: Apparently made the levenshtein distance algorithm slightly more efficient, but I made the change a while ago and honestly don't remember it. But I think probably it won't break anything so I guess it's okay.

= Version 2.35.8 (January 31, 2024) =
* Update: Fixed a supposed issue on the logs page that 1. I was unable to reproduce and 2. would definitely only be possible if you were an admin user anyway, so I'm not really sure why it was reported.

= Version 2.35.7 (November 10, 2023) =
* FIX: Avoid an Undefined array key for SERVER_NAME for some people.

= Version 2.35.6 (November 9, 2023) =
* Improvement: Handle even more emojis.

= Version 2.35.5 (November 5, 2023) =
* FIX: Avoid a PHP warning trim(): Passing null to parameter #1.
* FIX: Allow the fast text filter on the redirects and captured 404s tabs to work again (probably broken in 2.34.0).
* Improvement: Handle emojis in URLs without causing a collation SQL error.

= Version 2.35.4 (November 4, 2023) =
* FIX: Correctly log redirects to the default 404 page. 
* FIX: Allow redirecting to the homepage again (broken in 2.35.3).

= Version 2.35.3 (November 3, 2023) =
* FIX: Avoid a PHP warning preg_replace(): Passing null to parameter #3. It looks like this was preventing someone from saving their settings.
* FIX: Better handle the case When a redirect is created and then the destination page is deleted. Redirects with deleted destinations always appear at the top of the list of redirects.

= Version 2.35.2 (November 2, 2023) =
* Improvement: Add more log messages to help diagnose issues.

= Version 2.35.1 (November 1, 2023) =
* FIX: Fix a logging issue when redirected to a URL with no path and no trailing slash. (Thank you debug log file participants!)

= Version 2.35.0 (October 26, 2023) =
* FIX: Compatible with WordPress 6.4.
* FIX: Fix the labels for "Ignore" and "Organize later" on the captured 404 page.
* FIX: Correctly store the "exclude specific pages" setting again (broken in 2.34.0 I think).
* FIX: Try again to fix the supposed issue that allows admins to run code on their own system.

= Version 2.34.0 (October 23, 2023) =
* Improvement: Redirects to pages that have been deleted now appear red in UI so they're easy to see.
* FIX: Fixed a supposed SQL injection issue that I was unable to reproduce and would definitely only be possible if you were an admin user anyway, so I'm not really sure why it was reported, but thanks anyway I guess.

= Version 2.33.2 (October 17, 2023) =
* Improvement: Try to fix a logging issue.

= Version 2.33.1 (October 13, 2023) =
* Improvement: Fix a 'Sensitive Data Exposure vulnerability' for Joshua Chan that I didn't really think was a big deal, but it must matter to someone, so I added a random ID to the debug log filename.
* Improvement: Only try to update database tables to the correct engines if they're not already correct.
* FIX: Minor issues from some debug file participants like the referrer being too long sometimes and a missing cookie. 

= Version 2.33.0 (September 28, 2023) =
* Improvement: Add a file import function to the Tools page so redirects can be imported (for NoAdO).
* FIX: Remove the 'Thank you for creating with...' message because it was messing up the layout on the Tools page and removing the message is easier than figuring out what the issue is with the layout.

= Version 2.32.3 (May 29, 2023) =
* FIX: Fix the Undefined array key "path" in WordPress_Connector.

