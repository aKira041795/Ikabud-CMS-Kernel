<?php

/**
 * Front page section additions.
 */


if (!function_exists('morenews_full_width_upper_footer_section')) :
    /**
     *
     * @since MoreNews 1.0.0
     *
     * @param null
     * @return null
     *
     */
    function morenews_full_width_upper_footer_section()
    {



        ?>

        <section class="aft-blocks above-footer-widget-section">
            <?php

            if (1 == morenews_get_option('frontpage_show_latest_posts')) {
                morenews_get_block('latest');
            }

            ?>
        </section>
        <?php

    }
endif;
add_action('morenews_action_full_width_upper_footer_section', 'morenews_full_width_upper_footer_section');
