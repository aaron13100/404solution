<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Value object representing a successful match from a matching engine.
 *
 * Immutable after construction. Provides toLegacyArray() for backward compatibility
 * with code that expects the old associative array shape.
 */
class ABJ_404_Solution_MatchResult {

    /** @var string */
    private $id;

    /** @var string */
    private $type;

    /** @var string */
    private $link;

    /** @var string */
    private $title;

    /** @var float */
    private $score;

    /** @var string */
    private $engineName;

    /**
     * @param string $id        Post/page ID
     * @param string $type      Content type (ABJ404_TYPE_POST, etc.)
     * @param string $link      Permalink URL
     * @param string $title     Post/page title
     * @param float  $score     Match confidence score
     * @param string $engineName Name of the engine that produced this result
     */
    public function __construct(
        string $id,
        string $type,
        string $link,
        string $title,
        float $score,
        string $engineName
    ) {
        $this->id = $id;
        $this->type = $type;
        $this->link = $link;
        $this->title = $title;
        $this->score = $score;
        $this->engineName = $engineName;
    }

    /** @return string */
    public function getId(): string {
        return $this->id;
    }

    /** @return string */
    public function getType(): string {
        return $this->type;
    }

    /** @return string */
    public function getLink(): string {
        return $this->link;
    }

    /** @return string */
    public function getTitle(): string {
        return $this->title;
    }

    /** @return float */
    public function getScore(): float {
        return $this->score;
    }

    /** @return string */
    public function getEngineName(): string {
        return $this->engineName;
    }

    /**
     * Convert to the legacy associative array used by existing pipeline code.
     *
     * @return array{id: string, type: string, link: string, title: string, score: float, engine: string}
     */
    public function toLegacyArray(): array {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'link' => $this->link,
            'title' => $this->title,
            'score' => $this->score,
            'engine' => $this->engineName,
        ];
    }
}
