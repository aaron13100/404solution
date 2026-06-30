<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves a 404 request against previously used WordPress permalink
 * structures without building a full old-URL map.
 */
class ABJ_404_Solution_OldPermalinkStructureResolver {

    private const MAX_EVALUATED_STRUCTURES = 10;

    /** @var ABJ_404_Solution_OldPermalinkStructureStore */
    private $structureStore;

    /** @var ABJ_404_Solution_ContentRepositoryInterface */
    private $contentRepository;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /**
     * @param ABJ_404_Solution_OldPermalinkStructureStore $structureStore
     * @param ABJ_404_Solution_ContentRepositoryInterface $contentRepository
     * @param ABJ_404_Solution_Logging $logger
     */
    public function __construct(
        ABJ_404_Solution_OldPermalinkStructureStore $structureStore,
        ABJ_404_Solution_ContentRepositoryInterface $contentRepository,
        ABJ_404_Solution_Logging $logger
    ) {
        $this->structureStore = $structureStore;
        $this->contentRepository = $contentRepository;
        $this->logger = $logger;
    }

    /**
     * @param ABJ_404_Solution_MatchRequest $request
     * @return array{id: string, type: string, link: string, title: string, score: float}|null
     */
    public function resolve(ABJ_404_Solution_MatchRequest $request): ?array {
        $path = $this->normalizeRequestPath($request->getRequestedURL());
        if ($path === '') {
            return null;
        }

        foreach ($this->candidateStructures($path) as $candidate) {
            $compiled = $this->compileStructure($candidate['structure']);
            if ($compiled === null) {
                $this->logger->debugMessage('Old permalink structure skipped: ' . $candidate['structure']);
                continue;
            }

            $matches = array();
            $matched = @preg_match($compiled['regex'], $path, $matches);
            if ($matched !== 1) {
                continue;
            }

            $captures = $this->namedCaptures($matches);
            $postId = isset($captures['post_id'])
                ? $this->resolveByPostId($captures, $candidate, $request->getOptions())
                : $this->resolveBySlug($captures, $candidate, $request->getOptions());

            if ($postId === null) {
                continue;
            }

            $link = function_exists('get_permalink') ? get_permalink($postId) : '';
            if (!is_string($link) || $link === '') {
                return null;
            }

            if ($this->normalizeRequestPath($link) === $path) {
                $this->logger->debugMessage('Old permalink structure matched canonical URL; skipping self redirect.');
                return null;
            }

            return array(
                'id' => (string)$postId,
                'type' => (string)ABJ404_TYPE_POST,
                'link' => $link,
                'title' => function_exists('get_the_title') ? (string)get_the_title($postId) : '',
                'score' => 100.0,
            );
        }

        return null;
    }

    /**
     * Exposed for focused tests; runtime callers should use resolve().
     *
     * @param string $structure
     * @return array{regex: string, tokens: array<int, string>}|null
     */
    public function compileStructure(string $structure): ?array {
        $structure = $this->normalizeStructure($structure);
        if ($structure === '') {
            return null;
        }

        preg_match_all('/%[a-z_]+%/', $structure, $tokenMatches);
        $tokens = $tokenMatches[0];
        if (!in_array('%post_id%', $tokens, true)
                && !in_array('%postname%', $tokens, true)
                && !in_array('%pagename%', $tokens, true)) {
            return null;
        }

        $seenNames = array();
        foreach ($tokens as $token) {
            $name = trim($token, '%');
            if (!isset($this->tokenRegexMap()[$token]) || isset($seenNames[$name])) {
                return null;
            }
            $seenNames[$name] = true;
        }

        $segments = explode('/', trim($structure, '/'));
        $compiledSegments = array();
        foreach ($segments as $segment) {
            preg_match_all('/%[a-z_]+%/', $segment, $segmentTokens);
            if (count($segmentTokens[0]) > 1) {
                return null;
            }
            $compiledSegments[] = $this->compileSegment($segment);
        }

        return array(
            'regex' => '~^/' . implode('/', $compiledSegments) . '/?$~',
            'tokens' => $tokens,
        );
    }

    /**
     * @param string $requestedURL
     * @return string
     */
    private function normalizeRequestPath(string $requestedURL): string {
        $path = parse_url($requestedURL, PHP_URL_PATH);
        if (!is_string($path)) {
            $path = $requestedURL;
        }
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : $path . '/';
    }

    /**
     * @param string $structure
     * @return string
     */
    private function normalizeStructure(string $structure): string {
        $path = parse_url(trim($structure), PHP_URL_PATH);
        $structure = is_string($path) && $path !== '' ? $path : trim($structure);
        if ($structure === '') {
            return '';
        }
        return $structure[0] === '/' ? $structure : '/' . $structure;
    }

