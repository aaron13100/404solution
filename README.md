# 404 Solution #

Automatically redirect 404s when the slug matches (for permalink changes), when a very similar name match is found, or always to a default page.

## Description ##

404 Solution logs 404s and allows them to be redirected to pages that exist. Redirects can also be created based on the best possible match for the URL the visitor was most likely trying to reach.

### Features: ###

* Redirect 404 URLs to existing pages or ignore them.
* Automatically create redirects based on the URL the visitor was most likely trying to visit.
* Get a list of 404 URLs as they happen.
* View logs of hits to 404 pages and redirects including referrer data.
* WooCommerce compatible - pages, posts, products, and custom post types are supported.
* Automatically redirect to the correct page after a permalink structure change.
* Show possible page matches on a 404 page.
* Basic plugin usage statistics.
* Automatically remove redirects when the URL matches a new page or post permalink.
* Automatically remove manual and automatic redirects once they are no longer being used.
* Redirect based on a RegEx (regular expression) pattern.

Convert your 404 traffic by providing your visitors with a better browsing experience and eliminate 404 URLs on your site.

## Etc ##
* Untested: Test whether changing the permalink structure twice (3 different permalinks structures total) causes us to
    forward twice unnecessarily. Test by doing the following: Choose a permalink structre, create a page, change the 
    permalink structure, go to the old URL of the page to create an automatic redirect, change the permalink structure
    to a third type, go to the old URL of the page. Are we forwarded to the second (non-existent) permalink structre and 
    not the current valid one?  Workaround: Delete all redirects and start over.
* To be added: Multilingual support using icl_object_id.

## Installation ##

1. Unzip the files and upload the contents to `/wp-content/plugins/`.
2. Activate the plugin.
3. Use the `Settings -> 404 Solution` options page to set the options.

## Frequently Asked Questions ##

### How long does it take for 404 URLs to start showing up? ###

As long as the "Capture incoming 404 URLs" option is enabled in the options section, the 404 URLs will show up in the captured list as soon as a visitor hits a 404 page.

### Will there be a slow down on my site when running the plugin? ###

No, there should be no noticeable slow down when running the plugin on your site.

### Will this plugin redirect my pages if I change my permalinks structure? ###

Yes! 404 Solution records the page/post ID number and looks up the most current permalink before redirecting the user.

### Can I redirect all 404's to a particular page? ###

Yes. It's as easy as turning on this feature in the options (404 Solution -> Options -> Redirect all unhandled 404s to). Using this option you can create a custom 404 page by specifying a page you've created for that purpose with the normal WordPress editor.

### How do I delete log files? How do I purge log lines? ###

Deleting old log lines to limit disk space usage is done automatically. You can set the maximum size to as low as 1MB under Options -> General Settings -> Maximum log disk usage.

### I see the message "There are (some number of) captured 404 URLs to be processed." What should I do? ###

This is nothing to be worried about. It means people tried to access pages on your website that don't exist. You can either change the settings on the options page so that you're no longer notified about it (Options -> General Settings -> Admin notification level), or you can go to the "Captured 404 URLs" page and do something with them (either ignore them or specify which existing page they should redirect to).

### IP addresses are not displayed correctly. I can't see the IP addresses. ###

In the settings there is a setting named "Log raw IPs" that you need to select to show the IP addresses.

### An existing page is being redirected. ###

No, it's not. Only 404s are redirected. Did the page exist at the time of the redirect? Past issues have been caused by conflicts with other plugins, or by other plugins redirecting to non-existing pages. Turn on debug logging on the Options page and try the existing URL. Then view the debug log (from the Options page) and see how the 404 was handled.

### Have you written any other programs?  ###

