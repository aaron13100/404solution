=== 404 Solution ===
Contributors: aaron13100
Website: http://www.wealth-psychology.com/404-solution/
Tags: 404, page not found, redirect, 301, 302, permanent redirect, temporary redirect, error, permalink redirect, permalink
Requires at least: 3.1
Version: 1.6.3
Tested up to: 4.7.3
Stable tag: 1.6.3

Automatically redirect 404s when the slug matches (for permalink changes), when a very similar name match is found, or always to a default page.

== Description ==

404 Solution logs 404s and allows them to be redirected to pages that exist. Redirects can also be created based on the best possible match for the URL the visitor was most likely trying to reach.

= Features: =

* Get a list of 404 URLs as they happen.
* Redirect 404 URLs to existing pages or ignore them.
* Automatically create redirects based on the URL the visitor was most likely trying to visit.
* View logs of hits to 404 pages and redirects including referrer data.
* Automatically remove redirects when the URL matches a new page or post permalink.
* Automatically remove manual and automatic redirects once they are no longer being used.
* All features work with both pages and posts.
* Create automatic redirects for any URL resolving to a single page or post that isn't the current permalink.
* Basic plugin usage statistics.

Convert your 404 traffic by providing your visitors with a better browsing experience and eliminate 404 URLs on your site.

== Installation ==

1. Unzip the files and upload the contents to `/wp-content/plugins/`.
2. Activate the plugin.
3. Use the `Settings -> 404 Solution` options page to set the options.

== Frequently Asked Questions ==

= How long does it take for 404 URLs to start showing up? =

As long as the "Capture incoming 404 URLs" option is enabled in the options section, the 404 URLs will show up in the captured list as soon as a visitor hits a 404 page.

= Will there be a slow down on my site when running the plugin? =

No, there should be no noticeable slow down when running the plugin on your site.

= Will this plugin redirect my pages if I change my permalinks structure? =

Yes! 404 Solution records the page/post ID number and looks up the most current permalink before redirecting the user.

= Can I redirect all 404's to a particular page? =

Yes. It's as easy as turning on this feature in the options.

== Screenshots ==

1. Admin Options Screen
2. Logs
3. Create New Redirect

== Changelog ==

= Version 1.6.3 = (April 1, 2017)
* FIX: Log URLs with queries correctly and add REMOTE_ADDR, HTTP_USER_AGENT, and REQUEST_URI to the debug log for ozzymuppet.
* Improvement: Add a way to import redirects (Tools -> Import) from the old "404 Redirected" plugin for Dave and Mark.

= Version 1.6.2 =
* FIX: Pagination links keep you on the same tab again.
* FIX: You can empty the trash again.

= Version 1.6.1 =
* FIX: In some cases editing multiple captured 404s was not possible (when header information was already sent to
    the browser by a different plugin).
* Improvement: Forward using the fallback method of JavaScript (window.location.replace() if sending the Location:
    header does not work due to premature outptut).

= Version 1.6.0 =
* Improvement: Allow the default 404 page to be the "home page."
* Improvement: Add a debug and error log file for Dave.
* FIX: No duplicate captured URLs are created when a URL already exists and is not in the trash.

= Version 1.5.9 =
* FIX: Allow creating and editing redirects to external URLs again. 
* Improvement: Add the "create redirect" bulk operation to captured 404s.
* Improvement: Order posts alphabetically in the dropdown list.

= Version 1.5.8 =
* FIX: Store relative URLs correctly (without the "http://" in front).

= Version 1.5.7 =
* Improvement: Ignore requests for "draft" posts from "Zemanta Aggregator" (from the "WordPress Related Posts" plugin).
* Improvement: Handle normal ?p=# requests.
* Improvement: Be a little more relaxed about spelling (e.g. aboutt forwards to about).

= Version 1.5.6 =
* FIX: Deleting logs and redirects in the "tools" section works again.
* Improvement: Permalink structure changes for posts are handled better when the slug matches exactly.
* Improvement: Include screenshots on the plugin page, a banner, and an icon.

= Version 1.5.5 =

Released 2017-03-07

* FIX: Correct duplicate logging. 
* Improvement: Add debug messages.
* Improvement: Reorganize redirect code.

= Version 1.5.4 =
* FIX: Suggestions can be included via custom PHP code added to 404.php

= Version 1.5.3 =
* Refactor all code to prepare for WordPress.org release.

= Version 1.5.2 =
* FIX plugin activation. Avoid "Default value for parameters with a class type hint can only be NULL"
* Add a Settings link to the WordPress plugins page.

= Version 1.5.1 =
* Prepare for release on WordPress.org.
* Sanitize, escape, and validate POST calls.

= Version 1.5.0 = 
* Rename to 404 Solution (forked from 404 Redirected at https://github.com/ThemeMix/redirectioner)
* Update branding links
* Add an option to redirect all 404s to a specific page.
* When a slug matches a post exactly then redirect to that post (score +100). This covers cases when permalinks change.
