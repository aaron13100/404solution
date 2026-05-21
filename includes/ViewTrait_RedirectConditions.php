<?php

if (!defined('ABSPATH')) {
    exit;
}

trait ViewTrait_RedirectConditions {

    /**
     * Render the Conditions section on the Edit Redirect page.
     *
     * Reads the current redirect ID from GET/POST so existing conditions can
     * be pre-populated.  New redirects (id = 0) render an empty container.
     *
     * @return void
     */
    private function echoRedirectConditionsSection(): void {
        $redirectId = 0;
        if (isset($_GET['id']) && $this->f->regexMatch('[0-9]+', (string)$_GET['id'])) {
            $redirectId = absint($_GET['id']);
        } elseif (isset($_POST['id']) && $this->f->regexMatch('[0-9]+', (string)$_POST['id'])) {
            $redirectId = absint($_POST['id']);
        }

        $existingConditions = ($redirectId > 0) ? $this->redirectsRepository->getRedirectConditions($redirectId) : [];

        echo '<div class="abj404-form-group abj404-conditions-section">';
        echo '<h4>' . esc_html__('Conditions (optional)', '404-solution') . '</h4>';
        echo '<p class="abj404-form-help">' . esc_html__('This redirect only fires when all conditions are met. Leave empty to always redirect.', '404-solution') . '</p>';

        echo '<div id="abj404-conditions-container">';
        foreach ($existingConditions as $i => $cond) {
            $this->echoConditionRow($i, $cond);
        }
        echo '</div>';

        echo '<button type="button" onclick="abj404AddConditionRow()" class="button abj404-btn-add-condition">'
            . esc_html__('+ Add Condition', '404-solution')
            . '</button>';

        echo '</div>';

        // Hidden template row (display:none) cloned by JS.
        echo '<script type="text/template" id="abj404-condition-row-template">';
        $this->echoConditionRow('__IDX__', []);
        echo '</script>';

        // Inline JS for dynamic condition rows.
        $this->echoConditionsJavaScript();
    }

    /**
     * Render a single condition row.
     *
     * @param int|string           $index  Row index (used in field names).
     * @param array<string, mixed> $cond   Existing condition data (empty = defaults).
     * @return void
     */
    private function echoConditionRow($index, array $cond): void {
        $logic    = isset($cond['logic'])          && is_string($cond['logic'])          ? $cond['logic']          : 'AND';
        $type     = isset($cond['condition_type']) && is_string($cond['condition_type']) ? $cond['condition_type'] : '';
        $operator = isset($cond['operator'])       && is_string($cond['operator'])       ? $cond['operator']       : 'equals';
        $value    = isset($cond['value'])          && is_string($cond['value'])          ? $cond['value']          : '';
        $sortOrder = isset($cond['sort_order'])    && is_scalar($cond['sort_order'])     ? (int)$cond['sort_order'] : (int)$index;

        $namePrefix = 'conditions[' . $index . ']';

        echo '<div class="abj404-condition-row" data-index="' . esc_attr((string)$index) . '">';

        // Logic (AND / OR) — shown only on rows after the first.
        echo '<select name="' . esc_attr($namePrefix . '[logic]') . '" class="abj404-condition-logic" aria-label="' . esc_attr__('Logic', '404-solution') . '">';
        foreach (['AND' => __('AND', '404-solution'), 'OR' => __('OR', '404-solution')] as $logicVal => $logicLabel) {
            $sel = ($logic === $logicVal) ? ' selected' : '';
            echo '<option value="' . esc_attr($logicVal) . '"' . $sel . '>' . esc_html($logicLabel) . '</option>';
        }
        echo '</select>';

        // Condition type.
        $typeOptions = [
            'login_status' => __('Login Status', '404-solution'),
            'user_role'    => __('User Role', '404-solution'),
            'referrer'     => __('Referrer URL', '404-solution'),
            'user_agent'   => __('User Agent', '404-solution'),
            'ip_range'     => __('IP Range (CIDR)', '404-solution'),
            'http_header'  => __('HTTP Header', '404-solution'),
        ];
        echo '<select name="' . esc_attr($namePrefix . '[condition_type]') . '" class="abj404-condition-type" aria-label="' . esc_attr__('Condition type', '404-solution') . '">';
        echo '<option value="">' . esc_html__('— Select type —', '404-solution') . '</option>';
        foreach ($typeOptions as $typeVal => $typeLabel) {
            $sel = ($type === $typeVal) ? ' selected' : '';
            echo '<option value="' . esc_attr($typeVal) . '"' . $sel . '>' . esc_html($typeLabel) . '</option>';
        }
        echo '</select>';

        // Operator.
        $operatorOptions = [
            'equals'       => __('equals', '404-solution'),
            'not_equals'   => __('not equals', '404-solution'),
            'contains'     => __('contains', '404-solution'),
            'not_contains' => __('does not contain', '404-solution'),
            'regex'        => __('matches regex', '404-solution'),
        ];
        echo '<select name="' . esc_attr($namePrefix . '[operator]') . '" class="abj404-condition-operator" aria-label="' . esc_attr__('Operator', '404-solution') . '">';
        foreach ($operatorOptions as $opVal => $opLabel) {
            $sel = ($operator === $opVal) ? ' selected' : '';
            echo '<option value="' . esc_attr($opVal) . '"' . $sel . '>' . esc_html($opLabel) . '</option>';
        }
        echo '</select>';

        // Value input.
        echo '<input type="text" name="' . esc_attr($namePrefix . '[value]') . '" class="abj404-condition-value abj404-form-input" value="' . esc_attr($value) . '" placeholder="' . esc_attr__('Value', '404-solution') . '" aria-label="' . esc_attr__('Condition value', '404-solution') . '">';

        // Sort order (hidden).
        echo '<input type="hidden" name="' . esc_attr($namePrefix . '[sort_order]') . '" class="abj404-condition-sort-order" value="' . esc_attr((string)$sortOrder) . '">';

        // Remove button.
        echo '<button type="button" class="button abj404-remove-condition" onclick="abj404RemoveConditionRow(this)" aria-label="' . esc_attr__('Remove condition', '404-solution') . '">'
            . esc_html__('Remove', '404-solution')
            . '</button>';

        echo '</div>';
    }

