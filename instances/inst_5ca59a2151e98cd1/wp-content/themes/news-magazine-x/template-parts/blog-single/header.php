<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Get Options
$layout_preset = newsx_get_option( 'bs_header_layout_preset' );
$category_style = ' newsx-'. newsx_get_option('global_category_style');
$show_breadcrumbs = newsx_get_option( 'bs_header_show_breadcrumbs' );
$show_categories = newsx_get_option( 'bs_header_show_categories' );
$show_author = newsx_get_option( 'bs_header_show_author' );
$show_avatar = newsx_get_option( 'bp_header_show_avatar' );
$avatar_size = newsx_get_option( 'bs_header_avatar_size' );
$show_comments = newsx_get_option( 'bs_header_show_comments' );
$show_reading_time = newsx_get_option( 'bs_header_show_reading_time' );
$show_post_views = newsx_get_option( 'bs_header_show_post_views' );
$date_display = newsx_get_option( 'bs_header_date_display' );
$show_time = newsx_get_option( 'bs_header_show_time' );
$show_meta_icons = newsx_get_option( 'bs_header_show_meta_icons' );

if ( !defined('NEWSX_CORE_PRO_VERSION') || !newsx_core_pro_fs()->can_use_premium_code() ) {
    $layout_preset = in_array($layout_preset, ['s4', 's5']) ? $layout_preset : 's5';
    $show_reading_time = false;
    $show_post_views = false;
    $date_display = 'published';
}

// Header
echo '<div class="newsx-single-post-header">';

// Edit Button
echo newsx_customizer_edit_button_markup('bs_header');

// Breadcrumbs
if ( $show_breadcrumbs ) {
    newsx_breadcrumbs_markup();
}

// Categories
if ( $show_categories ) {
    echo '<div class="newsx-post-categories'. esc_attr($category_style) .'">';
        echo newsx_custom_category_list_markup();
    echo '</div>';
}

// Title
echo '<h1>'. get_the_title() .'</h1>';

// Post Meta
echo '<div class="newsx-post-meta newsx-flex">';

    echo '<div class="newsx-post-meta-inn-wrap newsx-flex">';

        if ( $show_author && $show_avatar ) {
            $author_id = get_post_field( 'post_author' );
            echo '<div class="newsx-post-author-avatar">'. get_avatar( $author_id, $avatar_size ) .'</div>';
        }

        echo '<div class="newsx-post-meta-inner newsx-flex">';
            echo '<div class="newsx-post-meta-inn-group newsx-flex">';
                if ( $show_author ) {
                    echo '<div class="newsx-post-author">';
                        echo esc_html__('By ', 'news-magazine-x') . get_the_author_posts_link();
                    echo '</div>';
                }

                if ( $show_comments ) {
                    echo '<div class="newsx-post-comments-number">';
                        if ( $show_meta_icons ) {
                            echo newsx_get_svg_icon('comment');
                        }
                        echo ' <a href="'. get_comments_link() .'">'. get_comments_number_text() .'</a>';
                    echo '</div>';
                }

                if ( $show_reading_time ) {
                    $word_count = str_word_count( strip_tags( get_the_content() ) );
                    $reading_time = ceil( $word_count / 200 );

                    echo '<div class="newsx-post-reading-time">';
                        if ( $show_meta_icons ) {
                            echo newsx_get_svg_icon('clock');
                        }
                        echo esc_html($reading_time) . esc_html(' Mins Read', 'news-magazine-x');
                    echo '</div>';
                }

                if ( $show_post_views ) {
                    newsx_post_views_markup(get_the_ID());
                }
            echo '</div>';

            echo '<div class="newsx-post-date">';
                if ( 'updated' === $date_display ) {
                    echo esc_html__('Updated: ', 'news-magazine-x') . get_the_modified_date();
                } else {
                    echo get_the_date();
                }

                if ( $show_time ) {
                    echo ' <span class="newsx-post-time">'. get_the_time() .'</span>';
                }
            echo '</div>';
        echo '</div>';

    echo '</div>';

    if ( newsx_get_option( 'bs_sharing_show_header' ) && defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
        newsx_post_sharing_markup();
    }

echo '</div>';

echo '</div>'; // .newsx-single-post-header
