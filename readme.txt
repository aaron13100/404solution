=== 404 Solution ===
Contributors: aaron13100
Website: http://www.wealth-psychology.com/404-solution/
Tags: 404, page not found, redirect, 301, 302, permanent redirect, temporary redirect, error, permalink redirect, permalink
Requires at least: 3.1
Version: 1.5.5
Tested up to: 4.7.2
Stable tag: 1.5.5

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

= Version 1.5.5 =
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
* When the a slug matches a post exactly then redirect to that post (score +100). This covers cases when permalinks change.

= Version 1.4.7 = 
* Fixed too strict data sanitation for the `abj404_suggestions()` template tag
* Fixed CSS class for suggested 404s div wrapper.

= Version 1.4.6 =
* Fixed bug where query vars were being stripped
* Fixed a bug caused by plugin incorrectly injecting end-points turning up as 404s
* Fixed log purging issues

= Version 1.4.4 =
* Fixed a [SQL bug](https://github.com/defries/404-redirected/issues/7)
* Fixed a bug where [logs wouldn't get deleted](https://github.com/defries/404-redirected/issues/8)
* Fixed a bug where [deactivating and activating the plugin would reset the stats to 0](https://github.com/defries/404-redirected/issues/9)
* Fixed various [PHP notices](https://github.com/defries/404-redirected/issues/10)

= Version 1.4.3 =

* Updating a bug where a check for an ancient MySQL version would throw an error
* Fixing integer bug in SQL query
* General debug errors fixed as well.

= Version 1.4.2 =

* Introducing WordPress Coding Standards
* Replace `wpdb::escape` for `esc_sql()`
* Removing exotic translation function and replacing with default translation setup. In other words, the plugin is now translatable.

= Version 1.4.1 =

Released: 2016-06-06

* Improved security hardening (bugfixing)

= Version 1.4.0 =

* Plugin takeover from rrolfe because of lack of maintenance in four years
* Data sanitization added where needed (everywhere)
* Fixed PHP notices

= Version 1.3.2 =

Released: 2012-08-29

New Features:

* None

Bug Fixes:

* Remember way back when 1.3.1 was released (yesterday) and I said the cron jobs were fixed? They weren't. This update fixes crons for users who are upgrading by implementing a version check on the DB.
* Added upgrade script functionality by implementing DB version checking
* Performed general code cleanup to get rid of PHP NOTICES

= Version 1.3.1 =

Released: 2012-08-28

New Features:

* None

Bug Fixes:

* Fixed bug that caused cron jobs not to register properly - Stopped automatic deletion of redirects and logs from happening

= Version 1.3 =

Released: 2012-08-28

New Features:

* Added ability to sort redirects by number of hits
* Added new tools tab to admin page with purge options

Bug Fixes:

* Fixed bug that caused plugin to ignore query string vars e.g. ?p=1234
* Fixed bug that caused duplicate redirects to be recorded
* Fixed bug causing feed URLs to be recorded as 404's
* Fixed bug causing preview URLs to be recorded as 404's
* Fixed bug causing trackback URLs to be recorded as 404's
* General Code Clean Up
* Fixed missing page title on Stats tab
* Fixed bug causing posts/pages using <!--nextpage--> tag to not allow visitor beyond page 1

= Version 1.2 =

Released: 2011-12-06

New Features:

* Added cron to clean up duplicate redirects caused by simultaneous hits
* Added optional admin notifications when captured URLs goes over specified level
* Added new basic stats information from logs
* Modified default options to turn on automatic redirects for new installs
* Added bulk processing of captured and ignored URLs
* Added bulk deletion on trashed redirects and URLs

Bug Fixes:

* Fixed bug that caused plugin to remove and re-add redirects
* Fixed bug that caused logs to display nothing when ordered by URL

= Version 1.1 =

Released: 2011-11-29

* Fixed adding and editing of Tag & Category redirects
* Fixed bug causing category redirects to incorrectly match external redirects

= Version 1.0 =

Released: 2011-11-20

* Initial Release

== Upgrade Notice ==

= Version 1.4.6 =
* Fixed bug where query vars were being stripped
* Fixed a bug caused by plugin incorrectly injecting end-points turning up as 404s
* Fixed log purging issues
* General code improvements

= Version 1.4.4 =
* Fixed a [SQL bug](https://github.com/defries/404-redirected/issues/7)
* Fixed a bug where [logs wouldn't get deleted](https://github.com/defries/404-redirected/issues/8)
* Fixed a bug where [deactivating and activating the plugin would reset the stats to 0](https://github.com/defries/404-redirected/issues/9)
* Fixed various [PHP notices](https://github.com/defries/404-redirected/issues/10)

= Version 1.3 =

Added new purge options and sorting by number of hits. Lots of bug fixes.

= Version 1.2 =

Major bug fixes. Also added bulk processing of URLs and admin notifications.

= Version 1.1 =

2 bug fixes in adding/editing redirects

= Version 1.0 =

Initial Release
