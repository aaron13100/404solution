<?php

// allow-no-test-found: covered by tests/SetupWizardTest.php through setup wizard admin entry points.

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validates setup wizard answers and maps them to setup decisions.
 */
class ABJ_404_Solution_SetupWizardAnswerPolicy {

    /** @var array<string,string> */
    private const DEFAULT_ANSWERS = array(
        'q1' => 'redirect',
        'q2' => 'yes',
        'q3' => 'yes',
    );

    /** @var array<string,array<int,string>> */
    private const ALLOWED_VALUES = array(
        'q1' => array('redirect', 'default'),
        'q2' => array('yes', 'no'),
        'q3' => array('yes', 'no'),
    );

    /**
     * Return validated answers from a submitted setup wizard request.
     *
     * @param array<string,mixed> $post Raw POST payload.
     * @return array{q1:string,q2:string,q3:string}
     */
    public static function answersFromRequest(array $post): array {
        return self::answersFromRaw(array(
            'q1' => self::readText($post, 'abj404_setup_q1', self::DEFAULT_ANSWERS['q1']),
            'q2' => self::readText($post, 'abj404_setup_q2', self::DEFAULT_ANSWERS['q2']),
            'q3' => self::readText($post, 'abj404_setup_q3', self::DEFAULT_ANSWERS['q3']),
        ));
    }

    /**
     * Return validated default answers for the rendered wizard form.
     *
     * @return array{q1:string,q2:string,q3:string}
     */
    public static function defaultAnswers(): array {
        $answers = self::DEFAULT_ANSWERS;

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('abj404_setup_wizard_default_answers', $answers);
            if (is_array($filtered)) {
                $answers = array_merge($answers, $filtered);
            }
        }

        return self::answersFromRaw($answers);
    }

    /**
     * Apply validated setup answers to an existing options array.
     *
     * @param array<string,mixed> $options Existing plugin options.
     * @param array{q1:string,q2:string,q3:string} $answers Validated setup answers.
     * @param string $adminEmail Site admin email address.
     * @return array<string,mixed> Updated plugin options.
     */
    public static function applyToOptions(array $options, array $answers, string $adminEmail): array {
        $redirectOptions = array(
            'redirect' => array(
                'auto_redirects' => '1',
                'auto_cats' => '1',
                'auto_tags' => '1',
            ),
            'default' => array(
                'auto_redirects' => '0',
                'auto_cats' => '0',
                'auto_tags' => '0',
            ),
        );

        foreach ($redirectOptions[$answers['q1']] as $key => $value) {
            $options[$key] = $value;
        }

        $options['dest404page'] = '0|' . ABJ404_TYPE_404_DISPLAYED;
        $options['capture_404'] = self::captures404s($answers) ? '1' : '0';

        if ($answers['q3'] === 'yes') {
            $options['admin_notification'] = '50';
            $options['admin_notification_frequency'] = 'weekly';
            $options['admin_notification_email'] = $adminEmail;
        }

        return $options;
    }

    /**
     * Build the admin redirect path after setup submission.
     *
     * @param array{q1:string,q2:string,q3:string} $answers Validated setup answers.
     * @return string Relative admin path.
     */
    public static function redirectPath(array $answers): string {
        $path = 'options-general.php?page=abj404_solution&setup_complete=1';
        if (self::captures404s($answers)) {
            $path .= '&subpage=abj404_captured';
        }

        return $path;
    }

    /**
     * Return whether the validated answers enable captured 404 logging.
     *
     * @param array{q1:string,q2:string,q3:string} $answers Validated setup answers.
     * @return bool
     */
    public static function captures404s(array $answers): bool {
        return $answers['q2'] === 'yes';
    }

    /**
     * @param array<string,mixed> $source
     */
    private static function readText(array $source, string $key, string $default): string {
        if (!array_key_exists($key, $source)) {
            return $default;
        }

        $value = $source[$key];
        if (!is_scalar($value)) {
            return $default;
        }

        $text = (string)$value;
        if (function_exists('sanitize_text_field')) {
            $text = sanitize_text_field($text);
        }

        return $text;
    }

    /**
     * @param array<string,mixed> $raw
     * @return array{q1:string,q2:string,q3:string}
     */
    private static function answersFromRaw(array $raw): array {
        return array(
            'q1' => self::validAnswerValue($raw['q1'] ?? '', self::ALLOWED_VALUES['q1'], self::DEFAULT_ANSWERS['q1']),
            'q2' => self::validAnswerValue($raw['q2'] ?? '', self::ALLOWED_VALUES['q2'], self::DEFAULT_ANSWERS['q2']),
            'q3' => self::validAnswerValue($raw['q3'] ?? '', self::ALLOWED_VALUES['q3'], self::DEFAULT_ANSWERS['q3']),
        );
    }

    /**
     * @param mixed $value Candidate value.
     * @param array<int,string> $allowed Allowed values.
     */
    private static function validAnswerValue($value, array $allowed, string $default): string {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }
}
