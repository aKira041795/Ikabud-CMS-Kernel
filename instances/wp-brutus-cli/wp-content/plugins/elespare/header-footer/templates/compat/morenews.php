<?php
$enable_preloader = morenews_get_option('enable_site_preloader');
if (1 == $enable_preloader) :
?>
    <div id="af-preloader">
        <div id="loader-wrapper">
            <div class="loader1"></div>
            <div class="loader2"></div>
            <div class="loader3"></div>
            <div class="loader4"></div>
        </div>
    </div>
<?php endif; ?>

<div id="page" class="site af-whole-wrapper">
    <a class="skip-link screen-reader-text" href="#content"><?php esc_html_e('Skip to content', 'morenews'); ?></a>
    <?php
            if (!is_front_page() && !is_home()) : ?>
                <div class="aft-main-breadcrumb-wrapper container-wrapper">
                    <?php do_action('morenews_action_get_breadcrumb'); ?>
                </div>
            <?php endif; ?>
    <div id="content" class="container-wrapper">
        <?php
        //do_action('morenews_action_get_breadcrumb');

        if (is_single()) {
            $single_post_title_view = morenews_get_option('single_post_title_view');
            if ($single_post_title_view == 'full') {
                do_action('morenews_action_single_header');
            }
        }
