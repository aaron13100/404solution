# 404 Redirected #

Creates automatic redirects for 404 traffic and page suggestions when matches are not found providing better service to your web visitors.

## Description ##

404 Redirected allows WordPress admins to have control over their dead links and redirects from inside the admin panel. 404 Redirected records all URLs that users have visited and allows the admin to easily create 301 and 302 redirects to valid pages on their site. Redirects can also be created based on the best possible match for the URL the visitor was most likely trying to reach.

> <strong>Support & Bug Reports</strong><br>
> If you're in need of support or would like to file a bug report, please head over to our Github repository and [create a new issue.](https://github.com/defries/404-redirected/issues)
>

### Features: ###

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

## Installation ##

Installation of 404 Redirect is simple:

1. Unzip `404-redirected.zip` and upload contents to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress Admin
1. Use the `Settings -> 404 Redirected` options page to enable desired features.

For the `suggested pages` feature to work you need to edit your 404.php template file to include the following code:
`<?php if (function_exists( 'wbz404_suggestions' ) ) { wbz404_suggestions(); } ?>`

## Frequently Asked Questions ##

### How long does it take for 404 URLs to start showing up? ###

As long as the "Capture incoming 404 URLs" option is enabled in the options section, the 404 URLs will show up in the captured list as soon as a visitor hits a 404 page.

### Will there be a slow down on my site when running the plugin? ###

No, there should be no noticeable slow down when running the plugin on your site.

### Will this plugin redirect my pages if I change my permalinks structure? ###

Yes! 404 Redirected records the page/post ID number and looks up the most current permalink before redirecting the user.

### Can I redirect all 404's to a particular page? ###

No, that's not what this plugin is for. This plugin is designed to make your visitors experience better by automatically fixing 404 problems caused by typos.

## Screenshots ##

1. Admin Options Screen
![1. Admin Options Screen](https://ps.w.org/404-redirected/assets/screenshot-1.jpg)

2. Logs
![2. Logs](https://ps.w.org/404-redirected/assets/screenshot-2.jpg)

3. Create New Redirect
![3. Create New Redirect](https://ps.w.org/404-redirected/assets/screenshot-3.png)



## Upgrade Notice ##

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
