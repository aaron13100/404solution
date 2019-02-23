<?php

// turn on debug for localhost etc
$whitelist = array('127.0.0.1', '::1', 'localhost', 'wealth-psychology.com', 'www.wealth-psychology.com');
if (in_array($_SERVER['SERVER_NAME'], $whitelist)) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

class ABJ_404_Solution_WPNotices {
    
    /** @var array<WPNotice> */
    private static $adminNotices = array();
    
    /** Add a hook for displaying messages. */
    static function init() {
        add_action('admin_notices', array(get_called_class(), 'getAdminNotices'));
    }
    
    /** Display a message with the specified importance level.
     * @param type $noticeLevel
     * @param type $message
     */
    public static function registerAdminMessage($noticeLevel, $message) {
        $notice = new ABJ_404_Solution_WPNotice($noticeLevel, $message);
        
        self::registerAdminNotice($notice);
    }

    /** Display a message with the specified importance level.
     * @param type $adminNotice
     */
    public static function registerAdminNotice($adminNotice) {
        self::$adminNotices[] = $adminNotice;
        
        self::$adminNotices = array_unique(self::$adminNotices, SORT_REGULAR);
    }

    /** 
     * @return type the messages to display.
     */
    static function getAdminNotices() {
        $allHTML = '';
        if (!current_user_can('administrator')) {
            return;
        }
        
        foreach (self::$adminNotices as $oneNotice) {
            $html = ABJ_FC_FileUtils::readFileContents(ABJ_FC_PATH . "/includes/html/notice.html");
            $html = str_replace('{class}', 'notice is-dismissable is-dismissible ' . $oneNotice->getType(), $html);
            $html = str_replace('{message}', esc_html($oneNotice->getMessage()), $html);
            
            $allHTML .= $html;
        }
        
        echo $allHTML;
        
        return;
    }

}

ABJ_404_Solution_WPNotices::init();
