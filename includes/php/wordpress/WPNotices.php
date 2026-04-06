<?php


if (!defined('ABSPATH')) {
    exit;
}

class ABJ_404_Solution_WPNotices {
    
    /** @var array<ABJ_404_Solution_WPNotice> */
    private static $adminNotices = array();
    
    /** Display a message with the specified importance level.
     * @param string $noticeLevel see ABJ_404_Solution_WPNotice for notice levels.
     * @param string $message
     */
    /**
     * @param string $noticeLevel
     * @param string $message
     * @return void
     */
    public static function registerAdminMessage(string $noticeLevel, string $message): void {
        $notice = new ABJ_404_Solution_WPNotice($noticeLevel, $message);
        
        self::registerAdminNotice($notice);
    }

    /** Display a message with the specified importance level.
     * @param ABJ_404_Solution_WPNotice $adminNotice
     */
    /**
     * @param ABJ_404_Solution_WPNotice $adminNotice
     * @return void
     */
    public static function registerAdminNotice(ABJ_404_Solution_WPNotice $adminNotice): void {
        self::$adminNotices[] = $adminNotice;
        
        self::$adminNotices = array_unique(self::$adminNotices, SORT_REGULAR);
    }

    /**
     * @return string the messages to display.
     */
    static function echoAdminNotices() {
    	$f = ABJ_404_Solution_Functions::getInstance();
    	$abj404logic = ABJ_404_Solution_PluginLogic::getInstance();

    	$allHTML = '';
    	if (!$abj404logic->userIsPluginAdmin()) {
            return '';
        }

        foreach (self::$adminNotices as $oneNotice) {
            $html = ABJ_404_Solution_Functions::readFileContents(ABJ404_PATH . "/includes/html/notice.html");
            $html = $f->str_replace('{class}', 'notice is-dismissable is-dismissible ' . $oneNotice->getType(), $html);
            $msg = $oneNotice->getMessage();
            $html = $f->str_replace('{message}', esc_html(is_string($msg) ? $msg : (is_scalar($msg) ? (string)$msg : '')), $html);
            
            $allHTML .= $html;
        }
        
        echo $allHTML;
        
        return $allHTML;
    }

}
