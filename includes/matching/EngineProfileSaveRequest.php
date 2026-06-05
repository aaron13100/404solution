<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Typed value object for the `wp_ajax_abj404_engine_profiles_save` request
 * payload (the $_POST that drives Ajax_EngineProfiles::handleSave).
 *
 * Boundary normalizer (task: type-pressure at module boundaries).
 *
 * The handler used to inline its own shape probing:
 *
 *   $id         = isset($_POST['id'])          ? absint($_POST['id'])                                    : 0;
 *   $name       = isset($_POST['name'])        ? sanitize_text_field(wp_unslash((string)$_POST['name'])) : '';
 *   $urlPattern = isset($_POST['url_pattern']) ? wp_unslash((string)$_POST['url_pattern'])               : '';
 *   $isRegex    = isset($_POST['is_regex'])    ? (int)(bool)$_POST['is_regex']                           : 0;
 *   $engines    = isset($_POST['enabled_engines']) ? wp_unslash((string)$_POST['enabled_engines'])       : '[]';
 *   $priority   = isset($_POST['priority'])    ? (int)$_POST['priority']                                 : 0;
 *   $status     = isset($_POST['status'])      ? (int)(bool)$_POST['status']                             : 1;
 *
 * Six fields, six casts, two sanitisers, all inline. The normalizer pulls
 * the contract into one place so:
 *
 *   - field rules cannot drift between the handler and any future caller
 *     (e.g. a WP-CLI command that wants to reuse the save path);
 *   - malformed-input tests live at the VO, not scattered through the
 *     handler's success path;
 *   - PHPStan sees a typed `getName(): string` etc. at the call sites,
 *     not `mixed` from $_POST.
 *
 * Schema (after normalization):
 *
 *   - id              : int >= 0     (0 means "insert new", any positive int means "update")
 *   - name            : string       (sanitize_text_field; '' when absent or non-scalar)
 *   - urlPattern      : string       (wp_unslash; raw payload, regex tested upstream)
 *   - isRegex         : int          (0 or 1; non-scalar treated as 0)
 *   - enabledEngines  : string       (JSON wire string; '[]' when absent / non-scalar)
 *   - priority        : int          (0 when absent or non-numeric)
 *   - status          : int          (0 or 1; default 1 = enabled)
 *
 * Construct via `fromPost($_POST)`. The VO accepts a raw payload array so
 * tests can build a fixture without round-tripping through PHP superglobals.
 */
final class ABJ_404_Solution_EngineProfileSaveRequest {

    /** @var int */
    private $id;

    /** @var string */
    private $name;

    /** @var string */
    private $urlPattern;

    /** @var int */
    private $isRegex;

    /** @var string */
    private $enabledEngines;

    /** @var int */
    private $priority;

    /** @var int */
    private $status;

    private function __construct(
        int $id,
        string $name,
        string $urlPattern,
        int $isRegex,
        string $enabledEngines,
        int $priority,
        int $status
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->urlPattern = $urlPattern;
        $this->isRegex = $isRegex;
        $this->enabledEngines = $enabledEngines;
        $this->priority = $priority;
        $this->status = $status;
    }

    /**
     * Normalize a $_POST payload (or any associative array of the same
     * shape) into a typed VO. Always returns a VO; missing or malformed
     * fields are filled with their documented defaults so callers can rely
     * on the typed accessors without further null checks.
     *
     * Validation of business rules (name non-empty, regex parses) is the
     * caller's job: the VO is the type boundary, not the policy boundary.
     *
     * @param array<mixed, mixed>|null $post
     */
    public static function fromPost($post): self {
        $payload = is_array($post) ? $post : array();

        $id             = self::coerceAbsInt($payload, 'id');
        $name           = self::coerceSanitizedText($payload, 'name');
        $urlPattern     = self::coerceUnslashedString($payload, 'url_pattern');
        $isRegex        = self::coerceBoolInt($payload, 'is_regex', 0);
        $enabledEngines = self::coerceEnabledEnginesJson($payload);
        $priority       = self::coerceInt($payload, 'priority');
        $status         = self::coerceBoolInt($payload, 'status', 1);

        return new self($id, $name, $urlPattern, $isRegex, $enabledEngines, $priority, $status);
    }

    public function getId(): int {
        return $this->id;
    }

    public function getName(): string {
        return $this->name;
    }

    public function getUrlPattern(): string {
        return $this->urlPattern;
    }

    public function isRegex(): bool {
        return $this->isRegex === 1;
    }

