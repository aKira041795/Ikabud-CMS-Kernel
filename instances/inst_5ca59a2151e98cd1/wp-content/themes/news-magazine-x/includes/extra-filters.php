<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}


/*
** Fire the wp_body_open action.
** Adds backward compatibility for WordPress versions < 5.2
*/
if ( ! function_exists( 'wp_body_open' ) ) { 
	function wp_body_open() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		do_action( 'wp_body_open' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}
}

/*
** Add Facebook Sharing Meta
*/
function newsx_facebook_meta_sharing() {
    global  $post ;
    $post_id = get_the_ID();
    $temp = $post;
    $post = get_post( $post_id );
    setup_postdata( $post );
    $excerpt = wp_trim_words( get_the_excerpt() );
    wp_reset_postdata();
    $post = $temp;
    $fb_image = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'large' );
    
    if ( has_custom_logo() ) {
        $fb_default_image = wp_get_attachment_url( get_theme_mod( 'custom_logo' ) );
    } else {
        $fb_default_image = get_header_image();
    }
    
    if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_PHP_VERSION_DIR' ) ) {
        return;
    }

    // Home Page
    if ( !is_single() && !is_page() ) {
        echo  '<meta property="og:image" content="'. esc_attr($fb_default_image) .'"/>' ;
        echo  '<meta property="og:title" content="'. esc_attr(get_bloginfo( 'name' )) .'"/>' ;
        echo  '<meta property="og:description" content="'. esc_attr(get_bloginfo( 'description' )) .'" />' ;
        echo  '<meta property="og:url" content="'. esc_url(home_url('/')) .'"/>' ;
    
    // Other Pages
    } else {
        if ( isset( $fb_image ) && has_post_thumbnail( $post_id ) ) {
            echo  '<meta property="og:image" content="'. esc_attr($fb_image[0]) .'"/>' ;
            echo  '<meta property="og:image:width" content="'. esc_attr($fb_image[1]) .'"/>' ;
            echo  '<meta property="og:image:height" content="'. esc_attr($fb_image[2]) .'"/>' ;
        } else {
            echo  '<meta property="og:image" content="'. esc_attr($fb_default_image) .'"/>' ;
        }
        
        echo  '<meta property="og:title" content="'. esc_attr(get_the_title()) .'"/>' ;
        echo  '<meta property="og:description" content="'. esc_attr($excerpt) .'" />' ;
        echo  '<meta property="og:url" content="'. esc_url(get_permalink()) .'"/>' ;
    }
    
    echo  '<meta property="og:type" content="website">' ;
    echo  '<meta property="og:locale" content="'. esc_attr(strtolower(str_replace( '-', '_', get_bloginfo( 'language' )))) .'" />' ;
    echo  '<meta property="og:site_name" content="'. esc_attr(get_bloginfo('name')) .'"/>' ;
}

add_action( 'wp_head', 'newsx_facebook_meta_sharing' );

/*
** Disable srcset for featured images
*/
function newsx_optimize_featured_image( $attr, $attachment, $size ) {
    unset( $attr['srcset'] );
    unset( $attr['sizes'] );

    return $attr;
}

add_filter( 'wp_get_attachment_image_attributes', 'newsx_optimize_featured_image', 10, 3 );

/*
** Add Retina Logo attribute
*/
if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
    $newsx_core_pro = new Newsx_Core_Pro();
    $newsx_core_pro_public = new Newsx_Core_Pro_Public( $newsx_core_pro->get_plugin_name(), $newsx_core_pro->get_version() );
    add_filter( 'wp_get_attachment_image_attributes', [ $newsx_core_pro_public, 'change_logo_attr' ], 10, 3 );
}

/*
** Header Menu Item Class
*/
function newsx_header_menu_link_class($atts, $item, $args, $depth) {
    if ( ('primary' == $args->theme_location || 'secondary' == $args->theme_location ) && $depth == 0 ) {
        if ( 'primary' == $args->theme_location ) {
            $effect = newsx_get_option('header_pm_hover_effect');
        } elseif ( 'secondary' == $args->theme_location ) {
            $effect = newsx_get_option('header_sm_hover_effect');
        }
        
        if ( 'none' !== $effect ) {
            if ( !defined('NEWSX_CORE_PRO_VERSION') || !newsx_core_pro_fs()->can_use_premium_code() ) {
                $effect = 'fade';
            }

            $atts['class'] = 'newsx-pointer-item newsx-pointer-'. $effect;
        }
    }
    
    return $atts;
}

add_filter('nav_menu_link_attributes', 'newsx_header_menu_link_class', 10, 4);

