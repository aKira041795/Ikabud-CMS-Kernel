<?php
/**
 * Phoenix Theme - Index Fallback
 * 
 * This file serves as a fallback if DiSyL rendering fails.
 * The theme primarily uses DiSyL templates in the /disyl directory.
 * 
 * @package Phoenix
 * @version 1.0.0
 */

// If DiSyL rendering worked, this file won't be reached
// This is just a safety fallback

get_header();
?>

<main class="site-content">
    <div class="container" style="max-width: 1200px; margin: 0 auto; padding: 4rem 2rem;">
        <h1>Phoenix Theme</h1>
        <p>This is a fallback template. DiSyL templates should be rendering instead.</p>
        
        <?php
        if (have_posts()) :
            while (have_posts()) : the_post();
                ?>
                <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                    <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                    <div class="entry-content">
                        <?php the_excerpt(); ?>
                    </div>
                </article>
                <?php
            endwhile;
        else :
            ?>
            <p>No content found.</p>
            <?php
        endif;
        ?>
    </div>
</main>

<?php
get_footer();
