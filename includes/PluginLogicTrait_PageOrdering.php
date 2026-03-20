<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Page ordering, hierarchy helpers, redirect destination building, and notification helpers.
 * Used by ABJ_404_Solution_PluginLogic via `use`.
 *
 * @phpstan-type PageObject object{id: int, post_parent: int, depth: int, post_type: string, post_title: string}
 */
trait ABJ_404_Solution_PluginLogicTrait_PageOrdering {

    /**
     * Build the final redirect destination URL.
     *
     * This is separated for testability and to avoid mixing HTML escaping with redirect URL construction.
     *
     * @param string $location Base redirect destination.
     * @param string $requestedURL Original requested URL (used for custom 404 ref tracking).
     * @param bool $isCustom404 Whether we are redirecting to a custom 404 page.
     * @return string Redirect destination suitable for wp_redirect().
     */
    public function buildFinalRedirectDestination($location, $requestedURL = '', $isCustom404 = false) {
        // Translate redirect destination for multilingual sites (TranslatePress, etc.)
        $location = $this->maybeTranslateRedirectUrl($location, $requestedURL);

        // Preserve comment pagination and query string from the original request.
        $commentPartAndQueryPart = (string)$this->getCommentPartAndQueryPartOfRequest();
        $finalDestination = (string)$location . $commentPartAndQueryPart;

        // Append _ref LAST for custom 404 redirects (prevents user override via query string).
        // This is a fallback for when cookies don't survive 301 redirects.
        if ($isCustom404 && is_string($requestedURL) && $requestedURL !== '') {
            $refUrlResult = preg_replace('/\?.*/', '', $requestedURL); // Strip query string from ref
            $refUrl = is_string($refUrlResult) ? $refUrlResult : $requestedURL;
            $refParam = ABJ404_PP . '_ref';
            if (function_exists('remove_query_arg')) {
                $finalDestination = remove_query_arg($refParam, $finalDestination);
            }
            if (function_exists('add_query_arg')) {
                $finalDestination = add_query_arg($refParam, rawurlencode($refUrl), $finalDestination);
            } else {
                $separator = (strpos($finalDestination, '?') === false) ? '?' : '&';
                $finalDestination .= $separator . $refParam . '=' . rawurlencode($refUrl);
            }
        }

        // Sanitize for redirect header context (NOT HTML context).
        // Harden against CRLF header injection even when WP helpers are not available.
        $finalDestCleaned = preg_replace("/[\\r\\n]+/", '', (string)$finalDestination);
        $finalDestination = is_string($finalDestCleaned) ? $finalDestCleaned : (string)$finalDestination;

        if (function_exists('wp_sanitize_redirect')) {
            $finalDestination = wp_sanitize_redirect($finalDestination);
        } elseif (function_exists('esc_url_raw')) {
            $finalDestination = esc_url_raw($finalDestination);
        }

        return (string)$finalDestination;
    }

