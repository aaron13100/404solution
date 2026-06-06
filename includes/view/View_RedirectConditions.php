<?php

if (!defined('ABSPATH')) {
    exit;
}

class ABJ_404_Solution_View_RedirectConditions extends ABJ_404_Solution_ViewComponent {

    /**
     * Render the Conditions section on the Edit Redirect page.
     *
     * Reads the current redirect ID from GET/POST so existing conditions can
     * be pre-populated. New redirects (id = 0) render an empty container.
     *
     * HTML is loaded from includes/html/redirectConditions*.html templates.
     *
     * @return void
     */
    public function echoRedirectConditionsSection(): void {
        $redirectId = 0;
        if (isset($_GET['id']) && is_scalar($_GET['id']) && $this->f->regexMatch('[0-9]+', (string)$_GET['id'])) {
            $redirectId = absint($_GET['id']);
        } elseif (isset($_POST['id']) && is_scalar($_POST['id']) && $this->f->regexMatch('[0-9]+', (string)$_POST['id'])) {
            $redirectId = absint($_POST['id']);
        }

        $existingConditions = ($redirectId > 0) ? $this->redirectsRepository->getRedirectConditions($redirectId) : [];

        $openTpl = $this->loadTemplate('redirectConditionsSectionOpen.html');
        echo strtr($openTpl, [
            '{section_label}' => esc_html__('Conditions (optional)', '404-solution'),
            '{section_help}'  => esc_html__('This redirect only fires when all conditions are met. Leave empty to always redirect.', '404-solution'),
        ]);

        foreach ($existingConditions as $i => $cond) {
            $this->echoConditionRow($i, $cond);
        }

        $closeTpl = $this->loadTemplate('redirectConditionsSectionClose.html');
        echo strtr($closeTpl, [
            '{add_label}' => esc_html__('+ Add Condition', '404-solution'),
        ]);

        // Hidden template row (display:none) cloned by JS.
        echo $this->loadTemplate('redirectConditionsRowTemplateOpen.html');
        $this->echoConditionRow('__IDX__', []);
        echo $this->loadTemplate('redirectConditionsRowTemplateClose.html');

        $this->echoConditionsJavaScript();
    }

    /**
     * Render a single condition row.
     *
     * @param int|string           $index  Row index (used in field names).
     * @param array<string, mixed> $cond   Existing condition data (empty = defaults).
     * @return void
     */
    public function echoConditionRow($index, array $cond): void {
        $logic    = isset($cond['logic'])          && is_string($cond['logic'])          ? $cond['logic']          : 'AND';
        $type     = isset($cond['condition_type']) && is_string($cond['condition_type']) ? $cond['condition_type'] : '';
        $operator = isset($cond['operator'])       && is_string($cond['operator'])       ? $cond['operator']       : 'equals';
        $value    = isset($cond['value'])          && is_string($cond['value'])          ? $cond['value']          : '';
        $sortOrder = isset($cond['sort_order'])    && is_scalar($cond['sort_order'])     ? (int)$cond['sort_order'] : (int)$index;

        $namePrefix = 'conditions[' . $index . ']';

        $logicOptions = $this->buildOptions([
            'AND' => __('AND', '404-solution'),
            'OR'  => __('OR', '404-solution'),
        ], $logic);

        $typeOptions = $this->buildOptions([
            'login_status' => __('Login Status', '404-solution'),
            'user_role'    => __('User Role', '404-solution'),
            'referrer'     => __('Referrer URL', '404-solution'),
            'user_agent'   => __('User Agent', '404-solution'),
            'ip_range'     => __('IP Range (CIDR)', '404-solution'),
            'http_header'  => __('HTTP Header', '404-solution'),
        ], $type);

        $operatorOptions = $this->buildOptions([
            'equals'       => __('equals', '404-solution'),
            'not_equals'   => __('not equals', '404-solution'),
            'contains'     => __('contains', '404-solution'),
            'not_contains' => __('does not contain', '404-solution'),
            'regex'        => __('matches regex', '404-solution'),
        ], $operator);

        $rowTpl = $this->loadTemplate('redirectConditionRow.html');
        echo strtr($rowTpl, [
            '{index}'            => esc_attr((string)$index),
            '{name_prefix}'      => esc_attr($namePrefix),
            '{logic_aria}'       => esc_attr__('Logic', '404-solution'),
            '{logic_options}'    => $logicOptions,
            '{type_aria}'        => esc_attr__('Condition type', '404-solution'),
            // allow-em-dash: preserving translated placeholder label.
            '{type_placeholder}' => esc_html__('— Select type —', '404-solution'),
            '{type_options}'     => $typeOptions,
            '{operator_aria}'    => esc_attr__('Operator', '404-solution'),
            '{operator_options}' => $operatorOptions,
            '{value}'            => esc_attr($value),
            '{value_placeholder}' => esc_attr__('Value', '404-solution'),
            '{value_aria}'       => esc_attr__('Condition value', '404-solution'),
            '{sort_order}'       => esc_attr((string)$sortOrder),
            '{remove_aria}'      => esc_attr__('Remove condition', '404-solution'),
            '{remove_label}'     => esc_html__('Remove', '404-solution'),
        ]);
    }

    /**
     * Build the inner <option> markup for a <select>.
     *
     * @param array<string, string> $options    value => translated label map.
     * @param string                $selected   Currently selected value.
     * @return string Concatenated <option> HTML.
     */
    private function buildOptions(array $options, string $selected): string {
        $tpl = $this->loadTemplate('redirectConditionOption.html');
        $out = '';
        foreach ($options as $val => $label) {
            $out .= strtr($tpl, [
                '{value}'    => esc_attr((string)$val),
                '{selected}' => ((string)$val === $selected) ? ' selected' : '',
                '{label}'    => esc_html((string)$label),
            ]);
        }
        return $out;
    }

    /**
     * Output inline JavaScript that manages dynamic condition rows.
     *
     * @return void
     */
    public function echoConditionsJavaScript(): void {
        $redirectId = 0;
        if (isset($_GET['id']) && is_scalar($_GET['id']) && ctype_digit((string)$_GET['id'])) {
            $redirectId = (int)$_GET['id'];
        } elseif (isset($_POST['id']) && is_scalar($_POST['id']) && ctype_digit((string)$_POST['id'])) {
            $redirectId = (int)$_POST['id'];
        }
        $initialIndex = ($redirectId > 0) ? max(1, count($this->redirectsRepository->getRedirectConditions($redirectId))) : 1;

        $tpl = $this->loadTemplate('redirectConditionsScript.html');
        echo strtr($tpl, [
            '{initial_index}' => (string)(int)$initialIndex,
        ]);
    }

    /**
     * Read an HTML template from includes/html/.
     *
     * @param string $filename Template filename relative to includes/html/.
     * @return string Template contents.
     */
    private function loadTemplate(string $filename): string {
        return ABJ_404_Solution_FileSystemService::readFileContents(
            dirname(__DIR__) . '/html/' . $filename
        );
    }
}
