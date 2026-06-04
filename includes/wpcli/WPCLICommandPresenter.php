<?php

if (!defined('ABSPATH')) {
    exit;
}

// allow-no-test-found: covered through public WP-CLI command entry points in tests/WPCLICommandsTest.php

/**
 * Presents WP-CLI command results through the WP_CLI output API.
 */
class ABJ_404_Solution_WPCLICommandPresenter {

    /**
     * @param array<string, mixed> $result
     * @return void
     */
    public function present(array $result): void {
        $this->presentMessages($result, 'warnings', 'warning');
        $this->presentMessages($result, 'lines', 'line');
        $type = isset($result['type']) && is_string($result['type']) ? $result['type'] : 'line';
        if ($type === 'format') {
            $this->presentFormat($result);
            return;
        }
        if ($type === 'content') {
            echo $this->scalarString($result, 'content', '');
            return;
        }
        if ($type === 'success') {
            \WP_CLI::success($this->scalarString($result, 'message', ''));
            return;
        }
        if ($type === 'error') {
            \WP_CLI::error($this->scalarString($result, 'message', ''));
        } else {
            \WP_CLI::line($this->scalarString($result, 'message', ''));
        }
    }

    /**
     * @param array<string, mixed> $result
     * @param string $key
     * @param string $method
     * @return void
     */
    private function presentMessages(array $result, string $key, string $method): void {
        $messages = isset($result[$key]) && is_array($result[$key]) ? $result[$key] : array();
        foreach ($messages as $message) {
            if (is_scalar($message)) {
                \WP_CLI::$method((string)$message);
            }
        }
    }

    /** @param array<string, mixed> $result @return void */
    private function presentFormat(array $result): void {
        $rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();
        \WP_CLI\Utils\format_items(
            $this->scalarString($result, 'format', 'table'),
            $rows,
            $this->stringList($result, 'fields')
        );
    }

    /**
     * @param array<string, mixed> $result
     * @param string $key
     * @return array<int, string>
     */
    private function stringList(array $result, string $key): array {
        $values = isset($result[$key]) && is_array($result[$key]) ? $result[$key] : array();
        $strings = array();
        foreach ($values as $value) {
            if (is_scalar($value)) {
                $strings[] = (string)$value;
            }
        }
        return $strings;
    }

    /**
     * @param array<string, mixed> $result
     * @param string $key
     * @param string $default
     * @return string
     */
    private function scalarString(array $result, string $key, string $default): string {
        return isset($result[$key]) && is_scalar($result[$key]) ? (string)$result[$key] : $default;
    }

    /**
     * @param string $question
     * @param array<string, mixed> $assocArgs
     * @return void
     */
    public function confirm(string $question, array $assocArgs): void {
        \WP_CLI::confirm($question, $assocArgs);
    }
}
