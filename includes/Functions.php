<?php declare(strict_types=1); 

/* Static functions that can be used from anywhere.  */

class ABJ_404_Solution_Functions {
    /** Turns ID|TYPE, SCORE into an array with id, type, score, link, and title.
     *
     * @param type $idAndType e.g. 15|POST is a page ID of 15 and a type POST.
     * @param type $linkScore
     * @return type an array with id, type, score, link, and title.
     */
    static function permalinkInfoToArray($idAndType, $linkScore) {
        global $abj404logging;
        $permalink = array();

        $meta = explode("|", $idAndType);

        $permalink['id'] = $meta[0];
        $permalink['type'] = $meta[1];
        $permalink['score'] = $linkScore;

        if ($permalink['type'] == ABJ404_POST) {
            $permalink['link'] = get_permalink($permalink['id']);
            $permalink['title'] = get_the_title($permalink['id']);
        } else if ($permalink['type'] == ABJ404_TAG) {
            $permalink['link'] = get_tag_link($permalink['id']);
            $tag = get_term($permalink['id'], 'post_tag');
            $permalink['title'] = $tag->name;
        } else if ($permalink['type'] == ABJ404_CAT) {
            $permalink['link'] = get_category_link($permalink['id']);
            $cat = get_term($permalink['id'], 'category');
            $permalink['title'] = $cat->name;
        } else if ($permalink['type'] == ABJ404_HOME) {
            $permalink['link'] = get_home_url();
            $permalink['title'] = get_bloginfo('name');
        } else {
            $abj404logging->errorMessage("Unrecognized permalink type: " . 
                    wp_kses_post(json_encode($permalink)));
        }

        return $permalink;
    }
}

