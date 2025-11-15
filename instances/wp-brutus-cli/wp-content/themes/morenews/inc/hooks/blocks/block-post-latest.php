<?php
    /**
     * List block part for displaying latest posts in footer.php
     *
     * @package MoreNews
     */
    
    $morenews_latest_posts_title = morenews_get_option('frontpage_latest_posts_section_title');
    $morenews_latest_posts_subtitle = morenews_get_option('frontpage_latest_posts_section_subtitle');
    $morenews_number_of_posts = morenews_get_option('number_of_frontpage_latest_posts');
    $morenews_all_posts = morenews_get_posts($morenews_number_of_posts);
?>
<div class="af-main-banner-latest-posts grid-layout morenews-customizer">
    <div class="container-wrapper">
        <div class="widget-title-section">
            <?php if (!empty($morenews_latest_posts_title)): ?>
                <?php morenews_render_section_title($morenews_latest_posts_title); ?>
            <?php endif; ?>
        </div>
        <div class="af-container-row clearfix">
            <?php
                if ($morenews_all_posts->have_posts()) :
                    while ($morenews_all_posts->have_posts()) : $morenews_all_posts->the_post();
                        global $post;

                        ?>
                        <div class="col-4 pad float-l">
                            <?php do_action('morenews_action_loop_grid', $post->ID); ?>
                        </div>
                    <?php
                    endwhile; ?>
                <?php
                endif;
                wp_reset_postdata();
            ?>
        </div>
    </div>
</div>
