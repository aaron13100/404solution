# 404 Solution #

Automatically redirect 404s when the slug matches (for permalink changes), when a very similar name match is found, or always to a default page.

## Description ##

404 Solution logs 404s and allows them to be redirected to pages that exist. Redirects can also be created based on the best possible match for the URL the visitor was most likely trying to reach.

### Features: ###

* Get list of 404 URLs as they happen.
* Redirect 404 URLs to existing pages or ignore them.
* Automatically create redirects based on the URL the visitor was most likely trying to visit.
* View logs of hits to 404 pages and redirects including referrer data.
* Automatically remove redirects when the URL matches a new page or post permalink.
* Automatically remove manual and automatic redirects once they are no longer being used.
* All features work with both pages and posts.
* Create automatic redirects for any URL resolving to a single page or post that isn't the current permalink.
* Basic plugin usage statistics.

Convert your 404 traffic by providing your visitors with a better browsing experience and eliminate 404 URLs on your site.

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

Yes. It's as easy as turning on this feature in the options.

## Screenshots ##

1. Admin Options Screen
![1. Admin Options Screen](https://ps.w.org/404-redirected/trunk/screenshot-1.png)

2. Logs
![2. Logs](https://ps.w.org/404-redirected/trunk/screenshot-2.png)

3. Create New Redirect
![3. Create New Redirect](https://ps.w.org/404-redirected/trunk/screenshot-3.png)


## Changelog ##

### Version 1.5.2 ###
* FIX plugin activation. Avoid "Default value for parameters with a class type hint can only be NULL"
* Add a Settings link to the WordPress plugins page.

### Version 1.5.1 ###
* Prepare for release on WordPress.org.
* Sanitize, escape, and validate POST calls.

### Version 1.5.0 ###
* Rename to 404 Solution (forked from 404 Redirected at https://github.com/ThemeMix/redirectioner)
* Update branding links
* Add an option to redirect all 404s to a specific page.
* When the a slug matches a post exactly then redirect to that post (score +100). This covers cases when permalinks change.

### Version 1.4.7 ###
* Fixed too strict data sanitation for the `abj404_suggestions()` template tag
* Fixed CSS class for suggested 404s div wrapper.

### Version 1.4.6 ###
* Fixed bug where query vars were being stripped
* Fixed a bug caused by plugin incorrectly injecting end-points turning up as 404s
* Fixed log purging issues
* General code improvements

### Version 1.4.4 ###
* Fixed a [SQL bug](https://github.com/defries/404-redirected/issues/7)
* Fixed a bug where [logs wouldn't get deleted](https://github.com/defries/404-redirected/issues/8)
* Fixed a bug where [deactivating and activating the plugin would reset the stats to 0](https://github.com/defries/404-redirected/issues/9)
* Fixed various [PHP notices](https://github.com/defries/404-redirected/issues/10)

### Version 1.3 ###

Added new purge options and sorting by number of hits. Lots of bug fixes.

### Version 1.2 ###

Major bug fixes. Also added bulk processing of URLs and admin notifications.

### Version 1.1 ###

2 bug fixes in adding/editing redirects

### Version 1.0 ###

Initial Release
