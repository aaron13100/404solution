<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clock interface — the seam every time-dependent class talks to instead
 * of `time()` / `microtime(true)` / `current_time('timestamp')` directly.
 *
 * Production code resolves a `ABJ_404_Solution_SystemClock` from the
 * service container; tests bind a `ABJ_404_Solution_FrozenClock` so they
 * can advance virtual time deterministically (no `usleep()`, no global
 * Brain\Monkey stubs of `time()`). See `docs/clock-injection-audit.md`.
 *
 * The four-method surface (`now`, `nowFloat`, `wpNow`, `wpNowMysql`) is the
 * minimum set the audit identified as covering every time-sensitive call
 * site in `includes/`. Adding new methods here is a project-wide change —
 * prefer to keep call sites within this surface.
 */
interface ABJ_404_Solution_Clock {
    /**
     * Current Unix epoch in seconds (whole integer). Replaces direct calls
     * to `time()`. Use for cooldown windows, rate-limit windows, and any
     * comparison whose precision is per-second.
     *
     * @return int
     */
    public function now(): int;

    /**
     * Current Unix epoch with microsecond precision. Replaces direct calls
     * to `microtime(true)`. Use for sub-second elapsed-time measurements
     * (deadline timers, performance probes).
     *
     * @return float
     */
    public function nowFloat(): float;

    /**
     * WordPress-localized "now" timestamp. Replaces direct calls to
     * `current_time('timestamp')`. Tests treat this as identical to
     * `now()` because the WP locale offset is irrelevant when virtual
     * time is being driven by the test directly.
     *
     * @return int
     */
    public function wpNow(): int;

    /**
     * MySQL-formatted "now" string ("Y-m-d H:i:s"). Replaces direct calls
     * to `current_time('mysql')`.
     *
     * @return string
     */
    public function wpNowMysql(): string;
}

/**
 * Default production clock — delegates to PHP's wall-clock and to
 * WordPress's `current_time()` helper. Stateless and immutable.
 *
 * Resolved by `bootstrap.php` for the `'clock'` container service.
 */
final class ABJ_404_Solution_SystemClock implements ABJ_404_Solution_Clock {

    public function now(): int {
        return time();
    }

    public function nowFloat(): float {
        return microtime(true);
    }

    public function wpNow(): int {
        if (function_exists('current_time')) {
            $value = current_time('timestamp');
            if (is_numeric($value)) {
                return (int)$value;
            }
        }
        return time();
    }

    public function wpNowMysql(): string {
        if (function_exists('current_time')) {
            $value = current_time('mysql');
            if (is_string($value)) {
                return $value;
            }
        }
        return gmdate('Y-m-d H:i:s');
    }
}

/**
 * Test-only clock that returns a fixed virtual time controllable by the
 * test author. Default start epoch is 2023-11-14T22:13:20Z (1700000000) —
 * any moment well after Y2K38 sanity checks would care about and well
 * before any test would pick a date that confuses date math.
 *
 * Tests advance the clock with `advance($seconds)` to fast-forward
 * cooldown windows / rate-limit windows / cron windows without sleeping.
 *
 * Not registered in the container by default. Tests bind it explicitly:
 *     $clock = new ABJ_404_Solution_FrozenClock();
 *     ABJ_404_Solution_ServiceContainer::getInstance()->set('clock',
 *         function() use ($clock) { return $clock; });
 */
final class ABJ_404_Solution_FrozenClock implements ABJ_404_Solution_Clock {

    /** @var float Virtual epoch seconds (microsecond precision). */
    private $t;

    /** @param float $startEpoch */
    public function __construct(float $startEpoch = 1700000000.0) {
        $this->t = $startEpoch;
    }

    public function now(): int {
        return (int)$this->t;
    }

    public function nowFloat(): float {
        return $this->t;
    }

    public function wpNow(): int {
        return (int)$this->t;
    }

    public function wpNowMysql(): string {
        return gmdate('Y-m-d H:i:s', (int)$this->t);
    }

    /**
     * Advance virtual time by `$seconds`. Negative values rewind — useful
     * for tests that need to verify behavior at a known point in the past
     * (e.g. an expired cooldown that was set before the test "started").
     *
     * @param float $seconds
     * @return void
     */
    public function advance(float $seconds): void {
        $this->t += $seconds;
    }

    /**
     * Set virtual time to an absolute epoch. Use for tests that need to
     * pin time to a specific calendar date (e.g. boundary-of-month cron
     * tests).
     *
     * @param float $epoch
     * @return void
     */
    public function set(float $epoch): void {
        $this->t = $epoch;
    }
}
