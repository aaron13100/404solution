<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders the "Linked from" expandable internal-source-evidence panel for a
 * captured 404 URL row: looks up which published pages/posts link to a
 * visitor-visible 404 URL, and builds the trigger button + expandable panel
 * HTML for each row. Extracted from View_CapturedURLsTable because internal
 * source-evidence lookup and rendering is a distinct responsibility from the
 * rest of the row's presentation formatting.
 */
class ABJ_404_Solution_CapturedSourceEvidenceRenderer {

    /** @var ABJ_404_Solution_Functions */
    private $f;

    public function __construct(ABJ_404_Solution_Functions $functions) {
        $this->f = $functions;
    }

    private function tpl(string $name): string {
        $raw = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/' . $name, false);
        return rtrim((string)$raw, "\n");
    }

    /** @param array<string,string> $vars */
    private function fillTpl(string $name, array $vars): string {
        return (string)$this->f->str_replace(array_keys($vars), array_values($vars), $this->tpl($name));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    public function evidenceByVisibleUrl(array $rows): array {
        $urls = array();
        foreach ($rows as $row) {
            if (isset($row['url']) && is_string($row['url']) && $row['url'] !== '') {
                $urls[] = $row['url'];
            }
        }
        if (empty($urls)) {
            return array();
        }

        $repo = $this->internalSourceEvidenceRepository();
        if (!is_object($repo) || !method_exists($repo, 'getEvidenceForCapturedUrls')) {
            return array();
        }
        $evidence = $repo->getEvidenceForCapturedUrls($urls, 5);
        if (!is_array($evidence)) {
            return array();
        }

        $typedEvidence = array();
        foreach ($evidence as $url => $rowEvidence) {
            if (is_string($url) && is_array($rowEvidence)) {
                $typedEvidence[$url] = $rowEvidence;
            }
        }
        return $typedEvidence;
    }

    /** @return mixed */
    private function internalSourceEvidenceRepository() {
        return abj_service('internal_source_evidence_repository');
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array{trigger:string,panel:string}
     */
    public function htmlFor(string $rowId, array $evidence): array {
        $sourceCount = isset($evidence['source_count']) && is_numeric($evidence['source_count'])
            ? (int)$evidence['source_count'] : 0;
        $sources = isset($evidence['sources']) && is_array($evidence['sources']) ? $evidence['sources'] : array();
        if ($sourceCount <= 0 || empty($sources)) {
            return array('trigger' => '', 'panel' => '');
        }

        $panelId = 'abj404-source-panel-' . sanitize_key($rowId);
        $trigger = $this->fillTpl('capturedSourceTrigger.html', array(
            '{panel_id}' => esc_attr($panelId),
            '{row_id}' => esc_attr($rowId),
            '{trigger_label}' => esc_html(sprintf(__('Sources (%d)', '404-solution'), $sourceCount)),
        ));

        $sourceRows = '';
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            $sourceRows .= $this->sourceRowHtml($source);
        }
        if ($sourceRows === '') {
            return array('trigger' => '', 'panel' => '');
        }

        $displayedCount = isset($evidence['displayed_source_count']) && is_numeric($evidence['displayed_source_count'])
            ? (int)$evidence['displayed_source_count'] : count($sources);
        $truncation = '';
        if ($sourceCount > $displayedCount) {
            $truncation = $this->fillTpl('capturedSourceTruncation.html', array(
                '{truncation_text}' => esc_html(sprintf(__('Showing %1$d of %2$d sources.', '404-solution'), $displayedCount, $sourceCount)),
            ));
        }

        $panel = $this->fillTpl('capturedSourcesPanel.html', array(
            '{panel_id}' => esc_attr($panelId),
            '{heading}' => esc_html__('Linked from', '404-solution'),
            '{source_rows}' => $sourceRows,
            '{truncation}' => $truncation,
        ));

        return array('trigger' => $trigger, 'panel' => $panel);
    }

    /** @param array<array-key, mixed> $source */
    private function sourceRowHtml(array $source): string {
        $title = isset($source['post_title']) && is_scalar($source['post_title']) ? trim((string)$source['post_title']) : '';
        $referrerUrl = isset($source['referrer_url']) && is_scalar($source['referrer_url']) ? (string)$source['referrer_url'] : '';
        $label = $title !== '' ? $title : $referrerUrl;
        $postId = isset($source['post_id']) && is_numeric($source['post_id']) ? (int)$source['post_id'] : 0;

        return $this->fillTpl('capturedSourcesRow.html', array(
            '{source_label}' => esc_html($label),
            '{hit_count}' => esc_html((string)(isset($source['hit_count']) && is_numeric($source['hit_count'])
                ? (int)$source['hit_count'] : 0)),
            '{edit_link}' => $this->editLinkHtml($postId),
        ));
    }

    /**
     * Builds the "Edit" admin link for a resolved source post, gated on the
     * current user's edit capability for that specific post.
     *
     * Authorization (current_user_can()) and edit-link construction
     * (get_edit_post_link()) live here -- the caller that renders
     * source-evidence rows -- rather than in
     * ABJ_404_Solution_InternalSourceEvidenceRepository, which is a
     * data-access repository and must return only raw post_id/post_title
     * data (CLAUDE.md "Strict layer separation"; c308).
     */
    private function editLinkHtml(int $postId): string {
        if ($postId <= 0) {
            return '';
        }
        if (!function_exists('current_user_can') || !current_user_can('edit_post', $postId)) {
            return '';
        }
        if (!function_exists('get_edit_post_link')) {
            return '';
        }
        $editUrl = (string)get_edit_post_link($postId);
        if ($editUrl === '') {
            return '';
        }

        return $this->fillTpl('capturedSourceEditLink.html', array(
            '{edit_url}' => esc_url($editUrl),
            '{edit_label}' => esc_html__('Edit', '404-solution'),
        ));
    }
}