/*
** Add Sub Menu Icon and Item Label to Menus
*/
function newsx_add_menu_items( $title, $item, $args, $depth ) {
    // Add menu label for primary menu
    if ( 'primary' === $args->theme_location && !empty( $item->description ) ) {
        $title = $title . '<span class="newsx-menu-item-label">' . esc_html($item->description) . '</span>';
    }

    // Add dropdown icon for primary/secondary menus
    if ( 'primary' === $args->theme_location || 'secondary' === $args->theme_location ) {
        foreach ( $item->classes as $value ) {
            if ( 'menu-item-has-children' === $value ) {
                $title = $title . newsx_default_icon_markup('sub-menu');
            }
        }
    } elseif ( '' === $args->theme_location ) {
        $title = newsx_get_svg_icon('chevron-right') . $title;
    }

    return $title;
}
add_filter( 'nav_menu_item_title', 'newsx_add_menu_items', 10, 4 );

/*
** Add Submenu Divider Header Menus
*/
function newsx_header_menu_submenu_class( $classes, $args, $depth ) {
    $divider = '';

    if ( 'primary' == $args->theme_location ) {
        $divider = newsx_get_option('header_pm_submenu_divider') ? 'newsx-submenu-divider' : '';
    } elseif ( 'secondary' == $args->theme_location ) {
        $divider = newsx_get_option('header_sm_submenu_divider') ? 'newsx-submenu-divider' : '';
    }

    $classes[] = $divider;

    return $classes;
}

add_filter( 'nav_menu_submenu_css_class', 'newsx_header_menu_submenu_class', 10, 3 );

/*
** Add Home Icon to Primary Menu
*/
function newsx_primary_nav_home_icon($items, $args) {
    $show_home_icon = newsx_get_option('header_pm_show_home_icon');
    
    // Check if it's the primary menu
    if ( 'primary' == $args->theme_location && $show_home_icon ) {
        foreach ($items as &$item) {
            if ($item->url === home_url('/') || '/' === $item->url) { // Check if menu item is the home page
                $item->title = newsx_default_icon_markup('home') . '<span>'. esc_html($item->title) .'</span>'; // Prepend the home icon
            }
        }
    }

    return $items;
}

add_filter('wp_nav_menu_objects', 'newsx_primary_nav_home_icon', 10, 2);

/*
** Add Item Divider to Mobile Menu
*/
function newsx_mobile_menu_item_divider_class( $classes, $item, $args, $depth ) {
    if ( 'newsx-mobile-menu-primary' === $args->menu_id ) {
        $divider =  newsx_get_option('header_pm_mobile_drop_divider') ? 'newsx-mobile-drop-divider' : '';
        $classes[] = $divider;
    }

    return $classes;
}

add_filter( 'nav_menu_css_class', 'newsx_mobile_menu_item_divider_class', 10, 4 );

/*
** Remove Sub menu from Footer menu
*/
function newsx_remove_footer_menu_sub_menu( $args ) {
    if ( 'footer' === $args['theme_location'] ) {
        $args['depth'] = 1;
    }

    return $args;
}

add_filter( 'wp_nav_menu_args', 'newsx_remove_footer_menu_sub_menu', 10, 1 );

/*
** Add placeholder to comment form fields
*/
function newsx_add_comment_placeholder( $defaults ) {
    if ( ! empty( $defaults['fields']['author'] ) ) {
        $defaults['fields']['author'] = str_replace( '<input', '<input placeholder="' . esc_html__( 'Your name', 'news-magazine-x' ) . '"', $defaults['fields']['author'] );
    }
    if ( ! empty( $defaults['fields']['email'] ) ) {
        $defaults['fields']['email'] = str_replace( '<input', '<input placeholder="' . esc_html__( 'Your email', 'news-magazine-x' ) . '"', $defaults['fields']['email'] );
    }

    if ( ! empty( $defaults['fields']['url'] ) ) {
        $defaults['fields']['url'] = str_replace( '<input', '<input placeholder="' . esc_html__( 'Your Website', 'news-magazine-x' ) . '"', $defaults['fields']['url'] );
    }

    if ( ! empty( $defaults['comment_field'] ) ) {
        $defaults['comment_field'] = str_replace( '<textarea', '<textarea placeholder="' . esc_html__( 'Leave a comment', 'news-magazine-x' ) . '"', $defaults['comment_field'] );
    }

    return $defaults;
}

add_filter( 'comment_form_defaults', 'newsx_add_comment_placeholder', 10 );

/*
** Custom function to modify excerpt more text
*/
function newsx_custom_excerpt_more($more) {
    return ''; // Change to '...' or any other text if desired
}
add_filter('excerpt_more', 'newsx_custom_excerpt_more');

/*
** Exclude pages from search results
*/
function newsx_exclude_pages_from_search( $query ) {
    // Ensure this only runs on the main query in the search context
    if ( $query->is_main_query() && $query->is_search() && ! is_admin() ) {
        $query->set( 'post_type', 'post' ); // Include only posts
    }
}
add_action( 'pre_get_posts', 'newsx_exclude_pages_from_search' );

/*
** Change search form tabindex to 0 on 404 page
*/
function newsx_search_form_tabindex( $form ) {
    if ( is_404() ) {
        $form = str_replace( 'tabindex="-1"', 'tabindex="0"', $form );
    }
    return $form;
}
add_filter( 'get_search_form', 'newsx_search_form_tabindex' );


