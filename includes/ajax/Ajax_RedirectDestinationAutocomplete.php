<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX adapter for redirect destination autocomplete.
 */
class ABJ_404_Solution_Ajax_RedirectDestinationAutocomplete {

    /** @return void */
    public static function echoRedirectToPages() {
        /** @var ABJ_404_Solution_PluginLogic $abj404logic */
        $abj404logic = ABJ_404_Solution_Ajax_ServiceResolver::required('plugin_logic');
        /** @var ABJ_404_Solution_ContentRepository $contentRepository */
        $contentRepository = ABJ_404_Solution_Ajax_ServiceResolver::required('content_repository');
        /** @var ABJ_404_Solution_Functions $f */
        $f = ABJ_404_Solution_Ajax_ServiceResolver::required('functions');
        $feedback = new ABJ_404_Solution_Ajax_SearchFeedback();

        $authResult = abj_service('ajax_security_gate')->authorizeAdminWithNonce('abj404_ajax');
        if (!$authResult['ok']) {
            self::sendJson(ABJ_404_Solution_Ajax_SearchFeedback::autocompleteErrorItem($authResult['message']), 200);
            return;
        }

        if (ABJ_404_Solution_Ajax_Php::consumeRateLimit('redirect_pages', 100, 60)) {
            self::sendJson(ABJ_404_Solution_Ajax_SearchFeedback::autocompleteErrorItem(__('Rate limit exceeded. Please try again later.', '404-solution')), 200);
            return;
        }

        $term = $f->strtolower(ABJ_404_Solution_RequestInputNormalizer::readText(
            $_GET, array('name' => 'term')));
        $term = substr($term, 0, 100);
        $includeDefault404PageRaw = array_key_exists('includeDefault404Page', $_GET) ? $_GET['includeDefault404Page'] : 'false';
        $includeDefault404Page = $includeDefault404PageRaw == "true";
        $includeSpecial = array_key_exists('includeSpecial', $_GET) &&
            $_GET['includeSpecial'] == "true";

        $specialPages = self::getDefaultRedirectDestinations($includeDefault404Page, $includeSpecial);

        $rowsOtherTypes = $contentRepository->getPublishedPagesAndPostsIDs(array(
            'search_term' => $term,
            'limit_results' => (string)ABJ404_MAX_AJAX_DROPDOWN_SIZE,
        ));
        $rowsOtherTypes = $abj404logic->pageOrdering()->orderPageResults($rowsOtherTypes, true);
        /** @var array<int, object{post_title: string, post_type: string, id: int|string, depth: int|string}> $rowsOtherTypesTyped */
        $rowsOtherTypesTyped = $rowsOtherTypes;
        $publishedPosts = self::formatRedirectDestinations($rowsOtherTypesTyped);

        /** @var array<int, object{taxonomy: string, name: string, term_id: int|string}> $cats */
        $cats = $contentRepository->getPublishedCategories(null, null, ABJ404_MAX_AJAX_DROPDOWN_SIZE);
        $categoryOptions = self::formatCategoryDestinations($cats);

        /** @var array<int, object{name: string, term_id: int|string}> $tags */
        $tags = $contentRepository->getPublishedTags(null, ABJ404_MAX_AJAX_DROPDOWN_SIZE);
        $tagOptions = self::formatTagDestinations($tags);

        /** @var array<int, object{taxonomy: string, name: string, term_id: int|string}> $catsForCustom */
        $catsForCustom = $cats;
        $customCategoriesMap = $abj404logic->pageOrdering()->getMapOfCustomCategories($catsForCustom);
        /** @var array<string, array<int, object{name: string, term_id: int|string}>> $customCategoriesMapTyped */
        $customCategoriesMapTyped = $customCategoriesMap;
        $customCategoryOptions = self::formatCustomCategoryDestinations($customCategoriesMapTyped);

        $specialPages = $feedback->filterPages($specialPages, $term);
        $publishedPosts = $feedback->filterPages($publishedPosts, $term);
        $categoryOptions = $feedback->filterPages($categoryOptions, $term);
        $tagOptions = $feedback->filterPages($tagOptions, $term);
        $customCategoryOptions = $feedback->filterPages($customCategoryOptions, $term);

        $suggestions = array_merge($specialPages, $publishedPosts, $categoryOptions, $tagOptions,
                $customCategoryOptions);
        $suggestions = $feedback->provideSearchFeedback($suggestions, $term);

        self::sendJson($suggestions, 200);
    }

