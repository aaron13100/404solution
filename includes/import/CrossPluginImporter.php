<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cross-plugin redirect importer.
 *
 * Public entry point for the cross-plugin import feature. It detects installed
 * redirect plugins, previews available redirects, and imports them into the
 * 404 Solution redirects table. Reading and normalizing the foreign sources is
 * delegated to {@see ABJ_404_Solution_ForeignRedirectSourceReader} (data
 * access); this class owns only the business logic of turning a normalized row
 * into a 404 Solution redirect (status/type resolution, RedirectSpec creation).
 *
 * Supported sources:
 *   - Rank Math (rank_math_redirections)
 *   - Yoast SEO Premium (yoast_seo_redirects)
 *   - AIOSEO (aioseo_redirects)
 *   - Safe Redirect Manager (redirect_rule CPT)
 *   - Redirection plugin (redirection_items)
 */
class ABJ_404_Solution_CrossPluginImporter {

    /** @var ABJ_404_Solution_RedirectsRepositoryInterface */
    private $redirectsRepository;

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    /** @var ABJ_404_Solution_ForeignRedirectSourceReader */
    private $sourceReader;

    /**
     * @param ABJ_404_Solution_RedirectsRepositoryInterface $redirectsRepository
     * @param ABJ_404_Solution_Logging $logger
     * @param ABJ_404_Solution_DatabaseQueryInterface|null $dbQuery
     */
    public function __construct($redirectsRepository, $logger, $dbQuery = null) {
        $this->redirectsRepository = $redirectsRepository;
        $this->logger = $logger;
        $this->sourceReader = new ABJ_404_Solution_ForeignRedirectSourceReader(
            $redirectsRepository,
            $logger,
            $dbQuery
        );
    }

    /**
     * Detect which source plugins are installed by checking for their DB tables
     * (or, for Safe Redirect Manager, by checking for the CPT).
     *
     * @return array<string, bool> e.g. ['rankmath' => true, 'redirection' => false, ...]
     */
    public function detectInstalledPlugins(): array {
        return $this->sourceReader->detectInstalledPlugins();
    }

    /**
     * Return a preview of redirects available from the given source plugin.
     *
     * @param string $source       One of 'rankmath', 'yoast', 'aioseo', 'safe-redirect-manager', 'redirection'
     * @param int    $previewLimit Maximum rows to return for preview
     * @return array<int, array<string, mixed>>
     */
    public function getImportPreview(string $source, int $previewLimit = 10): array {
        $rows = $this->sourceReader->readSource($source);
        return array_slice($rows, 0, $previewLimit);
    }

    /**
     * Import all redirects from the given source plugin.
     * Returns the number of redirects successfully imported.
     *
     * @param string $source
     * @return int
     */
    public function importFrom(string $source): int {
        $rows = $this->sourceReader->readSource($source);

        if (empty($rows)) {
            return 0;
        }

        $imported = 0;
        foreach ($rows as $row) {
            $sourceUrl = isset($row['source_url']) && is_string($row['source_url']) ? $row['source_url'] : '';
            $destUrl   = isset($row['dest_url'])   && is_string($row['dest_url'])   ? $row['dest_url']   : '';
            $code      = isset($row['code'])        && is_numeric($row['code'])      ? (int)$row['code']  : 301;
            $isRegex   = isset($row['is_regex'])    && (bool)$row['is_regex'];

            if ($sourceUrl === '' || $destUrl === '') {
                continue;
            }

            $status = $isRegex ? ABJ404_STATUS_REGEX : ABJ404_STATUS_MANUAL;

            // Determine destination type and resolve internal paths to post IDs.
            $resolved = $this->resolveDestinationType($destUrl);
            $type = $resolved['type'];
            $destUrl = $resolved['dest'];

            $result = $this->redirectsRepository->setupRedirect(
                ABJ_404_Solution_RedirectSpec::create(
                    $sourceUrl,
                    (string)$status,
                    (string)$type,
                    $destUrl,
                    (string)$code,
                    0
                )
            );

            if ($result !== 0 && $result !== false) {
                $imported++;
            }
        }

        $this->logger->infoMessage(
            'CrossPluginImporter: imported ' . $imported . ' redirect(s) from "' . $source . '".'
        );

        return $imported;
    }

    /**
     * Resolve the redirect type and final destination for a given URL.
     *
     * External URLs (http/https) use ABJ404_TYPE_EXTERNAL with the URL as-is.
     * Internal paths are resolved via url_to_postid() — if a post ID is found,
     * ABJ404_TYPE_POST is used with the numeric ID. Otherwise ABJ404_TYPE_EXTERNAL
     * is used so the path is preserved and used as-is by the redirect pipeline.
     *
     * @param string $destUrl
     * @return array{type: int, dest: string}
     */
    private function resolveDestinationType(string $destUrl): array {
        if (preg_match('/^https?:\/\//i', $destUrl)) {
            return array('type' => ABJ404_TYPE_EXTERNAL, 'dest' => $destUrl);
        }

        if (function_exists('url_to_postid') && function_exists('home_url')) {
            $postId = url_to_postid(home_url($destUrl));
            if ($postId > 0) {
                return array('type' => ABJ404_TYPE_POST, 'dest' => (string)$postId);
            }
        }

        return array('type' => ABJ404_TYPE_EXTERNAL, 'dest' => $destUrl);
    }
}
