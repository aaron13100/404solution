<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Backward-compatibility shim. New code should construct
 * ABJ_404_Solution_Functions directly with an explicit
 * ABJ_404_Solution_MbStringAdapter (or depend on the adapter alone if
 * the kitchen-sink Functions surface is not needed).
 *
 * Wires Functions to ABJ_404_Solution_MbStringAdapterMb so the 9
 * polymorphic primitives use the mbstring extension. The class itself
 * has no extra behavior beyond adapter selection - the legacy inheritance
 * pattern (abstract Functions + concrete subclass per platform) has been
 * replaced with composition (Functions holds an adapter).
 */
class ABJ_404_Solution_FunctionsMBString extends ABJ_404_Solution_Functions {

    /**
     * @param ABJ_404_Solution_Logging|null        $logging
     * @param ABJ_404_Solution_RequestContext|null $requestContext
     */
    public function __construct($logging = null, $requestContext = null) {
        parent::__construct($logging, $requestContext, ABJ_404_Solution_MbStringAdapterMb::getInstance());
    }
}
