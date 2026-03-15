<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Value object representing a 404 request to be matched.
 *
 * Bundles the data that matching engines need: the full requested URL,
 * the slug-only portion, and the plugin options array.
 */
class ABJ_404_Solution_MatchRequest {

    /** @var string */
    private $requestedURL;

    /** @var string */
    private $urlSlugOnly;

    /** @var array<string, mixed> */
    private $options;

    /**
     * @param string $requestedURL Full requested URL path (e.g., '/blog/old-pge')
     * @param string $urlSlugOnly  Slug portion only (e.g., 'old-pge')
     * @param array<string, mixed> $options Plugin options array
     */
    public function __construct(string $requestedURL, string $urlSlugOnly, array $options) {
        $this->requestedURL = $requestedURL;
        $this->urlSlugOnly = $urlSlugOnly;
        $this->options = $options;
    }

    /** @return string */
    public function getRequestedURL(): string {
        return $this->requestedURL;
    }

    /** @return string */
    public function getUrlSlugOnly(): string {
        return $this->urlSlugOnly;
    }

    /** @return array<string, mixed> */
    public function getOptions(): array {
        return $this->options;
    }

    /**
     * Get the minimum score threshold for a specific engine, falling back to the global auto_score.
     *
     * @param string $engineKey Engine-specific option key (e.g. 'auto_score_title')
     * @return float
     */
    public function getMinScore(string $engineKey): float {
        $opts = $this->options;
        if ($engineKey !== '' && isset($opts[$engineKey]) && $opts[$engineKey] !== '' && is_numeric($opts[$engineKey])) {
            return (float)$opts[$engineKey];
        }
        return isset($opts['auto_score']) && is_numeric($opts['auto_score'])
            ? (float)$opts['auto_score'] : 0.0;
    }
}
