<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Compiles redirect source patterns at every write boundary.
 *
 * This shared validator contains no presentation concerns so admin handlers,
 * imports, REST/WP-CLI writes, and the repository can enforce one contract.
 */
final class ABJ_404_Solution_RegexSourcePatternValidator {

    /** @var ABJ_404_Solution_Functions */
    private $functions;

    /**
     * @param ABJ_404_Solution_Functions $functions
     */
    public function __construct($functions) {
        $this->functions = $functions;
    }

    /**
     * @return array{valid: bool, detail: string}
     */
    public function validate(string $pattern): array {
        if ($pattern === '') {
            return array('valid' => false, 'detail' => 'Source pattern is empty.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $pattern) === 1) {
            return array('valid' => false, 'detail' => 'Source pattern contains control characters.');
        }

        $warning = '';
        set_error_handler(static function($severity, $message) use (&$warning) {
            $warning = $message;
            return true;
        });
        try {
            $this->functions->regexMatch($pattern, '');
        } catch (Throwable $error) { // allow-silent-catch: the original regex engine error is returned in detail below.
            $warning = $error->getMessage();
        } finally {
            restore_error_handler();
        }

        return array('valid' => $warning === '', 'detail' => $warning);
    }
}
