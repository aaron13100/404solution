<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Given a regex match against an old permalink structure, resolves and
 * validates a currently-published WordPress post: extracts named captures,
 * looks up the post by ID or by slug, and checks it against the structural
 * constraints implied by the captures (post type, date, author, category,
 * slug).
 *
 * Extracted from ABJ_404_Solution_OldPermalinkStructureResolver because
 * resolving a regex match to a validated post is a distinct concern from
 * compiling a structure into a regex or gathering candidate structures.
 */
class ABJ_404_Solution_OldPermalinkPostResolver {

    /** @var ABJ_404_Solution_ContentRepositoryInterface */
    private $contentRepository;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_ContentRepositoryInterface $contentRepository
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct(
        ABJ_404_Solution_ContentRepositoryInterface $contentRepository,
        ABJ_404_Solution_Logging $logger
    ) {
        $this->contentRepository = $contentRepository;
        $this->logger = $logger;
    }

    /**
     * @param array<int|string, mixed> $matches Raw preg_match() result.
     * @param array{structure: string, post_types: array<int, string>} $candidate
     * @param array<string, mixed> $options
     * @return int|null
     */
    public function resolve(array $matches, array $candidate, array $options): ?int {
        $captures = $this->namedCaptures($matches);

        return isset($captures['post_id'])
            ? $this->resolveByPostId($captures, $candidate, $options)
            : $this->resolveBySlug($captures, $candidate, $options);
    }

