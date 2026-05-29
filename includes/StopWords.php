<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Canonical list of English stop words shared between the content-matching
 * engine (Core) and the permalink-cache keyword extractor (Data). Lives in the
 * Shared layer so both layers can reference it without crossing layer
 * boundaries.
 */
final class ABJ_404_Solution_StopWords {

    /** @var array<int, string> */
    public static $common = [
        'the', 'and', 'for', 'with', 'this', 'that', 'from', 'your', 'have',
        'will', 'been', 'they', 'their', 'what', 'when', 'where', 'which',
        'there', 'about', 'would', 'could', 'should', 'into', 'than',
        'then', 'them', 'these', 'those', 'does', 'done', 'also', 'just',
        'more', 'most', 'much', 'very', 'some', 'only', 'over', 'such',
        'each', 'were', 'here', 'after', 'before', 'between', 'under',
        'through', 'being', 'other', 'like', 'not', 'but', 'are', 'was',
        'all', 'any', 'can', 'had', 'her', 'his', 'how', 'its', 'may',
        'our', 'own', 'who', 'you',
    ];
}
