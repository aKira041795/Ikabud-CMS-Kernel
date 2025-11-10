<div class="af-main-banner-thumb-posts">
    <div class="section-wrapper">
        <div class="small-grid-style clearfix">
            <?php
                
                $morenews_posts_filter_by = morenews_get_option('select_editors_picks_filterby');
                
                if ($morenews_posts_filter_by == 'tag') {
                    $morenews_editors_pick_category = morenews_get_option('select_editors_picks_news_tag');
                    $morenews_filterby = 'tag';
                } else {
                    
                    $morenews_editors_pick_category = morenews_get_option('select_editors_picks_news_category');
                    $morenews_filterby = 'cat';
                }
                
                $morenews_featured_posts = morenews_get_posts(2, $morenews_editors_pick_category, $morenews_filterby);
                if ($morenews_featured_posts->have_posts()) :
                    $morenews_count = 1;
                    while ($morenews_featured_posts->have_posts()) :
                        $morenews_featured_posts->the_post();
                        global $post;

                        ?>

                        <div class="af-sec-post">
                            <?php do_action('morenews_action_loop_grid', $post->ID, 'grid-design-texts-over-image'); ?>
                        </div>


                        <?php
                        $morenews_count++;
                    endwhile;
                    wp_reset_postdata();
                    ?>
                <?php endif; ?>
        </div>
    </div>
</div>
<!-- Editors Pick line END -->
