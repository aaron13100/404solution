<?php

// turn on debug for localhost etc
if (in_array($_SERVER['SERVER_NAME'], $GLOBALS['abj404_whitelist'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

class ABJ_404_Solution_WPNotices {
    
    /** @var array<WPNotice> */
    private static $adminNotices = array();
    
    /** Add a hook for displaying messages. */
    static function init() {
        //add_action('admin_notices', array(get_called_class(), 'getAdminNotices'));
    }
    
    /** Display a message with the specified importance level.
     * @param string $noticeLevel
     * @param string $message
     */
    public static function registerAdminMessage($noticeLevel, $message) {
        $notice = new ABJ_404_Solution_WPNotice($noticeLevel, $message);
        
        self::registerAdminNotice($notice);
    }

    /** Display a message with the specified importance level.
     * @param ABJ_404_Solution_WPNotice $adminNotice
     */
    public static function registerAdminNotice($adminNotice) {
        self::$adminNotices[] = $adminNotice;
        
        self::$adminNotices = array_unique(self::$adminNotices, SORT_REGULAR);
    }

    /** 
     * @return string the messages to display.
     */
    static function echoAdminNotices() {
        $allHTML = '';
        if (!current_user_can('administrator')) {
            return;
        }
        
        foreach (self::$adminNotices as $oneNotice) {
            $html = ABJ_404_Solution_Functions::readFileContents(ABJ404_PATH . "/includes/html/notice.html");
            $html = str_replace('{class}', 'notice is-dismissable is-dismissible ' . $oneNotice->getType(), $html);
            $html = str_replace('{message}', esc_html($oneNotice->getMessage()), $html);
            
            $allHTML .= $html;
        }
        
        echo $allHTML;
        
        return;
    }

}

ABJ_404_Solution_WPNotices::init();
