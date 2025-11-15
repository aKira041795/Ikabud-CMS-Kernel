<?php
if (!function_exists('morenews_banner_advertisement')):
    /**
     * Ticker Slider
     *
     * @since MoreNews 1.0.0
     *
     */
    function morenews_banner_advertisement()
    {


        $morenews_banner_advertisement = morenews_get_option('banner_advertisement_section');

        if (('' != $morenews_banner_advertisement) ) { ?>
            <div class="banner-promotions-wrapper">
                <?php if (('' != $morenews_banner_advertisement)):
                    $morenews_banner_advertisement = absint($morenews_banner_advertisement);
                    $morenews_banner_advertisement = wp_get_attachment_image($morenews_banner_advertisement, 'full');
                    $morenews_banner_advertisement_url = morenews_get_option('banner_advertisement_section_url');
                    $morenews_banner_advertisement_url = isset($morenews_banner_advertisement_url) ? esc_url($morenews_banner_advertisement_url) : '#';

                    ?>
                    <div class="promotion-section">
                        <a href="<?php echo esc_url($morenews_banner_advertisement_url); ?>" >
                            <?php echo wp_kses_post($morenews_banner_advertisement); ?>
                        </a>
                    </div>
                <?php endif; ?>                

            </div>
            <!-- Trending line END -->
            <?php
        }

         if (is_active_sidebar('home-advertisement-widgets')): ?>
                     <div class="banner-promotions-wrapper">
                    <div class="promotion-section">
                        <?php dynamic_sidebar('home-advertisement-widgets'); ?>
                    </div>
                </div>
                <?php endif;
    }
endif;

add_action('morenews_action_banner_advertisement', 'morenews_banner_advertisement', 10);