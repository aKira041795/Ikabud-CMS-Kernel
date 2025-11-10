<?php
    /**
     * List block part for displaying page content in page.php
     *
     * @package MoreNews
     */
    

    $morenews_content_view = morenews_get_option('archive_content_view');
    $morenews_show_excerpt = true;
    if($morenews_content_view == 'archive-content-none'){
        $morenews_show_excerpt = false;
    }

?>
<div class="archive-list-post list-style">
    <?php do_action('morenews_action_loop_list', $post->ID, 'medium_large', 0, true, true, $morenews_show_excerpt, true, $morenews_content_view); ?>
    <?php
        wp_link_pages(array(
            'before' => '<div class="page-links">' . esc_html__('Pages:', 'morenews'),
            'after' => '</div>',
        ));
    ?>
</div>