    /**
     * @param array<int|string, mixed> $matches
     * @return array<string, string>
     */
    private function namedCaptures(array $matches): array {
        $captures = array();
        foreach ($matches as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $captures[$key] = (string)$value;
            }
        }
        return $captures;
    }

    /**
     * @param array<string, string> $captures
     * @param array{structure: string, post_types: array<int, string>} $candidate
     * @param array<string, mixed> $options
     * @return int|null
     */
    private function resolveByPostId(array $captures, array $candidate, array $options): ?int {
        $postId = absint($captures['post_id'] ?? 0);
        if ($postId <= 0) {
            return null;
        }

        $post = $this->loadPost($postId);
        if ($post === null || !$this->postMatchesConstraints($post, $captures, $candidate, $options)) {
            return null;
        }

        $this->logger->debugMessage('Old permalink structure resolved by post ID: ' . $postId);
        return $postId;
    }

    /**
     * @param array<string, string> $captures
     * @param array{structure: string, post_types: array<int, string>} $candidate
     * @param array<string, mixed> $options
     * @return int|null
     */
    private function resolveBySlug(array $captures, array $candidate, array $options): ?int {
        $slug = $captures['postname'] ?? ($captures['pagename'] ?? '');
        if ($slug === '') {
            return null;
        }

        $rows = $this->contentRepository->getPublishedPagesAndPostsIDs(array(
            'slug' => $slug,
            'limit_results' => '11',
        ));
        $matches = array();
        foreach ($rows as $row) {
            $postId = $this->idFromRow($row);
            if ($postId <= 0) {
                continue;
            }
            $post = $this->loadPost($postId, $row);
            if ($post !== null && $this->postMatchesConstraints($post, $captures, $candidate, $options)) {
                $matches[] = $postId;
            }
        }

        $matches = array_values(array_unique($matches));
        if (count($matches) !== 1) {
            if (count($matches) > 1) {
                $this->logger->debugMessage('Old permalink structure ambiguous for slug: ' . $slug);
            }
            return null;
        }

        $this->logger->debugMessage('Old permalink structure resolved by slug: ' . $slug);
        return (int)$matches[0];
    }

    /**
     * @param mixed $row
     * @return int
     */
    private function idFromRow($row): int {
        if (is_object($row) && isset($row->id) && is_scalar($row->id)) {
            return absint($row->id);
        }
        if (is_object($row) && isset($row->ID) && is_scalar($row->ID)) {
            return absint($row->ID);
        }
        if (is_array($row) && isset($row['id']) && is_scalar($row['id'])) {
            return absint($row['id']);
        }
        if (is_array($row) && isset($row['ID']) && is_scalar($row['ID'])) {
            return absint($row['ID']);
        }
        return 0;
    }

    /**
     * @param int $postId
     * @param mixed|null $fallback
     * @return object|null
     */
    private function loadPost(int $postId, $fallback = null): ?object {
        if (function_exists('get_post')) {
            $post = get_post($postId);
            if (is_object($post)) {
                return $post;
            }
        }
        return is_object($fallback) ? $fallback : null;
    }

    /**
     * @param object $post
     * @param array<string, string> $captures
     * @param array{structure: string, post_types: array<int, string>} $candidate
     * @param array<string, mixed> $options
     * @return bool
     */
    private function postMatchesConstraints(object $post, array $captures, array $candidate, array $options): bool {
        $postId = isset($post->ID) ? absint($post->ID) : (isset($post->id) ? absint($post->id) : 0);
        $status = isset($post->post_status) ? (string)$post->post_status
            : (function_exists('get_post_status') ? (string)get_post_status($postId) : '');
        if (!in_array($status, array('publish', 'published'), true)) {
            return false;
        }

        $postType = isset($post->post_type) ? sanitize_key((string)$post->post_type) : '';
        $allowedTypes = $this->recognizedPostTypes($options, $candidate['post_types']);
        if ($postType === '' || !in_array($postType, $allowedTypes, true)) {
            return false;
        }

        // Positive-evidence check: when the captured old permalink specifies
        // a slug segment, the candidate post must actually carry a matching
        // post_name, not merely "no post_name to contradict it". The DB
        // query in getPublishedPagesAndPostsIDs() usually pre-filters by
        // slug, but for a UTF8MB4 slug it drops the SQL-level slug clause
        // entirely (PublishedContentRepository::buildPostSlugClause()) and
        // returns every published post/page of the recognized types -- this
        // check is the only remaining filter in that path. An empty
        // post_name must not be treated as "no evidence against a match";
        // it must be treated as "no evidence for one".
        $postName = isset($post->post_name) ? (string)$post->post_name : '';
        $slugCapture = $captures['postname'] ?? ($captures['pagename'] ?? null);
        if ($slugCapture !== null && $postName !== $slugCapture) {
            return false;
        }

        if (!$this->dateMatches($post, $captures)) {
            return false;
        }

        if (isset($captures['author']) && !$this->authorMatches($post, $captures['author'])) {
            return false;
        }

        if (isset($captures['category']) && !$this->categoryMatches($postId, $captures['category'])) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<int, string> $candidatePostTypes
     * @return array<int, string>
     */
    private function recognizedPostTypes(array $options, array $candidatePostTypes): array {
        $raw = isset($options['recognized_post_types']) && is_scalar($options['recognized_post_types'])
            ? (string)$options['recognized_post_types']
            : "page\npost\nproduct";
        $types = preg_split('/[\s,]+/', $raw) ?: array();
        $types = array_values(array_filter(array_map(static function($type): string {
            return sanitize_key((string)$type);
        }, $types)));

        if (empty($types)) {
            $types = array('page', 'post', 'product');
        }

        if (!empty($candidatePostTypes)) {
            $types = array_values(array_intersect($types, $candidatePostTypes));
        }

        return $types;
    }

    /**
     * $post->post_date is a WP-stored value expressed in the site's
     * configured timezone (Settings > General), the same convention WP
     * core uses to generate the %year%/%monthnum%/%day% permalink tags
     * these captures came from. Parsing and formatting it both anchor to
     * the WP site timezone (SiteTimezone) rather than PHP's implicit
     * default timezone, matching the convention established by
     * RedirectScheduleTimezone -- raw strtotime()/date() would parse and
     * re-format using PHP's default timezone instead, which is fragile:
     * it only stays a no-op because parsing and formatting happen to use
     * the same implicit zone today, a coupling a future refactor could
     * easily break.
     *
     * @param object $post
     * @param array<string, string> $captures
     * @return bool
     */
    private function dateMatches(object $post, array $captures): bool {
        if (!isset($captures['year']) && !isset($captures['monthnum']) && !isset($captures['day'])) {
            return true;
        }
        $date = isset($post->post_date) ? (string)$post->post_date : '';
        if ($date === '') {
            return false;
        }
        try {
            $postDateTime = new DateTimeImmutable($date, ABJ_404_Solution_SiteTimezone::resolve());
        } catch (Exception $e) {
            $this->logger->warn('OldPermalinkPostResolver: unparseable post_date "' . $date .
                '" on post ID ' . (isset($post->ID) ? (string)$post->ID : 'unknown') . ': ' . $e->getMessage());
            return false;
        }
        if (isset($captures['year']) && $postDateTime->format('Y') !== $captures['year']) {
            return false;
        }
        if (isset($captures['monthnum']) && $postDateTime->format('m') !== $captures['monthnum']) {
            return false;
        }
        if (isset($captures['day']) && $postDateTime->format('d') !== $captures['day']) {
            return false;
        }
        return true;
    }

    /** @param object $post @param string $authorSlug @return bool */
    private function authorMatches(object $post, string $authorSlug): bool {
        if (!function_exists('get_userdata') || !isset($post->post_author)) {
            return false;
        }
        $user = get_userdata(absint($post->post_author));
        return is_object($user) && isset($user->user_nicename) && (string)$user->user_nicename === $authorSlug;
    }

    /** @param int $postId @param string $categoryPath @return bool */
    private function categoryMatches(int $postId, string $categoryPath): bool {
        if (!function_exists('has_category')) {
            return false;
        }
        $segments = array_values(array_filter(explode('/', $categoryPath)));
        if (empty($segments)) {
            return false;
        }
        return (bool)has_category(end($segments), $postId);
    }
}
