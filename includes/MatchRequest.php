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
}
