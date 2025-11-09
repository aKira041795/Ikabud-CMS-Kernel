<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if (isset($args['post-id'])) {
    $post = get_post($args['post-id']);
    setup_postdata($post);
}

// Get Options
$content_width = newsx_get_option( 'global_single_content_width' );
$layout_preset = newsx_get_option( 'bs_header_layout_preset' );
$cont_read_enable = newsx_get_option( 'bs_advanced_load_posts_cont_read_enable' );

// Auto Load Next Posts
$load_next_posts = newsx_get_option( 'bs_advanced_load_posts_enable' );
$load_next_posts = (isset($load_next_posts) && $load_next_posts) ? 'yes' : '';

if ( !defined('NEWSX_CORE_PRO_VERSION') || !newsx_core_pro_fs()->can_use_premium_code() ) {
    $layout_preset = in_array($layout_preset, ['s4', 's5']) ? $layout_preset : 's5';
    $load_next_posts = '';
}

// Layout Class
$layout_class  = ' newsx-'. $layout_preset;
$layout_class .= 'boxed' === $content_width ? ' newsx-container' : '';
$layout_class .= !has_post_thumbnail() ? ' newsx-no-post-thumb': '';
if ( $cont_read_enable && isset($args['post-id']) ) {
    $layout_class .= ' newsx-half-content';
}


?>

<div class="newsx-single-wrap<?php echo esc_attr($layout_class) ?>" data-load-posts="<?php echo esc_attr($load_next_posts); ?>">

    <?php
    
    if ( 's0' === $layout_preset ) {
        get_template_part( 'template-parts/blog-single/header', '' );
    } elseif ( 's1' === $layout_preset ) {
        get_template_part( 'template-parts/blog-single/header' );
        get_template_part( 'template-parts/blog-single/media' );
    } elseif ( 's2' === $layout_preset ) {
        get_template_part( 'template-parts/blog-single/media' );
        get_template_part( 'template-parts/blog-single/header' );
    } elseif ( 's3' === $layout_preset ) {
        echo '<div class="newsx-single-media-header">';
            get_template_part( 'template-parts/blog-single/media' );
            get_template_part( 'template-parts/blog-single/header' );
        echo '</div>';

        // Add Featured image caption
        if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
            $thumbnail_id = get_post_thumbnail_id();
            if ( $thumbnail_id && newsx_get_option('bs_featured_image_show_caption') ) {
                $caption = wp_get_attachment_caption( $thumbnail_id );
                if ( $caption ) {
                    echo '<p class="newsx-single-post-media-caption">' . esc_html( $caption ) . '</p>';
                }
            }
        }
    } elseif ( 's4' === $layout_preset ) {
        get_template_part( 'template-parts/blog-single/header' );
    } elseif ( 's7' === $layout_preset ) {
        get_template_part( 'template-parts/blog-single/media' );
    }

    ?>

    <div class="newsx-single-inner">

    <?php newsx_general_sidebar_markup( 'global_blog_single_sidebar_display', 'left' ); ?>

    <div id="primary" class="content-area primary">

        <?php echo newsx_reading_progress_bar_markup(); ?>

        <div class="newsx-single-content-wrap">

            <?php
            
            if ( newsx_get_option( 'bs_sharing_show_float' ) && defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
                newsx_post_sharing_markup( 'float' );
            }

            ?>

            <article id="post-<?php echo esc_attr(get_the_ID()); ?>" class="<?php echo esc_attr(join( ' ', get_post_class())); ?>">

            <?php

            if ( 's0' === $layout_preset ) {
                get_template_part( 'template-parts/blog-single/media' );
            } elseif ( 's5' === $layout_preset ) {
                get_template_part( 'template-parts/blog-single/header' );
                get_template_part( 'template-parts/blog-single/media' );
            } elseif ( 's6' === $layout_preset ) {
                get_template_part( 'template-parts/blog-single/media' );
                get_template_part( 'template-parts/blog-single/header' );
            } elseif ( 's7' === $layout_preset ) {
                get_template_part( 'template-parts/blog-single/header' );
            }

            get_template_part( 'template-parts/blog-single/content' );

            // Post Sharing
            if ( newsx_get_option( 'bs_sharing_show_content' ) ) {
                newsx_post_sharing_markup();
            }
    
            // Post Sources
            get_template_part( 'template-parts/blog-single/sources' );
    
            // Post Navigation
            if ( newsx_get_option('bs_nav_enable') ) {
                get_template_part( 'template-parts/blog-single/navigation' );
            }
    
            // Post Author Box
            if ( newsx_get_option('bs_author_enable') ) {
                get_template_part( 'template-parts/blog-single/author' );
            }
            
            // Newsletter
            if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {   
                if ( newsx_get_option('bs_newsletter_enable') ) {
                    load_template( NEWSX_CORE_PRO_PATH .'public/template-parts/blog-single/newsletter.php' );
                }
            }
    
            // Related Posts
            if ( newsx_get_option('bs_related_enable') ) {
                get_template_part( 'template-parts/blog-single/related-posts' );
            }
            
            // Post Comments
            if ( comments_open() || get_comments_number() ) {
                echo '<div class="comments-area" id="comments">';
                    comments_template( '', true );
                echo '</div>';
            }

            ?>

            </article>

        </div><!-- .newsx-single-content-wrap -->

        <?php 

        if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
            if ( $cont_read_enable && isset($args['post-id']) ) {
                echo '<div class="newsx-single-continue-reading">';
                    echo '<a href="' . esc_url(get_the_permalink($args['post-id'])) . '">';
                        echo esc_html__('Continue Reading', 'news-magazine-x');
                    echo '</a>';
                echo '</div>';
            }
        }

        ?>
        
    </div><!-- #primary -->

    <?php newsx_general_sidebar_markup( 'global_blog_single_sidebar_display', 'right' ); ?>
    
    </div><!-- .newsx-single-inner -->

</div><!-- .newsx-single-wrap -->

<?php 

if (isset($args['post-id'])) {
    wp_reset_postdata();
}
