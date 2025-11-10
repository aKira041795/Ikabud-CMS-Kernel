<?php
/**
 * Template Name: Page Builder Full Width
 *
 * A full-width template for page builders with header and footer.
 */

get_header(); ?>

<div id="primary" class="content-area aft-pagebuilder-full-width-content">
    <main id="main" class="site-main" role="main">
        <?php
        if ( have_posts() ) :
            while ( have_posts() ) :
                the_post();
                the_content(); // Page builder content area
            endwhile;
        endif;
        ?>
    </main>
</div><!-- #primary -->

<?php
get_footer();
