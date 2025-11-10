<?php
if (!function_exists('morenews_single_header')) :
    /**
     * Banner Slider
     *
     * @since MoreNews 1.0.0
     *
     */
    function morenews_single_header()
    {
        global $post;
        $morenews_post_id = $post->ID;
        ?>
        <header class="entry-header pos-rel">
            <div class="read-details">
                <div class="entry-header-details af-cat-widget-carousel">
                    <?php if ('post' === get_post_type()) : ?>

                        <div class="figure-categories read-categories figure-categories-bg categories-inside-image">
                            <?php morenews_post_format($post->ID); ?>
                            <?php morenews_post_categories(true); ?>
                        </div>
                    <?php endif; ?>
                    <?php the_title('<h1 class="entry-title">', '</h1>'); ?>



                    <div class="aft-post-excerpt-and-meta color-pad">
                        <?php if ('post' === get_post_type($morenews_post_id)) :
                            if (has_excerpt($morenews_post_id)):

                                ?>
                                <div class="post-excerpt">
                                    <?php echo wp_kses_post(get_the_excerpt($post->ID)); ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <div class="entry-meta author-links">

                            <?php morenews_post_item_meta(); ?>
                            <?php morenews_count_content_words($post->ID); ?>
                            <?php morenews_single_post_commtents_view($post->ID); ?>
                            <?php
                            $morenews_social_share_icon_opt = morenews_get_option('single_post_social_share_view');
                            if($morenews_social_share_icon_opt == 'after-title-default'){
                                morenews_single_post_social_share_icons($post->ID);
                            }

                            ?>

                        </div>
                    </div>
                </div>
            </div>



        </header><!-- .entry-header -->




        <!-- end slider-section -->
        <?php
    }
endif;
add_action('morenews_action_single_header', 'morenews_single_header', 40);