<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validates root-relative replacement templates used by regex redirects.
 *
 * The runtime supports numbered `$N` substitutions. This validator keeps the
 * admin write boundary aligned with that contract so a relative destination
 * cannot be stored with syntax the matcher will leave unresolved.
 */
class ABJ_404_Solution_RegexDestinationTemplateValidator {

    /** @var ABJ_404_Solution_RegexSourcePatternValidator */
    private $sourceValidator;

    /**
     * @param ABJ_404_Solution_Functions $functions
     */
    public function __construct(
        $functions,
        ?ABJ_404_Solution_RegexSourcePatternValidator $sourceValidator = null
    ) {
        $this->sourceValidator = $sourceValidator !== null
            ? $sourceValidator
            : new ABJ_404_Solution_RegexSourcePatternValidator($functions);
    }

    /**
     * @return array{valid: bool, message: string, detail: string}
     */
    public function validate(string $sourcePattern, string $destination): array {
        if ($this->containsControlCharacters($destination)) {
            return $this->invalid(
                __('Error: Regex destination contains control characters.', '404-solution')
            );
        }

        if ($destination === '' || $destination[0] !== '/') {
            return $this->invalid(
                __('Error: Regex destination must be an HTTP(S) URL or a site-relative path starting with /.', '404-solution')
            );
        }

        if (isset($destination[1]) && $destination[1] === '/') {
            return $this->invalid(
                __('Error: A site-relative regex destination must start with a single /. URLs beginning with // are not allowed.', '404-solution')
            );
        }

        if (preg_match('/\s/u', $destination) === 1) {
            return $this->invalid(
                __('Error: Regex destination must not contain spaces. Use URL encoding such as %20 instead.', '404-solution')
            );
        }

        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $destination) === 1) {
            return $this->invalid(
                __('Error: Regex destination contains invalid percent encoding.', '404-solution')
            );
        }

        if (preg_match('/[<>"\'`]/u', $destination) === 1) {
            return $this->invalid(
                __('Error: Regex destination contains characters that are not safe in a redirect URL.', '404-solution')
            );
        }

        if (preg_match('#(?:^|/)\.{1,2}(?:/|$|\?|\\#)#', $destination) === 1) {
            return $this->invalid(
                __('Error: Regex destination must not contain . or .. path segments.', '404-solution')
            );
        }

        return $this->validateReplacement($sourcePattern, $destination);
    }

    /**
     * Validate the regex-specific portion of either an absolute or relative
     * destination after its URL shape has been validated by the caller.
     *
     * @return array{valid: bool, message: string, detail: string}
     */
    public function validateReplacement(string $sourcePattern, string $destination): array {
        if ($this->containsControlCharacters($destination)) {
            return $this->invalid(
                __('Error: Regex destination contains control characters.', '404-solution')
            );
        }

        $sourceValidation = $this->validateSourcePattern($sourcePattern);
        if (!$sourceValidation['valid']) {
            return $sourceValidation;
        }

        $tokenResult = $this->replacementTokens($destination);
        if (!$tokenResult['valid']) {
            return $this->invalid(
                __('Error: Regex destination contains unsupported replacement syntax. Use $1, $2, and so on.', '404-solution')
            );
        }

        if (!empty($tokenResult['tokens']) && $this->usesUnsupportedCapturingSyntax($sourcePattern)) {
            return $this->invalid(
                __('Error: Relative regex destinations support numbered capture groups written as (...). Named and branch-reset groups are not supported.', '404-solution')
            );
        }

        $captureCount = $this->countNumberedCaptureGroups($sourcePattern);
        foreach ($tokenResult['tokens'] as $token) {
            if ($token > $captureCount) {
                return $this->invalid(sprintf(
                    __('Error: Regex destination references $%1$d, but the source pattern defines %2$d capture group(s).', '404-solution'),
                    $token,
                    $captureCount
                ));
            }
        }

        return array('valid' => true, 'message' => '', 'detail' => '');
    }

    /**
     * Validate the source independently of destination type so selecting an
     * internal page cannot bypass regex compilation checks.
     *
     * @return array{valid: bool, message: string, detail: string}
     */
    public function validateSourcePattern(string $sourcePattern): array {
        $validation = $this->sourceValidator->validate($sourcePattern);
        if (!$validation['valid']) {
            return $this->invalid(
                __('Error: Source regular expression is invalid.', '404-solution'),
                $validation['detail']
            );
        }
        return array('valid' => true, 'message' => '', 'detail' => '');
    }

    private function containsControlCharacters(string $value): bool {
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }

    /**
     * @return array{valid: bool, tokens: array<int, int>}
     */
    private function replacementTokens(string $destination): array {
        $tokens = array();
        $length = strlen($destination);

        for ($index = 0; $index < $length; $index++) {
            $character = $destination[$index];
            if ($character === '\\' && isset($destination[$index + 1])
                    && (ctype_digit($destination[$index + 1]) || $destination[$index + 1] === '$')) {
                return array('valid' => false, 'tokens' => array());
            }
            if ($character !== '$') {
                continue;
            }

            $nextIndex = $index + 1;
            if ($nextIndex >= $length || $destination[$nextIndex] < '1' || $destination[$nextIndex] > '9') {
                return array('valid' => false, 'tokens' => array());
            }

            $digits = '';
            while ($nextIndex < $length && ctype_digit($destination[$nextIndex])) {
                $digits .= $destination[$nextIndex];
                $nextIndex++;
            }
            $tokens[] = (int)$digits;
            $index = $nextIndex - 1;
        }

        return array('valid' => true, 'tokens' => array_values(array_unique($tokens)));
    }

    private function usesUnsupportedCapturingSyntax(string $pattern): bool {
        return preg_match('/\(\?(?:P<|<(?!!|=)|\'|\||\()/', $pattern) === 1;
    }

    private function countNumberedCaptureGroups(string $pattern): int {
        $count = 0;
        $escaped = false;
        $inCharacterClass = false;
        $length = strlen($pattern);

        for ($index = 0; $index < $length; $index++) {
            $character = $pattern[$index];
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($character === '\\') {
                $escaped = true;
                continue;
            }
            if ($character === '[' && !$inCharacterClass) {
                $inCharacterClass = true;
                continue;
            }
            if ($character === ']' && $inCharacterClass) {
                $inCharacterClass = false;
                continue;
            }
            if ($inCharacterClass || $character !== '(') {
                continue;
            }

            if (!isset($pattern[$index + 1]) || $pattern[$index + 1] !== '?') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array{valid: bool, message: string, detail: string}
     */
    private function invalid(string $message, string $detail = ''): array {
        return array('valid' => false, 'message' => $message, 'detail' => $detail);
    }
}
