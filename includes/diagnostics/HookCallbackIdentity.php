<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Privacy-safe identity for WordPress hook callbacks and their source code.
 *
 * Callback arguments and source paths never leave this class. Callables become
 * stable hashes, and source files become either a component hash, a WordPress
 * core label, or an opaque path hash.
 *
 * allow-no-test-found: exercised through real AJAX hook dispatch in tests/OptionPersistenceTracerTest.php and tests/AjaxHookCallbackAttributionTest.php
 */
final class ABJ_404_Solution_HookCallbackIdentity {

    private const JSON_SAFE_INTEGER_MAX = 9007199254740991;

    /**
     * @param callable $callback
     * @return array{callback: string, source: string, has_reference: bool}
     */
    public static function describe(callable $callback): array {
        $descriptor = 'callable';
        $source = 'runtime';
        $hasReference = false;
        try {
            if (is_array($callback)) {
                $owner = is_object($callback[0]) ? get_class($callback[0]) : (string)$callback[0];
                $descriptor = $owner . '::' . (string)$callback[1];
                $reflection = new ReflectionMethod($callback[0], (string)$callback[1]);
            } elseif (is_string($callback) && strpos($callback, '::') !== false) {
                $descriptor = $callback;
                $reflection = new ReflectionMethod($callback);
            } elseif (is_string($callback)) {
                $descriptor = $callback;
                $reflection = new ReflectionFunction($callback);
            } elseif ($callback instanceof Closure) {
                $descriptor = 'closure';
                $reflection = new ReflectionFunction($callback);
            } elseif (is_object($callback)) {
                $descriptor = get_class($callback) . '::__invoke';
                $reflection = new ReflectionMethod($callback, '__invoke');
            } else {
                $reflection = new ReflectionFunction(Closure::fromCallable($callback));
            }
            $hasReference = $reflection->returnsReference();
            foreach ($reflection->getParameters() as $parameter) {
                $hasReference = $hasReference || $parameter->isPassedByReference();
            }
            $source = self::sourceIdentity((string)$reflection->getFileName());
            $descriptor .= '|' . (string)$reflection->getStartLine() . '|' . $source;
        } catch (Throwable $e) {
            self::reportFailure(
                'callback reflection failed (' . get_class($e) . '): ' . $e->getMessage()
            );
            // An unknown signature must take the marker path. Wrapping it would
            // risk erasing reference semantics that reflection could not prove
            // absent.
            $hasReference = true;
            $descriptor .= '|reflection-unavailable|' . get_class($e);
            $source = 'unavailable';
        }
        return array(
            'callback' => 'cb#' . substr(hash('sha256', $descriptor), 0, 12),
            'source' => $source,
            'has_reference' => $hasReference,
        );
    }

    public static function hookName(string $value): string {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_.:-]{0,63}$/', $value) === 1) {
            return preg_replace('/[0-9]+/', '#', $value) ?? '';
        }
        return 'hook#' . substr(hash('sha256', $value), 0, 12);
    }

    /**
     * Preserve a hook priority exactly when diagnostic JSON crosses a
     * JavaScript reader. WordPress still receives the original priority.
     */
    public static function jsonSafePriority(?int $priority): ?int {
        if ($priority === null || PHP_INT_SIZE < 8) {
            return $priority;
        }
        return max(
            -self::JSON_SAFE_INTEGER_MAX,
            min(self::JSON_SAFE_INTEGER_MAX, $priority)
        );
    }

    private static function sourceIdentity(string $file): string {
        $normalized = str_replace('\\', '/', $file);
        foreach (array('plugins' => 'plugin', 'mu-plugins' => 'mu', 'themes' => 'theme') as $part => $label) {
            if (preg_match('#/wp-content/' . $part . '/([^/]+)#i', $normalized, $match) === 1) {
                return $label . '#' . substr(hash('sha256', strtolower($match[1])), 0, 12);
            }
        }
        if (strpos($normalized, '/wp-includes/') !== false || strpos($normalized, '/wp-admin/') !== false) {
            return 'wordpress-core';
        }
        return $normalized === '' ? 'php-runtime'
            : 'source#' . substr(hash('sha256', $normalized), 0, 12);
    }

    private static function reportFailure(string $message): void {
        abj404_logPhpFallback('hook-callback-identity', $message);
    }
}
