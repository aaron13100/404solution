<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Translates and classifies pipeline trace labels for admin display.
 */
class ABJ_404_Solution_AdminTraceLabels {

    /**
     * Translate a known pipeline-trace string for admin display.
     *
     * @param string $text English text stored in the trace.
     * @return string Translated text, or the original text for dynamic values.
     */
    public function translate(string $text): string {
        $map = $this->translationMap();

        if (isset($map[$text])) {
            return $map[$text];
        }
        if (strpos($text, 'Engine: ') === 0) {
            return sprintf(__('Engine: %s', '404-solution'), substr($text, 8));
        }
        if (preg_match('/^Redirected \((\d+)\)$/', $text, $m)) {
            return sprintf(__('Redirected (%s)', '404-solution'), $m[1]);
        }
        if (preg_match('/^Responded with (.+)$/', $text, $m)) {
            return sprintf(__('Responded with %s', '404-solution'), $m[1]);
        }
        if (preg_match('/^Showed 404 page — (.+)$/', $text, $m)) {
            return sprintf(__('Showed 404 page — %s', '404-solution'), $m[1]);
        }

        return $text;
    }

    /** @return array<string, string> */
    private function translationMap(): array {
        static $map = null;
        if ($map === null) {
            $map = [
                'Ignore list'                       => __('Ignore list', '404-solution'),
                'Redirect lookup'                   => __('Redirect lookup', '404-solution'),
                'Redirect lookup (without comments)' => __('Redirect lookup (without comments)', '404-solution'),
                'Conditions'                        => __('Conditions', '404-solution'),
                'Conditions (without comments)'     => __('Conditions (without comments)', '404-solution'),
                'Health check'                      => __('Health check', '404-solution'),
                'Health check (without comments)'   => __('Health check (without comments)', '404-solution'),
                'Regex rules'                       => __('Regex rules', '404-solution'),
                'Suggestion engines'                => __('Suggestion engines', '404-solution'),
                'Result'                            => __('Result', '404-solution'),
                'Not ignored'                       => __('Not ignored', '404-solution'),
                'Matched — request ignored'         => __('Matched — request ignored', '404-solution'),
                'Found existing redirect'           => __('Found existing redirect', '404-solution'),
                'No matching redirect'              => __('No matching redirect', '404-solution'),
                'All conditions met'                => __('All conditions met', '404-solution'),
                'Blocked by conditions'             => __('Blocked by conditions', '404-solution'),
                'Destination unreachable — skipped' => __('Destination unreachable — skipped', '404-solution'),
                'Matched'                           => __('Matched', '404-solution'),
                'No match'                          => __('No match', '404-solution'),
                'Skipped'                           => __('Skipped', '404-solution'),
                'Excluded'                          => __('Excluded', '404-solution'),
                'Error'                             => __('Error', '404-solution'),
                'No match found'                    => __('No match found', '404-solution'),
                'Showed 404 page'                   => __('Showed 404 page', '404-solution'),
                'Redirected to external URL'        => __('Redirected to external URL', '404-solution'),
                'No redirect — showed 404 page'     => __('No redirect — showed 404 page', '404-solution'),
            ];
        }
        return $map;
    }

    /**
     * @param string $outcome
     * @return string CSS class for the trace outcome badge.
     */
    public function outcomeClass(string $outcome): string {
        $lower = strtolower($outcome);
        if (strpos($lower, 'blocked') !== false || strpos($lower, 'unreachable') !== false
            || strpos($lower, 'error') !== false || strpos($lower, 'ignored') !== false
            || strpos($lower, 'missing') !== false || strpos($lower, 'invalid') !== false) {
            return 'abj404-trace-outcome-fail';
        }
        if (strpos($lower, 'redirected') !== false || strpos($lower, 'responded') !== false
            || strpos($lower, 'showed 404') !== false || strpos($lower, 'no redirect') !== false) {
            return 'abj404-trace-outcome-result';
        }
        if (strpos($lower, 'found') !== false || strpos($lower, 'matched') !== false
            || strpos($lower, 'passed') !== false || strpos($lower, 'not ignored') !== false
            || strpos($lower, 'all conditions met') !== false) {
            return 'abj404-trace-outcome-pass';
        }

        return 'abj404-trace-outcome-neutral';
    }
}
