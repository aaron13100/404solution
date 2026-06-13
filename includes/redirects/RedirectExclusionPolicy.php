<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decides whether a match result should be excluded from redirect suggestions.
 *
 * Three signals, in order:
 *   1. Per-post meta `_abj404_exclude = '1'`     (post results)
 *   2. Per-term meta `_abj404_exclude = '1'`     (category / tag results)
 *   3. Legacy `excludePages[]` option (id|type tuple list, all types).
 *
 * External and Home redirect types are never excluded (the user-facing
 * destination is by definition off-site or the WP home, and the exclusion
 * mechanism is post/term scoped).
 *
 * Stateless. Constructor takes nothing.
 */
class ABJ_404_Solution_RedirectExclusionPolicy {

    /**
     * @param ABJ_404_Solution_MatchResult $result
     * @param array<string, mixed> $options
     * @return bool
     */
    function isExcluded(ABJ_404_Solution_MatchResult $result, array $options): bool {
        $type = $result->getType();
        $id = $result->getId();

        $typeInt = is_numeric($type) ? (int)$type : 0;
        $typePost = (int)ABJ404_TYPE_POST;
        $typeCat = (int)ABJ404_TYPE_CAT;
        $typeTag = (int)ABJ404_TYPE_TAG;
        $typeExternal = (int)ABJ404_TYPE_EXTERNAL;
        $typeHome = (int)ABJ404_TYPE_HOME;

        if ($typeInt === $typeExternal || $typeInt === $typeHome) {
            return false;
        }

        if ($id === '' || !is_numeric($id)) {
            return false;
        }

        $idInt = (int)$id;

        $meta = $this->resolveExcludeMeta($typeInt, $idInt, $typePost, $typeCat, $typeTag);
        if ($meta === '1') {
            return true;
        }

        $excludePagesRaw = isset($options['excludePages[]']) ? $options['excludePages[]'] : '';
        $excludePagesJson = is_string($excludePagesRaw) ? $excludePagesRaw : '';
        if (trim($excludePagesJson) !== '') {
            $excludePages = json_decode($excludePagesJson);
            if (!is_array($excludePages)) {
                $excludePages = array($excludePages);
            }
            $key = $id . '|' . $type;
            foreach ($excludePages as $entry) {
                if (!is_string($entry) && !is_scalar($entry)) {
                    continue;
                }
                if ((string)$entry === $key) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Look up the `_abj404_exclude` meta value for a post or term, returning
     * the raw meta string (`'1'` when excluded) or null when no meta lookup
     * applies.
     *
     * @param int $typeInt
     * @param int $idInt
     * @param int $typePost
     * @param int $typeCat
     * @param int $typeTag
     * @return mixed
     */
    private function resolveExcludeMeta(int $typeInt, int $idInt, int $typePost, int $typeCat, int $typeTag) {
        if ($typeInt === $typePost && function_exists('get_post_meta')) {
            return get_post_meta($idInt, '_abj404_exclude', true);
        }
        if (($typeInt === $typeCat || $typeInt === $typeTag) && function_exists('get_term_meta')) {
            return get_term_meta($idInt, '_abj404_exclude', true);
        }
        return null;
    }
}
