<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Presents the admin-only explanation block that sits below the front-end
 * suggestion list.
 *
 * What an admin testing the plugin used to see on a 404 that did not clear
 * auto_score was a list of pages with a bare number in brackets beside each
 * one, and nothing else: no statement of what the number is measured against,
 * what the bar is, or where the bar lives. That is the whole of uninstall
 * report 57 ("it just sent the visitor to a page with a suggested link") --
 * the plugin was working exactly as designed and the admin had no way to
 * learn the knob existed.
 *
 * This block names the score, names the bar in force, and offers both
 * remedies: redirect this one URL by hand, or change the score an automatic
 * redirect has to clear. It renders only for plugin admins (the same gate
 * that already controls the inline score), so no visitor ever sees it.
 *
 * // allow-no-test-found: exercised by SuggestionsPageAdminNoteTest
 */
class ABJ_404_Solution_ShortcodeSuggestionsAdminNotePresenter {

    /** Stylesheet handle shared with the async-suggestions loading skeleton. */
    const STYLE_HANDLE = 'abj404-suggestions-loading';

    /** The id of the auto_score field on the options page. */
    const SCORE_FIELD_ANCHOR = 'auto_score';

    /**
     * Render the block.
     *
     * @param bool $hasMatches Whether any suggestion was actually displayed.
     * @param float $bestScore Highest score among the displayed suggestions.
     *   Ignored when $hasMatches is false: there is no score to report, so the
     *   no-match wording never quotes one.
     * @param string $requestedURL The URL that 404'd, used to pre-fill the
     *   manual redirect form. May be empty, in which case the link still
     *   works and simply opens an empty form.
     * @param array<string, mixed> $options The plugin options.
     * @return string
     */
    public function render(bool $hasMatches, float $bestScore, string $requestedURL, array $options): string {
        $this->enqueueStyles();

        $redirectUrl = $this->manualRedirectUrl($requestedURL, $options);
        $redirectLabel = esc_html__('Redirect this URL', '404-solution');

        if (!$hasMatches) {
            return $this->fillTemplate('shortcodeSuggestionsAdminNoteNoMatch.html', array(
                'state_line' => esc_html__(
                    '404 Solution, admin only: nothing on the site scored close enough to suggest.',
                    '404-solution'),
                'redirect_url' => esc_url($redirectUrl),
                'redirect_label' => $redirectLabel,
            ));
        }

        return $this->fillTemplate('shortcodeSuggestionsAdminNote.html', array(
            'state_line' => esc_html($this->stateLine($bestScore, $options)),
            'redirect_url' => esc_url($redirectUrl),
            'redirect_label' => $redirectLabel,
            'score_url' => esc_url(
                ABJ_404_Solution_SettingsModeDeepLink::urlForAdvancedSetting(self::SCORE_FIELD_ANCHOR, $options)),
            'score_label' => esc_html__('Change the minimum score', '404-solution'),
        ));
    }

    /**
     * The sentence that states where this 404 landed relative to the bar.
     *
     * Two wordings rather than one, because a match can sit at or above the
     * bar and still show suggestions (automatic redirects turned off, the
     * best match excluded from redirects, the match being the current page).
     * Telling that admin the score was "under" the bar would be false on
     * screen, so the clause flips and the sentence stays true either way.
     *
     * @param float $bestScore
     * @param array<string, mixed> $options
     * @return string
     */
    private function stateLine(float $bestScore, array $options): string {
        $threshold = ABJ_404_Solution_MinimumAutoRedirectScore::forDisplay($options);
        $thresholdValue = ABJ_404_Solution_MinimumAutoRedirectScore::asFloat($options);
        $scoreText = $this->scoreText(array(
            'score' => $bestScore,
            'threshold' => $thresholdValue,
        ));

        if ($bestScore < $thresholdValue) {
            return sprintf(
                /* translators: 1: best match score, 2: the configured minimum score. */
                __('404 Solution, admin only: best match %1$s, under the %2$s needed to redirect automatically.',
                    '404-solution'),
                $scoreText, $threshold);
        }

        return sprintf(
            /* translators: 1: best match score, 2: the configured minimum score. */
            __('404 Solution, admin only: best match %1$s, at or above the %2$s needed to redirect automatically.',
                '404-solution'),
            $scoreText, $threshold);
    }

