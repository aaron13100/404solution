<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles WordPress post save/delete listeners and permalink/N-gram cache
 * invalidation for ABJ_404_Solution_SpellChecker.
 */
trait SpellCheckerTrait_PostListeners {

	/**
	 * @param int $post_id
	 * @param \WP_Post|null $post
	 * @param bool|null $update
	 */
	function save_postListener($post_id, $post = null, $update = null): void {
		if ($post == null) {
			$post = get_post($post_id);
		}
		if ($update == null) {
			$update = true;
		}

		$this->savePostHandler($post_id, $post, $update, 'save');
    }

	/**
	 * @param int $post_id
	 * @param \WP_Post|null $post
	 */
    function delete_postListener($post_id, $post = null): void {
    	if ($post == null) {
    		$post = get_post($post_id);
    	}

        $this->savePostHandler($post_id, $post, true, 'delete');
    }

	/**
	 * @param int $post_id
	 * @param \WP_Post|mixed $post
	 * @param bool $update
	 * @param string $saveOrDelete
	 */
	function savePostHandler($post_id, $post, $update, $saveOrDelete): void {
		$options = $this->logic->getOptions();
		// Defensive: some callers/tests may pass null; WordPress normally provides a WP_Post.
		if (!is_object($post) || !isset($post->post_type) || !isset($post->post_status) || !isset($post->post_name)) {
			$this->logger->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
				": Invalid post object for ID: " . $post_id . " (skipped).");
			return;
		}
		$postType = $post->post_type;

		$recognizedPostTypesRaw = isset($options['recognized_post_types']) ? $options['recognized_post_types'] : '';
		$acceptedPostTypes = $this->f->explodeNewline(is_string($recognizedPostTypesRaw) ? $recognizedPostTypesRaw : '');

		// 3 options: save a new page, save an existing page (update), delete a page.
		$deleteSpellingCache = false;
		$deleteFromPermalinkCache = false;
		$invalidateNGramCache = false;
		$reason = '';

		// 2: save an existing page. if any of the following changed then delete
		// from the permalink cache: slug, type, status.
		// if any of the following changed then delete the entire spelling cache:
		// slug, type, status.
		/** @var array<string, mixed> $cacheRow */
		$cacheRow = $this->dao->getPermalinkEtcFromCache($post_id) ?: array();
		$cacheUrlRaw = (array_key_exists('url', $cacheRow)) ? $cacheRow['url'] : null;
		$oldSlug = (is_string($cacheUrlRaw)) ?
			rtrim(ltrim($cacheUrlRaw, '/'), '/') : '(not found)';
		$newSlug = $post->post_name;
		$matches = array();
		$metaRowRaw = array_key_exists('meta', $cacheRow) ? $cacheRow['meta'] : '';
		$metaRow = is_string($metaRowRaw) ? $metaRowRaw : '';
		preg_match('/s:(\\w+?),/', $metaRow, $matches);
		$oldStatus = count($matches) > 1 ? $matches[1] : '(not found)';
		preg_match('/t:(\\w+?),/', $metaRow, $matches);
		$oldPostType = count($matches) > 1 ? $matches[1] : '(not found)';
		if ($update && $saveOrDelete == 'save' &&
				($oldSlug != $newSlug ||
				$oldStatus != $post->post_status ||
				$oldPostType != $post->post_type)
			) {
			$deleteSpellingCache = true; // TODO only delete where the page is referenced.
			$deleteFromPermalinkCache = true;
			$invalidateNGramCache = true;
			$reason = 'change. slug (' . $oldSlug . '(to)' . $newSlug . '), status (' .
				$oldStatus . '(to)' . $post->post_status . '), type (' . $oldPostType .
				'(to)' . $post->post_type . ')';
		}

