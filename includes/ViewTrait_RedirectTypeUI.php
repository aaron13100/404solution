<?php

if (!defined('ABSPATH')) {
    exit;
}

trait ViewTrait_RedirectTypeUI {

    /**
     * Get a plain-language label for an HTTP redirect code.
     * Used in Simple mode to replace technical numeric codes.
     *
     * @param string $code The numeric redirect code (e.g. '301', '302').
     * @return string Human-readable label.
     */
    private static function getPlainLanguageCodeLabel(string $code): string {
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

    private function echoRedirectTypeButtonGrid(string $selectedCode): void {
        $isSimple = $this->logic->getSettingsMode() === 'simple';

        echo '<div class="abj404-form-group">';
        echo '<label class="abj404-form-label">' . esc_html__('Redirect Type', '404-solution') . '</label>';
        echo '<input type="hidden" id="code" name="code" value="' . esc_attr($selectedCode) . '">';
        echo '<div class="abj404-redirect-type-grid">';

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

        foreach ($codeButtons as $code => $labels) {
            $isActive = ((string)$code === $selectedCode) ? ' abj404-redirect-type-btn--active' : '';
            $isFull   = ($code === 0) ? ' abj404-redirect-type-btn--full' : '';
            echo '<button type="button"'
                . ' class="abj404-redirect-type-btn' . $isActive . $isFull . '"'
                . ' data-code="' . esc_attr((string)$code) . '"'
                . ' onclick="abj404SelectRedirectType(this)">';
            echo '<strong>' . esc_html($labels[0]) . '</strong>';
            echo '<span>' . esc_html($labels[1]) . '</span>';
            echo '</button>';
        }
        echo '</div>';
        if ($isSimple) {
            echo '<p class="abj404-form-help">' . esc_html__('Permanent is best for most redirects. Use Temporary if the page may come back.', '404-solution') . '</p>';
        } else {
            echo '<p class="abj404-form-help">' . esc_html__('Use 301 for permanent page moves. Use 302 for A/B tests or seasonal pages.', '404-solution') . '</p>';
        }
        echo '</div>';
        echo '<script type="text/javascript">';
        echo 'if (typeof window.abj404SelectRedirectType === "undefined") {';
        echo '    window.abj404SelectRedirectType = function(btn) {';
        echo '        var grid = btn.closest(".abj404-redirect-type-grid");';
        echo '        grid.querySelectorAll(".abj404-redirect-type-btn").forEach(function(b) {';
        echo '            b.classList.remove("abj404-redirect-type-btn--active");';
        echo '        });';
        echo '        btn.classList.add("abj404-redirect-type-btn--active");';
        echo '        var hidden = document.getElementById("code");';
        echo '        if (hidden) {';
        echo '            hidden.value = btn.dataset.code;';
        echo '            if (typeof jQuery !== "undefined") { jQuery("#code").trigger("change"); }';
        echo '        }';
        echo '    };';
        echo '}';
        echo '</script>';
    }
}
