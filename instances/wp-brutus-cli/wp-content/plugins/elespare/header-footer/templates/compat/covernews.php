<?php
$enable_preloader = covernews_get_option('enable_site_preloader');
if (1 == $enable_preloader):
    ?>
    <div id="af-preloader">
        <div id="loader-wrapper">
            <div id="loader"></div>
        </div>
    </div>
<?php endif; ?>
<div id="page" class="site">
    <a class="skip-link screen-reader-text"
       href="#content"><?php esc_html_e('Skip to content', 'covernews'); ?></a>
    <div id="content" class="container header-covernews">

<?php
do_action('covernews_action_get_breadcrumb');
