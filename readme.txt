=== 404 Solution ===
Contributors: aaron13100
Website: https://www.ajexperience.com/404-solution/
Tags: 404, redirect, 301, 302
Requires at least: 3.9
Tested up to: 6.7
Stable tag: 2.35.23
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Smart 404 handling: redirect to matching slug, similar name, or default page.

== Description ==

404 Solution redirects page not found errors (404s) to pages that exist and logs the errors. Redirects can also be created based on the best possible match for the URL the visitor was most likely trying to reach.

Note: If your site gets a lot of simultaneous users you need to turn off the "Create automatic redirects" options and you need to NOT use the shortcode. Otherwise your site will slow down.

= Features: =

* Highly configurable - redirect specific 404 URLs to any existing page.
* Automatically create redirects based on the URL the visitor was most likely trying to visit.
* Get a list of 404s as they happen.
* View logs of 404 pages and redirects including referrer data.
* WooCommerce compatible - pages, posts, products, and custom post types are supported.
* Display a list of page suggestions on a custom 404 page with a shortcode (any page can be a custom 404 page).
* Basic plugin usage statistics.
* Automatically remove redirects when the URL matches a new page or post.
* Automatically remove manual and automatic redirects once they are no longer used.
* Redirect based on regular expressions and include query data.

Convert your 404 traffic by providing your visitors with a better browsing experience and eliminate 404 errors on your site.

== Installation ==

1. Unzip the files and upload the contents to `/wp-content/plugins/`.
2. Activate the plugin.
3. Use the `Settings -> 404 Solution` options page to set the options.

== Frequently Asked Questions ==

= How long does it take for 404 URLs to start showing up? =

As long as the "Capture incoming 404 URLs" option is enabled in the options section, the 404 URLs will show up in the captured list as soon as a visitor hits a 404 page.

= Will there be a slow down on my site when running the plugin? =

No, there should be no noticeable slow down when running the plugin on your site.

Note: If your site gets a lot of simultaneous users you need to turn off the "Create automatic redirects" options and you need to NOT use the shortcode. Otherwise your site will slow down.

= Will this plugin redirect my pages if I change my permalinks structure? =

Yes! 404 Solution records the page/post ID number and looks up the most current permalink before redirecting the user.

= Can I redirect all 404's to a particular page? =

Yes. It's as easy as turning on this feature in the options (404 Solution -> Options -> Redirect all unhandled 404s to). Using this option you can create a custom 404 page by specifying a page you've created for that purpose with the normal WordPress editor.

= How do I delete log files? How do I purge log lines? =

Deleting old log lines to limit disk space usage is done automatically. You can set the maximum size to as low as 1MB under Options -> General Settings -> Maximum log disk usage.

= I see the message "There are (some number of) captured 404 URLs to be processed." What should I do? =

This is nothing to be worried about. It means people tried to access pages on your website that don't exist. You can either change the settings on the options page so that you're no longer notified about it (Options -> General Settings -> Admin notification level), or you can go to the "Captured 404 URLs" page and do something with them (either ignore them or specify which existing page they should redirect to).

= IP addresses are not displayed correctly. I can't see the IP addresses. =

In the settings there is a setting named "Log raw IPs" that you need to select to show the IP addresses.

= An existing page is being redirected. =

No, it's not. Only 404s are redirected. Did the page exist at the time of the redirect? Past issues have been caused by conflicts with other plugins, or by other plugins redirecting to non-existing pages. Turn on debug logging on the Options page and try the existing URL. Then view the debug log (from the Options page) and see how the 404 was handled.

= I want to exclude certain pages or URLs. How? =

There’s a section in the options named “Files and Folders Ignore Strings – Do Not Process” under “Advanced Settings (Etc)”. You can add the path part of the URL to ignore there.

= Have you written any other programs?  =

Please try this website for studying flashcards.    
[https://www.ajexperience.com/](https://www.ajexperience.com/)

== Screenshots ==

1. Admin Options Screen
2. Logs
3. Create New Redirect

== Changelog ==

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

