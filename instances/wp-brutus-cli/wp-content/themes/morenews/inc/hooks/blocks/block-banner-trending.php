<?php
$morenews_banner_layout = morenews_get_option('select_main_banner_layout_section');
$morenews_posts_filter_by = morenews_get_option('select_trending_post_filterby');
if ($morenews_posts_filter_by == 'tag') {
    $morenews_editors_pick_category = morenews_get_option('select_trending_post_tag');
    $morenews_filterby = 'tag';
} elseif ($morenews_posts_filter_by == 'view') {
    $morenews_editors_pick_category = 0;
    $morenews_filterby = 'view';
} else {
    $morenews_editors_pick_category = morenews_get_option('select_trending_post_category');
    $morenews_filterby = 'cat';
}

$morenews_trending_post_number_of_slides = morenews_get_option('trending_post_number_of_slides');
$morenews_all_posts_vertical = morenews_get_posts($morenews_trending_post_number_of_slides, $morenews_editors_pick_category, $morenews_filterby);

?>
<div class="full-wid-resp">
    <div class="slick-wrapper banner-vertical-slider af-widget-carousel">
        <?php
        $morenews_count = 1;
        if ($morenews_all_posts_vertical->have_posts()) :
            while ($morenews_all_posts_vertical->have_posts()) : $morenews_all_posts_vertical->the_post();

                global $post;

                ?>
                <div class="slick-item">
                    <div class="aft-trending-posts list-part af-sec-post">
                        <?php do_action('morenews_action_loop_list', $post->ID, 'thumbnail', $morenews_count, false, true, false); ?>
                    </div>
                </div>
                <?php
                $morenews_count++;
            endwhile;
        endif;
        wp_reset_postdata();
        ?>
    </div>
    <div class="af-trending-navcontrols af-slick-navcontrols"></div>
</div>