    /**
     * @param string $path
     * @return array<int, array{structure: string, post_types: array<int, string>}>
     */
    private function candidateStructures(string $path): array {
        $raw = array();
        foreach ($this->structureStore->getObservedStructures() as $item) {
            $raw[] = array('structure' => $item['structure'], 'post_types' => array());
        }
        foreach ($this->defaultWellKnownStructures() as $structure) {
            $raw[] = array('structure' => $structure, 'post_types' => array());
        }

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('abj404_old_permalink_candidate_structures', $raw, $path);
            if (is_array($filtered)) {
                $raw = $filtered;
            }
        }

        $out = array();
        $seen = array();
        foreach ($raw as $entry) {
            $candidate = $this->normalizeCandidateEntry($entry);
            if ($candidate === null) {
                continue;
            }
            $key = $candidate['structure'] . '|' . implode(',', $candidate['post_types']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $candidate;
            if (count($out) >= self::MAX_EVALUATED_STRUCTURES) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    private function defaultWellKnownStructures(): array {
        return array(
            '/%year%/%monthnum%/%day%/%postname%/',
            '/%year%/%monthnum%/%postname%/',
            '/%postname%/%year%/',
            '/%postname%/%year%/%monthnum%/',
            '/archives/%post_id%/',
        );
    }

    /**
     * @param mixed $entry
     * @return array{structure: string, post_types: array<int, string>}|null
     */
    private function normalizeCandidateEntry($entry): ?array {
        if (is_string($entry)) {
            return array('structure' => $this->normalizeStructure($entry), 'post_types' => array());
        }
        if (!is_array($entry) || !isset($entry['structure']) || !is_scalar($entry['structure'])) {
            return null;
        }

        $postTypes = array();
        if (isset($entry['post_types']) && is_array($entry['post_types'])) {
            foreach ($entry['post_types'] as $postType) {
                if (is_scalar($postType)) {
                    $postType = sanitize_key((string)$postType);
                    if ($postType !== '') {
                        $postTypes[] = $postType;
                    }
                }
            }
        }

        return array(
            'structure' => $this->normalizeStructure((string)$entry['structure']),
            'post_types' => array_values(array_unique($postTypes)),
        );
    }

    /**
     * @return array<string, string>
     */
    private function tokenRegexMap(): array {
        return array(
            '%year%' => '(?P<year>[0-9]{4})',
            '%monthnum%' => '(?P<monthnum>0[1-9]|1[0-2])',
            '%day%' => '(?P<day>0[1-9]|[12][0-9]|3[01])',
            '%hour%' => '(?P<hour>[01][0-9]|2[0-3])',
            '%minute%' => '(?P<minute>[0-5][0-9])',
            '%second%' => '(?P<second>[0-5][0-9])',
            '%post_id%' => '(?P<post_id>[0-9]+)',
            '%postname%' => '(?P<postname>[^/]+)',
            '%pagename%' => '(?P<pagename>[^/]+)',
            '%category%' => '(?P<category>[^/]+(?:/[^/]+)*)',
            '%author%' => '(?P<author>[^/]+)',
        );
    }

    /** @param string $segment @return string */
    private function compileSegment(string $segment): string {
        $map = $this->tokenRegexMap();
        preg_match('/%[a-z_]+%/', $segment, $match, PREG_OFFSET_CAPTURE);
        if (empty($match)) {
            return preg_quote($segment, '~');
        }

        $token = $match[0][0];
        $offset = (int)$match[0][1];
        $before = substr($segment, 0, $offset);
        $after = substr($segment, $offset + strlen($token));
        return preg_quote($before, '~') . $map[$token] . preg_quote($after, '~');
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

        $rows = $this->contentRepository->getPublishedPagesAndPostsIDs($slug, '', '11');
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

        $postName = isset($post->post_name) ? (string)$post->post_name : '';
        $slugCapture = $captures['postname'] ?? ($captures['pagename'] ?? null);
        if ($slugCapture !== null && $postName !== '' && $postName !== $slugCapture) {
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

        if (!empty($candidatePostTypes)) {
            $types = array_values(array_intersect($types, $candidatePostTypes));
        }

        return empty($types) ? array('page', 'post', 'product') : $types;
    }

    /**
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
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return false;
        }
        if (isset($captures['year']) && date('Y', $timestamp) !== $captures['year']) {
            return false;
        }
        if (isset($captures['monthnum']) && date('m', $timestamp) !== $captures['monthnum']) {
            return false;
        }
        if (isset($captures['day']) && date('d', $timestamp) !== $captures['day']) {
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
            return true;
        }
        $segments = array_values(array_filter(explode('/', $categoryPath)));
        if (empty($segments)) {
            return false;
        }
        return (bool)has_category(end($segments), $postId);
    }
}
