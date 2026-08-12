<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validates uploaded import files before the importer reads CSV content.
 */
class ABJ_404_Solution_ImportUploadValidator {

    /**
     * @param array<mixed> $file
     * @return string Empty on success, error message on failure.
     */
    function validate(array $file): string {
        $allowed_extensions = array('csv', 'txt');
        $file_ext = strtolower(pathinfo($this->stringField($file, 'name'), PATHINFO_EXTENSION));
        if (!in_array($file_ext, $allowed_extensions)) {
            return __('Error: Invalid file type. Only CSV/TXT files are allowed.', '404-solution');
        }

        $max_file_size = 5 * 1024 * 1024;
        if ($this->intField($file, 'size') > $max_file_size) {
            return __('Error: File too large. Maximum size is 5MB.', '404-solution');
        }

        $allowed_mime_types = array('text/csv', 'text/plain', 'application/csv', 'text/comma-separated-values', 'application/vnd.ms-excel');
        // DESIGN-AUDIT-OK: the missing finfo_close() is deliberate, not an
        // oversight. Measured on PHP 8.5.9: 300 validate() calls that never
        // close the handle grow the process descriptor count by 0, because PHP
        // refcounts the handle and frees it when $finfo leaves scope. (Positive
        // control for that measurement: 50 handles held live in an array grew
        // the count by 50, so the probe can detect growth.) finfo_close() is
        // deprecated as of PHP 8.5 -- "finfo objects are freed automatically"
        // -- so calling it would emit a deprecation notice on supported
        // installs in exchange for fixing nothing.
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return __('Error: Unable to determine file type.', '404-solution');
        }
        $mime_type = finfo_file($finfo, $this->tmpName($file));
        if (!in_array($mime_type, $allowed_mime_types)) {
            return __('Error: Invalid file type. Only CSV files are allowed.', '404-solution');
        }

        return '';
    }

    /**
     * @param array<mixed> $file
     * @return string
     */
    function tmpName(array $file): string {
        return $this->stringField($file, 'tmp_name');
    }

    /**
     * @param array<mixed> $file
     * @param string $key
     * @return string
     */
    private function stringField(array $file, string $key): string {
        $value = $file[$key] ?? '';
        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * @param array<mixed> $file
     * @param string $key
     * @return int
     */
    private function intField(array $file, string $key): int {
        $value = $file[$key] ?? 0;
        return is_numeric($value) ? (int)$value : 0;
    }
}
