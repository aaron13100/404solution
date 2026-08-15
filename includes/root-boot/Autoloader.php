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
 * The classmap is read once per request and then kept in a static, which makes
 * it OLDER THAN THE FILES IT DESCRIBES for the length of a plugin self-update:
 * WordPress replaces the plugin directory underneath a request that is already
 * running, so from that moment the request reads NEW class files through the
 * PREVIOUS release's map. Any collaborator whose class is new in the incoming
 * release is then unresolvable. That has reached production twice -- report 266
 * (TableReadinessGate, 4.3.2 to 4.3.3, save_post during wp-cron) and the
 * 4.3.3-beta.2 PiiRedactor fatal -- so the map now re-reads itself when
 * classmap.php changes on disk. See abj404_autoloader_classmap_stamp().
 *
 * The spl_autoload_register() call stays in 404-solution.php (the boot
 * sequence); this file only defines the function. It is required with a plain
 * require FIRST in 404-solution.php, before any class use.
 */
// allow-no-test-found: boot-time global function (abj404_autoloader) wired via spl_autoload_register in 404-solution.php; no isolated unit-file seam. The classmap+missing-collaborator resolution behavior is exercised in BootResilienceTest (which references abj404_autoloader directly); the mid-request classmap swap is exercised in AutoloaderStaleClassmapRefreshTest.

if (!function_exists('abj404_autoloader_classmap_stamp')) {
/**
 * Identity of includes/classmap.php as it exists on disk RIGHT NOW.
 *
 * Compared against the stamp taken when the in-memory map was built, this is
 * what tells a running request that its plugin directory has been replaced.
 * mtime alone would answer in production (a release's files carry that
 * release's extraction time), but a same-second rewrite is cheap to also cover,
 * so the size goes in too.
 *
 * The stat cache must be cleared first: the file is rewritten by a DIFFERENT
 * process (the WordPress updater), so this request's cached stat predates the
 * swap and would report the file as unchanged forever.
 *
 * @param string $mapFile Absolute path to includes/classmap.php.
 * @return string '' when the file is absent or unreadable, which is the
 *                updater's directory-move window and means "cannot tell".
 */
function abj404_autoloader_classmap_stamp($mapFile) {
	clearstatcache(true, $mapFile);
	if (!is_file($mapFile)) {
		return '';
	}
	$mtime = @filemtime($mapFile);
	$size = @filesize($mapFile);
	if ($mtime === false || $size === false) {
		return '';
	}
	return $mtime . ':' . $size;
}
}

if (!function_exists('abj404_autoloader_read_classmap')) {
/**
 * Read and normalize includes/classmap.php.
 *
 * @param string $mapFile         Absolute path to includes/classmap.php.
 * @param bool   $refreshBytecode True when the file is known to have changed
 *                                since it was last read. opcache would
 *                                otherwise hand `require` back the previous
 *                                release's compiled copy of the very file we
 *                                are re-reading BECAUSE it changed. Guarded the
 *                                same way OpcacheUpgradeGuard guards its own
 *                                calls: a host with opcache.restrict_api set
 *                                raises a warning and refuses, and that warning
 *                                reaches the error reporter.
 * @return array<string, string> Class name => absolute file path. Empty when
 *                               the file is absent, unreadable, half-written,
 *                               or does not return an array. The caller treats
 *                               empty as "keep the map you already have".
 */
function abj404_autoloader_read_classmap($mapFile, $refreshBytecode = false) {
	if (!is_file($mapFile)) {
		return array();
	}
	if ($refreshBytecode && function_exists('opcache_invalidate')
			&& (!function_exists('abj404_opcache_api_is_restricted')
				|| !abj404_opcache_api_is_restricted(ini_get('opcache.restrict_api'), __FILE__))) {
		@opcache_invalidate($mapFile, true);
	}
	try {
		$loadedMap = require $mapFile;
	} catch (\Throwable $readFailure) {
		// A refresh reads this file at the one moment another process is
		// REWRITING it, so `require` can compile a truncated copy and raise a
		// ParseError. Reporting no map lets the caller keep the working one;
		// the next miss re-reads the by-then-complete file.
		if (function_exists('abj404_logRuntimeWarning')) {
			abj404_logRuntimeWarning('abj404_autoloader: could not read ' . $mapFile, $readFailure);
		}
		return array();
	}
	$normalizedMap = array();
	if (is_array($loadedMap)) {
		foreach ($loadedMap as $mappedClass => $mappedFile) {
			if (is_string($mappedClass) && is_string($mappedFile)) {
				$normalizedMap[$mappedClass] = $mappedFile;
			}
		}
	}
	return $normalizedMap;
}
}

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
	/** @var string $abj404_autoLoaderMapStamp Disk identity of the map above. */
	static $abj404_autoLoaderMapStamp = '';
	// Declared here rather than beside its first use: a `static` statement
	// rebinds the variable to static storage when it executes, so an
	// assignment placed before it (the refresh below invalidates this map)
	// would be silently discarded on every call after the first.
	/** @var array<string, array<int, string>>|null $traitDependencies */
	static $traitDependencies = null;
	/** @var string|null $mapFile */
	static $mapFile = null;

	if ($mapFile === null) {
		$mapFile = dirname(__DIR__) . '/classmap.php';
	}
	if ($abj404_autoLoaderClassMap === null) {
		$abj404_autoLoaderClassMap = abj404_autoloader_read_classmap($mapFile);
		$abj404_autoLoaderMapStamp = abj404_autoloader_classmap_stamp($mapFile);
	}

	if (!array_key_exists($class, $abj404_autoLoaderClassMap)) {
		// STALE-MAP REFRESH. A miss is normally the truth -- the class is not
		// ours -- but it is also exactly what a plugin self-update looks like
		// from inside a request that booted on the previous release: same
		// paths, new file contents, and a collaborator this map has never
		// heard of (production report 266, TableReadinessGate). Re-reading the
		// map is the only way to tell those two apart, and the stamp is what
		// keeps the re-read off the hot path: an ordinary miss costs one stat.
		$currentStamp = abj404_autoloader_classmap_stamp($mapFile);
		if ($currentStamp === '' || $currentStamp === $abj404_autoLoaderMapStamp) {
			return;
		}
		$refreshedMap = abj404_autoloader_read_classmap($mapFile, true);
		if ($refreshedMap === array()) {
			// Half-written or unreadable while the updater moves directories.
			// Keep the map we already have (and its stamp, so the next miss
			// tries again): trading a working map for an empty one would break
			// every remaining autoload in this request.
			return;
		}
		$abj404_autoLoaderClassMap = $refreshedMap;
		$abj404_autoLoaderMapStamp = $currentStamp;
		// Derived from the classmap, so it is stale for the same reason.
		$traitDependencies = null;
		if (!array_key_exists($class, $abj404_autoLoaderClassMap)) {
			return;
		}
	}

	// Composition dependency pre-check: parent facade classes depend on files
	// being present before they are loaded. Verify those files exist first so
	// a corrupt install can surface a degraded admin page instead of a fatal.
	// The class->collaborators map is DATA, loaded from an external file.
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
