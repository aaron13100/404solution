<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX handler for Engine Profile CRUD operations.
 *
 * Actions:
 *   abj404_engine_profiles_list   – Return all profiles as JSON.
 *   abj404_engine_profiles_save   – Insert or update a profile.
 *   abj404_engine_profiles_delete – Delete a profile by ID.
 */
class ABJ_404_Solution_Ajax_EngineProfiles {
    use ABJ_404_Solution_AjaxSecurityTrait;

    /** @return void */
    public static function registerActions(): void {
        ABJ_404_Solution_WPUtils::safeAddAction(
            'wp_ajax_abj404_engine_profiles_list',
            'ABJ_404_Solution_Ajax_EngineProfiles::handleList'
        );
        ABJ_404_Solution_WPUtils::safeAddAction(
            'wp_ajax_abj404_engine_profiles_save',
            'ABJ_404_Solution_Ajax_EngineProfiles::handleSave'
        );
        ABJ_404_Solution_WPUtils::safeAddAction(
            'wp_ajax_abj404_engine_profiles_delete',
            'ABJ_404_Solution_Ajax_EngineProfiles::handleDelete'
        );
    }

    /**
     * Return all profiles as a JSON array.
     *
     * @return void
     */
    public static function handleList(): void {
        self::requireAdminWithNonce('abj404_engine_profiles_nonce');

        $profiles = ABJ_404_Solution_EngineProfileResolver::getInstance()->getAllProfilesForAdmin();
        wp_send_json_success(['profiles' => $profiles]);
    }

    /**
     * Insert or update a profile.
     *
     * Expected POST fields: name, url_pattern, is_regex, enabled_engines (JSON),
     * priority, status, and optionally id (for update).
     *
     * @return void
     */
    public static function handleSave(): void {
        self::requireAdminWithNonce('abj404_engine_profiles_nonce');

        // Boundary normalizer: $_POST shape probing lives in the VO, not here.
        // See ABJ_404_Solution_EngineProfileSaveRequest.
        $req = ABJ_404_Solution_EngineProfileSaveRequest::fromPost($_POST);

        if (trim($req->getName()) === '') {
            wp_send_json_error(['message' => __('Profile name is required.', '404-solution')]);
            return; // @phpstan-ignore deadCode.unreachable
        }

        if (trim($req->getUrlPattern()) === '') {
            wp_send_json_error(['message' => __('URL pattern is required.', '404-solution')]);
            return; // @phpstan-ignore deadCode.unreachable
        }

        // Validate regex pattern before saving.
        // Patterns are stored WITHOUT PHP delimiters (users write ^/shop/ not #^/shop/#).
        // The resolver wraps with # delimiters at match-time when the first char is not
        // a common delimiter; we mirror that same logic here so validation matches
        // what will actually be executed.
        if ($req->isRegex()) {
            $testPattern      = $req->getUrlPattern();
            $commonDelimiters = ['/', '#', '~', '!', '@', '|', '%'];
            if (!in_array(substr($testPattern, 0, 1), $commonDelimiters, true)) {
                $testPattern = '#' . $testPattern . '#';
            }
            set_error_handler(function (int $errno, string $errstr, string $errfile = '', int $errline = 0): bool { return false; }, E_WARNING);
            $testResult = @preg_match($testPattern, '');
            restore_error_handler();
            if ($testResult === false) {
                wp_send_json_error(['message' => __('Invalid regular expression pattern.', '404-solution')]);
                return; // @phpstan-ignore deadCode.unreachable
            }
        }

        $resultId = ABJ_404_Solution_EngineProfileResolver::getInstance()->saveProfile($req->toResolverPayload());

        if ($resultId === false) {
            $logger = abj_service('logging');
            if ($logger !== null) {
                $logger->warn('Ajax_EngineProfiles::handleSave: saveProfile() returned false. id=' . $req->getId() .
                    ', name=' . $req->getName() . ', is_regex=' . $req->getIsRegexInt() .
                    '. Returning HTTP 200 with success=false to AJAX caller.');
            }
            wp_send_json_error(['message' => __('Failed to save engine profile.', '404-solution')]);
            return; // @phpstan-ignore deadCode.unreachable
        }

        wp_send_json_success(['id' => $resultId]);
    }

    /**
     * Delete a profile by ID.
     *
     * @return void
     */
    public static function handleDelete(): void {
        self::requireAdminWithNonce('abj404_engine_profiles_nonce');

        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;

        if ($id <= 0) {
            wp_send_json_error(['message' => __('Invalid profile ID.', '404-solution')]);
            return; // @phpstan-ignore deadCode.unreachable
        }

        $ok = ABJ_404_Solution_EngineProfileResolver::getInstance()->deleteProfile($id);
        if (!$ok) {
            $logger = abj_service('logging');
            if ($logger !== null) {
                $logger->warn('Ajax_EngineProfiles::handleDelete: deleteProfile(' . (int)$id .
                    ') returned false (row missing or DB error). Returning HTTP 200 with success=false to AJAX caller.');
            }
            wp_send_json_error(['message' => __('Failed to delete engine profile.', '404-solution')]);
            return; // @phpstan-ignore deadCode.unreachable
        }

        wp_send_json_success(['id' => $id]);
    }
}
