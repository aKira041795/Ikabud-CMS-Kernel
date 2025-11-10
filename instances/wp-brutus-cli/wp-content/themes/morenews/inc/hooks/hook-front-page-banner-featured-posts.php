<?php
if (!function_exists('morenews_banner_featured_posts')):
    /**
     * Ticker Slider
     *
     * @since MoreNews 1.0.0
     *
     */
    function morenews_banner_featured_posts()
    {

        $morenews_enable_featured_news = morenews_get_option('show_featured_posts_section');
        $morenews_category = morenews_get_option('select_featured_news_category');
        $morenews_number_of_featured_news = morenews_get_option('number_of_featured_news');
        $morenews_featured_posts = morenews_get_posts($morenews_number_of_featured_news, $morenews_category);
        $color_class = '';
        if (absint($morenews_category) > 0) {
            $color_id = "category_color_" . $morenews_category;
            // retrieve the existing value(s) for this meta field. This returns an array
            $term_meta = get_option($color_id);
            $color_class = ($term_meta) ? $term_meta['color_class_term_meta'] : 'category-color-1';
        }

        if ($morenews_enable_featured_news):
            $morenews_featured_news_title = morenews_get_option('featured_news_section_title');
            ?>
            <div class="af-main-banner-featured-posts featured-posts morenews-customizer">

                <?php if (!empty($morenews_featured_news_title)): ?>
                    <?php morenews_render_section_title($morenews_featured_news_title, $color_class); ?>
                <?php endif; ?>



                <div class="section-wrapper af-widget-body">
                    <div class="af-container-row clearfix">
                        <?php

                        
                        if ($morenews_featured_posts->have_posts()) :
                            while ($morenews_featured_posts->have_posts()) :
                                $morenews_featured_posts->the_post();
                                global $post;
                                ?>


                                    <div class="af-sec-post col-4 pad float-l">
                                        <?php do_action('morenews_action_loop_grid', $post->ID); ?>
                                    </div>


                            <?php endwhile;
                        endif;
                        wp_reset_postdata();
                        ?>
                    </div>
                </div>
            </div>

        <?php endif; ?>

        <!-- Trending line END -->
        <?php

    }
endif;

add_action('morenews_action_banner_featured_posts', 'morenews_banner_featured_posts', 10);