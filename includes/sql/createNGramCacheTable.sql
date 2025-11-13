
CREATE TABLE IF NOT EXISTS `{wp_abj404_ngram_cache}` (
    `id` bigint(20) NOT NULL COMMENT 'Post/page/category/tag ID',
    `type` varchar(20) NOT NULL DEFAULT 'post' COMMENT 'Entity type: post, page, category, tag',
    `url` varchar(2048) NOT NULL COMMENT 'Original URL',
    `url_normalized` varchar(2048) NOT NULL COMMENT 'Normalized URL for matching',
    `ngrams` text NOT NULL COMMENT 'JSON: {"bi":["he","el"...], "tri":["hel","ell"...]}',
    `ngram_count` smallint(6) NOT NULL DEFAULT 0 COMMENT 'Total n-grams (for quick filtering)',
    `last_updated` datetime NOT NULL COMMENT 'Last time N-grams were computed',
    PRIMARY KEY (`id`, `type`),
    KEY `idx_url_normalized` (`url_normalized`(255)),
    KEY `idx_ngram_count` (`ngram_count`),
    KEY `idx_type` (`type`)
) COMMENT='404 Solution Plugin N-Gram Cache Table for Spell Checker Optimization'