    /**
     * @param bool $includeDefault404Page
     * @param bool $includeSpecial
     * @return array<int, array<string, string>>
     */
    public static function getDefaultRedirectDestinations($includeDefault404Page, $includeSpecial) {
        $arrayWrapper = array();
        $suggestion = array();

        if ($includeSpecial && $includeDefault404Page) {
            $suggestion['category'] = __('Special', '404-solution');
            $suggestion['label'] = __('(Default 404 Page)', '404-solution');
            $suggestion['value'] = ABJ404_TYPE_404_DISPLAYED . '|' . ABJ404_TYPE_404_DISPLAYED;
            $suggestion['depth'] = '0';
            $arrayWrapper[] = $suggestion;
        }

        if ($includeSpecial) {
            $suggestion['category'] = __('Special', '404-solution');
            $suggestion['label'] = __('Home Page', '404-solution');
            $suggestion['value'] = ABJ404_TYPE_HOME . '|' . ABJ404_TYPE_HOME;
            $suggestion['depth'] = '0';
            $arrayWrapper[] = $suggestion;
        }

        return $arrayWrapper;
    }

    /**
     * @param array<int, object{taxonomy: string, name: string, term_id: int|string}> $rows
     * @return array<int, array<string, string>>
     */
    public static function formatCategoryDestinations($rows) {
        $suggestions = array();

        foreach ($rows as $row) {
            if ($row->taxonomy != 'category') {
                continue;
            }

            $suggestion = array();
            $suggestion['label'] = $row->name;
            $suggestion['category'] = __('Categories', '404-solution');
            $suggestion['value'] = $row->term_id . "|" . ABJ404_TYPE_CAT;
            $suggestion['depth'] = '0';

            $suggestions[] = $suggestion;
        }

        return $suggestions;
    }

    /**
     * @param array<int, object{name: string, term_id: int|string}> $rows
     * @return array<int, array<string, string>>
     */
    public static function formatTagDestinations($rows) {
        $suggestions = array();

        foreach ($rows as $row) {
            $suggestion = array();
            $suggestion['label'] = $row->name;
            $suggestion['category'] = __('Tags', '404-solution');
            $suggestion['value'] = $row->term_id . "|" . ABJ404_TYPE_TAG;
            $suggestion['depth'] = '0';

            $suggestions[] = $suggestion;
        }

        return $suggestions;
    }

    /**
     * @param array<string, array<int, object{name: string, term_id: int|string}>> $customCategoriesMap
     * @return array<int, array<string, string>>
     */
    public static function formatCustomCategoryDestinations($customCategoriesMap) {
        $suggestions = array();

        foreach ($customCategoriesMap as $taxonomy => $rows) {
            foreach ($rows as $row) {
                $suggestion = array();
                $suggestion['label'] = $row->name;
                $suggestion['category'] = $taxonomy;
                $suggestion['value'] = $row->term_id . "|" . ABJ404_TYPE_CAT;
                $suggestion['depth'] = '0';

                $suggestions[] = $suggestion;
            }
        }

        return $suggestions;
    }

    /**
     * @param array<int, object{post_title: string, post_type: string, id: int|string, depth: int|string}> $rows
     * @return array<int, array<string, string>>
     */
    public static function formatRedirectDestinations($rows) {
        $suggestions = array();

        foreach ($rows as $row) {
            $suggestion = array();
            $suggestion['label'] = $row->post_title;
            $suggestion['category'] = ucwords($row->post_type);
            $suggestion['value'] = $row->id . "|" . ABJ404_TYPE_POST;
            $suggestion['depth'] = (string)$row->depth;

            $suggestions[] = $suggestion;
        }

        return $suggestions;
    }

    /**
     * @param mixed $payload
     * @param int $status
     * @return void
     */
    private static function sendJson($payload, $status = 200) {
        ABJ_404_Solution_Ajax_Php::sendJson($payload, $status);
    }
}
