<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Marks plugin-owned diagnostic observers that foreign-callback tracers must
 * leave intact. Implementations observe a hook boundary; they are not part of
 * the application or third-party callback work those tracers attribute.
 */
interface ABJ_404_Solution_DiagnosticInternalHookObserver {
}
