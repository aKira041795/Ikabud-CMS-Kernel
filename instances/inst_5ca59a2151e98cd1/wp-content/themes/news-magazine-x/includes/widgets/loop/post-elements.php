<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Post Slider Thumbnail
function newsx_slider_thumbnail( $instance ) {
    $image_size = $instance['image_size'] ? $instance['image_size'] : 'newsx-1200x600';

    if ( has_post_thumbnail() ) {
        echo '<a href="'. esc_url( get_permalink() ) .'" class="newsx-slider-image" title="'. esc_attr( get_the_title() ) .'">';
            the_post_thumbnail( $image_size );
        echo '</a>';
    }
}

// Post Thumbnail
if ( !function_exists('newsx_post_thumbnail') ) {
    function newsx_post_thumbnail( $instance, $widget = '', $class = '' ) {
        $class  = '' !== $class ? ' '. $class : '';
        $class .= ('magazine' === $widget) ? ' newsx-full-stretch' : '';
        $size   = newsx_get_post_thumbnail_size($instance, $widget);
        $style  = 'background-image: url('. get_the_post_thumbnail_url( get_the_ID(), $size ) .')';

        if ( has_post_thumbnail() ) {
            if ( 'magazine' === $widget ) {
                echo '<a href="'. esc_url( get_permalink() ) .'"  style="'. esc_attr($style) .'" class="newsx-grid-image'. esc_attr($class) .'" title="'. esc_attr( get_the_title() ) .'"></a>';
            } else {
                $image_link = isset($instance['image_link']) && $instance['image_link'] ? true : false;

                if ( !defined('NEWSX_CORE_PRO_VERSION') || !newsx_core_pro_fs()->can_use_premium_code() ) {
                    $image_link = true;
                }

                echo ($image_link) ? '<a href="'. esc_url( get_permalink() ) .'" class="newsx-grid-image'. esc_attr($class) .'" title="'. esc_attr( get_the_title() ) .'">' : '';
                    the_post_thumbnail( $size, ['loading' => 'lazy'] );
                echo ($image_link) ? '</a>' : '';
            }
        }
    }
}

// Post Thumbnail Size
function newsx_get_post_thumbnail_size( $instance, $widget ) {
    $size = isset($instance['image_size']) ? $instance['image_size'] : 'full';
    $index = isset($instance['_post_index']) ? $instance['_post_index'] : 0;

    // Magazine Layouts
    if ( 'magazine' === $widget ) {
        $size = 'newsx-860x570';

        switch ( $instance['layout'] ) {
            case '1-2':
            case '1vh-3h':
                if ( 1 !== $index ) {
                    $size = 'newsx-670x370';
                }
                break;

            case '1-3':
                if ( 1 === $index ) {
                    // skip the first post
                } elseif ( 2 === $index ) {
                    $size = 'newsx-670x370';
                } else {
                    $size = 'newsx-420x280';
                }
                break;

            case '1-4':
            case '2-1-2':
                if ( 1 !== $index ) {
                    $size = 'newsx-420x280';
                }
                break;

            case '1-1-2':
                if ( 2 < $index ) {
                    $size = 'newsx-420x280';
                }
                break;

            case '1-1-3':
                if ( 1 === $index ) {
                    $size = 'newsx-670x370';
                } else {
                    $size = 'newsx-420x280';
                }
                break;

            case '2-3':
                if ( 2 >= $index ) {
                    $size = 'newsx-670x370';
                } else {
                    $size = 'newsx-420x280';
                }
                break;
        }
        
    // List Layouts
    } elseif ( 'list' === $widget ) {
        $size = 'newsx-150x100';

        switch ( $instance['layout'] ) {
            case 'list-1':
            case 'list-2':
            case 'list-3':
            case 'list-4':
            case 'list-5':
                if ( 1 === $index ) {
                    $size = 'newsx-860x570';
                }
                break;
                
            case 'list-6':
                if ( 2 >= $index ) {
                    $size = 'newsx-510x340';
                }
                break;

            case 'list-7':
            case 'list-8':
            case 'list-9':
                $size = 'newsx-420x280';
                break;
        }
    }

    return $size;
}

// Media Hover Link
function newsx_media_hover_link( $instance ) {
    $image_link = true;

    if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() && isset($instance['image_link']) ) {
        $image_link = $instance['image_link'];
    }

    if ( $image_link ) {
        echo '<a href="'. esc_url( get_permalink() ) .'" class="newsx-media-hover-link"></a>';
    }
}

// Post Categories
function newsx_post_categories( $instance, $class = '' ) {
    $class  = '' !== $class ? ' '. $class : '';
    $style_class = ' newsx-'. newsx_get_option('global_category_style');

    echo '<div class="newsx-grid-categories'. esc_attr($class) .'">';
        echo '<div class="newsx-post-categories'. esc_attr($style_class) .'">';
            echo newsx_custom_category_list_markup();
        echo '</div>';
    echo '</div>';
}

