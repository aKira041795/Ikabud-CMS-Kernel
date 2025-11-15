<?php

/**
 * The template for displaying all single posts
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/#single-post
 *
 * @package MoreNews
 */

get_header();

?>
<div class="section-block-upper">
    <div id="primary" class="content-area">
        <main id="main" class="site-main">
            <?php
            $social_share_icon_opt = morenews_get_option('single_post_social_share_view');
            if ($social_share_icon_opt == 'after-content') {
                $wrap_class = 'social-after-content';
            } else if ($social_share_icon_opt == 'side') {
                $wrap_class = 'social-vertical-share';
            } else {
                $wrap_class = 'social-after-title';
            }
            while (have_posts()) : the_post(); ?>
                <article id="post-<?php the_ID(); ?>" <?php post_class('af-single-article'); ?>>

                    <div class="entry-content-wrap read-single <?php echo esc_attr($wrap_class); ?>">

                        <?php

                        if (is_single()) {
                            $single_post_title_view = morenews_get_option('single_post_title_view');
                            if ($single_post_title_view == 'boxed') {
                                do_action('morenews_action_single_header');
                            }
                        }
                        ?>
                        <?php $social_share_icon_opt = morenews_get_option('single_post_social_share_view');
                        if ($social_share_icon_opt == 'after-content' || $social_share_icon_opt == 'side') {
                            morenews_single_post_social_share_icons($post->ID);
                        } ?>

                        <?php
                        if (has_post_thumbnail($post->ID)) :
                            $show_featured_image = morenews_get_option('single_show_featured_image');
                            if ($show_featured_image) :

                        ?>
                                <div class="read-img pos-rel">
                                    <?php morenews_post_thumbnail(); ?>                                    
                                </div>

                        <?php endif;
                        endif;
                        ?>

                        <?php
                        get_template_part('template-parts/content', get_post_type());
                        ?>
                    </div>



                    <?php
                    // If comments are open or we have at least one comment, load up the comment template.
                    if (comments_open() || get_comments_number()) :
                        comments_template();
                    endif;
                    ?>



                    <?php
                    $show_related_posts = morenews_get_option('single_show_related_posts');
                    if ($show_related_posts) :
                        if ('post' === get_post_type()) :
                            morenews_get_block('related');
                        endif;
                    endif;
                    ?>

                </article>
            <?php

            endwhile; // End of the loop...
            ?>

        </main><!-- #main -->
    </div><!-- #primary -->

    <?php
    get_sidebar();
    ?>
</div>
<?php
get_footer();
