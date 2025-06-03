<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/Functions.php';
require_once __DIR__ . '/../includes/php/FunctionsMBString.php';
require_once __DIR__ . '/../includes/php/FunctionsPreg.php';
require_once __DIR__ . '/../includes/Logging.php';
require_once __DIR__ . '/../includes/DataAccess.php';
require_once __DIR__ . '/../includes/PluginLogic.php';
require_once __DIR__ . '/../includes/php/objs/UserRequest.php';

if (!defined('ABJ404_TYPE_404_DISPLAYED')) { define('ABJ404_TYPE_404_DISPLAYED', 0); }
if (!defined('ABJ404_TYPE_POST')) { define('ABJ404_TYPE_POST', 1); }
if (!defined('ABJ404_TYPE_CAT')) { define('ABJ404_TYPE_CAT', 2); }
if (!defined('ABJ404_TYPE_TAG')) { define('ABJ404_TYPE_TAG', 3); }
if (!defined('ABJ404_TYPE_EXTERNAL')) { define('ABJ404_TYPE_EXTERNAL', 4); }
if (!defined('ABJ404_TYPE_HOME')) { define('ABJ404_TYPE_HOME', 5); }
if (!defined('ABJ404_PP')) { define('ABJ404_PP', 'abj404_solution'); }

use PHPUnit\Framework\TestCase;

class PluginLogicFlowTest extends TestCase {
    private $pluginLogic;
    private $dataAccessStub;

    protected function setUp(): void {
        if (!function_exists('is_admin')) { function is_admin() { return false; } }
        if (!function_exists('__')) { function __($s,$d=null) { return $s; } }
        if (!function_exists('get_home_url')) { function get_home_url() { return 'http://example.com/blog'; } }
        if (!function_exists('esc_html')) { function esc_html($s){ return $s; } }
        if (!function_exists('esc_url_raw')) { function esc_url_raw($s){ return $s; } }
        if (!function_exists('esc_url')) { function esc_url($s){ return $s; } }
        if (!function_exists('wp_kses_post')) { function wp_kses_post($s){ return $s; } }
        if (!function_exists('get_the_title')) { function get_the_title($id){ return 'Title'.$id; } }
        if (!function_exists('get_tag')) { function get_tag($id){ return (object)['name'=>'Tag'.$id]; } }
        if (!function_exists('admin_url')) { function admin_url(){ return 'http://example.com/wp-admin/'; } }
        if (!function_exists('wp_get_current_user')) { function wp_get_current_user(){ return new class { public $caps=[]; function get_role_caps(){return [];}}; } }
        if (!function_exists('get_option')) { function get_option($n){ return ''; } }
        if (!function_exists('update_option')) { function update_option($n,$v){} }
        if (!function_exists('wp_redirect')) { function wp_redirect($l,$s,$n=''){} }

        $_SERVER['REQUEST_URI'] = '/blog/page?foo=bar';
        $_COOKIE = [];

        $this->dataAccessStub = new class extends ABJ_404_Solution_DataAccess {
            public $publishedCategories = [];
            public function __construct() {}
            function getPublishedCategories($term_id = null, $slug = null) {
                return $this->publishedCategories;
            }
        };

        $this->pluginLogic = new ABJ_404_Solution_PluginLogic();
        $this->setSingleton('ABJ_404_Solution_PluginLogic','instance',$this->pluginLogic);
        $this->setSingleton('ABJ_404_Solution_DataAccess','instance',$this->dataAccessStub);
        $this->setSingleton('ABJ_404_Solution_UserRequest','instance', new class {
            function getQueryString(){ return 'a=1&p=2'; }
            function getCommentPagePart(){ return '/comment-page-2'; }
        });
        $options = $this->pluginLogic->getDefaultOptions();
        $this->setProperty($this->pluginLogic,'options',$options);
    }

    private function setSingleton($class, $property, $value) {
        $ref = new ReflectionClass($class);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue(null, $value);
    }

    private function setProperty($object,$property,$value) {
        $ref = new ReflectionClass($object);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($object,$value);
    }

    public function testRemoveHomeDirectory() {
        $this->assertSame('about', $this->pluginLogic->removeHomeDirectory('/blog/about'));
    }

    public function testThereIsAUserSpecified404Page() {
        $this->assertFalse($this->pluginLogic->thereIsAUserSpecified404Page(ABJ404_TYPE_404_DISPLAYED.'|'.ABJ404_TYPE_404_DISPLAYED));
        $this->assertTrue($this->pluginLogic->thereIsAUserSpecified404Page('12|1'));
    }

    public function testReadCookieWithPreviousRequestShort() {
        $cookie = ABJ404_PP.'_REQUEST_URI';
        $_COOKIE[$cookie] = '/prev';
        $_COOKIE[$cookie.'_SHORT'] = '/prev';
        $this->assertSame('/prev', $this->pluginLogic->readCookieWithPreviousRqeuestShort());
        unset($_COOKIE[$cookie]); unset($_COOKIE[$cookie.'_SHORT']);
        $this->assertSame('', $this->pluginLogic->readCookieWithPreviousRqeuestShort());
    }

    public function testGetCommentPartAndQueryPartOfRequest() {
        $this->assertSame('/comment-page-2?a=1', $this->pluginLogic->getCommentPartAndQueryPartOfRequest());
    }

    public function testShouldNotifyAboutCaptured404s() {
        $options = $this->pluginLogic->getDefaultOptions();
        $options['admin_notification'] = '5';
        $this->setProperty($this->pluginLogic,'options',$options);
        $this->assertTrue($this->pluginLogic->shouldNotifyAboutCaptured404s(5));
        $this->assertFalse($this->pluginLogic->shouldNotifyAboutCaptured404s(4));
    }

    public function testGetPageTitleFromIDAndType() {
        $this->dataAccessStub->publishedCategories = [ (object)['name'=>'Cat'] ];
        $this->assertSame('(Default 404 Page)', $this->pluginLogic->getPageTitleFromIDAndType(ABJ404_TYPE_404_DISPLAYED.'|'.ABJ404_TYPE_404_DISPLAYED,''));
        $this->assertSame('(Home Page)', $this->pluginLogic->getPageTitleFromIDAndType('5|5',''));
        $this->assertSame('http://ext', $this->pluginLogic->getPageTitleFromIDAndType('0|4','http://ext'));
        $this->assertSame('Title10', $this->pluginLogic->getPageTitleFromIDAndType('10|1',''));
        $this->assertSame('Cat', $this->pluginLogic->getPageTitleFromIDAndType('2|2',''));
        $this->assertSame('Tag7', $this->pluginLogic->getPageTitleFromIDAndType('7|3',''));
    }
}
?>
