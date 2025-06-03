<?php
require_once __DIR__ . '/../includes/Functions.php';
require_once __DIR__ . '/../includes/php/FunctionsMBString.php';
require_once __DIR__ . '/../includes/php/FunctionsPreg.php';

use PHPUnit\Framework\TestCase;

class FunctionsHighLevelTest extends TestCase {
    private $f;

    protected function setUp(): void {
        $this->f = ABJ_404_Solution_Functions::getInstance();
    }

    public function testSanitizeTextFieldRecursive() {
        if (!function_exists('sanitize_text_field')) {
            function sanitize_text_field($str) {
                return trim(strip_tags($str));
            }
        }
        $input = [
            'a' => ' <b>Hello</b> ',
            'b' => [
                'c' => "<script>alert('x')</script> test"
            ]
        ];
        $expected = [
            'a' => 'Hello',
            'b' => [
                'c' => "alert('x') test"
            ]
        ];
        $this->assertSame($expected, $this->f->sanitize_text_field_recursive($input));
    }

    public function testEscapeForXSS() {
        $input = '<script>alert("x")</script>';
        $result = $this->f->escapeForXSS($input);
        $this->assertSame('scriptalertx/script', $result);
    }

    public function testSingleStrReplaceAndNullReplacement() {
        $this->assertSame('foo  bar', $this->f->str_replace('x', null, 'foo x bar'));
        $this->assertSame('baz baz bar', $this->f->single_str_replace('foo', 'baz', 'foo foo bar'));
    }

    public function testFileSystemHelpers() {
        $base = ABJ404_PATH . 'testDir';
        $sub = $base . '/sub';
        $file = $sub . '/file.txt';
        $this->f->createDirectoryWithErrorMessages($sub);
        file_put_contents($file, 'data');
        $this->assertFileExists($file);
        ABJ_404_Solution_Functions::safeUnlink($file);
        $this->assertFileDoesNotExist($file);
        $this->assertTrue(ABJ_404_Solution_Functions::deleteDirectoryRecursively($base));
        $this->assertDirectoryDoesNotExist($base);
    }
}
?>
