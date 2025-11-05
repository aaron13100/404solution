<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/Functions.php';
require_once __DIR__ . '/../includes/php/FunctionsMBString.php';
require_once __DIR__ . '/../includes/php/FunctionsPreg.php';
require_once __DIR__ . '/../includes/Logging.php';
require_once __DIR__ . '/../includes/DataAccess.php';
require_once __DIR__ . '/../includes/PluginLogic.php';
require_once __DIR__ . '/../includes/SpellChecker.php';
require_once __DIR__ . '/../includes/php/objs/UserRequest.php';
require_once __DIR__ . '/../includes/WordPress_Connector.php';

if (!defined('ABJ404_TYPE_404_DISPLAYED')) { define('ABJ404_TYPE_404_DISPLAYED', 0); }

use PHPUnit\Framework\TestCase;

class Process404HighLevelTest extends TestCase {
    private $pluginLogicStub;
    private $dataAccessStub;
    private $spellCheckerStub;

    protected function setUp(): void {
        if (!function_exists('is_404')) { function is_404() { return true; } }
        if (!function_exists('is_admin')) { function is_admin() { return false; } }
        if (!function_exists('is_single')) { function is_single() { return false; } }
        if (!function_exists('is_page')) { function is_page() { return false; } }
        if (!function_exists('is_feed')) { function is_feed() { return false; } }
        if (!function_exists('is_trackback')) { function is_trackback() { return false; } }
        if (!function_exists('is_preview')) { function is_preview() { return false; } }
        if (!function_exists('esc_html')) { function esc_html($s) { return $s; } }
        if (!function_exists('esc_sql')) { function esc_sql($s) { return $s; } }
        if (!function_exists('esc_url')) { function esc_url($s) { return $s; } }
        if (!function_exists('esc_url_raw')) { function esc_url_raw($s) { return $s; } }
        if (!function_exists('wp_kses_post')) { function wp_kses_post($s) { return $s; } }
        if (!function_exists('wp_redirect')) { function wp_redirect($l,$s,$n=''){} }
        if (!function_exists('get_option')) { function get_option($n) { return ''; } }
        if (!function_exists('update_option')) { function update_option($n,$v){} }
        if (!function_exists('wp_get_current_user')) { function wp_get_current_user() { return new class { public $caps = []; function get_role_caps(){ return []; } }; } }

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_URI'] = '/missing-page';

        $this->pluginLogicStub = new class extends ABJ_404_Solution_PluginLogic {
            public $sentTo404 = '';
            public $forceRedirects = [];
            public function __construct() {}
            function getOptions($skip_db_check = false) {
                return [
                    'auto_redirects' => '0',
                    'default_redirect' => '302',
                    'capture_404' => '0',
                    'remove_matches' => '0',
                    'auto_score' => '50',
                    'template_redirect_priority' => '9',
                    'auto_cats' => '0',
                    'auto_tags' => '0',
                    'dest404page' => '0|' . ABJ404_TYPE_404_DISPLAYED,
                ];
            }
            function initializeIgnoreValues($urlRequest, $urlSlugOnly) {
                $_REQUEST[ABJ404_PP]['ignore_donotprocess'] = null;
            }
            function sendTo404Page($requestedURL, $reason = '', $useUserSpecified404 = true) {
                $this->sentTo404 = $requestedURL;
            }
            function forceRedirect($location, $status = 302, $type = -1, $requestedURL = '') {
                $this->forceRedirects[] = [$location, $status, $type, $requestedURL];
            }
            function tryNormalPostQuery($options) {}
            function thereIsAUserSpecified404Page($dest404page) { return false; }
        };

        $this->dataAccessStub = new class extends ABJ_404_Solution_DataAccess {
            public $loggedHits = [];
            public function __construct() {}
            function logRedirectHit($from, $to, $reason, $requestedURL = null) {
                $this->loggedHits[] = [$from, $to, $reason];
            }
            function getActiveRedirectForURL($url) { return ['id' => 0]; }
            function getExistingRedirectForURL($url) { return ['id' => 0]; }
            function setupRedirect($fromURL, $status, $type, $final_dest, $code, $disabled = 0) {}
        };

        $this->spellCheckerStub = new class extends ABJ_404_Solution_SpellChecker {
            public function __construct() {}
            function getPermalinkUsingRegEx($url) { return []; }
            function getPermalinkUsingSlug($slug) { return []; }
            function getPermalinkUsingSpelling($slug) { return []; }
        };

        $this->setSingleton('ABJ_404_Solution_PluginLogic','instance',$this->pluginLogicStub);
        $this->setSingleton('ABJ_404_Solution_DataAccess','instance',$this->dataAccessStub);
        $this->setSingleton('ABJ_404_Solution_SpellChecker','instance',$this->spellCheckerStub);
        $this->setSingleton('ABJ_404_Solution_UserRequest','instance', new class {
            function getPath() { return '/missing-page'; }
            function getOnlyTheSlug() { return 'missing-page'; }
            function getPathWithSortedQueryString() { return '/missing-page'; }
            function getRequestURIWithoutCommentsPage() { return '/missing-page'; }
        });
        $GLOBALS['wp_query'] = (object)['query' => []];
    }

    private function setSingleton($class, $property, $value) {
        $ref = new ReflectionClass($class);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue(null, $value);
    }

    public function testProcess404SendsTo404() {
        $connector = ABJ_404_Solution_WordPress_Connector::getInstance();
        $connector->process404();
        $this->assertSame('/missing-page', $this->pluginLogicStub->sentTo404);
        $this->assertCount(0, $this->pluginLogicStub->forceRedirects);
    }
}
?>
