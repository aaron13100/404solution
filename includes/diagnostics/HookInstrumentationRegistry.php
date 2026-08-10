<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The instrumenter's memory of what it changed, indexed for O(1) answers.
 *
 * ABJ_404_Solution_HookCallbackInstrumenter owns registry MUTATION: wrapping a
 * callback, marking one it must not wrap, putting the original back. This
 * collaborator owns the bookkeeping that mutation needs to be reversible, and
 * the three questions asked of it on the hot path:
 *
 *   - which registrations belong to hook X (staleness check, once per walk);
 *   - is this exact entry one I installed (per entry examined);
 *   - is this entry a wrapper or marker closure of mine (per entry examined).
 *
 * All three used to be linear scans over every registration the instrumenter
 * held, and the last two ran INSIDE the per-entry loop, so inspecting one hook
 * cost O(entries x registrations). On the owner's plugin-heavy localhost (4,024
 * registered callbacks) that is the difference between a bounded cost and a
 * request that takes tens of seconds with debug_mode on. Keeping the indexes
 * beside the table -- rather than deriving them per question -- is what makes
 * the quadratic shape unrepresentable here.
 *
 * The registration shapes live here rather than in the instrumenter because
 * this class is what stores them; the instrumenter imports them back, so there
 * is one definition of what a registration IS and a `mode` discriminator that
 * tells wrapper and marker apart wherever one is read.
 *
 * @phpstan-type WrapperRegistration array{mode: 'wrapper', hook: string, priority: int, ordinal: int, id: string, original: callable, wrapper: callable}
 * @phpstan-type MarkerRegistration array{mode: 'marker', hook: string, priority: int, ordinal: int, id: string, original: callable, before_id: string, before: callable}
 * @phpstan-type Registration WrapperRegistration|MarkerRegistration
 */
final class ABJ_404_Solution_HookInstrumentationRegistry {

    /** @var array<string, Registration> Registration by collision-safe key. */
    private $registrations = array();

    /** @var array<string, array<string, true>> Registration keys by hook name. */
    private $keysByHook = array();

    /**
     * Object ids of every wrapper/marker closure currently registered.
     *
     * spl_object_id() recycles ids once an object is freed, which would make a
     * stale entry here answer "yes, mine" about an unrelated object. It cannot
     * go stale: the closure is reachable from its own registration for exactly
     * as long as the id is in this set, so the id cannot be reissued while the
     * set still holds it.
     *
     * @var array<int, true>
     */
    private $ownedCallbackIds = array();

    /** @param Registration $registration */
    public function add(string $key, array $registration): void {
        $this->forget($key);
        $this->registrations[$key] = $registration;
        $hook = $registration['hook'];
        $this->keysByHook[$hook][$key] = true;
        foreach (array('wrapper', 'before') as $field) {
            $callback = $registration[$field] ?? null;
            if (is_object($callback)) {
                $this->ownedCallbackIds[spl_object_id($callback)] = true;
            }
        }
    }

    public function forget(string $key): void {
        if (!array_key_exists($key, $this->registrations)) {
            return;
        }
        $registration = $this->registrations[$key];
        $hook = $registration['hook'];
        unset($this->keysByHook[$hook][$key], $this->registrations[$key]);
        if (($this->keysByHook[$hook] ?? null) === array()) {
            unset($this->keysByHook[$hook]);
        }
        foreach (array('wrapper', 'before') as $field) {
            $callback = $registration[$field] ?? null;
            if (is_object($callback)) {
                unset($this->ownedCallbackIds[spl_object_id($callback)]);
            }
        }
    }

    /** @return Registration|null */
    public function get(string $key): ?array {
        return $this->registrations[$key] ?? null;
    }

    /** @return array<string, Registration> Registrations of one hook, keyed by key. */
    public function forHook(string $hook): array {
        $found = array();
        foreach (array_keys($this->keysByHook[$hook] ?? array()) as $key) {
            if (array_key_exists($key, $this->registrations)) {
                $found[$key] = $this->registrations[$key];
            }
        }
        return $found;
    }

    /** @return array<string, Registration> Every registration, keyed by key. */
    public function all(): array {
        return $this->registrations;
    }

    /**
     * Is this WP_Hook entry's callable one of the closures we installed?
     *
     * @param mixed $callback
     */
    public function ownsCallback($callback): bool {
        return is_object($callback)
            && isset($this->ownedCallbackIds[spl_object_id($callback)]);
    }

    public function clear(): void {
        $this->registrations = array();
        $this->keysByHook = array();
        $this->ownedCallbackIds = array();
    }
}
