# Changelog #

## Version 1.4.7 ##
* Fixed too strict data sanitation for the `wbz404_suggestions()` template tag
* Fixed CSS class for suggested 404s div wrapper.

## Version 1.4.6 ##
* Fixed bug where query vars were being stripped
* Fixed a bug caused by plugin incorrectly injecting end-points turning up as 404s
* Fixed log purging issues

## Version 1.4.4 ##
* Fixed a [SQL bug](https://github.com/defries/404-redirected/issues/7)
* Fixed a bug where [logs wouldn't get deleted](https://github.com/defries/404-redirected/issues/8)
* Fixed a bug where [deactivating and activating the plugin would reset the stats to 0](https://github.com/defries/404-redirected/issues/9)
* Fixed various [PHP notices](https://github.com/defries/404-redirected/issues/10)

## Version 1.4.3 ##

* Updating a bug where a check for an ancient MySQL version would throw an error
* Fixing integer bug in SQL query
* General debug errors fixed as well.

## Version 1.4.2 ##

* Introducing WordPress Coding Standards
* Replace `wpdb::escape` for `esc_sql()`
* Removing exotic translation function and replacing with default translation setup. In other words, the plugin is now translatable.

## Version 1.4.1 ##

Released: 2016-06-06

* Improved security hardening (bugfixing)

## Version 1.4.0 ##

* Plugin takeover from rrolfe because of lack of maintenance in four years
* Data sanitization added where needed (everywhere)
* Fixed PHP notices

## Version 1.3.2 ##

Released: 2012-08-29

New Features:

* None

Bug Fixes:

* Remember way back when 1.3.1 was released (yesterday) and I said the cron jobs were fixed? They weren't. This update fixes crons for users who are upgrading by implementing a version check on the DB.
* Added upgrade script functionality by implementing DB version checking
* Performed general code cleanup to get rid of PHP NOTICES

## Version 1.3.1 ##

Released: 2012-08-28

New Features:

* None

Bug Fixes:

* Fixed bug that caused cron jobs not to register properly - Stopped automatic deletion of redirects and logs from happening

## Version 1.3 ##

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

## Version 1.2 ##

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

## Version 1.1 ##

Released: 2011-11-29

* Fixed adding and editing of Tag & Category redirects
* Fixed bug causing category redirects to incorrectly match external redirects

## Version 1.0 ##

Released: 2011-11-20

* Initial Release