    public function getIsRegexInt(): int {
        return $this->isRegex;
    }

    public function getEnabledEnginesJson(): string {
        return $this->enabledEngines;
    }

    public function getPriority(): int {
        return $this->priority;
    }

    public function getStatusInt(): int {
        return $this->status;
    }

    /**
     * True iff the required business fields are populated. The handler
     * uses this to decide whether to early-return with a 400.
     */
    public function hasRequiredFields(): bool {
        return trim($this->name) !== '' && trim($this->urlPattern) !== '';
    }

    /**
     * The exact payload shape `EngineProfileResolver::saveProfile()`
     * expects. The VO is the single source of truth for the wire format
     * between the handler and the persistence layer.
     *
     * @return array{id: int, name: string, url_pattern: string, is_regex: int, enabled_engines: string, priority: int, status: int}
     */
    public function toResolverPayload(): array {
        return array(
            'id'              => $this->id,
            'name'            => $this->name,
            'url_pattern'     => $this->urlPattern,
            'is_regex'        => $this->isRegex,
            'enabled_engines' => $this->enabledEngines,
            'priority'        => $this->priority,
            'status'          => $this->status,
        );
    }

    /**
     * The enabled-engines payload is a JSON string on the wire. Absent or
     * malformed (non-scalar) inputs fall back to the documented default
     * '[]' so the resolver always receives a parseable string.
     *
     * @param array<mixed, mixed> $payload
     */
    private static function coerceEnabledEnginesJson(array $payload): string {
        if (!isset($payload['enabled_engines'])) {
            return '[]';
        }
        $v = $payload['enabled_engines'];
        if (!is_scalar($v)) {
            return '[]';
        }
        $s = (string)$v;
        if (function_exists('wp_unslash')) {
            $u = wp_unslash($s);
            $s = is_string($u) ? $u : $s;
        }
        return $s === '' ? '[]' : $s;
    }

    /**
     * @param array<mixed, mixed> $raw
     */
    private static function coerceAbsInt(array $raw, string $key): int {
        if (!isset($raw[$key])) {
            return 0;
        }
        $v = $raw[$key];
        if (is_int($v)) {
            return $v < 0 ? -$v : $v;
        }
        if (is_float($v)) {
            return abs((int)$v);
        }
        if (is_string($v) && is_numeric($v)) {
            return abs((int)$v);
        }
        if (is_string($v) && function_exists('absint')) {
            return (int)absint($v);
        }
        return 0;
    }

    /**
     * @param array<mixed, mixed> $raw
     */
    private static function coerceInt(array $raw, string $key): int {
        if (!isset($raw[$key])) {
            return 0;
        }
        $v = $raw[$key];
        if (is_int($v)) {
            return $v;
        }
        if (is_float($v)) {
            return (int)$v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (int)$v;
        }
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        return 0;
    }

    /**
     * Returns 0 or 1, never anything else. `$default` is what to return
     * when the key is absent (status defaults to 1 = enabled; is_regex
     * defaults to 0 = literal pattern).
     *
     * @param array<mixed, mixed> $raw
     */
    private static function coerceBoolInt(array $raw, string $key, int $default): int {
        if (!isset($raw[$key])) {
            return $default;
        }
        $v = $raw[$key];
        if (!is_scalar($v)) {
            return 0;
        }
        return (int)(bool)$v;
    }

    /**
     * Strings flow through wp_unslash to undo WP's magic-quotes pretence,
     * then sanitize_text_field for length/control-char hardening. Both
     * functions exist on real WP; in tests we degrade to identity.
     *
     * @param array<mixed, mixed> $raw
     */
    private static function coerceSanitizedText(array $raw, string $key): string {
        $s = self::coerceUnslashedString($raw, $key);
        if (function_exists('sanitize_text_field')) {
            return (string)sanitize_text_field($s);
        }
        return $s;
    }

    /**
     * Pulls a string out of the raw payload, applying wp_unslash if
     * available, and returns '' for missing / non-scalar values.
     *
     * @param array<mixed, mixed> $raw
     */
    private static function coerceUnslashedString(array $raw, string $key): string {
        if (!isset($raw[$key])) {
            return '';
        }
        $v = $raw[$key];
        if (!is_scalar($v)) {
            return '';
        }
        $s = (string)$v;
        if (function_exists('wp_unslash')) {
            $u = wp_unslash($s);
            return is_string($u) ? $u : $s;
        }
        return $s;
    }
}
