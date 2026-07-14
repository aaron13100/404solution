<?php


if (!defined('ABSPATH')) {
    exit;
}

if (version_compare(PHP_VERSION, '7.0.0', '>=')) {
    class ABJ_404_Solution_WPDBExtension_PHP7 extends wpdb {
        /**
         * @param string $query
         * @return string|null
         */
        public function public_strip_invalid_text_from_query(string $query) {
            try {
                $result = $this->strip_invalid_text_from_query($query);
                return is_string($result) ? $result : null;
            } catch (Exception $e) { // allow-silent-catch: strip_invalid_text_from_query wrapper; null signals "no usable result" and caller falls back to original query. The exception is NOT dropped: abj404_logRuntimeWarning() records it below before we return.
                if (function_exists('abj404_logRuntimeWarning')) {
                    abj404_logRuntimeWarning('WPDBExtension_PHP7::public_strip_invalid_text_from_query failed', $e);
                }
                return null;
            } catch (Error $e) { // allow-silent-catch: PHP 7+ Error from protected method access; same null fallback as Exception path. The error is NOT dropped: abj404_logRuntimeWarning() records it below before we return.
                if (function_exists('abj404_logRuntimeWarning')) {
                    abj404_logRuntimeWarning('WPDBExtension_PHP7::public_strip_invalid_text_from_query failed', $e);
                }
                return null;
            }
        }
    }
} else {
    class ABJ_404_Solution_WPDBExtension_PHP5 extends wpdb {
        /**
         * @param string $query
         * @return string|null
         */
        public function public_strip_invalid_text_from_query(string $query) {
            try {
                $result = $this->strip_invalid_text_from_query($query);
                /** @var mixed $resultMixed */
                $resultMixed = $result;
                if (is_object($resultMixed) && method_exists($resultMixed, 'get_error_message')) {
                    return 'WP_Error: ' . $resultMixed->get_error_message();
                }
                return is_string($result) ? $result : null;
            } catch (Exception $e) { // allow-silent-catch: strip_invalid_text_from_query wrapper; null signals "no usable result" and caller falls back to original query. The exception is NOT dropped: abj404_logRuntimeWarning() records it below before we return.
                if (function_exists('abj404_logRuntimeWarning')) {
                    abj404_logRuntimeWarning('WPDBExtension_PHP5::public_strip_invalid_text_from_query failed', $e);
                }
                return null;
            }
        }
    }
}