    /**
     * The best score, written at a precision that cannot contradict the clause
     * printed beside it.
     *
     * The suggestion rows show two decimals, so that is where this starts. But
     * rounding a score to two decimals can move it ACROSS the bar: a match of
     * 76.9999 against a bar of 77 did not redirect, and is displayed by a plain
     * number_format() as "77.00", producing "best match 77.00, under the 77
     * needed to redirect automatically" -- a sentence its own number disproves,
     * on the one screen whose entire job is to explain that number. Flipping the
     * clause instead would be worse: it would tell the admin the match cleared a
     * bar it did not clear, and leave them hunting for why no redirect happened.
     *
     * So the precision grows until the number as WRITTEN sits on the same side of
     * the bar as the number as MEASURED. Any score not within a rounding error of
     * the bar -- which is very nearly all of them -- comes back at two decimals,
     * exactly as the row above it reads.
     *
     * @param array{score: float, threshold: float} $comparison The best score
     *        and the score an automatic redirect has to clear.
     * @return string
     */
    private function scoreText(array $comparison): string {
        $score = $comparison['score'];
        $threshold = $comparison['threshold'];
        $isUnder = $score < $threshold;

        for ($decimals = 2; $decimals < 10; $decimals++) {
            $text = number_format($score, $decimals);
            if (((float)str_replace(',', '', $text) < $threshold) === $isUnder) {
                return $text;
            }
        }

        return number_format($score, 10);
    }

    /**
     * Link to the Page Redirects screen with the Add Manual Redirect form
     * open and this URL already filled in.
     *
     * @param string $requestedURL
     * @param array<string, mixed> $options
     * @return string
     */
    private function manualRedirectUrl(string $requestedURL, array $options): string {
        $args = array();
        $path = $this->requestedPath($requestedURL);
        if ($path !== '') {
            $args['abj404_add_url'] = $path;
        }
        return ABJ_404_Solution_AdminPageUrlBuilder::subpageUrl('abj404_redirects', $args, $options);
    }

    /**
     * The site-relative path of the 404'd request, which is the form the
     * manual redirect form stores. Query string and fragment are dropped:
     * a redirect rule matches on the path.
     *
     * @param string $requestedURL
     * @return string Empty when no usable path can be read.
     */
    private function requestedPath(string $requestedURL): string {
        if ($requestedURL === '') {
            return '';
        }
        $path = parse_url($requestedURL, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '';
        }
        return $path[0] === '/' ? $path : '/' . $path;
    }

    /**
     * Make sure the front-end suggestions stylesheet is on the page.
     *
     * Idempotent, and a no-op in the async path, where the placeholder render
     * has already enqueued it (an AJAX response cannot enqueue anything: its
     * HTML is injected into a page whose head has long since been sent).
     *
     * @return void
     */
    private function enqueueStyles(): void {
        if (!function_exists('wp_enqueue_style') || !defined('ABJ404_URL')) {
            return;
        }
        wp_enqueue_style(self::STYLE_HANDLE,
            ABJ404_URL . 'includes/css/suggestions-loading.css', array(), ABJ404_VERSION);
    }

    /**
     * @param string $name
     * @param array<string, string> $vars
     * @return string
     */
    private function fillTemplate(string $name, array $vars): string {
        $template = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/' . $name, false);
        $search = [];
        $replace = [];
        foreach ($vars as $key => $value) {
            $search[] = '{' . $key . '}';
            $replace[] = $value;
        }
        return str_replace($search, $replace, (string)$template);
    }
}