		// if the post type is uninteresting then ignore it.
		if (!in_array($oldPostType, $acceptedPostTypes) &&
			!in_array($post->post_type, $acceptedPostTypes)) {

			$httpUserAgent = "(none)";
			if (array_key_exists("HTTP_USER_AGENT", $_SERVER)) {
				$httpUserAgent = $_SERVER['HTTP_USER_AGENT'];
			}
			$this->logger->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
				": Ignored savePost change (uninteresting post types). " .
				"Action: " . $saveOrDelete . ", ID: " . $post_id . ", types: " .
				$oldPostType . "/" . $post->post_type . ", agent: " .
					$httpUserAgent);
			return;
		}

		// if the status is uninteresting then ignore it.
		$interestingStatuses = array('publish', 'published');
		if (!in_array($oldStatus, $interestingStatuses) &&
			!in_array($post->post_status, $interestingStatuses)) {

			$httpUserAgent = "(none)";
			if (array_key_exists("HTTP_USER_AGENT", $_SERVER)) {
				$httpUserAgent = $_SERVER['HTTP_USER_AGENT'];
			}
			$this->logger->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
				": Ignored savePost change (uninteresting post statuses). " .
				"Action: " . $saveOrDelete . ", ID: " . $post_id . ", statuses: " .
				$oldStatus . "/" . $post->post_status . ", agent: " .
				$httpUserAgent);
			return;
		}

		// save a new page. the cache is null. delete the spelling cache because
		// the new page may match searches better than the other previous matches.
		if (!$update && $saveOrDelete == 'save') {
			$deleteSpellingCache = true; // delete all.
			$deleteFromPermalinkCache = false; // it's not there anyway.
			$invalidateNGramCache = false; // it's not there anyway.
			$reason = 'new page';
		}

		// delete a page.
		if ($saveOrDelete == 'delete') {
			$deleteSpellingCache = true; // TODO only delete where the page is referenced.
			$deleteFromPermalinkCache = true;
			$invalidateNGramCache = true;
			$reason = 'deleted page';
		}

		if ($deleteFromPermalinkCache) {
			$this->logger->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
				": Delete from permalink cache: " . $post_id . ", action: " .
				$saveOrDelete . ", reason: " . $reason);

			try {
				$this->dao->removeFromPermalinkCache($post_id);
				// let's update some links.
				$this->permalinkCache->updatePermalinkCache(1);
			} catch (Exception $e) {
				$this->logger->errorMessage(__CLASS__ . "/" . __FUNCTION__ .
					": Exception while updating permalink cache for post ID " . $post_id .
					": " . $e->getMessage());
			}
		}

		if ($invalidateNGramCache) {
			$this->logger->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
				": Invalidate N-gram cache entry: " . $post_id . ", action: " .
				$saveOrDelete . ", reason: " . $reason);

			try {
				$result = $this->ngramFilter->invalidatePage($post_id);
				if (!$result) {
					$this->logger->errorMessage(__CLASS__ . "/" . __FUNCTION__ .
						": Failed to invalidate N-gram cache for post ID " . $post_id .
						". The cache may be out of sync until next daily maintenance.");
				}
			} catch (Exception $e) {
				$this->logger->errorMessage(__CLASS__ . "/" . __FUNCTION__ .
					": Exception while invalidating N-gram cache for post ID " . $post_id .
					": " . $e->getMessage());
			}
		}

		if ($deleteSpellingCache) {
			// TODO only delete the items from the cache that refer
			// to the post ID that was deleted?
			try {
				$this->dao->deleteSpellingCache();
			} catch (Exception $e) {
				$this->logger->errorMessage(__CLASS__ . "/" . __FUNCTION__ .
					": Exception while deleting spelling cache: " . $e->getMessage());
			}

			if ($this->logger->isDebug()) {
				$httpUserAgent = "(none)";
				if (array_key_exists("HTTP_USER_AGENT", $_SERVER)) {
					$httpUserAgent = $_SERVER['HTTP_USER_AGENT'];
				}

				$this->logger->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
					": Spelling cache deleted (post change). Action: " . $saveOrDelete .
					", ID: " . $post_id . ", type: " . $postType . ", reason: " .
					$reason . ", agent: " . $httpUserAgent);
			}
		}

		// Update N-gram cache for single post (incremental update for performance)
		// Use incremental update API to avoid rebuilding entire cache on every post save
		if ($saveOrDelete == 'save' && in_array($post->post_status, array('publish', 'published'))) {
			try {
				// Ensure permalink cache is updated first (for new posts)
				// This is lightweight and idempotent, so safe to call even if already updated
				$this->permalinkCache->updatePermalinkCache(1);

				// Only update N-grams for this specific post (incremental)
				$stats = $this->ngramFilter->updateNGramsForPages(array($post_id));

				if ($stats['success'] > 0) {
					$this->logger->debugMessage(__CLASS__ . "/" . __FUNCTION__ .
						": Incrementally updated N-grams for post ID: " . $post_id .
						" (processed: {$stats['processed']}, success: {$stats['success']}, failed: {$stats['failed']})");
				} else if ($stats['failed'] > 0) {
					$this->logger->errorMessage(__CLASS__ . "/" . __FUNCTION__ .
						": Failed to update N-grams for post ID: " . $post_id .
						" (stats: " . json_encode($stats) . ")");
				}
			} catch (Exception $e) {
				$this->logger->errorMessage(__CLASS__ . "/" . __FUNCTION__ .
					": Exception while updating N-grams for post ID " . $post_id .
					": " . $e->getMessage());
			}
		}
	}

	/**
	 * @param string $var1
	 * @param mixed $newStructure
	 */
	function permalinkStructureChanged($var1, $newStructure): void {
		if ($var1 != 'permalink_structure') {
			return;
		}

		$structure = empty($newStructure) ? '(empty)' : $newStructure;
		$this->dao->deleteSpellingCache();
		$this->logger->debugMessage(__CLASS__ . "/" . __FUNCTION__ . ": Spelling cache deleted because the permalink structure changed " . "to " . $structure);
	}

	function initializePublishedPostsProvider(): void {
		if ($this->publishedPostsProvider == null) {
			$this->publishedPostsProvider = ABJ_404_Solution_PublishedPostsProvider::getInstance();
		}
		$this->permalinkCache->updatePermalinkCache(1);
	}

}
