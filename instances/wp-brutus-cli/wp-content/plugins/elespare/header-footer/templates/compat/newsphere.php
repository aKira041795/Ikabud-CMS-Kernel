<?php
$enable_preloader = newsphere_get_option('enable_site_preloader');
if (1 == $enable_preloader):
    ?>
    <div id="af-preloader">
        <div class="af-preloader-wrap">
            <div class="af-sp af-sp-wave">
            </div>
        </div>
    </div>
<?php endif; ?>

<div id="page" class="site">
<a class="skip-link screen-reader-text" href="#content"><?php esc_html_e('Skip to content', 'newsphere'); ?></a>

<?php
if (is_singular('post')) {
    $single_post_featured_image_view = newsphere_get_option('single_post_featured_image_view');
    if ($single_post_featured_image_view == 'full') {
        do_action('newsphere_action_single_header');
    }
}
?>
    <?php do_action('newsphere_action_get_breadcrumb'); ?>
<div id="content" class="container-wrapper">