    /**
     * Output inline JavaScript that manages dynamic condition rows.
     *
     * @return void
     */
    private function echoConditionsJavaScript(): void {
        $redirectId = 0;
        if (isset($_GET['id']) && is_scalar($_GET['id']) && ctype_digit((string)$_GET['id'])) {
            $redirectId = (int)$_GET['id'];
        } elseif (isset($_POST['id']) && is_scalar($_POST['id']) && ctype_digit((string)$_POST['id'])) {
            $redirectId = (int)$_POST['id'];
        }
        $initialIndex = ($redirectId > 0) ? max(1, count($this->redirectsRepository->getRedirectConditions($redirectId))) : 1;

        echo '<script type="text/javascript">' . "\n";
        echo '(function() {' . "\n";
        echo '    var abj404ConditionIndex = ' . (int)$initialIndex . ';' . "\n";
        echo "\n";
        echo '    window.abj404AddConditionRow = function() {' . "\n";
        echo '        var template = document.getElementById(\'abj404-condition-row-template\');' . "\n";
        echo '        if (!template) { return; }' . "\n";
        echo '        var html = template.innerHTML.replace(/__IDX__/g, String(abj404ConditionIndex));' . "\n";
        echo '        var container = document.getElementById(\'abj404-conditions-container\');' . "\n";
        echo '        if (!container) { return; }' . "\n";
        echo '        var div = document.createElement(\'div\');' . "\n";
        echo '        div.innerHTML = html;' . "\n";
        echo '        while (div.firstChild) {' . "\n";
        echo '            container.appendChild(div.firstChild);' . "\n";
        echo '        }' . "\n";
        echo '        abj404UpdateConditionSortOrders();' . "\n";
        echo '        abj404ConditionIndex++;' . "\n";
        echo '    };' . "\n";
        echo "\n";
        echo '    window.abj404RemoveConditionRow = function(btn) {' . "\n";
        echo '        var row = btn.closest(\'.abj404-condition-row\');' . "\n";
        echo '        if (row) {' . "\n";
        echo '            row.parentNode.removeChild(row);' . "\n";
        echo '            abj404UpdateConditionSortOrders();' . "\n";
        echo '        }' . "\n";
        echo '    };' . "\n";
        echo "\n";
        echo '    function abj404UpdateConditionSortOrders() {' . "\n";
        echo '        var rows = document.querySelectorAll(\'#abj404-conditions-container .abj404-condition-row\');' . "\n";
        echo '        for (var i = 0; i < rows.length; i++) {' . "\n";
        echo '            var so = rows[i].querySelector(\'.abj404-condition-sort-order\');' . "\n";
        echo '            if (so) { so.value = String(i); }' . "\n";
        echo '        }' . "\n";
        echo '    }' . "\n";
        echo '}());' . "\n";
        echo '</script>' . "\n";
    }
}
