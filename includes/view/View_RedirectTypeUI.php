<?php

if (!defined('ABSPATH')) {
    exit;
}

class ABJ_404_Solution_View_RedirectTypeUI extends ABJ_404_Solution_ViewComponent {

    /**
     * Get a plain-language label for an HTTP redirect code.
     * Used in Simple mode to replace technical numeric codes.
     *
     * @param string $code The numeric redirect code (e.g. '301', '302').
     * @return string Human-readable label.
     */
    public static function getPlainLanguageCodeLabel(string $code): string {
        $labels = array(
            '301' => __('Permanent', '404-solution'),
            '308' => __('Permanent', '404-solution'),
            '302' => __('Temporary', '404-solution'),
            '307' => __('Temporary', '404-solution'),
            '410' => __('Gone', '404-solution'),
            '451' => __('Blocked', '404-solution'),
            '0'   => __('Meta Refresh', '404-solution'),
        );
        return isset($labels[$code]) ? $labels[$code] : $code;
    }

    public function echoRedirectTypeButtonGrid(string $selectedCode): void {
        $mode = abj_service('settings_mode_preference')->getMode();
        $isSimple = $mode === 'simple';

        if ($isSimple) {
            // Simple mode: show only Permanent and Temporary
            $codeButtons = array(
                301 => array(__('Permanent', '404-solution'),  __('Best for moved pages', '404-solution')),
                302 => array(__('Temporary', '404-solution'),  __('Best for seasonal or test pages', '404-solution')),
            );
        } else {
            $codeButtons = array(
                301 => array(__('301', '404-solution'),          __('Permanent', '404-solution')),
                302 => array(__('302', '404-solution'),          __('Temporary', '404-solution')),
                307 => array(__('307', '404-solution'),          __('Temp, method-safe', '404-solution')),
                308 => array(__('308', '404-solution'),          __('Perm, method-safe', '404-solution')),
                410 => array(__('410', '404-solution'),          __('Gone', '404-solution')),
                451 => array(__('451', '404-solution'),          __('Legal reasons', '404-solution')),
                0   => array(__('Meta Refresh', '404-solution'), __('HTTP 200 + meta tag', '404-solution')),
            );
        }

        $buttonTemplate = $this->readTemplate('redirectTypeButton.html');
        $buttons = '';
        foreach ($codeButtons as $code => $labels) {
            $isActive = ((string)$code === $selectedCode) ? ' abj404-redirect-type-btn--active' : '';
            $isFull   = ($code === 0) ? ' abj404-redirect-type-btn--full' : '';
            $buttons .= $this->replaceTemplate($buttonTemplate, array(
                '{active_class}'    => esc_attr($isActive),
                '{full_class}'      => esc_attr($isFull),
                '{code}'            => esc_attr((string)$code),
                '{primary_label}'   => esc_html($labels[0]),
                '{secondary_label}' => esc_html($labels[1]),
            ));
        }
        if ($isSimple) {
            $help = esc_html__('Permanent is best for most redirects. Use Temporary if the page may come back.', '404-solution');
        } else {
            $help = esc_html__('Use 301 for permanent page moves. Use 302 for A/B tests or seasonal pages.', '404-solution');
        }

        echo $this->replaceTemplate($this->readTemplate('redirectTypeButtonGrid.html'), array(
            '{label}'         => esc_html__('Redirect Type', '404-solution'),
            '{selected_code}' => esc_attr($selectedCode),
            '{buttons}'       => $buttons,
            '{help}'          => $help,
            '{script}'        => $this->readTemplate('redirectTypeScript.html'),
        ));
    }

    /**
     * @param string $name
     * @return string
     */
    private function readTemplate(string $name): string {
        return ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/' . $name, false);
    }

    /**
     * @param string $template
     * @param array<string, string> $replacements
     * @return string
     */
    private function replaceTemplate(string $template, array $replacements): string {
        return $this->f->str_replace(array_keys($replacements), array_values($replacements), $template);
    }
}
