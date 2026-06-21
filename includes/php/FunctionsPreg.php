<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Backward-compatibility shim. New code should depend on
 * ABJ_404_Solution_RegexHelperPreg directly (the preg helper is useful
 * even when mbstring is loaded - callers wanting PCRE regex semantics
 * regardless of host extension reach for it explicitly).
 *
 * Wires Functions to the Preg variants of MbStringAdapter and RegexHelper
 * so the polymorphic primitives use native string / preg_* fallbacks. The
 * class itself has no extra behavior beyond adapter selection.
 */
class ABJ_404_Solution_FunctionsPreg extends ABJ_404_Solution_Functions {

    /** @var self|null */
    private static $instance = null;
    /**
     * Test seam: install or clear the cached singleton instance without
     * private-field reflection. Pass null to reset between tests; pass a
     * configured instance (or double) to install it (M105 singleton-reset seam).
     *
     * @param self|null $instance
     * @return void
     */
    public static function setInstance($instance) {
        self::$instance = $instance;
    }


    /**
     * @param ABJ_404_Solution_Logging|null        $logging
     * @param ABJ_404_Solution_RequestContext|null $requestContext
     */
    public function __construct($logging = null, $requestContext = null) {
        parent::__construct(
            $logging,
            $requestContext,
            ABJ_404_Solution_MbStringAdapterPreg::getInstance(),
            ABJ_404_Solution_RegexHelperPreg::getInstance()
        );
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
     * ABJ_404_Solution_RegexHelperPreg::findADelimiter() directly.
     *
     * @param string $pattern
     * @return string
     */
    public function findADelimiter(string $pattern): string {
        return ABJ_404_Solution_RegexHelperPreg::getInstance()->findADelimiter($pattern);
    }
}
