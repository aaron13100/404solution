<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Compiles a WordPress permalink structure string (e.g. "/%year%/%postname%/")
 * into a matching regex plus its token list, and normalizes raw structure
 * strings into canonical path form.
 *
 * Pure and stateless: given the same structure string it always returns the
 * same result, with no WordPress or database dependency. Extracted from
 * ABJ_404_Solution_OldPermalinkStructureResolver because structure-to-regex
 * compilation is a distinct, independently testable concern from resolving a
 * matched request to a post.
 */
class ABJ_404_Solution_PermalinkStructureCompiler {

    /**
     * @param string $structure
     * @return array{regex: string, tokens: array<int, string>}|null
     */
    public function compile(string $structure): ?array {
        $structure = $this->normalizeStructure($structure);
        if ($structure === '') {
            return null;
        }

        preg_match_all('/%[a-z_]+%/', $structure, $tokenMatches);
        $tokens = $tokenMatches[0];
        if (!in_array('%post_id%', $tokens, true)
                && !in_array('%postname%', $tokens, true)
                && !in_array('%pagename%', $tokens, true)) {
            return null;
        }

        $seenNames = array();
        foreach ($tokens as $token) {
            $name = trim($token, '%');
            if (!isset($this->tokenRegexMap()[$token]) || isset($seenNames[$name])) {
                return null;
            }
            $seenNames[$name] = true;
        }

        $segments = explode('/', trim($structure, '/'));
        $compiledSegments = array();
        foreach ($segments as $segment) {
            preg_match_all('/%[a-z_]+%/', $segment, $segmentTokens);
            if (count($segmentTokens[0]) > 1) {
                return null;
            }
            $compiledSegments[] = $this->compileSegment($segment);
        }

        return array(
            'regex' => '~^/' . implode('/', $compiledSegments) . '/?$~',
            'tokens' => $tokens,
        );
    }

    /**
     * @param string $structure
     * @return string
     */
    public function normalizeStructure(string $structure): string {
        $path = parse_url(trim($structure), PHP_URL_PATH);
        $structure = is_string($path) && $path !== '' ? $path : trim($structure);
        if ($structure === '') {
            return '';
        }
        return $structure[0] === '/' ? $structure : '/' . $structure;
    }

    /**
     * @return array<string, string>
     */
    private function tokenRegexMap(): array {
        return array(
            '%year%' => '(?P<year>[0-9]{4})',
            '%monthnum%' => '(?P<monthnum>0[1-9]|1[0-2])',
            '%day%' => '(?P<day>0[1-9]|[12][0-9]|3[01])',
            '%hour%' => '(?P<hour>[01][0-9]|2[0-3])',
            '%minute%' => '(?P<minute>[0-5][0-9])',
            '%second%' => '(?P<second>[0-5][0-9])',
            '%post_id%' => '(?P<post_id>[0-9]+)',
            '%postname%' => '(?P<postname>[^/]+)',
            '%pagename%' => '(?P<pagename>[^/]+)',
            '%category%' => '(?P<category>[^/]+(?:/[^/]+)*)',
            '%author%' => '(?P<author>[^/]+)',
        );
    }

    /** @param string $segment @return string */
    private function compileSegment(string $segment): string {
        $map = $this->tokenRegexMap();
        preg_match('/%[a-z_]+%/', $segment, $match, PREG_OFFSET_CAPTURE);
        if (empty($match)) {
            return preg_quote($segment, '~');
        }

        $token = $match[0][0];
        $offset = (int)$match[0][1];
        $before = substr($segment, 0, $offset);
        $after = substr($segment, $offset + strlen($token));
        return preg_quote($before, '~') . $map[$token] . preg_quote($after, '~');
    }
}
