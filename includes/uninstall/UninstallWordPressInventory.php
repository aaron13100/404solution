<?php
// allow-no-test-found: covered by tests/UninstallDiagnosticsEntryPointTest.php public uninstall modal/email entry points

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads WordPress content and active-plugin inventory for uninstall feedback.
 */
class ABJ_404_Solution_UninstallWordPressInventory {

    /**
     * @return array{categories:int,tags:int,pages:int,posts:int}
     */
    public function getContentCounts(): array {
        $counts = array(
            'categories' => 0,
            'tags' => 0,
            'pages' => 0,
            'posts' => 0,
        );

        if (!function_exists('wp_count_terms') || !function_exists('wp_count_posts')) {
            return $counts;
        }

        $categoryCount = wp_count_terms(array('taxonomy' => 'category', 'hide_empty' => false));
        if (!is_wp_error($categoryCount)) {
            $counts['categories'] = intval($categoryCount);
        }

        if (function_exists('taxonomy_exists') && taxonomy_exists('product_cat')) {
            $productCategoryCount = wp_count_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
            if (!is_wp_error($productCategoryCount)) {
                $counts['categories'] += intval($productCategoryCount);
            }
        }

        $tagCount = wp_count_terms(array('taxonomy' => 'post_tag', 'hide_empty' => false));
        if (!is_wp_error($tagCount)) {
            $counts['tags'] = intval($tagCount);
        }

        if (function_exists('taxonomy_exists') && taxonomy_exists('product_tag')) {
            $productTagCount = wp_count_terms(array('taxonomy' => 'product_tag', 'hide_empty' => false));
            if (!is_wp_error($productTagCount)) {
                $counts['tags'] += intval($productTagCount);
            }
        }

        $pageCounts = wp_count_posts('page');
        if (isset($pageCounts->publish)) {
            $counts['pages'] = intval($pageCounts->publish);
        }

        $postCounts = wp_count_posts('post');
        if (isset($postCounts->publish)) {
            $counts['posts'] = intval($postCounts->publish);
        }

        if (function_exists('post_type_exists') && post_type_exists('product')) {
            $productCounts = wp_count_posts('product');
            if (isset($productCounts->publish)) {
                $counts['posts'] += intval($productCounts->publish);
            }
        }

        return $counts;
    }

    /**
     * @return string Comma-separated active plugin names, capped at 10.
     */
    public function getActivePluginsList(): string {
        if (!function_exists('get_plugins')) {
            $pluginFile = ABSPATH . 'wp-admin/includes/plugin.php';
            if (!is_readable($pluginFile)) {
                return 'Unavailable: wp-admin/includes/plugin.php not readable';
            }
            require_once $pluginFile;
        }

        $allPlugins = get_plugins();
        $activePlugins = get_option('active_plugins', array());
        if (!is_array($activePlugins)) {
            $activePlugins = array();
        }

        $activePluginNames = array();
        foreach ($activePlugins as $pluginPath) {
            if (!is_string($pluginPath) || !isset($allPlugins[$pluginPath]) || !is_array($allPlugins[$pluginPath])) {
                continue;
            }
            $pluginName = $allPlugins[$pluginPath]['Name'] ?? null;
            if (is_string($pluginName)) {
                $activePluginNames[] = $pluginName;
            }
        }

        return !empty($activePluginNames)
            ? implode(', ', array_slice($activePluginNames, 0, 10)) . (count($activePluginNames) > 10 ? '...' : '')
            : 'None';
    }
}
