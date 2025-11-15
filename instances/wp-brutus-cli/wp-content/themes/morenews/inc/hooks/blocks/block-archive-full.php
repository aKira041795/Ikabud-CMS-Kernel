<?php
/**
 * Full block part for displaying page content in page.php
 *
 * @package MoreNews
 */

$morenews_thumbnail_size = 'large';
$morenews_grid_design = 'grid-design-default';
$morenews_title_position = 'bottom';

$morenews_content_view = morenews_get_option('archive_content_view');

$morenews_term_meta_grid = '';
if(is_category()){
    $morenews_queried_object = get_queried_object();
    $morenews_t_id = $morenews_queried_object->term_id;
    $morenews_term_meta_grid = get_option("category_layout_full_$morenews_t_id");
}



$morenews_archive_image = morenews_get_option('archive_layout_full');
if($morenews_archive_image == 'full-title-first'){
    $morenews_title_position = 'top';
}



$morenews_show_excerpt = true;
if ($morenews_content_view == 'archive-content-none') {
    $morenews_show_excerpt = false;
}
do_action('morenews_action_loop_grid', $post->ID, $morenews_grid_design, $morenews_thumbnail_size, $morenews_show_excerpt, $morenews_content_view, $morenews_title_position);