<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decides which destination warning, if any, a redirect table row should show.
 */
class ABJ_404_Solution_RedirectDestinationWarningPolicy {

    /**
     * @param array<string, mixed> $row
     * @param mixed $rowType
     * @param string $rowFinalDest
     * @param string $destForView
     * @param bool $destinationIsMissing
     * @param array<mixed> $deadDestIds
     * @return array{exists: string, notExists: string, text: string, destForView: string}
     */
    public function resolve(array $row, $rowType, string $rowFinalDest, string $destForView,
            bool $destinationIsMissing, array $deadDestIds): array {
        $exists = '';
        $notExists = 'display: none;';
        $text = __("This page doesn't exist or is not published so the redirect won't work.", '404-solution');

        if ($destinationIsMissing) {
            $exists = 'display: none;';
            $notExists = '';
            $text = __('Destination missing. Edit this redirect and choose a destination.', '404-solution');
            if (trim((string)$destForView) === '') {
                $destForView = __('(Destination missing)', '404-solution');
            }
        }

        if (array_key_exists('published_status', $row) && $row['published_status'] == '0') {
            $exists = 'display: none;';
            $notExists = '';
            if (trim((string)$destForView) === '') {
                $destForView = __('(Destination unavailable)', '404-solution');
            }
        }

        $rowIdStr = is_scalar($row['id'] ?? '') ? (string)($row['id'] ?? '') : '';
        if (in_array($rowIdStr, $deadDestIds, true)) {
            $exists = 'display: none;';
            $notExists = '';
            $text = __('Destination returned 404 recently, redirect suspended until destination is restored.', '404-solution');
        }

        return array('exists' => $exists, 'notExists' => $notExists, 'text' => $text, 'destForView' => $destForView);
    }
}