    /** Order pages and set the page depth for child pages.
     * Move the children to be underneath the parents.
     * @param array<int, object> $pages
     * @param bool $includeMissingParentPages
     * @return array<int, object>
     */
    function orderPageResults(array $pages, bool $includeMissingParentPages = false): array {

        // sort by type then title.
        usort($pages, function (object $a, object $b): int {
            return $this->sortByTypeThenTitle($a, $b);
        });
        // run this to see if there are any child pages left.
        $orderedPages = $this->setDepthAndAddChildren($pages);

        // The pages are now sorted. We now apply the depth AND we make sure the child pages
        // always immediately follow the parent pages.

        // -------------
        if ($includeMissingParentPages && (count($orderedPages) != count($pages))) {
            $iterations = 0;

            do {
                $idsOfMissingParentPages = $this->getMissingParentPageIDs($pages);
                $pageCountBefore = count($pages);
                $iterations = $iterations + 1;

                // get the parents of the unused pages.
                foreach ($idsOfMissingParentPages as $pageID) {
                    $postParent = get_post(is_scalar($pageID) ? (int)$pageID : 0);
                    if ($postParent == null) {
                        continue;
                    }
                    $parentPageSlug = $postParent->post_name;
                    $parentPage = $this->dao->getPublishedPagesAndPostsIDs($parentPageSlug);
                    if (count($parentPage) != 0) {
                        $pages[] = $parentPage[0];
                    }
                }

                if ($iterations > 30) {
                    break;
                }

                $idsOfMissingParentPages = $this->getMissingParentPageIDs($pages);

                // loop until we can't find any more parents. This may happen if a sub-page is published
                // and the parent page is not published.
            } while ($pageCountBefore != count($pages));

            // sort everything again
            usort($pages, function (object $a, object $b): int {
                return $this->sortByTypeThenTitle($a, $b);
            });
            $orderedPages = $this->setDepthAndAddChildren($pages);
        }

        // if there are child pages left over then there's an issue. it means there's a child page that was
        // returned but the parent for that child was not returned. so we don't have any place to display
        // the child page. this could be because the parent page is not "published"
        if (count($orderedPages) != count($pages)) {
            $unusedPages = array_udiff($pages, $orderedPages, function (object $a, object $b): int {
                return $this->compareByID($a, $b);
            });
            $this->logger->debugMessage("There was an issue finding the parent pages for some child pages. " .
                    "These pages' parents may not have a 'published' status. Pages: " .
                    wp_kses_post(json_encode($unusedPages) ?: ''));
        }

        return $orderedPages;
    }

    /** For custom categories we create a Map<String, List> where the key is the name
     * of the taxonomy and the list holds the rows that have the category info.
     * @param array<int, object{taxonomy: string, name?: string}> $categoryRows
     * @return array<string, array<int, object{taxonomy: string, name?: string}>>
     */
    function getMapOfCustomCategories(array $categoryRows): array {
        $customTagsEtc = array();

        foreach ($categoryRows as $cat) {
            $taxonomy = $cat->taxonomy;
            if ($taxonomy == 'category') {
                continue;
            }
            // for custom categories we create a Map<String, List> where the key is the name
            // of the taxonomy and the list holds the rows that have the category info.
            if (!array_key_exists($taxonomy, $customTagsEtc) || $customTagsEtc[$taxonomy] == null) {
                $customTagsEtc[$taxonomy] = array($cat);
            } else {
                array_push($customTagsEtc[$taxonomy], $cat);
            }

        }
        return $customTagsEtc;
    }

    /** Returns a list of parent IDs that can't be found in the passed in pages.
     * @param array<int, object> $pages
     * @return array<int, mixed>
     */
    function getMissingParentPageIDs(array $pages): array {
        $listOfIDs = array();
        $missingParentPageIDs = array();

        foreach ($pages as $page) {
            /** @var PageObject $page */
            $listOfIDs[] = $page->id;
        }

        foreach ($pages as $page) {
            /** @var PageObject $page */
            if ($page->post_parent == 0) {
                continue;
            }
            if (in_array($page->post_parent, $listOfIDs)) {
                continue;
            }

            $missingParentPageIDs[] = $page->post_parent;
        }

        $missingParentPageIDs = array_merge(
        	array_unique($missingParentPageIDs, SORT_REGULAR), array());
        return $missingParentPageIDs;
    }

    /**
     * Compare pages based on their ID.
     * @param object $a
     * @param object $b
     * @return int
     */
    function compareByID(object $a, object $b): int {
        /** @var PageObject $a */
        /** @var PageObject $b */
        if ($a->id < $b->id) {
            return -1;
        }
        if ($b->id < $a->id) {
            return 1;
        }
        return 0;
    }

