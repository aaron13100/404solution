<?php

/* Static functions that can be used from anywhere.  */

class ABJ_404_Solution_Functions {
    /** Turns ID|TYPE, SCORE into an array with id, type, score, link, and title.
     *
     * @param type $idAndType e.g. 15|POST is a page ID of 15 and a type POST.
     * @param type $linkScore
     * @return type an array with id, type, score, link, and title.
     */
    static function permalinkInfoToArray($idAndType, $linkScore) {
        $permalink = array();

        $meta = explode("|", $idAndType);

        $permalink['id'] = $meta[0];
        $permalink['type'] = $meta[1];
        $permalink['score'] = $linkScore;

        if ($permalink['type'] == "POST") {
            $permalink['link'] = get_permalink($permalink['id']);
            $permalink['title'] = get_the_title($permalink['id']);
        } else if ($permalink['type'] == "TAG") {
            $permalink['link'] = get_tag_link($permalink['id']);
            $tag = get_term($permalink['id'], 'post_tag');
            $permalink['title'] = $tag->name;
        } else if ($permalink['type'] == "CAT") {
            $permalink['link'] = get_category_link($permalink['id']);
            $cat = get_term($permalink['id'], 'category');
            $permalink['title'] = $cat->name;
        }

        return $permalink;
    }

    /** @return boolean true if debug mode is on. false otherwise. */
    static function isDebug() {
        global $abj404logic;
        $options = $abj404logic->getOptions(1);

        if (isset($options['debug_mode']) && $options['debug_mode'] == true) {
            return true;
        }
        return false;
    }
    
    /** Send a message to the error_log if debug mode is on. 
     * This goes to a file and is used by every other class so it goes here.
     * @param type $message  */
    static function debugMessage($message) {
        if (ABJ_404_Solution_Functions::isDebug()) {
            error_log("ABJ-404-SOLUTION: " . $message);
        }
    }

    /** Always send a message to the error_log.
     * This goes to a file and is used by every other class so it goes here.
     * @param type $message  */
    static function errorMessage($message) {
        error_log("ABJ-404-SOLUTION: " . $message);
    }
    
}

