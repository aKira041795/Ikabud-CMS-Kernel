<?php
if (!function_exists('morenews_front_page_main_section')) :
    /**
     * Banner Slider
     *
     * @since MoreNews 1.0.0
     *
     */
    function morenews_front_page_main_section()
    {
        $morenews_enable_main_slider = morenews_get_option('show_main_news_section');

        if ($morenews_enable_main_slider):

            $morenews_banner_layout = morenews_get_option('select_main_banner_layout_section');
            $morenews_banner_background = morenews_get_option('main_banner_background_section');

            $morenews_layout_class = 'aft-banner-' . $morenews_banner_layout;

            if(($morenews_banner_layout == 'layout-1') || ($morenews_banner_layout == 'layout-2') || ($morenews_banner_layout == 'layout-3')){
                $morenews_main_banner_order = morenews_get_option('select_main_banner_order');
                $morenews_layout_class .= ' aft-banner-'.$morenews_main_banner_order;
            } elseif(($morenews_banner_layout == 'layout-4') || ($morenews_banner_layout == 'layout-5') || ($morenews_banner_layout == 'layout-6')){
                $morenews_main_banner_order = morenews_get_option('select_main_banner_order_2');
                $morenews_layout_class .= ' aft-banner-'.$morenews_main_banner_order;
            }elseif(($morenews_banner_layout == 'layout-0')){
                $morenews_main_banner_order = morenews_get_option('select_main_banner_order_3');
                $morenews_layout_class .= ' aft-banner-'.$morenews_main_banner_order;
            }

            $morenews_main_banner_url = '';

            if (!empty($morenews_banner_background)) {
                $morenews_banner_background = absint($morenews_banner_background);
                $morenews_main_banner_url = wp_get_attachment_url($morenews_banner_background);
                $morenews_layout_class .= ' data-bg';

            }

            ?>

            <section
                    class="aft-blocks aft-main-banner-section banner-carousel-1-wrap bg-fixed  morenews-customizer <?php echo esc_attr($morenews_layout_class); ?>"
                    data-background="<?php echo esc_attr($morenews_main_banner_url); ?>">
            <?php do_action('morenews_action_banner_exclusive_posts'); ?>


            <?php
                if (is_active_sidebar('home-above-main-banner-widgets')): ?>
                    <section class="aft-blocks aft-above-main-banner-section">
                        <div class="container-wrapper">
                            <div class="home-main-banner-widgets upper">
                                <?php dynamic_sidebar('home-above-main-banner-widgets'); ?>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>
                
                <div class="container-wrapper">
                    <div class="aft-main-banner-wrapper">
                        <?php
                        $morenews_banner_block = 'main-' . $morenews_banner_layout;
                        morenews_get_block($morenews_banner_block, 'banner');
                        ?>
                    </div>
                </div>



            </section>


        <?php
        else:
            do_action('morenews_action_banner_exclusive_posts');
        endif;
    }
endif;
add_action('morenews_action_front_page_main_section', 'morenews_front_page_main_section', 40);