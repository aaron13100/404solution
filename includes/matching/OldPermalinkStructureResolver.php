<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves a 404 request against previously used WordPress permalink
 * structures without building a full old-URL map.
 *
 * Orchestrates three collaborators: ABJ_404_Solution_OldPermalinkCandidateStructureProvider
 * (which structures to try), ABJ_404_Solution_PermalinkStructureCompiler
 * (structure -> regex), and ABJ_404_Solution_OldPermalinkPostResolver (regex
 * match -> validated post).
 */
class ABJ_404_Solution_OldPermalinkStructureResolver {

    /** @var ABJ_404_Solution_PermalinkStructureCompiler */
    private $compiler;

    /** @var ABJ_404_Solution_OldPermalinkCandidateStructureProvider */
    private $candidateProvider;

    /** @var ABJ_404_Solution_OldPermalinkPostResolver */
    private $postResolver;

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
        $this->compiler = new ABJ_404_Solution_PermalinkStructureCompiler();
        $this->candidateProvider = new ABJ_404_Solution_OldPermalinkCandidateStructureProvider($structureStore, $this->compiler);
        $this->postResolver = new ABJ_404_Solution_OldPermalinkPostResolver($contentRepository, $logger);
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

        foreach ($this->candidateProvider->candidatesFor($path) as $candidate) {
            $compiled = $this->compiler->compile($candidate['structure']);
            if ($compiled === null) {
                $this->logger->debugMessage('Old permalink structure skipped: ' . $candidate['structure']);
                continue;
            }

            $matches = array();
            $matched = @preg_match($compiled['regex'], $path, $matches);
            if ($matched !== 1) {
                continue;
            }

            $postId = $this->postResolver->resolve($matches, $candidate, $request->getOptions());
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
}
