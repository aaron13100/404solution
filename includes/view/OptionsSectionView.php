<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parameter object for the collapsible admin options-section renderer,
 * {@see ABJ_404_Solution_View_AdminChrome::echoOptionsSection()}.
 *
 * Replaces a 7-positional-parameter method signature (criterion 220
 * Interface Size). Plain data carrier read directly by the consumer.
 */
// allow-no-test-found: pure parameter-object DTO (typed public fields, constructor-only assignment, no behavior); fields consumed by View_AdminChrome::echoOptionsSection(), exercised when the stats/options page renders in ViewStatsPageTest and StatsRefreshWiringTest.
class ABJ_404_Solution_OptionsSectionView {

    /** @var string */
    public string $sectionId;

    /** @var string */
    public string $postboxId;

    /** @var string */
    public string $title;

    /** @var string */
    public string $content;

    /** @var bool */
    public bool $initiallyVisible;

    /** @var string */
    public string $icon;

    /** @var string */
    public string $badge;

    /**
     * @param string $sectionId
     * @param string $postboxId
     * @param string $title
     * @param string $content
     * @param bool $initiallyVisible
     * @param string $icon
     * @param string $badge
     */
    public function __construct(string $sectionId, string $postboxId, string $title, string $content,
            bool $initiallyVisible = false, string $icon = '', string $badge = '') {
        $this->sectionId = $sectionId;
        $this->postboxId = $postboxId;
        $this->title = $title;
        $this->content = $content;
        $this->initiallyVisible = $initiallyVisible;
        $this->icon = $icon;
        $this->badge = $badge;
    }
}