    /** Set the depth of each page and add pages under their parents by rebuilding the list
     * every time we iterate through it and adding the child pages at the right moment every time
     * the list is built.
     * @param array<int, object> $pages
     * @return array<int, object>
     */
    function setDepthAndAddChildren(array $pages): array {
        // find all child pages (pages that have parents).
        $childPages = $this->findChildPages($pages);

        // find all pages with no parents.
        $mainPages = $this->findAllMainPages($pages);

        $oldChildPageCount = -1;

        // this do{} loop is here because some child pages have children.
        do {
            // add every page to a new list, while looking for parents.
            $orderedPages = array();
            foreach ($mainPages as $page) {
                /** @var PageObject $page */
                // always add the main page.
                $orderedPages[] = $page;

                // if this page is the parent of any children then add the children.
                $removeThese = array();
                foreach ($childPages as $child) {
                    /** @var PageObject $child */
                    if ($child->post_parent == $page->id) {
                        // set the page depth based on the parent's page depth.
                        $parentDepth = $page->depth;
                        /** @var \stdClass $childMut */
                        $childMut = $child;
                        $childMut->depth = $parentDepth + 1;

                        $removeThese[] = $child;
                        $orderedPages[] = $child;
                    }
                }

                // remove any child pages that have been placed already
                $childPages = $this->removeUsedChildPages($childPages, $removeThese);
            }

            // the new list becomes the list that we will iterate over next time.
            // this prepares us for the next iteration and for child pages with a depth greater than 1.
            // (for child pages that have children).
            $mainPages = $orderedPages;

            // if the count has not changed then there's no point in looping again.
            if (count($childPages) == $oldChildPageCount) {
                break;
            }
            $oldChildPageCount = count($childPages);
            // stop the loop once there are no more children to add.
        } while (count($childPages) > 0);

        return $orderedPages;
    }

    /**
     * @param array<int, object> $pages
     * @return array<int, object>
     */
    function findAllMainPages(array $pages): array {
        $mainPages = array();
        foreach ($pages as $page) {
            /** @var PageObject $page */
            // if there's no parent then just add the page.
            if ($page->post_parent == 0) {
                $mainPages[] = $page;
            }
        }

        return $mainPages;
    }

    /**
     * @param array<int, object> $childPages
     * @param array<int, object> $removeThese
     * @return array<int, object>
     */
    function removeUsedChildPages(array $childPages, array $removeThese): array {
        // if any children were added then remove them from the list.
        foreach ($removeThese as $removeThis) {
            $key = array_search($removeThis, $childPages);
            if ($key !== false) {
                unset($childPages[$key]);
            }
        }

        return $childPages;
    }

    /** Return pages that have a non-0 parent.
     * @param array<int, object> $pages
     * @return array<int, object>
     */
    function findChildPages(array $pages): array {
        $childPages = array();
        foreach ($pages as $page) {
            /** @var PageObject $page */
            if ($page->post_parent != 0) {
                $childPages[] = $page;
            }
        }
        return $childPages;
    }

    /**
     * @param object $a
     * @param object $b
     * @return int
     */
    function sortByTypeThenTitle(object $a, object $b): int {
        /** @var PageObject $a */
        /** @var PageObject $b */
        // first sort by type
        $result = strcmp($a->post_type, $b->post_type);
        if ($result != 0) {
            return $result;
        }

        // then by title.
        return strcmp($a->post_title, $b->post_title);
    }

