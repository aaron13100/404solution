<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Typed value object for a WordPress user as returned by
 * `wp_get_current_user()`, `get_user_by()`, and `get_userdata()`.
 *
 * Boundary normalizer (task: type-pressure at module boundaries).
 *
 * WordPress's user APIs return `WP_User|false`. The `WP_User` class has a
 * stable property surface (`ID`, `user_login`, `user_email`, `display_name`,
 * `roles`), but every public property is typed `string|int|array` in the
 * stubs (raw DB column types) and is also subject to:
 *
 *   1. Anonymous-user objects with `ID = 0` (the documented sentinel for
 *      visitors not logged in: `WP_User::exists()` returns false).
 *   2. Third-party plugins that mutate `roles` into something other than
 *      `string[]` (observed in legacy multisite migrations).
 *   3. A historical mix of `wp_get_current_user()` returning a real
 *      `WP_User` versus `null` in stub-only test harnesses where the
 *      pluggable function never resolved.
 *
 * Before this VO, six call sites (DataAccessTrait_Logs, Privacy,
 * ViewTrait_Stats, ErrorHandler, ViewUpdater, RedirectConditionEvaluator)
 * each reinvented some subset of the probing:
 *
 *   $u = wp_get_current_user();
 *   if (is_object($u) && property_exists($u, 'roles') && is_array($u->roles)) { ... }
 *
 *   $u = wp_get_current_user();
 *   $login = $u->user_login ?? '';        // misses 'absent property' case
 *
 *   $u = wp_get_current_user();
 *   $login = $u->user_login;              // crashes if $u is null in stubs
 *
 *   $u = wp_get_current_user();
 *   if (!($u instanceof WP_User) || !$u->exists()) { ... }
 *
 * This VO collapses them into one boundary:
 *
 *   $ref = ABJ_404_Solution_UserRef::fromWpUser(wp_get_current_user());
 *   if ($ref === null || !$ref->exists()) { return; }
 *   if ($ref->isAdministrator()) { ... }
 *   $login = $ref->getLogin();           // always a string, never null
 *
 * Schema (after normalization):
 *
 *   - id          : int >= 0   (0 is the documented anonymous sentinel)
 *   - login       : string     ('' if absent / non-scalar)
 *   - email       : string
 *   - displayName : string
 *   - roles       : string[]   (non-string entries skipped)
 *
 * Construction accepts a `WP_User` object, any object that mirrors the
 * documented property surface, or `null` / non-object input.
 * `fromWpUser` returns `null` only when the input is truly unrecoverable
 * (not an object, or a non-array shape posing as an array).
 * An object with `ID = 0` is *not* null. It is a real anonymous user
 * VO whose `exists()` returns false, matching `WP_User::exists()`.
 */
final class ABJ_404_Solution_UserRef {

    /** @var int */
    private $id;

    /** @var string */
    private $login;

    /** @var string */
    private $email;

    /** @var string */
    private $displayName;

    /** @var array<int, string> */
    private $roles;

    /**
     * @param array<int, string> $roles
     */
    private function __construct(
        int $id,
        string $login,
        string $email,
        string $displayName,
        array $roles
    ) {
        $this->id = $id;
        $this->login = $login;
        $this->email = $email;
        $this->displayName = $displayName;
        $this->roles = $roles;
    }

    /**
     * Normalize a `wp_get_current_user()` / `get_user_by()` /
     * `get_userdata()` return into a typed VO. Returns null only when
     * the input is unrecoverably malformed (not an object or array).
     *
     * @param mixed $raw
     */
    public static function fromWpUser($raw): ?self {
        if ($raw === null || is_bool($raw)) {
            return null;
        }
        if (is_object($raw)) {
            return new self(
                self::coerceObjectInt($raw, 'ID'),
                self::coerceObjectString($raw, 'user_login'),
                self::coerceObjectString($raw, 'user_email'),
                self::coerceObjectString($raw, 'display_name'),
                self::coerceObjectRoles($raw)
            );
        }
        if (is_array($raw)) {
            return new self(
                self::coerceArrayInt($raw, 'ID'),
                self::coerceArrayString($raw, 'user_login'),
                self::coerceArrayString($raw, 'user_email'),
                self::coerceArrayString($raw, 'display_name'),
                self::coerceArrayRoles($raw)
            );
        }
        return null;
    }

