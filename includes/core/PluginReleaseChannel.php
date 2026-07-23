<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Which release channel the running build belongs to: a deliberately
 * published pre-release (`4.3.3-beta.2`, `4.4.0-rc.1`) or an ordinary stable
 * release (`4.3.2`).
 *
 * The distinction exists so that instrumentation which is legitimate on a
 * build handed to one consenting user, but NOT legitimate on the thousands of
 * ordinary installs a wp.org release reaches, can arm itself from a fact the
 * build already carries instead of from a switch somebody has to remember to
 * flip. That memory is not hypothetical: the detach A/B experiment
 * (ABJ_404_Solution_AjaxRequestLedger::isDetachAbDiagnosticEnabled(), Bruno
 * timeout cause matrix gap G9) shipped completely inert because its filter
 * defaulted false and nothing in the codebase or the packaging steps ever set
 * it true. A gate whose only "on" path is a manual step is a gate that ships
 * off.
 *
 * The version string is the single source of truth (the plugin header, read
 * into ABJ404_VERSION by Loader.php), because it is the one thing that cannot
 * be true of the source tree and false of the built artifact: packaging cannot
 * produce a beta zip whose header does not say beta.
 *
 * Parsing follows semver's rule, not a substring search for "beta": a version
 * is a pre-release exactly when a hyphen-introduced identifier follows the
 * numeric version core. Build metadata (`+20260722`) is NOT a pre-release
 * marker under that rule and is deliberately treated as stable.
 *
 * Fail-safe by construction: a version that does not parse at all reports
 * CHANNEL_UNKNOWN, and isPreRelease() collapses both 'stable' and 'unknown' to
 * false. An install this class cannot identify is never opted into anything.
 * The channel itself stays a three-value answer rather than a boolean so a
 * diagnostic record can say WHICH of "stable build" or "unparseable version"
 * kept an experiment inert, instead of leaving a reader to infer it.
 */
final class ABJ_404_Solution_PluginReleaseChannel {

    /** An ordinary published release: `4.3.2`, `4.3.2+20260722`. */
    const CHANNEL_STABLE = 'stable';

    /** A deliberately published pre-release: `4.3.3-beta.2`, `4.4.0-rc.1`. */
    const CHANNEL_PRERELEASE = 'prerelease';

    /** The version string is absent or unparseable; treated as stable for gating. */
    const CHANNEL_UNKNOWN = 'unknown';

    /** Numeric version core: `4`, `4.3`, `4.3.3`. */
    const VERSION_CORE = '\d+(?:\.\d+)*';

    /** Semver identifier body, used for both the pre-release and build-metadata tails. */
    const IDENTIFIER_TAIL = '[0-9A-Za-z.\-]+';

    /**
     * Classify one version string. Pure: takes the version rather than
     * reading the constant, so every case (stable, pre-release, build
     * metadata, empty, garbage) is directly assertable without a process
     * whose ABJ404_VERSION happens to hold that value -- a constant cannot be
     * redefined mid-process, which would otherwise make most of these cases
     * untestable.
     *
     * @param mixed $version
     */
    public static function channelForVersion($version): string {
        $candidate = is_scalar($version) ? trim((string)$version) : '';
        if ($candidate === '') {
            return self::CHANNEL_UNKNOWN;
        }
        $buildMetadata = '(?:\+' . self::IDENTIFIER_TAIL . ')?';
        if (preg_match('/^' . self::VERSION_CORE . '-' . self::IDENTIFIER_TAIL . $buildMetadata . '$/', $candidate) === 1) {
            return self::CHANNEL_PRERELEASE;
        }
        if (preg_match('/^' . self::VERSION_CORE . $buildMetadata . '$/', $candidate) === 1) {
            return self::CHANNEL_STABLE;
        }
        return self::CHANNEL_UNKNOWN;
    }

    /**
     * The running build's channel, reported honestly (including 'unknown')
     * so it can be journaled as evidence rather than only acted on.
     */
    public static function currentChannel(): string {
        return self::channelForVersion(defined('ABJ404_VERSION') ? ABJ404_VERSION : '');
    }

    /**
     * Whether this build may arm pre-release-only instrumentation. Only a
     * parsed pre-release qualifies: 'unknown' deliberately behaves like
     * 'stable' here, because the cost of guessing wrong is instrumentation
     * running on real installs that never opted into it.
     */
    public static function isPreRelease(): bool {
        return self::currentChannel() === self::CHANNEL_PRERELEASE;
    }
}
