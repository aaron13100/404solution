<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves request URLs to active or existing redirect rows.
 */
class ABJ_404_Solution_RedirectLookupService {

    /** @var ABJ_404_Solution_Functions */
    private $f;

    /** @var ABJ_404_Solution_RedirectLookupRepository */
    private $lookupRepository;

    /** @var ABJ_404_Solution_PluginLogicUrlNormalization|null */
    private $urlNormalization;

    /**
     * @param ABJ_404_Solution_Functions $functions
     * @param ABJ_404_Solution_RedirectLookupRepository $lookupRepository
     * @param ABJ_404_Solution_PluginLogicUrlNormalization|null $urlNormalization
     */
    public function __construct(
        $functions,
        ABJ_404_Solution_RedirectLookupRepository $lookupRepository,
        ?ABJ_404_Solution_PluginLogicUrlNormalization $urlNormalization = null
    ) {
        $this->f = $functions;
        $this->lookupRepository = $lookupRepository;
        $this->urlNormalization = $urlNormalization;
    }

    /**
     * @param string $url
     * @param bool $degradedMode
     * @return array<string, mixed>
     */
    public function getActiveRedirectForURL($url, $degradedMode = false): array {
        return $this->findFirstCandidate($url, function($candidate) use ($degradedMode) {
            return $this->lookupRepository->getActiveRedirectForNormalizedUrl($candidate, $degradedMode);
        });
    }

    /**
     * @param string $url
     * @return array<string, mixed>
     */
    public function getExistingRedirectForURL($url): array {
        return $this->findFirstCandidate($url, function($candidate) {
            return $this->lookupRepository->getExistingRedirectForNormalizedUrl($candidate);
        });
    }

    /**
     * @param string $url
     * @param callable $lookup
     * @return array<string, mixed>
     */
    private function findFirstCandidate($url, callable $lookup): array {
        $url = $this->f->sanitizeInvalidUTF8($url);

        if (function_exists('mb_check_encoding') && !mb_check_encoding($url, 'UTF-8')) {
            return array('id' => 0);
        }

        $candidates = $this->urlNormalization()->getNormalizedUrlCandidates($url);
        foreach ($candidates as $candidate) {
            $redirect = $lookup($candidate);
            if (!is_array($redirect)) {
                continue;
            }
            $assocRedirect = $this->assocRedirectRow($redirect);
            if (isset($assocRedirect['id']) && $assocRedirect['id'] !== 0) {
                return $assocRedirect;
            }
        }

        return array('id' => 0);
    }

    /**
     * @param array<mixed, mixed> $redirect
     * @return array<string, mixed>
     */
    private function assocRedirectRow(array $redirect): array {
        $assoc = array();
        foreach ($redirect as $key => $value) {
            if (is_string($key)) {
                $assoc[$key] = $value;
            }
        }
        return $assoc;
    }

    /**
     * @return ABJ_404_Solution_PluginLogicUrlNormalization
     */
    private function urlNormalization() {
        if ($this->urlNormalization !== null) {
            return $this->urlNormalization;
        }
        return abj_service('plugin_logic')->urlNormalization();
    }
}
