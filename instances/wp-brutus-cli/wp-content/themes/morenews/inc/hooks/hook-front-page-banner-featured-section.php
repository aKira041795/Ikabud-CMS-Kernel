<?php
if (!function_exists('morenews_banner_featured_section')):
    /**
     * Ticker Slider
     *
     * @since MoreNews 1.0.0
     *
     */
    function morenews_banner_featured_section()
    {
        ?>
        <div class="aft-frontpage-feature-section-wrapper">

            <?php $morenews_show_featured_section = morenews_get_option('show_featured_posts_section');
            if ($morenews_show_featured_section): ?>
                <section class="aft-blocks af-main-banner-featured-posts morenews-customizer">
                    <div class="container-wrapper">
                        <?php do_action('morenews_action_banner_featured_posts'); ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php

            $morenews_show_post_list_section = morenews_get_option('show_featured_post_list_section');

            ?>

            <?php if ($morenews_show_post_list_section) { ?>
                <section class="aft-blocks aft-featured-category-section af-list-post featured-cate-sec morenews-customizer">
                    <div class="container-wrapper">

                            <?php morenews_get_block('list-posts', 'featured'); ?>

                    </div>
                </section>
            <?php } ?>

        </div>
    <?php }
endif;


add_action('morenews_action_banner_featured_section', 'morenews_banner_featured_section', 10);