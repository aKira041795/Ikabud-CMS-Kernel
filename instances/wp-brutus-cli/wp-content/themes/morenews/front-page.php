<?php
// Get the current page template
$page_template = get_page_template_slug();

if ('posts' == get_option('show_on_front')) {
    include(get_home_template());
} elseif ($page_template === 'page-templates/blank-canvas.php') {
    // Blank Canvas Template: Only display content, no header/footer
?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>

    <head>
        <meta charset="<?php bloginfo('charset'); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">       
        <?php wp_head(); ?>

    </head>

    <body <?php body_class('aft-pagebuilder-blank-canvas'); ?>>

        <div class="aft-pagebuilder-page-section">
            <?php
            // Output the page builder content
            if (have_posts()) :
                while (have_posts()) :
                    the_post();
                    the_content();
                endwhile;
            endif;
            ?>
        </div>

        <?php wp_footer(); ?>
    </body>

    </html>
<?php

} elseif ($page_template === 'page-templates/full-width.php') {
    // Full Width Template: Include header and footer, no sidebar or extra content

    get_header(); ?>

    <div id="primary" class="content-area aft-pagebuilder-full-width-content">
        <main id="main" class="site-main" role="main">
            <?php
            if (have_posts()) :
                while (have_posts()) :
                    the_post();
                    the_content(); // Page builder content area
                endwhile;
            endif;
            ?>
        </main>
    </div><!-- #primary -->

<?php
    get_footer();
} else {
    // Default front page behavior (for other templates)
    get_header();

    /**
     * morenews_action_sidebar_section hook
     * @since MoreNews 1.0.0
     *
     * @hooked morenews_front_page_section -  20
     */
    do_action('morenews_front_page_section');

    get_footer();
}
?>