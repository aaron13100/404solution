<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Defines the Page Redirects list-table columns and header metadata.
 */
class ABJ_404_Solution_RedirectsTableColumns {

    /**
     * @param array<string, mixed> $tableOptions
     * @return array<string, array<string, string>>
     */
    public function build(array $tableOptions): array {
        return array(
            'url' => array(
                'title' => __('URL', '404-solution'),
                'orderby' => 'url',
                'width' => '27%',
            ),
            'status' => array(
                'title' => __('Status', '404-solution'),
                'orderby' => 'status',
                'width' => '5%',
            ),
            'type' => array(
                'title' => __('Type', '404-solution'),
                'orderby' => 'type',
                'width' => '10%',
            ),
            'dest' => array(
                'title' => __('Destination', '404-solution'),
                'orderby' => 'final_dest',
                'width' => '22%',
            ),
            'code' => array(
                'title' => __('Redirect', '404-solution'),
                'orderby' => 'code',
                'width' => '5%',
            ),
            'confidence' => array(
                'title' => __('Confidence', '404-solution'),
                'orderby' => 'score',
                'width' => '7%',
                'class' => 'hide-on-tablet',
            ),
            'hits' => array(
                'title' => __('Hits', '404-solution'),
                'orderby' => 'logshits',
                'width' => '5%',
            ),
            'timestamp' => array(
                'title' => __('Created', '404-solution'),
                'orderby' => 'timestamp',
                'width' => '10%',
                'class' => 'hide-on-tablet',
            ),
            'last_used' => array(
                'title' => __('Last Used', '404-solution'),
                'orderby' => 'last_used',
                'width' => '10%',
            ),
        );
    }
}
