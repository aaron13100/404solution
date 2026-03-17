<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fallback matching engine that redirects to post type archive pages.
 *
 * When all other engines fail, if the URL path starts with a known post type slug
 * that has an archive, redirect to that post type's archive page.
 * E.g., /products/nonexistent-item → /products/
 */
class ABJ_404_Solution_ArchiveFallbackEngine implements ABJ_404_Solution_MatchingEngine {

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_Functions $f
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct(
        ABJ_404_Solution_Functions $f,
        ABJ_404_Solution_Logging $logger
    ) {
        $this->f = $f;
        $this->logger = $logger;
    }

    /** @return string */
    public function getName(): string {
        return __('archive fallback', '404-solution');
    }

    /** @param ABJ_404_Solution_MatchRequest $request */
    public function shouldRun(ABJ_404_Solution_MatchRequest $request): bool {
        $slug = $request->getUrlSlugOnly();

        if ($slug === '') {
            return false;
        }

        $options = $request->getOptions();
        $autoRedirects = isset($options['auto_redirects']) ? $options['auto_redirects'] : '0';

        if ($autoRedirects !== '1') {
            return false;
        }

        return true;
    }

    /** @param ABJ_404_Solution_MatchRequest $request */
    public function match(ABJ_404_Solution_MatchRequest $request): ?ABJ_404_Solution_MatchResult {
        $slug = $request->getUrlSlugOnly();

        // Get the first path segment
        $segments = array_values(array_filter(explode('/', $slug), function ($s) {
            return $s !== '';
        }));

        if (empty($segments)) {
            return null;
        }

        $firstSegment = $this->f->strtolower($segments[0]);

        // Get post types with archives
        /** @var array<string, object> $postTypes */
        $postTypes = get_post_types(['has_archive' => true], 'objects');

        if (empty($postTypes)) {
            $this->logger->debugMessage("Archive fallback engine: no post types with archives");
            return null;
        }

        foreach ($postTypes as $postTypeName => $postTypeObj) {
            $hasArchive = isset($postTypeObj->has_archive) ? $postTypeObj->has_archive : false;

            // has_archive can be true (use post type name as slug) or a string (custom archive slug)
            $archiveSlug = '';
            if (is_string($hasArchive) && $hasArchive !== '') {
                $archiveSlug = $this->f->strtolower($hasArchive);
            } elseif ($hasArchive === true) {
                $archiveSlug = $this->f->strtolower((string)$postTypeName);
            }

            if ($archiveSlug === '') {
                continue;
            }

            if ($firstSegment !== $archiveSlug && $firstSegment !== $this->f->strtolower((string)$postTypeName)) {
                continue;
            }

            // Found a matching post type — get its archive URL
            $archiveUrl = get_post_type_archive_link((string)$postTypeName);

            if (!is_string($archiveUrl) || $archiveUrl === '') {
                continue;
            }

            $label = isset($postTypeObj->label) && is_string($postTypeObj->label)
                ? $postTypeObj->label : (string)$postTypeName;

            $this->logger->debugMessage("Archive fallback engine: matched post type '" .
                $postTypeName . "' archive for segment '" . $firstSegment . "'");

            return new ABJ_404_Solution_MatchResult(
                '0',
                (string)ABJ404_TYPE_POST,
                $archiveUrl,
                $label,
                100.0,
                $this->getName()
            );
        }

        $this->logger->debugMessage("Archive fallback engine: no matching post type for segment '" .
            $firstSegment . "'");

        return null;
    }
}
