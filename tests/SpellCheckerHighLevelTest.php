<?php
require_once __DIR__ . '/../includes/Functions.php';
require_once __DIR__ . '/../includes/php/FunctionsMBString.php';
require_once __DIR__ . '/../includes/php/FunctionsPreg.php';
require_once __DIR__ . '/../includes/Logging.php';
require_once __DIR__ . '/../includes/DataAccess.php';
require_once __DIR__ . '/../includes/PublishedPostsProvider.php';
require_once __DIR__ . '/../includes/PermalinkCache.php';
require_once __DIR__ . '/../includes/SpellChecker.php';

// Define minimal plugin logic used by the spell checker
class ABJ_404_Solution_PluginLogic {
    public $options = [];
    private static $instance = null;
    public static function getInstance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }
    function getOptions($skip_db_check = false) { return $this->options; }
    function thereIsAUserSpecified404Page($id) { return false; }
    function removeHomeDirectory($path) { return ltrim($path, '/'); }
}

// Define missing WP helper functions
if (!function_exists('get_home_url')) { function get_home_url(){ return 'http://example.com'; } }
if (!function_exists('get_option')) { function get_option($name){ return null; } }
if (!function_exists('update_option')) { function update_option($n,$v){} }
if (!function_exists('add_option')) { function add_option($n,$v,$a=null,$b=null){} }
if (!function_exists('absint')) { function absint($v){ return abs(intval($v)); } }
if (!function_exists('get_the_permalink')) { function get_the_permalink($id){ global $mock_posts; return $mock_posts[$id]['url']; } }
if (!function_exists('get_permalink')) { function get_permalink($id){ return get_the_permalink($id); } }
if (!function_exists('get_the_title')) { function get_the_title($id){ global $mock_posts; return $mock_posts[$id]['title']; } }
if (!function_exists('esc_sql')) { function esc_sql($v){ return $v; } }
if (!function_exists('esc_html')) { function esc_html($v){ return $v; } }
if (!function_exists('esc_url')) { function esc_url($v){ return $v; } }
if (!function_exists('add_action')) { function add_action(...$args){} }
if (!function_exists('add_filter')) { function add_filter(...$args){} }
if (!function_exists('wp_next_scheduled')) { function wp_next_scheduled(...$args){ return false; } }
if (!function_exists('wp_unschedule_event')) { function wp_unschedule_event(...$args){} }
if (!function_exists('wp_clear_scheduled_hook')) { function wp_clear_scheduled_hook(...$args){} }
if (!function_exists('wp_schedule_single_event')) { function wp_schedule_single_event(...$args){} }
if (!function_exists('__')) { function __($t,$d=null){ return $t; } }
if (!defined('ABJ404_TYPE_404_DISPLAYED')) { define('ABJ404_TYPE_404_DISPLAYED',0); }
if (!defined('ABJ404_TYPE_POST')) { define('ABJ404_TYPE_POST',1); }
if (!defined('ABJ404_TYPE_CAT')) { define('ABJ404_TYPE_CAT',2); }
if (!defined('ABJ404_TYPE_TAG')) { define('ABJ404_TYPE_TAG',3); }
if (!defined('ABJ404_TYPE_EXTERNAL')) { define('ABJ404_TYPE_EXTERNAL',4); }
if (!defined('ABJ404_TYPE_HOME')) { define('ABJ404_TYPE_HOME',5); }
if (!defined('ABJ404_MAX_URL_LENGTH')) { define('ABJ404_MAX_URL_LENGTH',4096); }
if (!defined('ABJ404_PP')) { define('ABJ404_PP','abj404_solution'); }
if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir().'/wp/'); }
if (!defined('ABJ404_PATH')) { define('ABJ404_PATH', sys_get_temp_dir().'/abj404_test/'); }
if (!defined('ABJ404_VERSION')) { define('ABJ404_VERSION','2.36.9'); }

