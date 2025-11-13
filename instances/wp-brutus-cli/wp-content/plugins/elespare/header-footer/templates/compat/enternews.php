<?php
$enable_preloader = enternews_get_option('enable_site_preloader');
if (1 == $enable_preloader):
    ?>
    <div id="af-preloader">
        <div class="spinner">
            <div class="af-preloader-bar"></div>
        </div>
    </div>
<?php endif; ?>

<div id="page" class="site">
    <a class="skip-link screen-reader-text" href="#content"><?php esc_html_e('Skip to content', 'enternews'); ?></a>
    <?php do_action('enternews_action_get_breadcrumb'); ?>
    <div id="content" class="container-wrapper ">