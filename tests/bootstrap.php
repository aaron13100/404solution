<?php
if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/wp/');
    if (!is_dir(ABSPATH)) {
        mkdir(ABSPATH, 0777, true);
    }
}
if (!defined('ABJ404_PATH')) {
    define('ABJ404_PATH', sys_get_temp_dir() . '/abj404_test/');
    if (!is_dir(ABJ404_PATH)) {
        mkdir(ABJ404_PATH, 0777, true);
    }
}
if (!defined('ABJ404_PP')) {
    define('ABJ404_PP', 'abj404_solution');
}
require_once __DIR__ . '/../includes/Functions.php';
require_once __DIR__ . '/../includes/php/FunctionsMBString.php';
require_once __DIR__ . '/../includes/php/FunctionsPreg.php';
