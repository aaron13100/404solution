<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders legacy redirect destination option groups for posts and taxonomies.
 */
class ABJ_404_Solution_RedirectDestinationOptionsPresenter {

    /**
     * @param string $dest
     * @param array<int, object> $rows
     * @param callable|null $debugInfoWriter Receives debug text while rendering large option sets.
     * @return string
     */
    public function buildPostOptions(string $dest, array $rows, ?callable $debugInfoWriter = null): string {
        $content = array();
        $rowCounter = 0;
        $currentPostType = '';

        foreach ($rows as $row) {
            $rowCounter++;
            /** @var object{id: int|string, post_type: string, depth?: int} $row */
            $id = is_scalar($row->id) ? (string)$row->id : '';
            $titleId = is_numeric($id) ? (int)$id : 0;
            $theTitle = get_the_title($titleId);
            $thisval = $id . "|" . ABJ404_TYPE_POST;

            if ($debugInfoWriter !== null) {
                $debugInfoWriter('Before row: ' . $rowCounter . ', Title: ' . $theTitle .
                        ', Post type: ' . $row->post_type);
            }

            if ($row->post_type != $currentPostType) {
                if ($currentPostType != '') {
                    $content[] = "\n" . '</optgroup>' . "\n";
                }

                $postTypeLabel = $this->postTypeLabel((string)$row->post_type);
                $content[] = "\n" . '<optgroup label="' . esc_attr($postTypeLabel) . '">' . "\n";
                $currentPostType = $row->post_type;
            }

            $content[] = "\n <option value=\"";
            $content[] = esc_attr($thisval);
            $content[] = "\"";
            $content[] = ($thisval == $dest) ? " selected" : "";
            $content[] = ">";

            $depth = property_exists($row, 'depth') ? intval($row->depth) : 0;
            for ($i = 0; $i < $depth; $i++) {
                $content[] = "&nbsp;&nbsp;&nbsp;";
            }

            $content[] = esc_html($this->postTypeLabel((string)$row->post_type));
            $content[] = ": ";
            $content[] = esc_html($theTitle);
            $content[] = "</option>";

            if ($debugInfoWriter !== null) {
                $debugInfoWriter('After row: ' . $rowCounter . ', Title: ' . $theTitle .
                        ', Post type: ' . $row->post_type);
            }
        }

        if ($currentPostType !== '') {
            $content[] = "\n" . '</optgroup>' . "\n";
        }

        if ($debugInfoWriter !== null) {
            $debugInfoWriter('Cleared after building redirect destination page list.');
        }

        return implode('', $content);
    }

    /**
     * @param string $dest
     * @param array<int, WP_Term|object> $categories
     * @param array<int, WP_Term|object> $tags
     * @param array<string, array<int, WP_Term|object>> $customCategories
     * @return string
     */
    public function buildTaxonomyOptions(string $dest, array $categories, array $tags, array $customCategories): string {
        $content = "";
        $content .= "\n" . '<optgroup label="' . esc_attr(__('Categories', '404-solution')) . '">' . "\n";

        foreach ($categories as $cat) {
            /** @var WP_Term|object{term_id: int|string, name: string, taxonomy: string} $cat */
            if ($cat->taxonomy != 'category') {
                continue;
            }
            $content .= $this->buildTaxonomyOption(
                (string)$cat->term_id,
                ABJ404_TYPE_CAT,
                __('Category', '404-solution'),
                (string)$cat->name,
                $dest
            );
        }
        $content .= "\n" . '</optgroup>' . "\n";

        $content .= "\n" . '<optgroup label="' . esc_attr(__('Tags', '404-solution')) . '">' . "\n";
        foreach ($tags as $tag) {
            /** @var WP_Term|object{term_id: int|string, name: string} $tag */
            $content .= $this->buildTaxonomyOption(
                (string)$tag->term_id,
                ABJ404_TYPE_TAG,
                __('Tag', '404-solution'),
                (string)$tag->name,
                $dest
            );
        }
        $content .= "\n" . '</optgroup>' . "\n";

        foreach ($customCategories as $taxonomy => $catRow) {
            $content .= "\n" . '<optgroup label="' . esc_attr($taxonomy) . '">' . "\n";
            foreach ($catRow as $cat) {
                /** @var WP_Term|object{term_id: int|string, name: string} $cat */
                $content .= $this->buildTaxonomyOption(
                    (string)$cat->term_id,
                    ABJ404_TYPE_CAT,
                    __('Custom', '404-solution'),
                    (string)$cat->name,
                    $dest
                );
            }
            $content .= "\n" . '</optgroup>' . "\n";
        }

        return $content;
    }

    /**
     * @param string $id
     * @param int $type
     * @param string $label
     * @param string $title
     * @param string $dest
     * @return string
     */
    private function buildTaxonomyOption(string $id, int $type, string $label, string $title, string $dest): string {
        $thisval = $id . "|" . $type;
        $selected = ($thisval == $dest) ? " selected" : "";
        return "\n<option value=\"" . esc_attr($thisval) . "\"" . $selected . ">" .
                esc_html($label) . ": " . esc_html($title) . "</option>";
    }

    private function postTypeLabel(string $postType): string {
        if (function_exists('get_post_type_object')) {
            $postTypeObject = get_post_type_object($postType);
            if (is_object($postTypeObject) && property_exists($postTypeObject, 'labels') && is_object($postTypeObject->labels)) {
                $labels = $postTypeObject->labels;
                $label = $labels->singular_name ?? $labels->name ?? '';
                if (is_scalar($label) && (string)$label !== '') {
                    return (string)$label;
                }
            }
        }

        return ucwords(str_replace(array('_', '-'), ' ', $postType));
    }
}
