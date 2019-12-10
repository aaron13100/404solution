<?php

// turn on debug for localhost etc
if ($GLOBALS['abj404_display_errors']) {
	error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

class ABJ_404_Solution_WPNotices {
    
    /** @var array<WPNotice> */
    private static $adminNotices = array();
    
    /** Display a message with the specified importance level.
     * @param string $noticeLevel see ABJ_404_Solution_WPNotice for notice levels.
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
    	$f = ABJ_404_Solution_Functions::getInstance();
    	
    	$allHTML = '';
        if (!current_user_can('administrator')) {
            return;
        }
        
        foreach (self::$adminNotices as $oneNotice) {
            $html = ABJ_404_Solution_Functions::readFileContents(ABJ404_PATH . "/includes/html/notice.html");
            $html = $f->str_replace('{class}', 'notice is-dismissable is-dismissible ' . $oneNotice->getType(), $html);
            $html = $f->str_replace('{message}', esc_html($oneNotice->getMessage()), $html);
            
            $allHTML .= $html;
        }
        
        echo $allHTML;
        
        return;
    }

}
