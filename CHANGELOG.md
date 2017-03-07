# Changelog #

## Version 1.5.6 ##
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
