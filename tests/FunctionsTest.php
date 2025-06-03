<?php
require_once __DIR__ . '/../includes/Functions.php';
require_once __DIR__ . '/../includes/php/FunctionsMBString.php';
require_once __DIR__ . '/../includes/php/FunctionsPreg.php';

use PHPUnit\Framework\TestCase;

class FunctionsTest extends TestCase {
    private $f;

    protected function setUp(): void {
        $this->f = ABJ_404_Solution_Functions::getInstance();
    }

    public function testSelectivelyURLEncode() {
        $result = $this->f->selectivelyURLEncode("résumé and foo <bar>");
        $this->assertSame("résumé and foo %3Cbar%3E", $result);
    }

    public function testUrlencodeEmojis() {
        $result = $this->f->urlencodeEmojis("hello 😀 world");
        $this->assertSame("hello %F0%9F%98%80 world", $result);
    }

    public function testExplodeNewline() {
        $result = $this->f->explodeNewline("Foo\nBar\n\nBaz");
        $this->assertSame(['foo','bar','baz'], array_values($result));
    }

    public function testDecodeComplicatedData() {
        $encoded = urlencode('{"foo":"bar"}');
        $result = $this->f->decodeComplicatedData($encoded);
        $this->assertSame(['foo' => 'bar'], $result);
    }

    public function testMd5LastOctet() {
        $hashed = $this->f->md5lastOctet('192.168.0.1');
        $this->assertStringStartsWith('192.168.0.', $hashed);
        $this->assertNotSame('192.168.0.1', $hashed);
    }

    public function testEndsWithCaseSensitive() {
        $this->assertTrue($this->f->endsWithCaseSensitive('HelloWorld', 'World'));
        $this->assertFalse($this->f->endsWithCaseSensitive('HelloWorld', 'world'));
    }

    public function testEndsWithCaseInsensitive() {
        $this->assertTrue($this->f->endsWithCaseInsensitive('HelloWorld', 'world'));
        $this->assertFalse($this->f->endsWithCaseInsensitive('HelloWorld', 'planet'));
    }

    public function testSortQueryString() {
        $this->assertSame('a=1&b=2', $this->f->sortQueryString(['query' => 'b=2&a=1']));
    }

    public function testRemovePageIDFromQueryString() {
        $this->assertSame('a=1&b=2', $this->f->removePageIDFromQueryString('a=1&p=5&b=2'));
    }
}
