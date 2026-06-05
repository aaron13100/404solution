<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cache-coherent verification for high-stakes staged-build option writes.
 *
 * Progress storage owns which keys are high stakes. This verifier owns the
 * read-back, cache invalidation retry, current-stage classification, transient
 * diagnostics, and warning output once a high-stakes option write is attempted.
 *
 * @property ABJ_404_Solution_Logging $logger
 */
class ABJ_404_Solution_ViewBuildOptionWriteVerifier extends ABJ_404_Solution_ViewBuildCollaborator {

    /** @var string Progress option suffix used for current-stage diagnostics. */
    private const CURRENT_STAGE_OPTION_SUFFIX = 'abj404_view_build_current_stage';

    /**
     * Cache-coherent option write. Persistent object caches (Redis,
     * Memcached, mu-cluster split routing) can serve a stale `get_option`
     * value for one tick after `update_option` writes the row. For
     * high-stakes options (staged-build current_stage, batch high-water
     * marks) that single tick is enough to let a parallel worker rewind to
     * a just-completed stage and re-run destructive work.
     *
     * Procedure:
     *   1. update_option($name, $expected, autoload=false).
     *   2. get_option($name) and strict-compare to $expected.
     *   3. On mismatch: wp_cache_delete($name, 'options') and the
     *      'alloptions' bucket (covers both keying strategies WP uses), then
     *      update_option + get_option once more.
     *   4. On persistent mismatch: set a 24h transient
     *      'abj404_option_cache_incoherent' carrying name + observed value
     *      so other code can short-circuit cache-coherence-sensitive logic,
     *      log a warning, return false.
     *   5. On success (first or retry): return true.
     *
     * Idempotent and safe to call repeatedly. Loose-equal comparison is
     * intentional: option values round-trip through serialization and
     * scalar coercion, so an int 4 may come back as the string "4".
     *
     * @param string $optionName  WordPress option name (already fully prefixed).
     * @param mixed  $expected    Value just written, compared against the read-back.
     * @return bool  True on coherent write (first try or retry); false when the
     *               cache layer fails to invalidate even after wp_cache_delete.
     */
    public function verifyOptionWriteCoherent(string $optionName, $expected): bool {
        if (!function_exists('update_option') || !function_exists('get_option')) {
            return false;
        }
        // Capture the prior persisted value so a first-read-back-fail WARN
        // (below, for current_stage only) can carry prior + new + observed,
        // letting support see whether the cache returned the previous value
        // or some unrelated state from a parallel request.
        $prior = get_option($optionName, null);
        update_option($optionName, $expected, false);
        $actual = get_option($optionName, null);
        if ($this->optionReadBackMatches($actual, $expected)) {
            return true;
        }
        // First read disagrees with the just-written value. Surface a WARN
        // for current_stage specifically (the most diagnostically valuable
        // stage-progress key) so the signal survives a site with DEBUG off.
        // Other high-stakes keys (s2/s4/s5_high_water) stay silent on the
        // first miss to avoid log volume; they still hit the persistent-
        // mismatch WARN further down if the retry also fails.
        if ($this->isCurrentStageOptionName($optionName) && is_object($this->host->logger())
                && method_exists($this->host->logger(), 'warn')) {
            $this->host->logger()->warn(sprintf(
                '[staged] option write incoherent (first read-back) on %s: prior=%s new=%s observed=%s; flushing cache and retrying',
                $optionName,
                is_scalar($prior)    ? (string)$prior    : '<non-scalar>',
                is_scalar($expected) ? (string)$expected : '<non-scalar>',
                is_scalar($actual)   ? (string)$actual   : '<non-scalar>'
            ));
        }
        // Flush both candidate cache keys and retry. Use a typeof-guarded
        // call because wp_cache_delete is part of WP core but not loaded
        // in unit-test bootstraps that don't pull in cache.php.
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($optionName, 'options');
            // alloptions is the bundled bucket WP loads on every page; even
            // for autoload=false writes some object-cache backends miss the
            // per-key invalidation and need the bucket flushed.
            wp_cache_delete('alloptions', 'options');
        }
        update_option($optionName, $expected, false);
        $retry = get_option($optionName, null);
        if ($this->optionReadBackMatches($retry, $expected)) {
            return true;
        }

        // Persistent mismatch: surface to other code via a deduplicated
        // transient and log a warning. Don't email; this is a host-config
        // problem, not a plugin defect.
        if (function_exists('set_transient')) {
            // allow-cache-empty: payload is diagnostic state; empty observed/error fields are still actionable.
            set_transient(
                'abj404_option_cache_incoherent',
                array(
                    'option'   => $optionName,
                    'expected' => is_scalar($expected) ? (string)$expected : 'non-scalar',
                    'observed' => is_scalar($retry) ? (string)$retry : 'non-scalar',
                    'when'     => time(),
                ),
                86400
            );
        }
        if (is_object($this->host->logger())) {
            $message = sprintf(
                '[staged] option write incoherent on this host: %s expected=%s observed=%s '
                . '(persistent object cache likely returning stale values; '
                . 'wp_cache_delete + retry did not invalidate).',
                $optionName,
                is_scalar($expected) ? (string)$expected : '<non-scalar>',
                is_scalar($retry)    ? (string)$retry    : '<non-scalar>'
            );
            if (method_exists($this->host->logger(), 'warn')) {
                $this->host->logger()->warn($message);
            } elseif (method_exists($this->host->logger(), 'debugMessage')) {
                $this->host->logger()->debugMessage($message);
            }
        }
        return false;
    }

    /**
     * Whether the fully-prefixed option name refers to the view-build
     * `current_stage` key (the prefix component varies by site). Used by
     * verifyOptionWriteCoherent() to scope its first-read-back-fail WARN to
     * the most diagnostically valuable stage-progress key.
     *
     * @param string $optionName
     * @return bool
     */
    public function isCurrentStageOptionName(string $optionName): bool {
        $suffix = self::CURRENT_STAGE_OPTION_SUFFIX;
        $len = strlen($suffix);
        return $len > 0 && substr($optionName, -$len) === $suffix;
    }

    /**
     * Loose-equal read-back comparison. WP option values round-trip through
     * serialize() and may come back as a different scalar type than written
     * (int 4 -> string "4"). The semantic question is "did the persisted
     * value reflect the write," so we compare via string casts when both
     * sides are scalar; otherwise fall back to ==.
     *
     * Null asymmetry is treated as a mismatch. PHP's loose-equal would
     * otherwise have `null == 0`, `null == ''`, `null == false` all return
     * true, so a cache layer that served null ("option not found") for a
     * value-of-zero write (s2/s4/s5_high_water reset on a fresh build) would
     * have spuriously passed verification. An unwritten cache slot is not
     * the same value as a written falsy value.
     *
     * @param mixed $actual
     * @param mixed $expected
     * @return bool
     */
    public function optionReadBackMatches($actual, $expected): bool {
        if (($actual === null) !== ($expected === null)) {
            return false;
        }
        if (is_scalar($actual) && is_scalar($expected)) {
            return (string)$actual === (string)$expected;
        }
        return $actual == $expected;
    }
}
