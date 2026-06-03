<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Backward-compatibility shim. New code should depend on
 * ABJ_404_Solution_MbStringAdapterPreg directly (the preg adapter is
 * useful even when mbstring is loaded - callers wanting PCRE regex
 * semantics regardless of host extension reach for it explicitly).
 *
 * Wires Functions to ABJ_404_Solution_MbStringAdapterPreg so the 9
 * polymorphic primitives use native string / preg_* fallbacks. The class
 * itself has no extra behavior beyond adapter selection.
 */
class ABJ_404_Solution_FunctionsPreg extends ABJ_404_Solution_Functions {

    /** @var self|null */
    private static $instance = null;

    /**
     * @param ABJ_404_Solution_Logging|null        $logging
     * @param ABJ_404_Solution_RequestContext|null $requestContext
     */
    public function __construct($logging = null, $requestContext = null) {
        parent::__construct($logging, $requestContext, ABJ_404_Solution_MbStringAdapterPreg::getInstance());
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Find a delimiter character that does not occur in $pattern. Exposed
     * here for the rare caller that constructed a delimiter-aware PCRE
     * string manually; new code should call
     * ABJ_404_Solution_MbStringAdapterPreg::findADelimiter() directly.
     *
     * @param string $pattern
     * @return string
     */
    public function findADelimiter(string $pattern): string {
        return ABJ_404_Solution_MbStringAdapterPreg::getInstance()->findADelimiter($pattern);
    }
}
