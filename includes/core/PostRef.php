<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Typed value object for a WP_Post return from `get_post()` / `get_posts()`.
 *
 * Boundary normalizer (task: type-pressure at module boundaries).
 *
 * WordPress's `get_post()` is typed `WP_Post|array|null` and has a
 * documented escape hatch (`OBJECT_K` / `ARRAY_A` / `ARRAY_N` output modes)
 * that changes the return shape. Even in OBJECT mode (the default), the
 * underlying WP_Post properties are typed as `string|int` in stubs (the
 * raw DB column types) and consumers must shape-probe them before use:
 *
 *   $post = get_post($destId);
 *   if (!is_object($post)) { return false; }
 *   $postStatus = strtolower($post->post_status);
 *
 *   foreach ($posts as $post) {
 *       if (!is_object($post)) { continue; }
 *       $content   = property_exists($post, 'post_content') ? (string)$post->post_content : '';
 *       $postId    = property_exists($post, 'ID')           ? intval($post->ID)            : 0;
 *       $postTitle = property_exists($post, 'post_title')  ? (string)$post->post_title     : '';
 *   }
 *
 * Two consumer shapes for the same boundary, both reinventing their own
 * property-existence probing. This VO collapses them into one:
 *
 *   $ref = ABJ_404_Solution_PostRef::fromWpPost(get_post($destId));
 *   if ($ref === null) { return false; }
 *   if ($ref->isPublished()) { ... }
 *
 * Schema (after normalization):
 *
 *   - id          : int >= 0
 *   - title       : string
 *   - content     : string
 *   - status      : string   (lowercased; '' if absent / non-scalar)
 *   - type        : string   (post_type; '' if absent)
 *   - parentId    : int >= 0
 *
 * Construction accepts WP_Post, an associative array (ARRAY_A output mode
 * or a hand-built fixture), or `null` / non-object input. `fromWpPost`
 * returns `null` when the input cannot be coerced to a usable post
 * reference.
 */
final class ABJ_404_Solution_PostRef {

    /** @var int */
    private $id;

    /** @var string */
    private $title;

    /** @var string */
    private $content;

    /** @var string */
    private $status;

    /** @var string */
    private $type;

    /** @var int */
    private $parentId;

    private function __construct(
        int $id,
        string $title,
        string $content,
        string $status,
        string $type,
        int $parentId
    ) {
        $this->id = $id;
        $this->title = $title;
        $this->content = $content;
        $this->status = $status;
        $this->type = $type;
        $this->parentId = $parentId;
    }

    /**
     * Normalize a `get_post()` return into a typed VO, or null when the
     * input is unrecoverably malformed (not an object or array, or
     * lacking an ID that we can act on).
     *
     * @param mixed $raw
     */
    public static function fromWpPost($raw): ?self {
        if ($raw === null || is_bool($raw)) {
            return null;
        }
        if (is_object($raw)) {
            $id       = self::coerceObjectInt($raw, 'ID');
            if ($id <= 0) {
                return null;
            }
            $title    = self::coerceObjectString($raw, 'post_title');
            $content  = self::coerceObjectString($raw, 'post_content');
            $status   = strtolower(self::coerceObjectString($raw, 'post_status'));
            $type     = self::coerceObjectString($raw, 'post_type');
            $parentId = self::coerceObjectInt($raw, 'post_parent');
            return new self($id, $title, $content, $status, $type, $parentId);
        }
        if (is_array($raw)) {
            $id       = self::coerceArrayInt($raw, 'ID');
            if ($id <= 0) {
                return null;
            }
            $title    = self::coerceArrayString($raw, 'post_title');
            $content  = self::coerceArrayString($raw, 'post_content');
            $status   = strtolower(self::coerceArrayString($raw, 'post_status'));
            $type     = self::coerceArrayString($raw, 'post_type');
            $parentId = self::coerceArrayInt($raw, 'post_parent');
            return new self($id, $title, $content, $status, $type, $parentId);
        }
        return null;
    }

    /**
     * @param array<int, mixed> $rawList
     * @return array<int, self>  Skips inputs that fail normalization.
     */
    public static function fromWpPostList(array $rawList): array {
        $result = array();
        foreach ($rawList as $raw) {
            $vo = self::fromWpPost($raw);
            if ($vo !== null) {
                $result[] = $vo;
            }
        }
        return $result;
    }

    public function getId(): int {
        return $this->id;
    }

    public function getTitle(): string {
        return $this->title;
    }

    public function getContent(): string {
        return $this->content;
    }

    /** Lowercased post_status. '' when absent. */
    public function getStatus(): string {
        return $this->status;
    }

    public function getType(): string {
        return $this->type;
    }

    public function getParentId(): int {
        return $this->parentId;
    }

    /**
     * True iff the post is considered live by `DataAccessTrait_Redirects`:
     * the historical predicate covers both 'publish' and 'published'
     * because some imports / legacy plugins store the alternate spelling.
     */
    public function isPublished(): bool {
        return in_array($this->status, array('publish', 'published'), true);
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
        $v = $obj->{$key};
        return self::scalarToInt($v);
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
