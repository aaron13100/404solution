<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Page ordering, hierarchy helpers, redirect destination building, and notification helpers.
 * Standalone class extracted from PluginLogicTrait_PageOrdering.
 *
 * @phpstan-type PageObject object{id: int, post_parent: int, depth: int, post_type: string, post_title: string}
 */
class ABJ_404_Solution_PluginLogicPageOrdering {

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_ContentRepositoryInterface */
    private $contentRepo;

    /** @var ABJ_404_Solution_StatsRepositoryInterface */
    private $statsRepo;

    /** @var ABJ_404_Solution_PluginLogicUrlNormalization */
    private $urlNormalization;

    /** @var ABJ_404_Solution_PluginLogic */
    private $pluginLogic;

    /**
     * @param ABJ_404_Solution_Functions $f
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_ContentRepositoryInterface $contentRepo
     * @param ABJ_404_Solution_StatsRepositoryInterface $statsRepo
     * @param ABJ_404_Solution_PluginLogicUrlNormalization $urlNormalization
     * @param ABJ_404_Solution_PluginLogic $pluginLogic
     */
    function __construct($f, $logger, $contentRepo, $statsRepo, $urlNormalization, $pluginLogic) {
        $this->f = $f;
        $this->logger = $logger;
        $this->contentRepo = $contentRepo;
        $this->statsRepo = $statsRepo;
        $this->urlNormalization = $urlNormalization;
        $this->pluginLogic = $pluginLogic;
    }

    /**
     * Build the final redirect destination URL.
     *
     * @param string $location Base redirect destination.
     * @param string $requestedURL Original requested URL (used for custom 404 ref tracking).
     * @param bool $isCustom404 Whether we are redirecting to a custom 404 page.
     * @return string Redirect destination suitable for wp_redirect().
     */
    public function buildFinalRedirectDestination($location, $requestedURL = '', $isCustom404 = false) {
        $location = $this->urlNormalization->maybeTranslateRedirectUrl($location, $requestedURL);

        $commentPartAndQueryPart = (string)$this->pluginLogic->getCommentPartAndQueryPartOfRequest();
        $finalDestination = (string)$location . $commentPartAndQueryPart;

        if ($isCustom404 && is_string($requestedURL) && $requestedURL !== '') {
            $refUrlResult = preg_replace('/\?.*/', '', $requestedURL);
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
     * @param array<int, object> $pages
     * @param bool $includeMissingParentPages
     * @return array<int, object>
     */
    function orderPageResults(array $pages, bool $includeMissingParentPages = false): array {

        usort($pages, function (object $a, object $b): int {
            return $this->sortByTypeThenTitle($a, $b);
        });
        $orderedPages = $this->setDepthAndAddChildren($pages);

        if ($includeMissingParentPages && (count($orderedPages) != count($pages))) {
            $iterations = 0;

            do {
                $idsOfMissingParentPages = $this->getMissingParentPageIDs($pages);
                $pageCountBefore = count($pages);
                $iterations = $iterations + 1;

                foreach ($idsOfMissingParentPages as $pageID) {
                    $postParent = get_post(is_scalar($pageID) ? (int)$pageID : 0);
                    if ($postParent == null) {
                        continue;
                    }
                    $parentPageSlug = $postParent->post_name;
                    $parentPage = $this->contentRepo->getPublishedPagesAndPostsIDs($parentPageSlug);
                    if (count($parentPage) != 0) {
                        $pages[] = $parentPage[0];
                    }
                }

                if ($iterations > 30) {
                    break;
                }

                $idsOfMissingParentPages = $this->getMissingParentPageIDs($pages);

            } while ($pageCountBefore != count($pages));

            usort($pages, function (object $a, object $b): int {
                return $this->sortByTypeThenTitle($a, $b);
            });
            $orderedPages = $this->setDepthAndAddChildren($pages);
        }

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

    /** Set the depth of each page and add pages under their parents.
     * @param array<int, object> $pages
     * @return array<int, object>
     */
    function setDepthAndAddChildren(array $pages): array {
        $childPages = $this->findChildPages($pages);
        $mainPages = $this->findAllMainPages($pages);

        $oldChildPageCount = -1;

        do {
            $orderedPages = array();
            foreach ($mainPages as $page) {
                /** @var PageObject $page */
                $orderedPages[] = $page;

                $removeThese = array();
                foreach ($childPages as $child) {
                    /** @var PageObject $child */
                    if ($child->post_parent == $page->id) {
                        $parentDepth = $page->depth;
                        /** @var \stdClass $childMut */
                        $childMut = $child;
                        $childMut->depth = $parentDepth + 1;

                        $removeThese[] = $child;
                        $orderedPages[] = $child;
                    }
                }

                $childPages = $this->removeUsedChildPages($childPages, $removeThese);
            }

            $mainPages = $orderedPages;

            if (count($childPages) == $oldChildPageCount) {
                break;
            }
            $oldChildPageCount = count($childPages);
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
        $result = strcmp($a->post_type, $b->post_type);
        if ($result != 0) {
            return $result;
        }

        return strcmp($a->post_title, $b->post_title);
    }

    /** Send an email if a notification should be displayed.
     * @return string
     */
    function emailCaptured404Notification() {

        $options = $this->pluginLogic->getOptions(true);

        $frequency = isset($options['admin_notification_frequency']) && is_string($options['admin_notification_frequency'])
            ? $options['admin_notification_frequency']
            : 'instant';

        if ($frequency !== 'instant') {
            $emailDigest = new ABJ_404_Solution_EmailDigest(abj_service('logs_repository'), abj_service('stats_repository'), $this->logger);
            return $emailDigest->sendDigest();
        }

        $captured404Count = $this->statsRepo->getCapturedCountForNotification();
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

        $this->logger->debugMessage("Sending captured 404 notification email to: " . $to);
        wp_mail($to, $subject, $body, $headers);
        $this->logger->debugMessage("Captured 404 notification email sent.");
        return "Captured 404 notification email sent to: " . trim($to);
    }

    /** Return true if a notification should be displayed.
     * @param number $captured404Count the number of captured 404s
     * @return boolean
     */
    function shouldNotifyAboutCaptured404s($captured404Count) {
        $options = $this->pluginLogic->getOptions(true);

        if (isset($options['admin_notification']) && $options['admin_notification'] != '0') {
            if ($captured404Count >= $options['admin_notification']) {
                return true;
            }
        }

        return false;
    }

    /** 0|0 => "(Default 404 Page)"
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
        $type = isset($meta[1]) ? $meta[1] : '';

        $typeInt = is_numeric($type) ? (int)$type : -1;

        if ($idAndType == ABJ404_TYPE_404_DISPLAYED . '|' . ABJ404_TYPE_404_DISPLAYED) {
            return __('(Default 404 Page)', '404-solution');
        } else if ($idAndType == ABJ404_TYPE_HOME . '|' . ABJ404_TYPE_HOME) {
            return __('(Home Page)', '404-solution');
        } else if ($typeInt === ABJ404_TYPE_EXTERNAL) {
            return $externalLinkURL;
        } else if ($typeInt === ABJ404_TYPE_HOME) {
            return __('(Home Page)', '404-solution');
        }

        $idInt = (int)$id;
        if ($typeInt === ABJ404_TYPE_POST) {
            return get_the_title($idInt);

        } else if ($typeInt === ABJ404_TYPE_CAT) {
            $rows = $this->contentRepo->getPublishedCategories($idInt);
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