    public function getId(): int {
        return $this->id;
    }

    public function getLogin(): string {
        return $this->login;
    }

    public function getEmail(): string {
        return $this->email;
    }

    public function getDisplayName(): string {
        return $this->displayName;
    }

    /** @return array<int, string> */
    public function getRoles(): array {
        return $this->roles;
    }

    /**
     * True iff this VO represents an authenticated user. Mirrors
     * `WP_User::exists()`: `ID === 0` is the documented sentinel for
     * an anonymous visitor and must read as "no user".
     */
    public function exists(): bool {
        return $this->id > 0;
    }

    /** Case-sensitive role match (WordPress role slugs are lowercase). */
    public function hasRole(string $role): bool {
        return in_array($role, $this->roles, true);
    }

    public function isAdministrator(): bool {
        return $this->hasRole('administrator');
    }

    /**
     * @param object $obj
     */
    private static function coerceObjectString($obj, string $key): string {
        if (!property_exists($obj, $key)) {
            return '';
        }
        $v = $obj->{$key};
        if (is_string($v)) {
            return $v;
        }
        if (is_scalar($v)) {
            return (string)$v;
        }
        return '';
    }

    /**
     * @param object $obj
     */
    private static function coerceObjectInt($obj, string $key): int {
        if (!property_exists($obj, $key)) {
            return 0;
        }
        return self::scalarToInt($obj->{$key});
    }

    /**
     * Extract a clean `string[]` from `$user->roles`. WP_User stores roles
     * as `string[]`, but the property is `mixed` in the stubs and third-
     * party plugins have been observed shoving in nested arrays / objects.
     * Non-string entries are dropped rather than coerced: a role slug
     * that wasn't a string was never matchable by `in_array(..., true)`
     * anyway, and silently coercing would mask the upstream bug.
     *
     * @param object $obj
     * @return array<int, string>
     */
    private static function coerceObjectRoles($obj): array {
        if (!property_exists($obj, 'roles')) {
            return array();
        }
        $v = $obj->{'roles'};
        if (!is_array($v)) {
            return array();
        }
        return self::filterRoleStrings($v);
    }

    /**
     * @param array<mixed, mixed> $arr
     */
    private static function coerceArrayString(array $arr, string $key): string {
        if (!isset($arr[$key])) {
            return '';
        }
        $v = $arr[$key];
        if (is_string($v)) {
            return $v;
        }
        if (is_scalar($v)) {
            return (string)$v;
        }
        return '';
    }

    /**
     * @param array<mixed, mixed> $arr
     */
    private static function coerceArrayInt(array $arr, string $key): int {
        if (!isset($arr[$key])) {
            return 0;
        }
        return self::scalarToInt($arr[$key]);
    }

    /**
     * @param array<mixed, mixed> $arr
     * @return array<int, string>
     */
    private static function coerceArrayRoles(array $arr): array {
        if (!isset($arr['roles']) || !is_array($arr['roles'])) {
            return array();
        }
        return self::filterRoleStrings($arr['roles']);
    }

    /**
     * @param array<mixed, mixed> $candidate
     * @return array<int, string>
     */
    private static function filterRoleStrings(array $candidate): array {
        $clean = array();
        foreach ($candidate as $entry) {
            if (is_string($entry) && $entry !== '') {
                $clean[] = $entry;
            }
        }
        return $clean;
    }

    /**
     * @param mixed $v
     */
    private static function scalarToInt($v): int {
        if (is_int($v)) {
            return $v < 0 ? 0 : $v;
        }
        if (is_float($v)) {
            $i = (int)$v;
            return $i < 0 ? 0 : $i;
        }
        if (is_string($v) && is_numeric($v)) {
            $i = (int)$v;
            return $i < 0 ? 0 : $i;
        }
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        return 0;
    }
}
