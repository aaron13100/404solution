<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Early settings and path helpers used during plugin boot.
 *
 * abj404_getUploadsDir() resolves the plugin's temp upload directory and is
 * needed before any Loader.php initialization that might touch
 * Logging/SynchronizationUtils. abj404_get_settings_options() is the
 * centralized, container-aware read of the abj404_settings option and is the
 * plugin-root fallback boundary for raw settings reads.
 *
 * Both are required early (before Loader.php) because boot-time code paths call
 * them before the service container is fully available.
 */
// allow-no-test-found: boot-time global settings/path helpers required before Loader.php and the service container; no same-named unit file. abj404_get_settings_options (the raw-settings boundary) is exercised in OptionsRepositoryMigrationContractTest::testBootstrapRawSettingsReadsStayIsolatedToHelper.

// Used by multiple classes during early admin initialization (e.g. upgrade/migration paths).
// This must be defined before any Loader.php initialization that might touch Logging/SynchronizationUtils.
if (!function_exists('abj404_getUploadsDir')) {
	/** @return string */
	function abj404_getUploadsDir() {
		$uploadsDirArray = wp_upload_dir(null, false);
		$uploadsDir = $uploadsDirArray['basedir'];
		$uploadsDir .= DIRECTORY_SEPARATOR . 'temp_' . ABJ404_PP . DIRECTORY_SEPARATOR;
		return $uploadsDir;
	}
}

if (!function_exists('abj404_get_settings_options')) {
	/**
	 * Centralized settings read so call sites don't repeat option-shape checks.
	 * This is the plugin-root fallback boundary for raw abj404_settings reads:
	 * ordinary callers must use this helper or options_repository.
	 *
	 * @return array<string, mixed>
	 */
	function abj404_get_settings_options() {
		if (function_exists('abj_service_optional')) {
			$optionsRepository = abj_service_optional('options_repository');
			if (is_object($optionsRepository) && method_exists($optionsRepository, 'getOptions')) {
				try {
					$options = $optionsRepository->getOptions(true);
					if (is_array($options)) {
						$normalizedOptions = array();
						foreach ($options as $key => $value) {
							if (is_string($key)) {
								$normalizedOptions[$key] = $value;
							}
						}
						return $normalizedOptions;
					}
					if (function_exists('abj404_logRuntimeWarning')) {
						abj404_logRuntimeWarning('options_repository->getOptions(true) returned ' . gettype($options) . '; falling back to raw bootstrap option read');
					}
				} catch (\Throwable $e) {
					if (function_exists('abj404_logRuntimeWarning')) {
						abj404_logRuntimeWarning('options_repository->getOptions(true) failed while reading settings options; falling back to raw bootstrap option read', $e);
					} else {
						abj404_logPhpFallback(
							'early-boot',
							'options_repository->getOptions(true) failed while reading settings options; falling back to raw bootstrap option read (' . $e->getMessage() . ')'
						);
					}
				}
			}
		}
		$options = function_exists('get_option') ? get_option('abj404_settings') : false;
		if (!is_array($options)) {
			return array();
		}
		$normalizedOptions = array();
		foreach ($options as $key => $value) {
			if (is_string($key)) {
				$normalizedOptions[$key] = $value;
			}
		}
		return $normalizedOptions;
	}
}
