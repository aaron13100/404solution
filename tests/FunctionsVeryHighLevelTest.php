<?php
require_once __DIR__ . '/../includes/Functions.php';
require_once __DIR__ . '/../includes/php/FunctionsMBString.php';
require_once __DIR__ . '/../includes/php/FunctionsPreg.php';
require_once __DIR__ . '/../includes/Logging.php';

use PHPUnit\Framework\TestCase;

class FunctionsVeryHighLevelTest extends TestCase {
    private $f;

    protected function setUp(): void {
        $this->f = ABJ_404_Solution_Functions::getInstance();
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
        if (!defined('ABJ404_TYPE_POST')) { define('ABJ404_TYPE_POST', 1); }
        if (!defined('ABJ404_TYPE_CAT')) { define('ABJ404_TYPE_CAT', 2); }
        if (!defined('ABJ404_TYPE_TAG')) { define('ABJ404_TYPE_TAG', 3); }
        if (!defined('ABJ404_TYPE_EXTERNAL')) { define('ABJ404_TYPE_EXTERNAL', 4); }
        if (!defined('ABJ404_TYPE_HOME')) { define('ABJ404_TYPE_HOME', 5); }
        if (!defined('ABJ404_TYPE_404_DISPLAYED')) { define('ABJ404_TYPE_404_DISPLAYED', 0); }
    }

    public function testPermalinkInfoToArrayPost() {
        if (!function_exists('get_permalink')) { function get_permalink($id) { return 'post/' . $id; } }
        if (!function_exists('get_the_title')) { function get_the_title($id) { return 'Title ' . $id; } }
        if (!function_exists('get_post_status')) { function get_post_status($id) { return 'publish'; } }
        $expected = [
            'id' => '10',
            'type' => strval(ABJ404_TYPE_POST),
            'score' => 55,
            'status' => 'publish',
            'link' => 'post/10',
            'title' => 'Title 10',
        ];
        $this->assertSame($expected, ABJ_404_Solution_Functions::permalinkInfoToArray('10|' . ABJ404_TYPE_POST, 55));
    }

    public function testPermalinkInfoToArrayExternalSpecialID() {
        $options = ['dest404pageURL' => 'http://dest.local'];
        $expected = [
            'id' => strval(ABJ404_TYPE_EXTERNAL),
            'type' => strval(ABJ404_TYPE_EXTERNAL),
            'score' => 0,
            'status' => 'published',
            'link' => 'http://dest.local',
            'title' => '',
        ];
        $this->assertSame($expected, ABJ_404_Solution_Functions::permalinkInfoToArray(ABJ404_TYPE_EXTERNAL . '|' . ABJ404_TYPE_EXTERNAL, 0, null, $options));
    }

    public function testPermalinkInfoToArrayHome() {
        if (!function_exists('get_home_url')) { function get_home_url() { return 'http://home.test'; } }
        if (!function_exists('get_bloginfo')) { function get_bloginfo($arg) { return 'Blog Name'; } }
        $expected = [
            'id' => '1',
            'type' => strval(ABJ404_TYPE_HOME),
            'score' => 5,
            'status' => 'published',
            'link' => 'http://home.test',
            'title' => 'Blog Name',
        ];
        $this->assertSame($expected, ABJ_404_Solution_Functions::permalinkInfoToArray('1|' . ABJ404_TYPE_HOME, 5));
    }

    public function testReadFileContentsWithSQL() {
        $path = ABJ404_PATH . 'data.sql';
        file_put_contents($path, 'SQLDATA');
        $contents = ABJ_404_Solution_Functions::readFileContents($path);
        $this->assertStringContainsString('/* ------------------ ' . $path . ' BEGIN ----- */', $contents);
        $this->assertStringContainsString('SQLDATA', $contents);
        $this->assertStringContainsString('/* ------------------ ' . $path . ' END ----- */', $contents);
        ABJ_404_Solution_Functions::safeUnlink($path);
    }

    public function testReadURLtoFileWithLocalFile() {
        $source = ABJ404_PATH . 'source.txt';
        $dest = ABJ404_PATH . 'dest.txt';
        file_put_contents($source, 'HELLO');
        $this->f->readURLtoFile('file://' . $source, $dest);
        $this->assertFileExists($dest);
        $this->assertSame('HELLO', file_get_contents($dest));
        ABJ_404_Solution_Functions::safeUnlink($source);
        ABJ_404_Solution_Functions::safeUnlink($dest);
    }
}
