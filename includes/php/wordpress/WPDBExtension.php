<?php

if (version_compare(PHP_VERSION, '7.0.0', '>=')) {
    class ABJ_404_Solution_WPDBExtension_PHP7 extends wpdb {
        public function public_strip_invalid_text_from_query($query) {
            try {
                return $this->strip_invalid_text_from_query($query);
            } catch (Exception $e) {
                return null;
            } catch (Error $e) {
                return null;
            }
        }
    }
} else {
    class ABJ_404_Solution_WPDBExtension_PHP5 extends wpdb {
        public function public_strip_invalid_text_from_query($query) {
            try {
                return $this->strip_invalid_text_from_query($query);
            } catch (Exception $e) {
                return null;
            }
        }
    }
}
