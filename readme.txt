=== 404 Redirected ===
Contributors: rrolfe
Donate link: http://www.weberz.com/
Tags: 404, page not found, redirect, 301, 302, permanent redirect, temporary redirect, error
Requires at least: 3.1
Tested up to: 3.4.1
Stable tag: 1.3.2

Creates automatic redirects for 404 traffic and page suggestions when matches are not found providing better service to your web visitors.

== Description ==

404 Redirected allows Wordpress admins to have control over their dead links and redirects from inside the admin panel. 404 Redirected records all URLs that users have visited and allows the admin to easily create 301 and 302 redirects to valid pages on their site. Redirects can also be created based on the best possible match for the URL the visitor was most likely trying to reach.

**Features:**

* Get list of 404 URLs as they happen inside the admin panel
* Easily redirect 404 URLs to existing pages or choose to ignore the 404 error
* Provides the ability to automatically create redirects based on the URL the visitor was most likely trying to visit
* Provide visitors with a list of suggested pages on the 404 page when a automatic redirect can not be made
* Ability to suggest tag and category pages
* Ability to create automatic redirect for misspelled tag and category pages
* Ability to view logs of hits to 404 pages and redirects including referrer data
* Ability to remove automatically remove redirects when the URL matches a new page or post permalink
* Ability to automatically remove manual and automatic redirects once they are no longer being used
* All features work with both pages and posts
* Create automatic redirects for any URL resolving to a single page or post that isn't the current permalink
* Basic stats of plugin usage

Convert your 404 traffic by providing your site visitors with a better browsing experience and eliminate 404 URLs on your site.

== Installation ==

Installation of 404 Redirect is simple:

1. Unzip `404-redirected.zip` and upload contents to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress Admin
1. Use the `Settings -> 404 Redirected` options page to enable desired features.

For the `suggested pages` feature to work you need to edit your 404.php template file to include the following code:
`<?php if (function_exists('wbz404_suggestions')) { wbz404_suggestions(); } ?>`

== Frequently Asked Questions ==

= How long does it take for 404 URLs to start showing up? =

As long as the "Capture incoming 404 URLs" option is enabled in the options section, the 404 URLs will show up in the captured list as soon as a visitor hits a 404 page.

= Will there be a slow down on my site when running the plugin? =

No there should be no noticeable slow down when running the plugin on your site.

= Will this plugin redirect my pages if I change my permalinks structure? = 

Yes! 404 Redirected records the page/post ID number and looks up the most current permalink before redirecting the user.

= Can I redirect all 404's to a particular page? =

No, that's not what this plugin is for. This plugin is designed to make your visitors experience better by automatically fixing 404 problems caused by typos.

= Why doesn't anyone answer in the support forums? =

I try to get to the support forums as often as I can. This plugin is just one of many things I have to work on. Sometimes it takes 6-8 months for me to get back to working it, I will work on trying to get better about this. In the meantime, please be patient or try finding me on Twitter/Facebook.

== Screenshots ==

1. Admin Options Screen
2. Logs
3. Create New Redirect

== Changelog == 

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

= Version 1.3 =

Added new purge options and sorting by number of hits. Lots of bug fixes.

= Version 1.2 =

Major bug fixes. Also added bulk processing of URLs and admin notifications.

= Version 1.1 =

2 bug fixes in adding/editing redirects

= Version 1.0 =

Initial Release
