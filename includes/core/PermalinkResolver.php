<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves stored "ID|TYPE" permalink references into live arrays of
 * {id, type, score, link, title, status} by querying the WordPress entity
 * (post, term, attachment, etc.) the reference points at.
 *
 * Extracted from ABJ_404_Solution_Functions per design-audit-2026-06-02
 * M201 (Functions.php grab-bag split). This is a translation/lookup
 * concern, not a string/url utility.
 */
class ABJ_404_Solution_PermalinkResolver {

    /** Turns ID|TYPE, SCORE into an array with id, type, score, link, and title.
     *
     * @param string $idAndType e.g. 15|POST is a page ID of 15 and a type POST.
     * @param int|float $linkScore
     * @param string $rowType if this is "image" then wp_get_attachment_image_src() is used.
     * @param array<string, mixed>|null $options in case an external URL is used.
     * @return array<string, mixed> an array with id, type, score, link, and title.
     */
    static function permalinkInfoToArray($idAndType, $linkScore, $rowType = null, $options = null) {
        if ($idAndType == null) {
            return array('score' => -999);
        }

        $meta = explode("|", $idAndType);
        $permalink = array(
            'id'     => $meta[0],
            // Handle malformed data that doesn't contain a pipe separator
            'type'   => isset($meta[1]) ? $meta[1] : '',
            'score'  => $linkScore,
            'status' => 'unknown',
            'link'   => 'dunno',
        );

        /** @var int $idInt */
        $idInt = (int)$permalink['id'];
        // Use strict comparison to avoid null/false == 0 issues with type coercion
        // Cast to int for comparison since ABJ404_TYPE_* constants are integers
        $typeInt = is_numeric($permalink['type']) ? (int)$permalink['type'] : -1;

        self::resolveByType($permalink, $typeInt, $idInt, $rowType, $options);

        if ($permalink['status'] === false) {
            $permalink['status'] = 'trash';
        }

        // Decode anything that might be encoded to support utf8 characters
        $sanitizer = abj_service('sanitizer');
        $linkVal = is_string($permalink['link']) ? $permalink['link']
            : (is_scalar($permalink['link']) ? (string)$permalink['link'] : '');
        $permalink['link'] = $sanitizer->normalizeUrlString($linkVal);
        $titleVal = (array_key_exists('title', $permalink) && is_string($permalink['title']))
            ? $permalink['title'] : '';
        $permalink['title'] = $sanitizer->normalizeUrlString($titleVal);

        return $permalink;
    }

    /**
     * Fill link/title/status on $permalink for the recognized $typeInt.
     *
     * @param array<string, mixed> $permalink (by-ref) mutated with link/title/status
     * @param int $typeInt one of the ABJ404_TYPE_* integer constants, or -1 for unrecognized
     * @param int $idInt
     * @param string|null $rowType
     * @param array<string, mixed>|null $options
     * @return void
     */
    private static function resolveByType(array &$permalink, $typeInt, $idInt, $rowType, $options) {
        if ($typeInt === ABJ404_TYPE_POST) {
            self::fillPost($permalink, $idInt, $rowType);
        } else if ($typeInt === ABJ404_TYPE_TAG) {
            self::fillTag($permalink, $idInt);
        } else if ($typeInt === ABJ404_TYPE_CAT) {
            self::fillCategory($permalink, $idInt);
        } else if ($typeInt === ABJ404_TYPE_HOME) {
            $permalink['link'] = get_home_url();
            $permalink['title'] = get_bloginfo('name');
            $permalink['status'] = 'published';
        } else if ($typeInt === ABJ404_TYPE_EXTERNAL) {
            self::fillExternal($permalink, $options);
        } else if ($typeInt === ABJ404_TYPE_404_DISPLAYED) {
            $permalink['link'] = '404';
            $permalink['status'] = 'published';
        } else {
            // A corrupt/unrecognized redirect row (e.g. empty or garbage type
            // from a damaged DB record) is a tolerable data condition: the
            // resolver simply can't resolve it, so the row is left in its
            // unresolvable 'unknown'/'dunno' state for the caller to skip.
            // Per Defensive Coding Philosophy #8, bad-data the plugin can
            // function past is logged at WARNING level -- not errorMessage(),
            // which fires a production telemetry report / admin error notice.
            $logger = abj_service('logging');
            $logger->warn("Unrecognized permalink type: " .
                wp_kses_post((string)json_encode($permalink)));
        }
    }

    /**
     * @param array<string, mixed> $permalink (by-ref)
     * @param int $idInt
     * @param string|null $rowType
     * @return void
     */
    private static function fillPost(array &$permalink, $idInt, $rowType) {
        if ($rowType == 'image') {
            $imageURL = wp_get_attachment_image_src($idInt, "attached-image");
            $permalink['link'] = is_array($imageURL) ? $imageURL[0] : '';
        } else {
            $permalink['link'] = get_permalink($idInt);
        }
        $permalink['title'] = get_the_title($idInt);
        $permalink['status'] = get_post_status($idInt);
    }

    /**
     * @param array<string, mixed> $permalink (by-ref)
     * @param int $idInt
     * @return void
     */
    private static function fillTag(array &$permalink, $idInt) {
        $permalink['link'] = get_tag_link($idInt);
        $tag = get_term($idInt);
        if (is_object($tag) && !is_wp_error($tag)) {
            $permalink['title'] = $tag->name;
        } else {
            $permalink['title'] = $permalink['link'];
        }
        $permalink['status'] = ($permalink['title'] == null || $permalink['title'] == '') ? 'trash' : 'published';
    }

    /**
     * Uses get_term_link() instead of get_category_link() to support
     * custom taxonomies like WooCommerce product_cat.
     *
     * @param array<string, mixed> $permalink (by-ref)
     * @param int $idInt
     * @return void
     */
    private static function fillCategory(array &$permalink, $idInt) {
        $catTerm = get_term($idInt);
        if (is_object($catTerm) && !is_wp_error($catTerm)) {
            $termLink = get_term_link($catTerm);
            $permalink['link'] = is_wp_error($termLink) ? get_category_link($idInt) : $termLink;
            $permalink['title'] = $catTerm->name;
        } else {
            $permalink['link'] = get_category_link($idInt);
            $permalink['title'] = $permalink['link'];
        }
        $permalink['status'] = ($permalink['title'] == null || $permalink['title'] == '') ? 'trash' : 'published';
    }

    /**
     * @param array<string, mixed> $permalink (by-ref)
     * @param array<string, mixed>|null $options
     * @return void
     */
    private static function fillExternal(array &$permalink, $options) {
        $permalink['link'] = $permalink['id'];
        if ($permalink['link'] == ABJ404_TYPE_EXTERNAL) {
            if ($options == null) {
                $options = abj_service('options_repository')->getOptions();
            }
            $urlDestination = (array_key_exists('dest404pageURL', $options) &&
                isset($options['dest404pageURL']) ? $options['dest404pageURL'] :
                'External URL not found in options ABJ404 Solution Error');
            $permalink['link'] = $urlDestination;
        }
        $permalink['status'] = 'published';
    }
}