    /** Send an email if a notification should be displayed. Return true if an email is sent, or false otherwise.
     * @return string
     */
    function emailCaptured404Notification() {

        $options = $this->getOptions(true);

        $captured404Count = $this->dao->getCapturedCountForNotification();
        if (!$this->shouldNotifyAboutCaptured404s($captured404Count)) {
            return "Not enough 404s found to send an admin notification email (" . $captured404Count . ").";
        }

        $captured404URLSettings = admin_url() . "options-general.php?page=" . ABJ404_PP . '&subpage=abj404_captured';
        $generalSettings = admin_url() . "options-general.php?page=" . ABJ404_PP . '&subpage=abj404_options';
        $to = is_string($options['admin_notification_email']) ? $options['admin_notification_email'] : '';
        $subject = '404 Solution: Captured 404 Notification';
        $body = "There are currently " . $captured404Count . " captured 404s to look at. <BR/><BR/>\n\n";
        $body .= 'Visit <a href="' . $captured404URLSettings . '">' . $captured404URLSettings .
                '</a> to see them.<BR/><BR/>' . "\n";
        $body .= 'To stop getting these emails, update the settings at <a href="' . $generalSettings . '">' .
                $generalSettings . '</a>, or contact the site administrator.' . "<BR/>\n";
        $body .= "<BR/><BR/>\n\nSent " . date('Y/m/d h:i:s T') . "<BR/>\n" . "PHP version: " . PHP_VERSION .
                ", <BR/>\nPlugin version: " . ABJ404_VERSION;
        $headers = array('Content-Type: text/html; charset=UTF-8');
        $adminEmail = get_option('admin_email');
        $adminEmailStr = is_string($adminEmail) ? $adminEmail : '';
        $headers[] = 'From: ' . $adminEmailStr . '<' . $adminEmailStr . '>';

        // send the email
        $this->logger->debugMessage("Sending captured 404 notification email to: " . $to);
        wp_mail($to, $subject, $body, $headers);
        $this->logger->debugMessage("Captured 404 notification email sent.");
        return "Captured 404 notification email sent to: " . trim($to);
    }

    /** Return true if a notification should be displayed, or false otherwise.
     * @global type $abj404dao
     * @param number $captured404Count the number of captured 404s
     * @return boolean
     */
    function shouldNotifyAboutCaptured404s($captured404Count) {
        $options = $this->getOptions(true);

        if (isset($options['admin_notification']) && $options['admin_notification'] != '0') {
            if ($captured404Count >= $options['admin_notification']) {
                return true;
            }
        }

        return false;
    }

    /** 0|0 => "(Default 404 Page)"
     * 5|5 => "(Home Page)"
     * 10|1 => "About"
     * @param string $idAndType
     * @param string $externalLinkURL
     * @return string
     */
    function getPageTitleFromIDAndType($idAndType, $externalLinkURL) {

        if ($idAndType == '') {
            return '';
        }

        $meta = explode("|", $idAndType);
        $id = $meta[0];
        // Handle malformed data that doesn't contain a pipe separator
        $type = isset($meta[1]) ? $meta[1] : '';

        // Use strict comparison to avoid null/false == 0 issues with type coercion
        // Cast to int for comparison since ABJ404_TYPE_* constants are integers
        $typeInt = is_numeric($type) ? (int)$type : -1;

        if ($idAndType == ABJ404_TYPE_404_DISPLAYED . '|' . ABJ404_TYPE_404_DISPLAYED) {
            return __('(Default 404 Page)', '404-solution');
        } else if ($idAndType == ABJ404_TYPE_HOME . '|' . ABJ404_TYPE_HOME) {
            return __('(Home Page)', '404-solution');
        } else if ($typeInt === ABJ404_TYPE_EXTERNAL) {
            return $externalLinkURL;
        }

        $idInt = (int)$id;
        if ($typeInt === ABJ404_TYPE_POST) {
            return get_the_title($idInt);

        } else if ($typeInt === ABJ404_TYPE_CAT) {
            $rows = $this->dao->getPublishedCategories($idInt);
            if (empty($rows)) {
                $this->logger->debugMessage('No TERM (category) found with ID: ' . $id);
                return '';
            }
            $firstRow = $rows[0];
            return property_exists($firstRow, 'name') ? (string)$firstRow->name : '';

        } else if ($typeInt === ABJ404_TYPE_TAG) {
            $tag = get_tag($idInt);
            if (is_object($tag) && property_exists($tag, 'name')) {
                return (string)$tag->name;
            }
            return '';
        }

        $this->logger->errorMessage("Couldn't get page title. No matching type found for type: " . esc_html($type));
        return '';
    }

}
