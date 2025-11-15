<?php

/**
 * List block part for displaying page content in page.php
 *
 * @package MoreNews
 */
?>

<div class="promotionspace enable-promotionspace">
    <div class="af-reated-posts morenews-customizer">
        <?php
        global $post;
        $morenews_categories = get_the_category($post->ID);
        $morenews_related_section_title = morenews_get_option('single_related_posts_title');
        $morenews_number_of_related_posts = 3;

        if ($morenews_categories) {
            $morenews_cat_ids = array();
            foreach ($morenews_categories as $morenews_category) $morenews_cat_ids[] = $morenews_category->term_id;
            $morenews_args = array(
                'category__in' => $morenews_cat_ids,
                'post__not_in' => array($post->ID),
                'posts_per_page' => $morenews_number_of_related_posts, // Number of related posts to display.
                'ignore_sticky_posts' => 1
            );
            $morenews_related_posts = new wp_query($morenews_args);

            if ($morenews_related_posts->have_posts()) { ?>
                <?php morenews_render_section_title($morenews_related_section_title);

                ?>
            <?php }
            ?>
            <div class="af-container-row clearfix">
                <?php
                while ($morenews_related_posts->have_posts()) {
                    $morenews_related_posts->the_post();
                    global $post;


                ?>
                    <div class="col-3 float-l pad latest-posts-grid af-sec-post">
                        <?php do_action('morenews_action_loop_grid', $post->ID); ?>
                    </div>
            <?php }
            }
            wp_reset_postdata();
            ?>
            </div>
    </div>
</div>