<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Typed value object for the suggestion-display slice of the `abj404_settings`
 * WordPress option.
 *
 * Boundary normalizer (task: type-pressure at module boundaries).
 *
 * The full `abj404_settings` option is a wide heterogeneous bag (60+ keys
 * with mixed string/int types and historical defaults). Wrapping the
 * whole option in one VO would be premature and high-churn. Instead, this
 * VO focuses on the slice used by the suggestion-display pipeline, which
 * is read in at least three sites:
 *
 *   - `FrontendRequestPipeline::dispatchRedirect` reads suggest_cats /
 *     suggest_tags to feed SpellChecker::findMatchingPosts.
 *   - `ShortCode::renderSuggestionsShortcode` reads suggest_max,
 *     suggest_minscore, suggest_minscore_enabled to filter rendered hits.
 *   - `ViewTrait_Settings` reads the same fields to render the admin form.
 *
 * Before this VO, each site re-implemented its own shape probing:
 *
 *   $suggestCats = isset($options['suggest_cats']) && is_string($options['suggest_cats']) ? $options['suggest_cats'] : '1';
 *   $suggestTags = isset($options['suggest_tags']) && is_string($options['suggest_tags']) ? $options['suggest_tags'] : '1';
 *
 * The fields are stored as the strings '0' and '1' (legacy WP option
 * serialization), so consumers that wanted a bool had to also cast.
 * The VO surfaces both the legacy string form (for the rendering path
 * that echoes the value back into form HTML) and a typed bool.
 *
 * Defaults match `PluginLogic::getDefaultOptions()` so a missing key
 * is treated identically to the documented default.
 */
final class ABJ_404_Solution_SuggestionDisplayOptions {

    /** @var string */
    private $suggestCats;

    /** @var string */
    private $suggestTags;

    /** @var int */
    private $suggestMax;

    /** @var int */
    private $suggestMinscore;

    /** @var string */
    private $suggestMinscoreEnabled;

    /** @var string */
    private $suggestTitle;

    /** @var string */
    private $suggestBefore;

    /** @var string */
    private $suggestAfter;

    /** @var string */
    private $suggestEntryBefore;

    /** @var string */
    private $suggestEntryAfter;

    /** @var string */
    private $suggestNoResults;

    private function __construct(
        string $suggestCats,
        string $suggestTags,
        int $suggestMax,
        int $suggestMinscore,
        string $suggestMinscoreEnabled,
        string $suggestTitle,
        string $suggestBefore,
        string $suggestAfter,
        string $suggestEntryBefore,
        string $suggestEntryAfter,
        string $suggestNoResults
    ) {
        $this->suggestCats = $suggestCats;
        $this->suggestTags = $suggestTags;
        $this->suggestMax = $suggestMax;
        $this->suggestMinscore = $suggestMinscore;
        $this->suggestMinscoreEnabled = $suggestMinscoreEnabled;
        $this->suggestTitle = $suggestTitle;
        $this->suggestBefore = $suggestBefore;
        $this->suggestAfter = $suggestAfter;
        $this->suggestEntryBefore = $suggestEntryBefore;
        $this->suggestEntryAfter = $suggestEntryAfter;
        $this->suggestNoResults = $suggestNoResults;
    }

    /**
     * Normalize the raw `get_option('abj404_settings')` array (or any
     * compatible payload) into a typed VO. A non-array input falls back
     * to the documented defaults, matching the behaviour of
     * `PluginLogic::getOptions()` when the option row is missing.
     *
     * @param mixed $raw
     */
    public static function fromOptionsArray($raw): self {
        $options = is_array($raw) ? $raw : array();

        return new self(
            self::coerceBoolString($options, 'suggest_cats', '1'),
            self::coerceBoolString($options, 'suggest_tags', '1'),
            self::coercePositiveInt($options, 'suggest_max', 5),
            self::coerceNonNegativeInt($options, 'suggest_minscore', 25),
            self::coerceBoolString($options, 'suggest_minscore_enabled', '0'),
            self::coerceString($options, 'suggest_title', '<h3>{suggest_title_text}</h3>'),
            self::coerceString($options, 'suggest_before', '<ol>'),
            self::coerceString($options, 'suggest_after', '</ol>'),
            self::coerceString($options, 'suggest_entrybefore', '<li>'),
            self::coerceString($options, 'suggest_entryafter', '</li>'),
            self::coerceString($options, 'suggest_noresults', '<p>{suggest_noresults_text}</p>')
        );
    }

    /**
     * Legacy '0'/'1' string. Consumers that echo into form HTML or pass
     * to SpellChecker (which expects the legacy shape) want this.
     */
    public function getSuggestCatsString(): string {
        return $this->suggestCats;
    }

    public function getSuggestTagsString(): string {
        return $this->suggestTags;
    }

    public function shouldSuggestCategories(): bool {
        return $this->suggestCats === '1';
    }

    public function shouldSuggestTags(): bool {
        return $this->suggestTags === '1';
    }

    public function getSuggestMax(): int {
        return $this->suggestMax;
    }

    public function getSuggestMinscore(): int {
        return $this->suggestMinscore;
    }

    public function isMinscoreEnabled(): bool {
        return $this->suggestMinscoreEnabled === '1';
    }

    public function getSuggestTitleHtml(): string {
        return $this->suggestTitle;
    }

    public function getSuggestBeforeHtml(): string {
        return $this->suggestBefore;
    }

    public function getSuggestAfterHtml(): string {
        return $this->suggestAfter;
    }

    public function getSuggestEntryBeforeHtml(): string {
        return $this->suggestEntryBefore;
    }

    public function getSuggestEntryAfterHtml(): string {
        return $this->suggestEntryAfter;
    }

    public function getSuggestNoResultsHtml(): string {
        return $this->suggestNoResults;
    }

    /**
     * @param array<mixed, mixed> $options
     */
    private static function coerceString(array $options, string $key, string $default): string {
        if (!isset($options[$key])) {
            return $default;
        }
        $v = $options[$key];
        if (is_string($v)) {
            return $v;
        }
        if (is_scalar($v)) {
            return (string)$v;
        }
        return $default;
    }

    /**
     * Legacy boolean stored as '0' / '1'. Coerces other truthy/falsy
     * scalar shapes (numeric int, bool) into the canonical string.
     *
     * @param array<mixed, mixed> $options
     */
    private static function coerceBoolString(array $options, string $key, string $default): string {
        if (!isset($options[$key])) {
            return $default;
        }
        $v = $options[$key];
        if (is_string($v)) {
            return $v === '1' ? '1' : '0';
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_int($v) || is_float($v)) {
            return ((int)$v) === 1 ? '1' : '0';
        }
        return $default;
    }

    /**
     * @param array<mixed, mixed> $options
     */
    private static function coerceNonNegativeInt(array $options, string $key, int $default): int {
        if (!isset($options[$key])) {
            return $default;
        }
        $v = $options[$key];
        if (is_int($v)) {
            return $v < 0 ? $default : $v;
        }
        if (is_float($v)) {
            $i = (int)$v;
            return $i < 0 ? $default : $i;
        }
        if (is_string($v) && is_numeric($v)) {
            $i = (int)$v;
            return $i < 0 ? $default : $i;
        }
        return $default;
    }

    /**
     * suggest_max must be >= 1; a 0 or negative would render no
     * suggestions at all and is treated as a malformed override.
     *
     * @param array<mixed, mixed> $options
     */
    private static function coercePositiveInt(array $options, string $key, int $default): int {
        $i = self::coerceNonNegativeInt($options, $key, $default);
        return $i < 1 ? $default : $i;
    }
}
