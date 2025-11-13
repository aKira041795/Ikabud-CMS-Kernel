<?php
$enable_preloader = newsium_get_option('enable_site_preloader');
if (1 == $enable_preloader):
    ?>
    <div id="af-preloader">
        <div class="af-fancy-spinner">
            <div class="af-ring"></div>
            <div class="af-ring"></div>
            <div class="af-dot"></div>
        </div>
    </div>
<?php endif; ?>
<div id="page" class="site">
    <a class="skip-link screen-reader-text"
       href="#content"><?php esc_html_e('Skip to content', 'covernews'); ?></a>

    <?php

    if(is_singular( 'post')){
        $single_post_featured_image_view = newsium_get_option('single_post_featured_image_view');
        /*if($single_post_featured_image_view == 'full'){*/
        do_action('newsium_action_single_header');
        /*}*/
    }

    do_action('newsium_action_get_breadcrumb');
    ?>

    <div id="content" class="container-wrapper header-newsium">