// Stub DataAccess for unit tests
class SpellCheckerTestDAO extends ABJ_404_Solution_DataAccess {
    public $pages;
    function __construct($pages){ $this->pages = $pages; }
    function getPublishedPagesAndPostsIDs($slug='',$searchTerm='',$limit='',$order='',$extraWhere='') {
        if ($limit !== '') { list($o,$c) = array_map('intval', explode(',', $limit)); $slice = array_slice($this->pages,$o,$c,true); }
        else { $slice = $this->pages; }
        $rows = [];
        foreach ($slice as $id => $data) { $rows[] = (object)['id'=>$id,'url'=>$data['url']]; }
        return $rows; }
    function getPublishedTags($slug=null){ return []; }
    function getPublishedCategories($term_id=null,$slug=null){ return []; }
    function getPermalinkFromCache($id){ return $this->pages[$id]['url']; }
    function storeSpellingPermalinksToCache($a,$b){}
    function getRedirectsWithRegEx(){ return []; }
}
class SpellCheckerTestPermalinkCache extends ABJ_404_Solution_PermalinkCache { function updatePermalinkCache($a,$b=1){ return 0; } }

use PHPUnit\Framework\TestCase;

class SpellCheckerHighLevelTest extends TestCase {
    private $sc;

    private function setupSpellChecker($posts, $options) {
        global $mock_posts; $mock_posts = $posts;
        $dao = new SpellCheckerTestDAO($posts);
        $ref = new ReflectionProperty('ABJ_404_Solution_DataAccess','instance');
        $ref->setAccessible(true); $ref->setValue($dao);
        $pc = new SpellCheckerTestPermalinkCache();
        $refpc = new ReflectionProperty('ABJ_404_Solution_PermalinkCache','instance');
        $refpc->setAccessible(true); $refpc->setValue($pc);
        $pl = ABJ_404_Solution_PluginLogic::getInstance();
        $pl->options = $options;
        $this->sc = ABJ_404_Solution_SpellChecker::getInstance();
    }

    public function testMultipleMatchesOrdering() {
        $posts = [
            1 => ['url'=>'http://example.com/hello-world','title'=>'Hello World'],
            2 => ['url'=>'http://example.com/hello-there','title'=>'Hello There'],
            3 => ['url'=>'http://example.com/goodbye-world','title'=>'Goodbye World'],
        ];
        $options = [
            'suggest_max'=>'5','recognized_post_types'=>'page','recognized_categories'=>'',
            'suggest_cats'=>'0','suggest_tags'=>'0','excludePages[]'=>'','auto_cats'=>'0','auto_tags'=>'0','DB_VERSION'=>ABJ404_VERSION
        ];
        $this->setupSpellChecker($posts, $options);
        $result = $this->sc->findMatchingPosts('hello-worrld','0','0');
        $keys = array_keys($result[0]);
        $this->assertSame(['1|'.ABJ404_TYPE_POST,'2|'.ABJ404_TYPE_POST,'3|'.ABJ404_TYPE_POST], $keys);
    }

    public function testFarMatchesExcluded() {
        $posts = [
            1 => ['url'=>'http://example.com/hello-world','title'=>'Hello World'],
            2 => ['url'=>'http://example.com/hello-there','title'=>'Hello There'],
            3 => ['url'=>'http://example.com/helo-world','title'=>'Helo World'],
            4 => ['url'=>'http://example.com/unrelated','title'=>'Unrelated'],
            5 => ['url'=>'http://example.com/another','title'=>'Another'],
            6 => ['url'=>'http://example.com/different','title'=>'Different'],
        ];
        $options = [
            'suggest_max'=>'3','recognized_post_types'=>'page','recognized_categories'=>'',
            'suggest_cats'=>'0','suggest_tags'=>'0','excludePages[]'=>'','auto_cats'=>'0','auto_tags'=>'0','DB_VERSION'=>ABJ404_VERSION
        ];
        $this->setupSpellChecker($posts, $options);
        $result = $this->sc->findMatchingPosts('hello-world','0','0');
        $keys = array_keys($result[0]);
        $this->assertSame(3, count($keys));
        $this->assertNotContains('4|'.ABJ404_TYPE_POST, $keys);
        $this->assertNotContains('5|'.ABJ404_TYPE_POST, $keys);
        $this->assertNotContains('6|'.ABJ404_TYPE_POST, $keys);
    }
}
?>
