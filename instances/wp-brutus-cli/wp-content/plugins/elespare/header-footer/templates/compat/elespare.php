<?php
if (function_exists('wp_body_open')) {
    wp_body_open();
} else {
    do_action('wp_body_open');
} ?>
<?php
$enable_preloader = elespare_theme_get_option('enable_site_preloader');
if (1 == $enable_preloader) :
?>
    <div id="af-preloader">
        <div class="spinner">
            <div class="af-preloader-bar"></div>
        </div>
    </div>
<?php endif; ?>

<div id="page" class="site">
    <a class="skip-link screen-reader-text" href="#content"><?php esc_html_e('Skip to content', 'elespare'); ?></a>

    <?php
    $container_wrapper_class = 'container-wrapper';
    if (get_page_template_slug() == 'elementor_header_footer') { ?>
        <div id="elementor-content" class="elementor-container">
        <?php
    } else { ?>
            <?php do_action('elespare_action_get_breadcrumb'); ?>
            <div id="content" class="container-wrapper">
            <?php }
            ?>