Please try this website for studying Japanese flashcards.    
[https://www.ajexperience.com/](https://www.ajexperience.com/)

## Screenshots ##

1. Admin Options Screen
![1. Admin Options Screen](https://plugins.svn.wordpress.org/404-solution/trunk/assets/screenshot-1.jpg)

2. Logs
![2. Logs](https://plugins.svn.wordpress.org/404-solution/trunk/assets/screenshot-2.jpg)

3. Create New Redirect
![3. Create New Redirect](https://plugins.svn.wordpress.org/404-solution/trunk/assets/screenshot-3.jpg)

## Changelog ##

## Version 2.36.1 (November 23, 2024) ##
* FIX: Finalize fixes for case sensitive lower_case_table_names settings.

## Version 2.35.25 (November 22, 2024) ##
* FIX: Hopefully fix the "Could not perform query because it contains invalid data" issue.
* FIX: Account for arrays when escaping parts of a URL.

## Version 2.35.24 (November 22, 2024) ##
* FIX: Hopefully fix a case issue with MySQL and the lower_case_table_names setting. (thanks lonesync)

## Version 2.35.23 (November 20, 2024) ##
* FIX: Hopefully avoid a requested_url cannot be null for query error.

## Version 2.35.22 (November 20, 2024) ##
* FIX: Don't load classes unless they're necessary to save memory and execution time on all pages.

## Version 2.35.21 (November 19, 2024) ##
* FIX: Make sure initializing files are included when necessary in PluginLogic.php since JetPack seems to be including that file early, by itself, without loading the plugin correctly for some reason.

## Version 2.35.20 (November 18, 2024) ##
* FIX: Resolved a security issue by no longer decoding query parameters when redirecting to a 404 page.

## Version 2.35.19 (November 18, 2024) ##
* FIX: Try to fix logging issues caused by people that use latin1 as their database encoding (urlencode utf8mb4 characters when storing to the logs table and warn about it). (thanks to debug log file participants)
* FIX: Avoid error messages when trying to assure that table names are lower case (probably introduced in 2.35.16).

## Version 2.35.18 (November 14, 2024) ##
* FIX: Make sure only admin users can export redirects using the Tools page export function.
* Update: Avoid some of the issues listed in the Plugin Check feature of WordPress.

## Version 2.35.17 (October 15, 2024) ##
* FIX: Don't throw an exception on the Options page when there are no log entries (for crzyhrse).

## Version 2.35.16 (October 9, 2024) ##
* FIX: Try to fix a case issue with MySQL and the lower_case_table_names setting.

## Version 2.35.15 (September 30, 2024) ##
* FIX: Try to fix a table collation issue for atlet.

## Version 2.35.14 (September 27, 2024) ##
* Improvement: Include WooCommerce categories also.
* FIX: Avoid an out of memory issue during spellcheck when there were too many posts or pages (> 10,000) (caused by debug_backtrace() apparently).

## Version 2.35.13 (August 7, 2024) ##
* FIX: Fix the 'Files and Folders Ignore Strings - Do Not Process' functionality.

## Version 2.35.12 (August 3, 2024) ##
* FIX: Fix an undefined array key due to the new template redirect priority option.

## Version 2.35.11 (August 2, 2024) ##
* FIX: Allow users to set the template_redirect priority which allows other plugins or other "things" to handle 404s before this plugin handles it. Hopefully this will fix an issue where some payment systems purposefully direct to non-existent pages and then handle them.
* Improvement: Try to fix some sql "contains invalid data" issues when logging redirects.

## Version 2.35.10 (July 11, 2024) ##
* FIX: Avoid an Undefined array key warning in PHP 8. Thanks @peterbp.

## Version 2.35.9 (April 17, 2024) ##
* FIX: Fix an undefined constant warning for PHP 7 (and probably 8).
* FIX: Don't esc_url() before redirecting, because it escapes things like & when it shouldn't (thanks @wordknowledge).
* Update: Apparently made the levenshtein distance algorithm slightly more efficient, but I made the change a while ago and honestly don't remember it. But I think probably it won't break anything so I guess it's okay.

## Version 2.35.8 (January 31, 2024) ##
* Update: Fixed a supposed issue on the logs page that 1. I was unable to reproduce and 2. would definitely only be possible if you were an admin user anyway, so I'm not really sure why it was reported.

## Version 2.35.7 (November 10, 2023) ##
* FIX: Avoid an Undefined array key for SERVER_NAME for some people.

## Version 2.35.6 (November 9, 2023) ##
* Improvement: Handle even more emojis.

## Version 2.35.5 (November 5, 2023) ##
* FIX: Avoid a PHP warning trim(): Passing null to parameter #1.
* FIX: Allow the fast text filter on the redirects and captured 404s tabs to work again (probably broken in 2.34.0).
* Improvement: Handle emojis in URLs without causing a collation SQL error.

## Version 2.35.4 (November 4, 2023) ##
* FIX: Correctly log redirects to the default 404 page. 
* FIX: Allow redirecting to the homepage again (broken in 2.35.3).

## Version 2.35.3 (November 3, 2023) ##
* FIX: Avoid a PHP warning preg_replace(): Passing null to parameter #3. It looks like this was preventing someone from saving their settings.
* FIX: Better handle the case When a redirect is created and then the destination page is deleted. Redirects with deleted destinations always appear at the top of the list of redirects.

## Version 2.35.2 (November 2, 2023) ##
* Improvement: Add more log messages to help diagnose issues.

## Version 2.35.1 (November 1, 2023) ##
* FIX: Fix a logging issue when redirected to a URL with no path and no trailing slash. (Thank you debug log file participants!)

## Version 2.35.0 (October 26, 2023) ##
* FIX: Compatible with WordPress 6.4.
* FIX: Fix the labels for "Ignore" and "Organize later" on the captured 404 page.
* FIX: Correctly store the "exclude specific pages" setting again (broken in 2.34.0 I think).
* FIX: Try again to fix the supposed issue that allows admins to run code on their own system.

## Version 2.34.0 (October 23, 2023) ##
* Improvement: Redirects to pages that have been deleted now appear red in UI so they're easy to see.
* FIX: Fixed a supposed SQL injection issue that I was unable to reproduce and would definitely only be possible if you were an admin user anyway, so I'm not really sure why it was reported, but thanks anyway I guess.

## Version 2.33.2 (October 17, 2023) ##
* Improvement: Try to fix a logging issue.

## Version 2.33.1 (October 13, 2023) ##
* Improvement: Fix a 'Sensitive Data Exposure vulnerability' for Joshua Chan that I didn't really think was a big deal, but it must matter to someone, so I added a random ID to the debug log filename.
* Improvement: Only try to update database tables to the correct engines if they're not already correct.
* FIX: Minor issues from some debug file participants like the referrer being too long sometimes and a missing cookie. 

## Version 2.33.0 (September 28, 2023) ##
* Improvement: Add a file import function to the Tools page so redirects can be imported (for NoAdO).
* FIX: Remove the 'Thank you for creating with...' message because it was messing up the layout on the Tools page and removing the message is easier than figuring out what the issue is with the layout.

## Version 2.32.2 (May 29, 2023) ##
* FIX: Fix the Undefined array key "path" in WordPress_Connector.

## Version 2.32.2 (May 14, 2023) ##
* FIX: Attempt to read property 'comments_pagination_base' on null in UserRequest.php on line 103.

## Version 2.32.0 (May 13, 2023) ##
* Improvement: Combine the current_user_can function calls into one userIsPluginAdmin function that has a filter so it can be easily overridden.
* Improvement: Add a 'plugin admins' section to the advanced options so non-admin users can be admins of the plugin.

## Version 2.31.13 (April 12, 2023) ##
* Improvement: Fix a compatibility issue with the "Copy & Delete Posts" plugin (copy-delete-posts) that caused the "Empty Trash" button to not be clickable for Anja.

## Version 2.31.12 (April 4, 2023) ##
* FIX: Fix a deprecated trim(null) warning for laubeauscb.
* Improvement: Try to allow the plugin to work for databaes that don't support myISAM (for debug file participants).

## Version 2.31.11 (January 31, 2023) ##
* FIX: Try to fix a php warning for Justin (array_key_exists() expects parameter 2 to be array) in the SpellChecker.

## Version 2.31.10 (January 25, 2023) ##
* FIX: Fix some errors that probably didn't affect the functionality at all, but were that were sending me emails from the debug participants.

## Version 2.31.9 (December 24, 2022) ##
* FIX: Manual redirects to 404 use the theme's 404 page (for Janio).

## Version 2.31.8 (December 16, 2022) ##
* FIX: Allow manually created redirects to redirect to the default 404 page or to the home page (for Janio).

## Version 2.31.7 (December 16, 2022) ##
* Improvement: Allow manually created redirects to redirect to the default 404 page or to the home page (for Janio).

## Version 2.31.6 (December 16, 2022) ##
* Improvement: Allow manually created redirects to redirect to the default 404 page or to the home page (for Janio).

## Version 2.31.5 (December 16, 2022) ##
* FIX: Fix the specified page doesnt exist message and probably other issues as a result of the publish status also being published now for some reason. Thanks to rik0399.

## Version 2.31.4 (December 1, 2022) ##
* FIX: Various apparently minor issues from debug file participants. 
* Improvement: Avoid casting things in SQL to make @shortcutsolutions happy.

## Version 2.31.3 (October 21, 2022) ##
* FIX: Fix the Undefined array key warning for GevoelVoorHumus.
* FIX: Try to urldecode the referer before saving it to the log table.

## Version 2.31.2 (October 17, 2022) ##
* FIX: Update the version number to try to signal to some installations to redownload the plugin because the missing file is still missing for some people for some reason.

## Version 2.31.1 (October 17, 2022) ##
* FIX: Add a missing file.

## Version 2.31.0 (October 16, 2022) ##
* FIX: Correct the page URLs in the permalink cache table and in the export data (pages previously used the permalink structure for posts). (thanks mmmartin)
* FIX: Log redirects even when the referrer is more than 512 characters long.

## Version 2.30.16 (July 15, 2022) ##
* FIX: Don't try to forward to a page that's in draft status (only 'publish' status is ok). (thanks Andrea) 

## Version 2.30.15 (May 24, 2022) ##
* FIX: Try to fix a max_join_size issue for Shelly. 
* FIX: Make saving options work with Safari for Anja. 

## Version 2.30.14 (May 19, 2022) ##
* Improvement: Change the default maximum log usage size from 100M to 10M because lots of logs cause problems for some databases.
* FIX: Force the maximum log usage size to be more than 0 even when the user enters a decimal. 

## Version 2.30.13 (April 26, 2022) ##
* FIX: Ignore the trailing /amp/ at the end of URLs (for @amendezc) (/amp was already ignored).
* FIX: Some kind of random issue when viewing redirects (thanks to a debug file participant).

## Version 2.30.12 (April 20, 2022) ##
* FIX: Avoid a PHP 8 issue. (setcookie Passing null to parameter #2). Thanks to debug file participants. 

## Version 2.30.11 (April 18, 2022) ##
* FIX: Avoid a SQL error during daily maintenance of deleting old logs. Thanks to debug file participants. 

## Version 2.30.10 (April 17, 2022) ##
* FIX: Avoid a deprecated warning with php 8 (explode and str_replace). Thanks to debug file participants. 

## Version 2.30.9 (April 15, 2022) ##
* FIX: Avoid a deprecated warning with php 8 (jsonSerialize). Thanks to debug file participants. 

## Version 2.30.8 (April 10, 2022) ##
* FIX: Avoid a deprecated warning with php 8. rtrim(): Passing null to parameter #1. Thanks to debug file participants. 

## Version 2.30.7 (March 31, 2022) ##
* FIX: Remove the extra "view details" link from the plugins page.

## Version 2.30.6 (March 31, 2022) ##
* FIX: Remove the Update URI so people can get updates from wordpress.org again.

## Version 2.30.5 (March 22, 2022) ##
* FIX: Fix a tagging/release issue with the readme.txt file.

## Version 2.30.4 (March 22, 2022) ##
* FIX: Try to fix a MAX_JOIN_SIZE issue for a debug file participant.

## Version 2.30.3 (March 18, 2022) ##
* Improvement: Allow WordPress to handle updates.
* Improvement: Add a "View details" link to the plugins page.

## Version 2.30.2 (March 17, 2022) ##
* FIX: Try to fix a MAX_JOIN_SIZE issue for a debug file participant.
* FIX: Never include the user specified custom 404 page in the suggested results (thanks @csine).

## Version 2.30.1 (March 6, 2022) ##
* FIX: Change the page status to 404 (instead of 200) when a custom 404 page is specified (for jpglegalteam).

## Version 2.30.0 (March 2, 2022) ##
* Improvement: (Speed) Cache URLs for tags and categories instead of calling get_permalink for each one (filters are not applied).
* FIX: (Error Handler) Don't log errors caused by other plugins.
* FIX: Match categories slightly better than before (still unlikely to match).

## Version 2.29.4 (February 27, 2022) ##
* FIX: Fix an undefined array key ('table_comment') issue for Michael.

## Version 2.29.3 (February 27, 2022) ##
* FIX: Fix an undefined array key issue for Michael.

## Version 2.29.2 (February 9, 2022) ##
* FIX: Avoid a Duplicate entry issue for some users (alter table ..._spelling_cache add unique key url).
* FIX: Avoid an activation issue for some users (ABJ404_TEMP_BASE not defined).

## Version 2.29.1 (December 31, 2021) ##
* FIX: Maybe fix the Illegal mix of collations issue for todayer.

## Version 2.29.0 (December 13, 2021) ##
* Improvement: Add the ability to export redirects etc. (For adelinehorry and everyone else that's asked for it that I've ignored until now.)

## Version 2.28.1 (November 30, 2021) ##
* FIX: Avoid the unexpected output warning message during activation (thanks gevoelvoorhumus).

## Version 2.28.0 (November 30, 2021) ##
* Improvement: Use the /uploads directory for temp files instead of a directory within the plugins folder that was causing some write permission issues for some users (thanks gevoelvoorhumus).
* FIX: Remove the last sent error line # from the database when deleting the debug file (for debug file participants).

## Version 2.27.14 (November 24, 2021) ##
* FIX: Correct collations even databases that return column names in ALL CAPS (thanks to a debug file participant).

## Version 2.27.13 (November 20, 2021) ##
* FIX: Avoid an Undefined array key PHP8 warning (thanks @toto).

## Version 2.27.12 (October 25, 2021) ##
* FIX: Don't force other plugins to auto update (thanks ravin001).

## Version 2.27.11 (October 18, 2021) ##
* Improvement: Add a debug message and make turning off auto updates for 404 Solution more specific.

## Version 2.27.10 (October 1, 2021) ##
* FIX: Correct collation issues even for people that have strange databases that always return column names in ALL CAPS.

## Version 2.27.9 (September 22, 2021) ##
* Improvement: Improve logging for a warning message for a debug file participant.

## Version 2.27.8 (September 10, 2021) ##
* Improvement: Change the default of the 'update URL' option to off since it's causing issues for some people.

## Version 2.27.7 (September 7, 2021) ##
* FIX: Don't update the URL when the default 404 page is used (for Sinkadus (@niwin)).

## Version 2.27.6 (September 3, 2021) ##
* FIX: Use the correct URL to update the URL bar after multiple redirects (only one redirect was supported before) (for Sinkadus (@niwin)).

## Version 2.27.5 (August 20, 2021) ##
* FIX: Use empty() instead of count() so php 7.2 will stop complaining even though everything was working fine before PHP 7.2.

## Version 2.27.4 (August 13, 2021) ##
* FIX: Fix a possible packaging issue for some users.
* Improvement: Try harder to repair crashed tables.

## Version 2.27.3 (August 11, 2021) ##
* Improvement: Only load plugin files when absolutely necessary.

## Version 2.27.2 (August 9, 2021) ##
* FIX: Save/display options correctly (thanks Greg).

## Version 2.27.1 (August 9, 2021) ##
* FIX: Avoid a Use of undefined constant warning for PHP 7 users (thanks debug file participants).

## Version 2.27.0 (August 8, 2021) ##
* FIX: Avoid the Missing argument error for the delete post listener (for kvoko and others).
* Improvement: Update the URL bar to the requested URL when a custom 404 page is used.

## Version 2.26.7 (July 12, 2021) ##
* Improvement: Minor changes to avoid unnecessary emails to the developer.

## Version 2.26.6 (July 11, 2021) ##
* Improvement: Minor changes for database table consistency.

## Version 2.26.4 (July 10, 2021) ##
* Improvement: Minor changes for database table consistency. 

## Version 2.26.3 (July 8, 2021) ##
* FIX: Avoid a possible infinite loop when updating the database version. 

## Version 2.26.2 (July 7, 2021) ##
* Improvement: Handle table and index creation more consistently to avoid various 'Index column size too large' messages. 

## Version 2.25.5 (June 28, 2021) ##
* Improvement: Ignore the "Unknown storage engine 'InnoDB'" message that some debug file participant is sending me every day. 

## Version 2.25.4 (June 25, 2021) ##
* Improvement: Maybe avoid the "The plugin does not have a valid header" issue.
* Improvement: Use less code for error display and testing.

## Version 2.25.3 (June 15, 2021) ##
* Improvement: Avoid a warning message for a debug file participant.

## Version 2.25.2 (June 10, 2021) ##
* FIX: Definitely avoid an unimportant message when upgrading to the latest version.

## Version 2.25.1 (June 9, 2021) ##
* FIX: Maybe avoid an unimportant SQL error when upgrading to the latest version.
* Improvement: Maybe use less memory.

## Version 2.25.0 (June 8, 2021) ##
* FIX: Ignore page types that are supposed to be ignored for page changes (for Vahan).

## Version 2.24.7 (June 1, 2021) ##
* FIX: Allow backslashes in regular expressions matches.
* FIX: Disable automatic updates by WordPress.
* Improvement: Wait 3 days before automatically updating to a minor version.

## Version 2.24.6 (May 31, 2021) ##
* FIX: Fix an index length on a database table (spelling_cache.url) (thanks debug file participants).

## Version 2.24.5 (May 29, 2021) ##
* FIX: Fix an index length on a database table (spelling_cache.url) (thanks debug file participants).

## Version 2.24.4 (May 28, 2021) ##
* FIX: Convert plugin tables to use InnoDB instead of MyISAM to avoid table lock issues (for jmartin).
* Improvement: Remove the first part of the path from log files in some cases because some people don't want to see it in the logs.

## Version 2.24.3 (May 25, 2021) ##
* Improvement: Include information about page matches in the debug log.

## Version 2.24.2 (May 22, 2021) ##
* FIX: Maybe work with PHP 5.6 again (for Joseph).

## Version 2.24.1 (May 21, 2021) ##
* FIX: Avoid a warning/error when used with PHP 8.

## Version 2.24.0 (May 18, 2021) ##
* FIX: Allow the user to select "default 404 page" and "home page" as the default redirect destination.
* FIX: Avoid some warnings/errors created when used with PHP 8.
* Improvement: Return suggested pages faster. Use less memory.

## Version 2.23.13 (May 16, 2021) ##
* Improvement: Adjusted the algorithm for finding similar pages.

## Version 2.23.12 (May 16, 2021) ##
* Improvement: Update the permalink cache with one query instead of many.

## Version 2.23.11 (April 8, 2021) ##
* FIX: Maybe fix a Too few arguments message for some users.

## Version 2.23.10 (March 14, 2021) ##
* Improvement: Add bulk actions to the bottom of the page redirects and captured 404s pages. (for deeveearr)

## Version 2.23.9 (March 12, 2021) ##
* FIX: Try to fix seemingly randomly created automatic redirects when a page is saved. (for WonderGeekWoman)

## Version 2.23.8 (February 22, 2021) ##
* FIX: Try to avoid infinite redirects (for adam198).
* FIX: The "delete the debug file" button works again.

## Version 2.23.7 (February 19, 2021) ##
* FIX: Handle parameters correctly (for adam198).

## Version 2.23.6 (November 22, 2020) ##
* FIX: Fix the Captured URLs view when ordered by the Hits column and when ENFORCE_GTID_CONSISTENCY is turned on by not using CREATE TABLE AS SELECT (for Anton).

## Version 2.23.5 (November 12, 2020) ##
* FIX: Avoid an Undefined index: SERVER_NAME for shark986.

## Version 2.23.4 (October 20, 2020) ##
* FIX: Allow saving settings.
* FIX: Allow updating the redirect all 404s to option. 

## Version 2.23.3 (October 19, 2020) ##
* FIX: Avoid a missing index message for editors (for nicmare). 

## Version 2.23.2 (October 12, 2020) ##
* FIX: Fixed various issues with the new exclude pages feature.

## Version 2.23.1 (October 10, 2020) ##
* FIX: Fixed various issues with the new exclude pages feature.

## Version 2.23.0 (October 9, 2020) ##
* Feature: Add a list of pages to exclude from results under advanced options (for AndreLung). (Also for other people who have requested it in the past, but really it's never been actually necessary until now, in my opinion. Which is why I avoided adding it. I like ice cream.)
* Improvement: By default disable the "admin notification" of captured 404s (this only affects new installations).
* FIX: When the enter key was pressed after typing a filter the filter didn't display correctly until the "rows per page" option was changed.

## Version 2.22.11 (September 26, 2020) ##
* Improvement: Always include query parameters when linking to pages in the shortcode page suggestions (thanks lellolallo).

## Version 2.22.10 (August 28, 2020) ##
* Improvement: Avoid a modsecurity issue for cosmoweb.
* FIX: Illegal mix of collations for a debug file participant.
* Improvement: Don't annoy the plugin author with emails about database users not having the right to delete from the database.

## Version 2.22.9 (May 5, 2020) ##
* Improvement: Avoid a warning message in a php log file (for mborin).

## Version 2.22.8 (May 5, 2020) ##
* Improvement: Avoid a warning message in a php log file (for mborin).

## Version 2.22.7 (May 5, 2020) ##
* Improvement: Avoid a warning message in a php log file (for mborin).

## Version 2.22.6 (April 1, 2020) ##
* Improvement: Try to fix a damaged 404 Solution table (for rentptr).

## Version 2.22.5 (March 19, 2020) ##
* Improvement: Try to fix a damaged 404 Solution table (for rentptr).

## Version 2.22.4 (January 30, 2020) ##
* FIX: Avoid a warning about upcoming PHP 8 (thanks to a debug file participant).

## Version 2.22.3 (January 7, 2020) ##
* FIX: Avoid a warning about upcoming PHP 8 (thanks to a debug file participant).

## Version 2.22.2 (December 26, 2019) ##
* FIX: Allow saving options in the 'advanced' section for user agents, post types, and custom taxonomies (thanks arnonalex).

## Version 2.22.1 (December 14, 2019) ##
* FIX: Remove minor PHP warning messages in some cases.
* FIX: Allow updating captured 404s again.

## Version 2.22.0 (December 10, 2019) ##
* FIX: A rare logging error that an error log participant sent.
* Improvement: Use less memory (use a dynamic class loader).

## Version 2.21.19 (November 1, 2019) ##
* Improvement: Make the pagination links easier to click for steveraven.
* Improvement: Redirects are recognized with or without a trailing slash (/) (for leehodson).

## Version 2.21.18 (October 29, 2019) ##
* Improvement: Make the pagination links easier to click for steveraven.

## Version 2.21.17 (October 1, 2019) ##
* FIX: Ordering by the Hits column on the Captured 404 URLs page sometimes caused no URLs to be displayed (attempt 2).

## Version 2.21.16 (September 29, 2019) ##
* FIX: Process stored regex redirects even when creating automatic redirects is turned off.

## Version 2.21.15 (September 28, 2019) ##
* FIX: Regular expressions with replacement values should work again (broken in 2.21.12).

## Version 2.21.14 (September 27, 2019) ##
* FIX: Ordering by the Hits column on the Captured 404 URLs page sometimes caused no URLs to be displayed.

## Version 2.21.14 (September 18, 2019) ##
* FIX: Setting the "Redirect all unhandled 404s to" option to an external URL works agian.
* Improvement: Only hash the last octet of IP addresses for GDPR compliance (last part for IPv4, last 5 parts for IPv6).

## Version 2.21.11 (July 27, 2019) ##
* FIX: Do not create redirects if the option is unchecked.

## Version 2.21.10 (July 24, 2019) ##
* FIX: Avoid the implode issue by using explode() instead of split() and mb_split().

## Version 2.21.9 (July 22, 2019) ##
* FIX: Avoid an Undefined index warning for MrBrian.
* Improvement: More logging during automatic updates for minor versions.

## Version 2.21.8 (July 18, 2019) ##
* FIX: Avoid emailing the debug file multiple times (for participating users).
* FIX: Avoid the ''implode(): Invalid arguments passed'' issue for some users and include more debugging information.
* FIX: Correct an automatic update issue with ob_end_clean(): failed to delete buffer. No buffer to delete.

## Version 2.21.7 (July 13, 2019) ##
* FIX: Avoid the ''implode(): Invalid arguments passed'' issue for some users.

## Version 2.21.6 (July 9, 2019) ##
* FIX: Revert to 2.21.3 after various issues with 2.21.4 and 2.21.5.

## Version 2.21.3 (July 7, 2019) ##
* FIX: Avoid the ''implode(): Invalid arguments passed'' issue for some users.
* Improvement: Better logging during an automatic update.

## Version 2.21.2 (July 3, 2019) ##
* FIX: Handle the case where an existing page name has invalid html characters.
* FIX: When a user requests a URL that's invalid because it's too long then truncate it so it doesn't break things. 

## Version 2.21.1 (June 29, 2019) ##
* FIX: Correctly update the Hits column on the Captured 404 URLs page. 
* FIX: Correctly update the permalink cache table.

## Version 2.21.0 (June 26, 2019) ##
* Improvement: Automatically create a redirect when a post slug is changed (doesn't work for pages). 
* Improvement: Various logging improvements to help me debug things.

## Version 2.20.5 (June 18, 2019) ##
* Improvement: See all categories when searching page suggestions by typing Categ in the search box.

## Version 2.20.4 (June 18, 2019) ##
* FIX: Remember which column to order by on the page redirects and captured URLs pages (for vijilamarshal).

## Version 2.20.3 (June 17, 2019) ##
* Improvement: Log more information about the implode() issue some people are experiencing and other issues.

## Version 2.20.2 (June 5, 2019) ##
* FIX: When a missing default 404 page is specified, don't redirect to it. Also warn the user on the options page (for nsoutter).

## Version 2.20.1 (June 3, 2019) ##
* Improvement: Backend improvements. Syncing the permalink cache, sorting by hits, logging.

## Version 2.20.0 (May 30, 2019) ##
* FIX: Fix the 'count(): Parameter must be an array or an object that implements Countable' issue. 
* FIX: Fix manual redirects not working because of a trailing slash issue. 
* Improvement: Use a temporary file instead of the DB for a syncing issue some users are having.

## Version 2.19.6 (May 23, 2019) ##
* FIX: Fix the term_id syntax error for Karel. 

## Version 2.19.5 (May 23, 2019) ##
* FIX: Redirecting to Tags and Categories works again.
* FIX: Redirecting with ?query=parts works again.

## Version 2.19.4 (May 17, 2019) ##
* FIX: Fix the Trash links on the Redirects and Captured 404s pages.

## Version 2.19.3 (May 17, 2019) ##
* FIX: Fix the Empty Trash button on the Redirects and Captured 404s pages.

## Version 2.19.2 (May 15, 2019) ##
* Improvement: Minor debug logging improvements.

## Version 2.19.1 (May 13, 2019) ##
* FIX: Allow editing redirects (broken in 2.19.0).

## Version 2.19.0 (May 11, 2019) ##
* Improvement: The optional developer debug email includes a list of active plugins.
* Improvement: Various logging improvements.
* Improvement: Ignore a trailing /amp on 404s.

## Version 2.18.6 (May 6, 2019) ##
* Improvement: Better similar posts matching for sites with over 300 pages or posts.

## Version 2.18.5 (May 3, 2019) ##
* FIX: Run maintenance again (broken in 2.18.4).

## Version 2.18.4 (May 2, 2019) ##
* Improvement: Only load classes when necessary (for Marc).

## Version 2.18.3 (May 1, 2019) ##
* FIX: Fix a minor logging issue...

## Version 2.18.2 (May 1, 2019) ##
* Improvement: Use less memory for spellchecking. Load less on non-admin pages. Improved logging.

## Version 2.18.1 (April 28, 2019) ##
* FIX: Correct the 'requested URL can't be null' logging issue.
* FIX: Avoid an empty page when sorting by 'Hits' or 'Last Used' on the Redirects page when there are too many log entries. 

## Version 2.18.0 (April 24, 2019) ##
* Improvement: Add a search filter on the Redirects page and the Captured 404s page 
    (does not filter columns Hits, Created, or Last Used) (for Carol).
* Improvement: Add the following folders to the ignore list in the advanced options section. 
    "wp-content/plugins/*", "wp-content/themes/*", ".well-known/acme-challenge/*"
* Improvement: Strip out any /comment-page-#/ part of the URL before looking for similar pages.

## Version 2.17.2 (April 19, 2019) ##
* FIX: Correct a rare issue during an automatic update (thanks to a debug file participant).

## Version 2.17.1 (April 17, 2019) ##
* FIX: Correct the ''Column ‘referrer’ cannot be null'' issue with a nightly maintenance run.

## Version 2.17.0 (April 13, 2019) ##
* Improvement: Allow the plugin to work without mbstring PHP extension (for alex).
* Improvement: The URL column on the Redirects page opens the URL in a new tab (for Carol).

## Version 2.16.2 (April 9, 2019) ##
* FIX: Avoid a PHP warning message (thanks Marc).

## Version 2.16.0 (April 4, 2019) ##
* Improvement: (Speed) Cache similar pages when a 404 happens and reuse them when possible.
* Improvement: Use less memory. Tested with about 20k pages and 40M for WordPress.
* FIX: PHP 7 compatibility fixes and warning messages from debug file participants. 

## Version 2.15.4 (March 28, 2019) ##
* FIX: Fix a compatibility issue with PHP versions earlier than 5.5.18 (for thowarth91).

## Version 2.15.3 (March 27, 2019) ##
* Feature: Automatically update to major versions after waiting 30 days (configurable) after their release.

## Version 2.15.2 (March 25, 2019) ##
* FIX: Correct a "Call to undefined method" when a database update didn't work (thanks itjebsen).

## Version 2.15.1 (March 24, 2019) ##
* Improvement: Include more log information for trying to solve an issue for a developer feedback participant.
* Improvement: Performance improvements when an unrecognized 404 is captured (speed, memory).

## Version 2.15.0 (March 23, 2019) ##
* Feature: Automatically update the plugin when a new minor version is released (major versions are still manual).
* Improvement: Only include links in the permalink cache that will actually be used.

## Version 2.14.1 (March 22, 2019) ##
* FIX: Correct a minor logging issue for PHP 7.2.

## Version 2.14.0 (March 22, 2019) ##
* Feature: Now faster with sites with 10k+ pages.
* FIX: Respect the log size limit (for Phil and others).
* Improvement: Automatically limit the debug file size.
* FIX: Avoid an "Illegal mix of collations" issue for lestadt (and many others).

## Version 2.13.0 (February 25, 2019) ##
* Feature: Allow bulk operations on the Page Redirects tab (for Carol).
* Feature: Allow bulk operations on the Captured 404 URLs -> Trash page.
* Improvement: Faster response on the logs page for the dropdown search.
* Improvement: Faster page load when using page suggestions with the [abj404_solution_page_suggestions] shortcode.
* FIX: Avoid a rare division by 0 (thanks to an automatically submitted error file).

## Version 2.12.2 (February 17, 2019) ##
* FIX: Don't include unnecessary files for users when redirecting (speed up redirects, introduced in 2.11.0).
* FIX: Don't show the "Add a Redirect" button on the Trash page where it can't be done.
* FIX: Sort by Destination using the page title, not the page ID.
* FIX: Change the hook priority for compatibility with the '404page - your smart custom 404 error page' plugin.

## Version 2.12.1 (February 17, 2019) ##
* FIX: Correct an issue with adding external URLs introduced in 2.12.0 (thanks Людмила via email).
* FIX: Don't rely on external sources for CSS.

## Version 2.12.0 (February 16, 2019) ##
* Improvement: Use a dropdown search when choosing the default 404 destination on the options page.
* Improvement: Use a dropdown search when choosing which URL to view on the Logs page.
* Improvement: Limit the list of pages to 1000 results when searching for page names on options pages.

## Version 2.11.2 (February 8, 2019) ##
* FIX: Correct an issue with adding external URLs introduced in 2.11.0 (thanks Людмила via email).

## Version 2.11.1 (February 8, 2019) ##
* FIX: Correct a minor JavaScript issue (introduced in 2.11.0).

## Version 2.11.0 (February 8, 2019) ##
* Improvement: Adding a manual redirect uses a search and a dropdown list (for samwebdev).

## Version 2.10.3 (November 18, 2018) ##
* Improvement: Remember which column to order by on the page redirects and captured URLs pages (for vijilamarshal).
* FIX: Support international characters like Japanese and Hebrew (for arnonalex) (second attempt).

## Version 2.10.2 (October 17, 2018) ##
* FIX: Support international characters like Japanese and Hebrew (for arnonalex).

## Version 2.10.1 (September 29, 2018) ##
* Improvement: Minor changes to avoid error messages for some users (for lestadt).

## Version 2.10.0 (September 6, 2018) ##
* FIX: Maintenance to delete duplicates now deletes the oldest duplicate rows instead of the most recent ones (thanks Marc Siepman).
* FIX: A debug line is now GDPR compliant (according to the options) (thanks Marc Siepman).
* Improvement: Minor changes to avoid rare error messages for some users.

## Version 2.9.5 (July 4, 2018) ##
* FIX: Include a list of all of the post types in the database on the options page (for Mauricio).

## Version 2.9.4 (July 2, 2018) ##
* FIX: Work with earlier versions of PHP again (bug introduced in 2.9.3).
    (by using a global variable instead of a constant to store some array values)

## Version 2.9.3 (July 1, 2018) ##
* FIX: The "Files and Folders Ignore Strings" setting now works better (for Phil).

## Version 2.9.2 (July 1, 2018) ##
* FIX: Regex redirects can now be emptied from the trash (for VA3DBJ bug #23).

## Version 2.9.1 (May 24, 2018) ##
* FIX: Custom taxonomies: allow entering the taxonomy name instead of the children of taxonomies to use them.

## Version 2.9.0 (May 17, 2018) ##
* Improvement: Support custom taxonomies.
* Improvement: Allow group matching and replacements in regular expression matches.

## Version 2.8.0 (April 26, 2018) ##
* Feature: When a recognized image extension is requested, only images are used as possible matches.

## Version 2.7.0 (April 19, 2018) ##
* FIX: Hash IP addresses before storing them to be General Data Protection Regulation (GDPR) friendly (for Marc).

## Version 2.6.4 (April 14, 2018) ##
* FIX: Try to avoid an activation error on older php versions for HuntersServices.

## Version 2.6.3 (April 13, 2018) ##
* FIX: Correct a minor levenshtein algorithm bug introduced in 2.6.2 when no pages match a URL.

## Version 2.6.2 (April 12, 2018) ##
* FIX: Allow editing a RegEx URL and keeping the RegEx status (thanks joseph_t).
* FIX: Maintain a query string when redirecting in some cases (such as RegEx redirects) (thanks joseph_t).

## Version 2.6.1 (February 24, 2018) ##
* FIX: RegEx redirects support external URLs.
* FIX: The Levenshtein algorithm improvement works with URLs up to 2083 characters in length (up from 300).
* FIX: Try to avoid an issue where strange URLs starting with ///? are returned.

## Version 2.6.0 (February 2, 2018) ##
* Feature: Use RegEx (regular expressions) to match URLs and redirect to specific pages.
* Feature: New option: The Settings menu can be under "Settings" or at the same level as the "Settings" and "Tools" menus.
* Feature: Optionally send an email notification when a certain number of 404s are captured.
* FIX: Delete old redirects based on when they were last used instead of the date they were created.
* Improvement: Allow ordering redirects and captured 404s by the "Last Used" (most recently used date) column on the admin page.
* Improvement: Add the logged in "user" column to the logs table.
* Improvement: Matching categories and tags works a little better than before.
* Improvement: Use a faster, more memory efficient Levenshtein algorithm.

## Version 2.5.4 (December 18, 2017) ##
* Improvement: Improved error message for the customLevenshtein function.
* FIX: Handle a version upgrade without an SQL error when the old logs table doesn't exist 
    (thanks to the user error reporting option).

## Version 2.5.3 (December 6, 2017) ##
* FIX: Work with URLs longer than 255 characters (for lestadt).

## Version 2.5.2 (December 3, 2017) ##
* FIX: Work with PHP version 5.2 again (5.5 required otherwise) (thanks Peter Ford).
    (by limiting array references to one-level deep when accessing arrays)

## Version 2.5.1 (December 3, 2017) ##
* FIX: Work with PHP version 5.4 again (5.5 required otherwise) (thanks moneyman910!).
    (by removing the "finally" block from a try/catch)

## Version 2.5.0 (December 2, 2017) ##
* FIX: Avoid a critical issue that may have caused an infinite loop in rare cases when updating versions.
* Feature: Add an option to email the log file to the developer when there's an error in the log file.
* Feature: Add the [abj404_solution_page_suggestions] shortcode to display page suggestions on custom 404 pages.
* Improvement: Optimize the redirects table after emptying the trash (thanks Christos).
* Improvement: Add a button to the "Page Redirects" to scroll to the "Add a Manual Redirect" section (for wireplay).
* Improvement: Remove the page suggestions on/off option. To turn it off, don't include the shortcode.
* FIX: Ordering redirects and 404s by the 'Hits' column works again (broken in 2.4.0) (thanks Christos).
* FIX: Duplicate redirects are no longer created when a user specified 404 page is used.

## Version 2.4.1 (November 27, 2017) ##
* FIX: Make the 'Empty Trash' button work for lots of data (for Christos).

## Version 2.4.0 (November 26, 2017) ##
* Improvement: Major speed improvement on 'Redirects' and 'Captured' tabs when there are lots of logs.

## Version 2.3.2 (November 25, 2017) ##
* Improvement: Minor efficiency improvements to work better on larger sites.

## Version 2.3.1 (November 24, 2017) ##
* FIX: Try to fix the Captured 404 URLs page when there is a lot in the logs table (for Christos).

## Version 2.3.0 (November 10, 2017) ##
* Improvement: Add an "Organize Later" category for captured 404s (for wireplay).
* Improvement: Add an advanced option to ignore a set of files or folders (for Hans Glyk).

## Version 2.2.2 (November 5, 2017) ##
* FIX: The first usage of the options page didn't work on fresh installations (Lee Hodson).

## Version 2.2.1 (November 4, 2017) ##
* FIX: The options page was unusable on fresh installations (Lee Hodson).

## Version 2.2.0 (October 29, 2017) ##
* FIX: Display child pages under their parent pages on admin screen dropdowns (for wireplay).

## Version 2.1.1 (September 24, 2017) ##
* FIX: Order the list of pages, posts, etc in dropdown boxes again (broken since 2.1.0. thanks to Hans im Glyk for reporting this).

## Version 2.1.0 (September 23, 2017) ##
* Improvement: Don't suggest or forward to product pages that are hidden in WooCommerce, for ajna667.

## Version 2.0.0 (September 20, 2017) ##
* Improvement: Speed up the Captured 404s page for blankpagestl.

## Version 1.9.3 (September 16, 2017) ##
* FIX: Try to fix Rickard's MAX_JOIN_SIZE issue.

## Version 1.9.2 (September 15, 2017) ##
* FIX: Try to fix techjockey's out of memory issue on the options page with an array.

## Version 1.9.1 (September 14, 2017) ##
* FIX: Try to fix techjockey's out of memory issue on the options page.

## Version 1.9.0 (August 12, 2017) ##
* FIX: Allow manual redirects to forward to the home page.
* Improvement: Support user defined post types (defaults are post, page, and product).
* Improvement: Change "Slurp" to "Yahoo! Slurp" and add SeznamBot, Pinterestbot, and UptimeRobot to the list of known bots for the do not log list.

## Version 1.8.2 (August 8, 2017) ##
* FIX: Verify that the daily cleanup cron job is running.
* FIX: Include post type "product" in the spell checker for compatibility with WooCommerce (fix part 1/?).
* FIX: Ignore characters -, _, ., and ~ in URLs when spell checking slugs (for ozzymuppet).

## Version 1.8.1 (June 13, 2017) ##
* Improvement: Add a new link and don't require a link to view the debug file (for perthmetro).

## Version 1.8.0 ##
* Improvement: Do not create captured URLs for specified user agent strings (such as search engine bots).

## Version 1.7.4 (June 8, 2017) ##
* FIX: Try to fix issue #19 for totalfood (Redirects & Captured 404s Not Recording Hits).

## Version 1.7.3 (June 2, 2017) ##
* FIX: Try to fix issue #12 for scidave (Illegal mix of collations).

## Version 1.7.2 (June 1, 2017) ##
* FIX: Try to fix issue #12 for scidave (Call to a member function readFileContents() on a non-object).

## Version 1.7.1 (May 27, 2017) ##
* FIX: Always show the requested URL on the "Logs" tab (even after a redirect is deleted).
* FIX: "View Logs For" on the logs tab shows all of the URLs found in the logs.

## Version 1.7.0 (May 24, 2017) ##
* Improvement: Old log entries are deleted automatically based on the maximum log size.
* Improvement: Log structure improved. Log entries no longer require redirects. 
This means additional functionality can be added in the future, 
such as redirects based on regular expressions and ignoring requests based on user agents.

## Version 1.6.7 (May 3, 2017) ## 
* FIX: Correctly log URLs with only special characters at the end, like /&.
* FIX: Fix a blank options page when a page exists with a parent page (for Mike and wdyim).

## Version 1.6.6 (April 20, 2017) ##
* Improvement: Avoid logging redirects from exact slug matches missing only the trailing slash (avoid canonical 
    redirects - let WordPress handle them).
* Improvement: Remove the "force permalinks" option. That option is always on now.

## Version 1.6.5 ##
* Improvement: Add 500 and "all" to the rows per page option to close issue #8 (Move ALL Captured 404 URLs to Trash).
* FIX: Correct the "Redirects" tab display when the user clicks the link from the settings menu.

## Version 1.6.4 (April 6, 2017) ##
* Improvement: Add a "rows per page" option for pagination for ozzymuppet.
* FIX: Allow an error message to be logged when the logger hasn't been initialized (for totalfood).

## Version 1.6.3 (April 1, 2017) ##
* FIX: Log URLs with queries correctly and add REMOTE_ADDR, HTTP_USER_AGENT, and REQUEST_URI to the debug log for ozzymuppet.
* Improvement: Add a way to import redirects (Tools -> Import) from the old "404 Redirected" plugin for Dave and Mark.

## Version 1.6.2 ##
* FIX: Pagination links keep you on the same tab again.
* FIX: You can empty the trash again.

## Version 1.6.1 ##
* FIX: In some cases editing multiple captured 404s was not possible (when header information was already sent to
    the browser by a different plugin).
* Improvement: Forward using the fallback method of JavaScript (window.location.replace() if sending the Location:
    header does not work due to premature outptut).

## Version 1.6.0 ##
* Improvement: Allow the default 404 page to be the "home page."
* Improvement: Add a debug and error log file for Dave.
* FIX: No duplicate captured URLs are created when a URL already exists and is not in the trash.

## Version 1.5.9 ##
* FIX: Allow creating and editing redirects to external URLs again. 
* Improvement: Add the "create redirect" bulk operation to captured 404s.
* Improvement: Order posts alphabetically in the dropdown list.

## Version 1.5.8 ##
* FIX: Store relative URLs correctly (without the "http://" in front).

## Version 1.5.7 ##
* Improvement: Ignore requests for "draft" posts from "Zemanta Aggregator" (from the "WordPress Related Posts" plugin).
* Improvement: Handle normal ?p=# requests.
* Improvement: Be a little more relaxed about spelling (e.g. aboutt forwards to about).

## Version 1.5.6 ##
* FIX: Deleting logs and redirects in the "tools" section works again.
* Improvement: Permalink structure changes for posts are handled better when the slug matches exactly.
* Improvement: Include screenshots on the plugin page, a banner, and an icon.

## Version 1.5.5 ##
* FIX: Correct duplicate logging. 
* Improvement: Add debug messages.
* Improvement: Reorganize redirect code.

## Version 1.5.4 ##
* FIX: Suggestions can be included via custom PHP code added to 404.php

## Version 1.5.3 ##
* Refactor all code to prepare for WordPress.org release.

## Version 1.5.2 ##
* FIX plugin activation. Avoid "Default value for parameters with a class type hint can only be NULL"
* Add a Settings link to the WordPress plugins page.

## Version 1.5.1 ##
* Prepare for release on WordPress.org.
* Sanitize, escape, and validate POST calls.

## Version 1.5.0 ##
* Rename to 404 Solution (forked from 404 Redirected at https://github.com/ThemeMix/redirectioner)
* Update branding links
* Add an option to redirect all 404s to a specific page.
* When a slug matches a post exactly then redirect to that post (score +100). This covers cases when permalinks change.
