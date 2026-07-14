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
     * Consumes {@see ABJ_404_Solution_ForeignRedirectSourceReader::readSource()}
     * as a generator and stops as soon as $previewLimit rows are collected,
     * rather than materializing the full source (M502, 2026-07-14) --
     * readSource() only fetches one IMPORT_PAGE_SIZE page of rows per query,
     * so a small $previewLimit (the AJAX preview UI's default) typically
     * needs only a single page fetch regardless of the source's true size.
     *
     * @param string $source       One of 'rankmath', 'yoast', 'aioseo', 'safe-redirect-manager', 'redirection'
     * @param int    $previewLimit Maximum rows to return for preview
     * @return array<int, array<string, mixed>>
     */
    public function getImportPreview(string $source, int $previewLimit = 10): array {
        if ($previewLimit <= 0) {
            return array();
        }

        $preview = array();
        foreach ($this->sourceReader->readSource($source) as $row) {
            $preview[] = $row;
            if (count($preview) >= $previewLimit) {
                break;
            }
        }
        return $preview;
    }

    /**
     * Count redirects available from the given source plugin without
     * materializing the full row set. Used by the AJAX preview handler,
     * which only needs a number to display, not actual rows -- unlike
     * getImportPreview() above, this never reads more than the source
     * plugin's own storage needs to answer "how many" (see
     * {@see ABJ_404_Solution_ForeignRedirectSourceReader::countSource()}).
     *
     * @param string $source One of 'rankmath', 'yoast', 'aioseo',
     *                       'safe-redirect-manager', 'redirection'
     * @return int
     */
    public function countImportable(string $source): int {
        return $this->sourceReader->countSource($source);
    }

    /**
     * Import all redirects from the given source plugin.
     * Returns the number of redirects successfully imported.
     *
     * Consumes {@see ABJ_404_Solution_ForeignRedirectSourceReader::readSource()}
     * row-by-row as a generator rather than requiring a fully materialized
     * array upfront (M502, 2026-07-14): each row is written via
     * setupRedirect() as soon as it is read, so at most one source page is
     * ever held in memory regardless of how many rows the source plugin has.
     *
     * @param string $source
     * @return int
     */
    public function importFrom(string $source): int {
        $imported = 0;
        foreach ($this->sourceReader->readSource($source) as $row) {
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
