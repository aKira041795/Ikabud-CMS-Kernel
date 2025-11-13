<?php
$enable_preloader = chromenews_get_option('enable_site_preloader');
if (1 == $enable_preloader):
    ?>
    <div id="af-preloader">
        <div id="loader-wrapper">
            <div id="loader"></div>
        </div>
    </div>
<?php endif; ?>

<div id="page" class="site af-whole-wrapper">
    <a class="skip-link screen-reader-text" href="#content"><?php esc_html_e('Skip to content', 'chromenews'); ?></a>  

    
    <div class="aft-main-breadcrumb-wrapper container-wrapper">
        <?php
        if (is_single()) {
            $single_post_title_view = chromenews_get_option('single_post_title_view');
            if (($single_post_title_view == 'boxed') || ($single_post_title_view == 'title-below-image')) {
                do_action('chromenews_action_get_breadcrumb');
            }
        } else {
            do_action('chromenews_action_get_breadcrumb');
        }

        ?>
    </div>
    <div id="content" class="container-wrapper">