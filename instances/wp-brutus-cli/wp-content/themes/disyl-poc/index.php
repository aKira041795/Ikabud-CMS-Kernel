<?php
/**
 * Main Template File
 * 
 * DiSyL templates are now handled by Ikabud Kernel integration.
 * This file is a fallback in case DiSyL rendering fails.
 */

// If we reach here, DiSyL rendering failed or is disabled
// Fall back to basic WordPress template

get_header();

if (have_posts()) {
    while (have_posts()) {
        the_post();
        ?>
        <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
            <h1><?php the_title(); ?></h1>
            <div class="entry-content">
                <?php the_content(); ?>
            </div>
        </article>
        <?php
    }
} else {
    ?>
    <p>No content found.</p>
    <?php
}

get_footer();
