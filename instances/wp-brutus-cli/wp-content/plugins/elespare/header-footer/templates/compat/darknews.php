<?php
$enable_preloader = darknews_get_option('enable_site_preloader');
if (1 == $enable_preloader):
    ?>
    <div id="af-preloader">
        <div id="loader-wrapper">
            <div id="loader">
            </div>
        </div>
    </div>
<?php endif; ?>
<div id="page" class="site af-whole-wrapper">
    <a class="skip-link screen-reader-text"
       href="#content"><?php esc_html_e('Skip to content', 'covernews'); ?></a>
<div id="content" class="container-wrapper header-darknews">
    <?php

    do_action('darknews_action_get_breadcrumb');