// Post Title
function newsx_post_title( $instance, $class = '' ) {
    $class = '' !== $class ? ' '. $class : '';
    $count = (isset($instance['title_letter_count']) && '' !== $instance['title_letter_count']) ? intval($instance['title_letter_count']) : 999;
    $title = strlen(html_entity_decode(get_the_title())) > $count ? substr(html_entity_decode(get_the_title()), 0, $count) . '...' : get_the_title();
    $title_tag = isset($instance['title_tag']) ? $instance['title_tag'] : 'h2';

    echo '<div class="newsx-grid-title'. esc_attr($class) .'">';
        echo '<'. esc_html($title_tag) .'>';
            echo '<a href="'. esc_url( get_permalink() ) .'" title="'. esc_attr( get_the_title() ) .'" class="newsx-underline-hover">';
                echo wp_kses_post( $title );
            echo '</a>';
        echo '</'. esc_html($title_tag) .'>';
    echo '</div>';
}

// Post Excerpt
function newsx_post_excerpt( $instance, $class = '' ) {
    $class = '' !== $class ? ' '. $class : '';
    $count = isset( $instance['excerpt_letter_count'] ) ? $instance['excerpt_letter_count'] : 600;
    $excerpt = get_the_excerpt();
    
    if ( '' !== $count ) {
        if ( function_exists('mb_substr') ) {
            $excerpt = mb_substr($excerpt, 0, absint($count)) . '...';
        } else {
            $excerpt = substr($excerpt, 0, absint($count)) . '...'; 
        }
    }

    if ( $count > 0 ) {
        echo '<div class="newsx-grid-excerpt'. esc_attr($class) .'">';
            echo '<p>'. esc_html( $excerpt ) .'</p>';
        echo '</div>';
    }
}

// Post Date & Time
function newsx_post_date( $instance, $class = '' ) {
    $class = '' !== $class ? ' '. $class : '';

    // Get Time Ago
    $post_date = get_the_date();
    $current_time = current_time('timestamp');
    $time_ago = human_time_diff(strtotime($post_date), $current_time) . esc_html__(' ago', 'news-magazine-x');
    $date_format = isset( $instance['date_format'] ) ? $instance['date_format'] : 'default';
    $date_show_time = isset( $instance['date_show_time'] ) ? $instance['date_show_time'] : '';

    echo '<div class="newsx-grid-date-time'. esc_attr($class) .'">';
        if ( 'default' === $date_format ) {
            echo '<span class="newsx-grid-date">'. esc_html( get_the_date() ) .' </span>';

            if ( $date_show_time ) {
                echo '<span class="newsx-grid-time">'. esc_html( get_the_time() ) .'</span>';
            }
        } else {
            echo '<span class="newsx-grid-date">'. esc_html( $time_ago ) .'</span>';
        }
    echo '</div>';
}

// Post Author
function newsx_post_author( $instance, $class = '' ) {
    $class = '' !== $class ? ' '. $class : '';
    $author_id = get_post_field( 'post_author' );
    $author_show_avatar = isset( $instance['author_show_avatar'] ) ? $instance['author_show_avatar'] : '';

    echo '<div class="newsx-grid-author newsx-flex'. esc_attr($class) .'">';
        echo '<a href="'. esc_url( get_author_posts_url( $author_id ) ) .'" class="newsx-flex">';

            if ( $author_show_avatar ) {
                echo '<span class="newsx-grid-author-avatar">'. get_avatar( $author_id, $instance['author_avatar_size'] ) .'</span>';
            }

            echo '<span class="newsx-grid-author-name">'. esc_html( get_the_author_meta( 'display_name', $author_id ) ) .'</span>';

        echo '</a>';
    echo '</div>';
}

// Post Meta
function newsx_post_meta( $instance ) {
    echo '<div class="newsx-grid-post-meta newsx-flex">';

        newsx_post_author( $instance );
        newsx_post_date( $instance );

    echo '</div>';
}

// Post Read More
function newsx_post_read_more( $instance ) {
    $read_more_text = isset( $instance['read_more_text'] ) ? $instance['read_more_text'] : esc_html__('Read More', 'news-magazine-x');

    echo '<div class="newsx-grid-read-more">';
        echo '<a href="'. esc_url( get_permalink() ) .'" class="newsx-grid-read-more-link">'. esc_html( $read_more_text ) .'</a>';
    echo '</div>';
}

// Post Comments
// function newsx_post_comments( $instance ) {
//     echo '<div class="newsx-grid-comments">';
//         echo '<a href="'. esc_url( get_comments_link() ) .'" class="newsx-grid-comments-link">'. esc_html( $instance['comments_text'] ) .'</a>';
//     echo '</div>';
// }


// Get Elements by Location
function newsx_get_post_elements_by_location( $instance, $location ) {
    $locations = isset($instance['_el_locations'][$location]) ? $instance['_el_locations'][$location] : [];

    if ( !isset($locations) ) {
        return;
    }
    
    foreach ( $locations as $element ) {
        if ( 'categories' === $element ) {
            newsx_post_categories( $instance );
        }
    
        if ( 'title' === $element ) {
            newsx_post_title( $instance );
        }
    
        if ( 'excerpt' === $element ) {
            newsx_post_excerpt( $instance );
        }
    
        if ( 'author' === $element ) {
            newsx_post_author( $instance );
        }
    
        if ( 'date' === $element ) {
            newsx_post_date( $instance );
        }
    
        if ( 'meta' === $element ) {
            newsx_post_meta( $instance );
        }
    
        if ( 'read-more' === $element) {
            newsx_post_read_more( $instance );
        }
    }
}