<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin class autoloader.
 *
 * Resolves ABJ_404_Solution_* classes via a deterministic classmap
 * (includes/classmap.php) to avoid runtime glob() scans on real sites. Before
 * loading a "host" facade class it verifies that the collaborator files it
 * depends on are present, using the composition-dependency map stored in
 * includes/data/autoloader-trait-dependencies.php; a missing collaborator is
 * recorded in $GLOBALS['abj404_missing_files'] and the host class load is
 * skipped so a corrupt install surfaces a degraded admin page instead of an
 * uncatchable compile-time fatal.
 *
 * The spl_autoload_register() call stays in 404-solution.php (the boot
 * sequence); this file only defines the function. It is required with a plain
 * require FIRST in 404-solution.php, before any class use.
 */
// allow-no-test-found: boot-time global function (abj404_autoloader) wired via spl_autoload_register in 404-solution.php; no isolated unit-file seam. The classmap+missing-collaborator resolution behavior is exercised in BootResilienceTest (which references abj404_autoloader directly).

if (!function_exists('abj404_autoloader')) {
/**
 * @param string $class
 * @return void
 */
function abj404_autoloader($class) {
	// some people were having issues with possibly parent classes not being loaded before their children.
	$childParentMap = [
		'ABJ_404_Solution_FunctionsMBString' => 'ABJ_404_Solution_Functions',
		'ABJ_404_Solution_FunctionsPreg' => 'ABJ_404_Solution_Functions',
		'ABJ_404_Solution_MbStringAdapterMb' => 'ABJ_404_Solution_MbStringAdapter',
		'ABJ_404_Solution_MbStringAdapterPreg' => 'ABJ_404_Solution_MbStringAdapter',
		'ABJ_404_Solution_RegexHelperMb' => 'ABJ_404_Solution_RegexHelper',
		'ABJ_404_Solution_RegexHelperPreg' => 'ABJ_404_Solution_RegexHelper',
	];

	// only pay attention if it's for us. don't bother for other things.
	if (substr($class, 0, 16) !== 'ABJ_404_Solution') {
		return;
	}

	// Use a deterministic classmap to avoid runtime glob() scans on real sites.
	/** @var array<string, string>|null $abj404_autoLoaderClassMap */
	static $abj404_autoLoaderClassMap = null;
	if ($abj404_autoLoaderClassMap === null) {
		$mapFile = dirname(__DIR__) . '/classmap.php';
		$loadedMap = file_exists($mapFile) ? require $mapFile : array();
		$normalizedMap = array();
		if (is_array($loadedMap)) {
			foreach ($loadedMap as $mappedClass => $mappedFile) {
				if (is_string($mappedClass) && is_string($mappedFile)) {
					$normalizedMap[$mappedClass] = $mappedFile;
				}
			}
		}
		$abj404_autoLoaderClassMap = $normalizedMap;
	}

	if (!array_key_exists($class, $abj404_autoLoaderClassMap)) {
		return;
	}

	// Composition dependency pre-check: parent facade classes depend on files
	// being present before they are loaded. Verify those files exist first so
	// a corrupt install can surface a degraded admin page instead of a fatal.
	// The class->collaborators map is DATA, loaded from an external file.
	/** @var array<string, array<int, string>>|null $traitDependencies */
	static $traitDependencies = null;
	if ($traitDependencies === null) {
		$mapDataFile = dirname(__DIR__) . '/data/autoloader-trait-dependencies.php';
		$traitDependencyClasses = file_exists($mapDataFile) ? require $mapDataFile : array();
		if (!is_array($traitDependencyClasses)) {
			$traitDependencyClasses = array();
		}
		$traitDependencies = array();
		foreach ($traitDependencyClasses as $hostClass => $dependencyClasses) {
			$traitDependencies[$hostClass] = array();
			if (!is_array($dependencyClasses)) {
				continue;
			}
			foreach ($dependencyClasses as $dependencyClass) {
				if (is_string($dependencyClass) && isset($abj404_autoLoaderClassMap[$dependencyClass])) {
					$traitDependencies[$hostClass][] = $abj404_autoLoaderClassMap[$dependencyClass];
				}
			}
		}
	}

	if (isset($traitDependencies[$class])) {
		foreach ($traitDependencies[$class] as $dependencyFile) {
			if (!file_exists($dependencyFile)) {
				abj404_record_missing_file($dependencyFile);
				// Don't load the parent class: the compile-time fatal is uncatchable.
				return;
			}
		}
	}

	// Ensure the parent class is loaded first.
	if (array_key_exists($class, $childParentMap)) {
		$parentClass = $childParentMap[$class];
		if (!class_exists($parentClass, false) && array_key_exists($parentClass, $abj404_autoLoaderClassMap)) {
			$parentFile = $abj404_autoLoaderClassMap[$parentClass];
			if (!file_exists($parentFile)) {
				abj404_record_missing_file($parentFile);
				return;
			}
			require_once $parentFile;
		}
	}

	$classFile = $abj404_autoLoaderClassMap[$class];
	if (!file_exists($classFile)) {
		abj404_record_missing_file($classFile);
		return;
	}

	require_once $classFile;
}
}
