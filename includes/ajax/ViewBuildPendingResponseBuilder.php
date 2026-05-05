<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Helpers for translating an `ABJ_404_Solution_ViewBuildPendingException`
 * caught inside an AJAX handler into the same `viewBuildPending` JSON
 * shape the cheap pre-check gate produces.
 *
 * Race recovery rationale: `getPaginationLinks()` and `warmTableCache()`
 * normally call `viewDoneIsServeable()` first and short-circuit when
 * view_done is missing.  But an `invalidateViewDone()` (redirect
 * create/edit/delete) can land between the gate and the read; the staged
 * DAO will then throw the pending sentinel rather than running an inline
 * build.  Without this translation the exception bubbles up into the
 * generic `catch (Throwable)` and produces an HTTP 500 / "critical error"
 * page that the JS client cannot recover from.  With it, the JS poller
 * picks up the build via `ajaxAdvanceViewBuild` exactly as if the gate
 * had triggered.
 *
 * Extracted from `ABJ_404_Solution_ViewUpdater` to keep that file under
 * the project line-count cap.
 */
class ABJ_404_Solution_ViewBuildPendingResponseBuilder {

    /**
     * Walk the throwable chain looking for a pending sentinel up to depth 5.
     *
     * @param Throwable $throwable
     * @return ABJ_404_Solution_ViewBuildPendingException|null
     */
    public static function find(Throwable $throwable) {
        if (!class_exists('ABJ_404_Solution_ViewBuildPendingException')) {
            return null;
        }
        $current = $throwable;
        $depth = 0;
        while ($current !== null && $depth < 5) {
            if ($current instanceof ABJ_404_Solution_ViewBuildPendingException) {
                return $current;
            }
            $current = $current->getPrevious();
            $depth++;
        }
        return null;
    }

    /**
     * Read the build progress safely, falling back to a "stage 0/11, not
     * yet started" shape when the DAO call throws or is unavailable.
     *
     * @param object $abj404dao
     * @param ABJ_404_Solution_ViewBuildPendingException|null $pending
     * @return array<string, mixed>
     */
    public static function progress($abj404dao, $pending = null) {
        $progress = null;
        if (is_object($abj404dao) && method_exists($abj404dao, 'getViewBuildProgress')) {
            try {
                $progress = $abj404dao->getViewBuildProgress();
            } catch (Throwable $ignored) {
                $progress = null;
            }
        }
        if (!is_array($progress)) {
            $progressText = ($pending instanceof ABJ_404_Solution_ViewBuildPendingException)
                ? $pending->getProgressText() : 'not yet started';
            $progress = array(
                'status' => 'pending',
                'stage' => 0,
                'of' => 11,
                'build_started' => 0,
                'progress_text' => $progressText !== '' ? $progressText : 'not yet started',
            );
        }
        return $progress;
    }

    /**
     * Build the response shape `getPaginationLinks()` returns when the
     * fetch path is blocked on a pending build.
     *
     * @param object $abj404dao
     * @param string $subpage
     * @param string $cacheMode
     * @param ABJ_404_Solution_ViewBuildPendingException|null $pending
     * @return array<string, mixed>
     */
    public static function fetchResponse($abj404dao, $subpage, $cacheMode, $pending = null) {
        return array(
            'viewBuildPending' => true,
            'cacheMode' => $cacheMode,
            'subpage' => $subpage,
            'progress' => self::progress($abj404dao, $pending),
            'message' => function_exists('__')
                ? __('Preparing the redirects view table. Please wait.', '404-solution')
                : 'Preparing the redirects view table. Please wait.',
        );
    }

    /**
     * Build the response shape `warmTableCache()` returns when the snapshot
     * warm path is blocked on a pending build.  Different from the fetch
     * shape because the JS placeholder hydration consumes `ready=false`
     * and the stage/stageNumber fields directly.
     *
     * @param object $abj404dao
     * @param ABJ_404_Solution_ViewBuildPendingException|null $pending
     * @return array<string, mixed>
     */
    public static function warmResponse($abj404dao, $pending = null) {
        return array(
            'status' => 'pending',
            'ready' => false,
            'viewBuildPending' => true,
            'stage' => 'rows',
            'stageNumber' => 1,
            'queryLabel' => 'getRedirectsForView',
            'progress' => self::progress($abj404dao, $pending),
        );
    }
}
