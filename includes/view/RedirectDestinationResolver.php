<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves the edit-form destination view-model from a stored redirect row.
 *
 * This is the form's data-prep step, deliberately kept separate from
 * {@see ABJ_404_Solution_RedirectEditFormPresenter}, which only renders HTML
 * templates. Given a redirect row and the plugin options it computes the three
 * values the edit form needs to seed its controls:
 *
 *   - final         the external destination string (only for external redirects)
 *   - pageIDAndType the "{id}|{type}" composite key the redirect-to dropdown uses
 *   - codeSelected  the redirect HTTP code, falling back to the configured default
 *
 * It encodes redirect-type rules (external vs. page vs. 404-displayed) and the
 * default-code fallback, so it is pure, dependency-free, and independently
 * testable.
 */
class ABJ_404_Solution_RedirectDestinationResolver {

    /**
     * Resolve final destination, pageIDAndType, and redirect code from a redirect row.
     *
     * @param array<string, mixed> $redirect
     * @param array<string, mixed> $options
     * @return array{final: string, pageIDAndType: string, codeSelected: string}
     */
    public function resolveRedirectDestinationInfo(array $redirect, array $options): array {
        $final = "";
        $pageIDAndType = "";
        $redirectTypeRaw = $redirect['type'] ?? '';
        $redirectType = is_scalar($redirectTypeRaw) ? (string)$redirectTypeRaw : '';
        $redirectFinalDestRaw = $redirect['final_dest'] ?? 0;
        $redirectFinalDest = is_scalar($redirectFinalDestRaw) ? (string)$redirectFinalDestRaw : '0';
        if ($redirectType === (string)ABJ404_TYPE_EXTERNAL) {
            $final = $redirectFinalDest;
            $pageIDAndType = ABJ404_TYPE_EXTERNAL . "|" . ABJ404_TYPE_EXTERNAL;
        } else if ($redirectFinalDest != 0) {
            $pageIDAndType = $redirectFinalDest . "|" . $redirectType;
        } else if ($redirectType === (string)ABJ404_TYPE_404_DISPLAYED) {
            $pageIDAndType = ABJ404_TYPE_404_DISPLAYED . "|" . ABJ404_TYPE_404_DISPLAYED;
        }

        $rawCode = $redirect['code'] ?? '';
        if ($rawCode == "") {
            $rawDefault = $options['default_redirect'] ?? '301';
            $codeSelected = is_string($rawDefault) ? $rawDefault : '301';
        } else {
            $codeSelected = is_string($rawCode) ? $rawCode : '301';
        }

        return array('final' => $final, 'pageIDAndType' => $pageIDAndType, 'codeSelected' => $codeSelected);
    }
}
