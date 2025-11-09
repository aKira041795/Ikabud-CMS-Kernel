<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/*
** Get Customzier Option.
*/
if ( !function_exists('newsx_get_option') ) {
	function newsx_get_option( $control, $default = '' ) {
		$value = null;

		$newsx_defaults = newsx_get_theme_defaults();

		// Merge defaults and options
		$newsx_defaults = wp_parse_args( get_option('newsx_options'), $newsx_defaults );

		// Get value
		if ( isset( $newsx_defaults[ $control ] ) ) {
			$value = $newsx_defaults[ $control ];
		} else {
			$value = $default;
		}

		// if ( 'global_color_accent' === $control ) {
		// 	var_dump($value);
		// }

		// Return value
		return $value;
	}
}

/*
** Get Tablet Breakpoint
*/
if ( !function_exists( 'newsx_get_tablet_breakpoint' ) ) {
	function newsx_get_tablet_breakpoint($tablet = 768) {
		return $tablet;
	}
}

/*
** Get Mobile Breakpoint
*/
if ( !function_exists( 'newsx_get_mobile_breakpoint' ) ) {
	function newsx_get_mobile_breakpoint($mobile = 480) {
		return $mobile;
	}
}

/*
** Get SVG icon.
*/
if ( !function_exists('newsx_get_svg_icon') ) {
	function newsx_get_svg_icon( $icon ) {
		static $icons_array = null;

		// Load the SVG icons array if it's not loaded yet
		if ( is_null( $icons_array ) ) {
			/** @psalm-suppress DocblockTypeContradiction */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
			ob_start();
			include_once NEWSX_ASSETS_DIR .'/svg/svg-icons.json'; // phpcs:ignore WPThemeReview.CoreFunctionality.FileInclude.FileIncludeFound
			$icons_array = json_decode( ob_get_clean(), true );
		}

		// Output
		$output  = '<span class="newsx-svg-icon newsx-inline-flex">';

		$output .= isset( $icons_array[ $icon ] ) ? $icons_array[ $icon ] : '';

		$output .= '</span>';

		return $output;
	}
}

/*
** Get Theme Author details.
*/
if ( !function_exists('newsx_get_theme_author_details') ) {
	function newsx_get_theme_author_details() {
		return [
			'theme_name'       => __( 'News Magazine X', 'news-magazine-x' ),
			'theme_author_url' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-free/?ref=newsx-free-footer-credit',
		];
	}
}

/*
** Get User Socials.
*/
if ( !function_exists('newsx_get_user_socials') ) {
	function newsx_get_user_socials( $author_id = '' ) {

		if ( empty( $author_id ) ) {
			return false;
		}

		$data               = [];
		$data['website']    = get_the_author_meta( 'user_url', $author_id );
		$data['facebook']   = get_the_author_meta( 'facebook', $author_id );
		$data['x_twitter']  = get_the_author_meta( 'x_twitter', $author_id );
		$data['instagram']  = get_the_author_meta( 'instagram', $author_id );
		$data['pinterest']  = get_the_author_meta( 'pinterest', $author_id );
		$data['linkedin']   = get_the_author_meta( 'linkedin', $author_id );
		$data['tumblr']     = get_the_author_meta( 'tumblr', $author_id );
		$data['flickr']     = get_the_author_meta( 'flickr', $author_id );
		$data['skype']      = get_the_author_meta( 'skype', $author_id );
		$data['snapchat']   = get_the_author_meta( 'snapchat', $author_id );
		$data['youtube']    = get_the_author_meta( 'youtube', $author_id );
		$data['digg']       = get_the_author_meta( 'digg', $author_id );
		$data['dribbble']   = get_the_author_meta( 'dribbble', $author_id );
		$data['soundcloud'] = get_the_author_meta( 'soundcloud', $author_id );
		$data['vimeo']      = get_the_author_meta( 'vimeo', $author_id );
		$data['reddit']     = get_the_author_meta( 'reddit', $author_id );
		$data['vkontakte']  = get_the_author_meta( 'vkontakte', $author_id );
		$data['telegram']   = get_the_author_meta( 'telegram', $author_id );
		$data['whatsapp']   = get_the_author_meta( 'whatsapp', $author_id );
		$data['rss']        = get_the_author_meta( 'rss', $author_id );

		return $data;
	}
}

/*
** Get Posts Per Page for Layouts
*/
if ( !function_exists('newsx_get_widget_posts_per_page') ) {
    function newsx_get_widget_posts_per_page( $instance = [], $widget = '' ) {
        $posts_per_page = isset($instance['posts_per_page']) && is_int($instance['posts_per_page']) ? $instance['posts_per_page'] : 6;
        
        if ( 'list' === $widget ) {
            $layout = isset($instance['layout']) ? $instance['layout'] : 'list-1';
    
            if ( 'list-4' === $layout ) {
                $posts_per_page = 5;
            }
    
            if ( 'list-5' === $layout ) {
                $posts_per_page = 6;
            }
        } else if ( 'magazine' === $widget ) {
            $layout = isset($instance['layout']) ? $instance['layout'] : '1-2';

            // Convert layout string numbers to integers before summing
            $layout_parts = explode('-', $layout);
            $layout_numbers = array_map('intval', $layout_parts);
            $posts_per_page = array_sum($layout_numbers);
        }

        return $posts_per_page;
    }
}

/*
** Main Query Args.
*/
if ( !function_exists('newsx_get_main_query_args') ) {
	function newsx_get_main_query_args( $instance = [], $widget = '', $option_prefix = '' ) {
		
        // Widget Options
        if ( !empty($instance) ) {
            $posts_per_page = newsx_get_widget_posts_per_page( $instance, $widget );
            $orderby = isset($instance['orderby']) ? $instance['orderby'] : 'date';
            $offset = isset($instance['offset']) ? $instance['offset'] : 0;
			$published_days = isset($instance['published_days']) ? $instance['published_days'] : 365;

		// Element Options
        } else if ( !empty($option_prefix) ) {
            $posts_per_page = !empty(newsx_get_option($option_prefix .'_posts_per_page')) ? newsx_get_option($option_prefix .'_posts_per_page') : 6;
            $orderby = !empty(newsx_get_option($option_prefix .'_orderby')) ? newsx_get_option($option_prefix .'_orderby') : 'date';
            $offset = !empty(newsx_get_option($option_prefix .'_offset')) ? newsx_get_option($option_prefix .'_offset') : 0;
			$published_days = !empty(newsx_get_option($option_prefix .'_published_days')) ? newsx_get_option($option_prefix .'_published_days') : 365;

		// Default
		} else {
			$posts_per_page = 6;
			$orderby = 'date';
			$offset = 0;
			$published_days = 365;
		}

        // Get Paged
		if ( get_query_var( 'paged' ) ) {
			$paged = get_query_var( 'paged' );
		} elseif ( get_query_var( 'page' ) ) {
			$paged = get_query_var( 'page' );
		} else {
			$paged = 1;
		}

		// Date Query
		$date_query = [];
		
		// Handle Popular Posts Logic
		if ( str_contains($orderby, 'popular') && function_exists('pvc_get_most_viewed_posts') ) {
			if ( 'popular-custom' === $orderby ) {
				$date_query = [
					[
						'after' => $published_days .' days ago',
					],
				];
			}
			
			// For popular posts, we need to get the post IDs and use them in a standard query
			$popular_args = [
				'post_type' => 'post',
				'posts_per_page' => $posts_per_page,
				'offset' => $offset,
				'date_query' => $date_query,
			];

			// Add taxonomy query if provided
			$tax_query = newsx_get_tax_query_args( $instance );
			if ( !empty($tax_query) ) {
				$popular_args['tax_query'] = $tax_query;
			}

			// Add meta query for featured image (except for header_nt)
			if ( 'header_nt' !== $option_prefix ) {
				$popular_args['meta_query'] = array(array('key' => '_thumbnail_id'));
			}

			// Get popular posts
			$popular_posts = pvc_get_most_viewed_posts($popular_args);
			
			// Extract post IDs from popular posts
			$post_ids = [];
			if ( !empty($popular_posts) ) {
				foreach ( $popular_posts as $post ) {
					$post_ids[] = $post->ID;
				}
			}
			
			// Return standard query args with post__in to maintain the popular posts order
			return [
				'post_type' => 'post',
				'post__in' => $post_ids,
				'orderby' => 'post__in', // This maintains the order from popular posts
				'posts_per_page' => $posts_per_page,
				'ignore_sticky_posts' => true,
				'paged' => $paged,
			];
		}

		// Handle Random Custom Logic
		if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
			if ( 'random-custom' === $orderby ) {
				$orderby = 'rand';
				$date_query = [
					[
						'after' => $published_days .' days ago',
					],
				];
			}
		}

        // Standard Query Args
		$args = [
			'post_type' => 'post',
			'tax_query' => newsx_get_tax_query_args( $instance ),
			'posts_per_page' => $posts_per_page,
			'ignore_sticky_posts' => true,
			'orderby' => $orderby,
			'paged' => $paged,
			'offset' => $offset,
			'date_query' => $date_query,
		];

		if ( 'header_nt' !== $option_prefix ) {
			$args['meta_query'] = array(array('key' => '_thumbnail_id'));
		}

        return $args;
    }
}



/*
** Taxonomy Query Args.
*/
if ( !function_exists('newsx_get_tax_query_args') ) {
	function newsx_get_tax_query_args( $instance ) {
        if ( !empty($instance) ) {
            $categories = isset($instance[ 'categories' ]) ? $instance[ 'categories' ] : '';
            $tags = isset($instance[ 'tags' ]) ? $instance[ 'tags' ] : '';
        } else {
            $categories = newsx_get_option('header_nt_categories');
            $tags = newsx_get_option('header_nt_tags');
        }

		$tax_query = [];

        // Post Categories
        if ( ! empty($categories) ) {
            array_push( $tax_query, [
                'taxonomy' => 'category',
                'field' => 'id',
                'terms' => $categories
            ] );
        }

        // Post Tags
        if ( ! empty($tags) ) {
            array_push( $tax_query, [
                'taxonomy' => 'post_tag',
                'field' => 'id',
                'terms' => $tags
            ] );
        }

		return $tax_query;
	}
}

/*
** Get Boxed Content Class
*/
if ( !function_exists('newsx_get_boxed_content_class') ) {
	function newsx_get_boxed_content_class() {
		$wrapper_class = 'boxed' === newsx_get_option('global_content_width') ? ' newsx-container' : '';

		return $wrapper_class;
	}
}

/*
** Allow Custom Tags and Attributes 
*/
if ( !function_exists('newsx_allow_custom_tags_and_atts_for_svg_and_img') ) {
	function newsx_allow_custom_tags_and_atts_for_svg_and_img() {
		return [
			'img' => [
				'src' => true,
				'alt' => true,
				'class' => true,
				'id' => true,
				'style' => true,
				'width' => true,
				'height' => true,
				'title' => true,
				'loading' => true,
				'decoding' => true,
				'srcset' => true,
				'sizes' => true
			],
			'span' => [
				'class' => true,
				'id' => true,
				'style' => true,
				'dir' => true,
				'lang' => true,
				'title' => true
			],
			'style' => [
				'display' => true,
				'type' => true
			],
			'svg' => [
				'class' => true,
				'aria-hidden' => true,
				'role' => true,
				'xmlns' => true,
				'width' => true,
				'height' => true,
				'viewbox' => true,
				'viewBox' => true,
				'fill' => true,
				'id' => true,
				'style' => true,
				'transform' => true,
				'version' => true,
				'xmlns:xlink' => true,
				'x' => true,
				'y' => true
			],
			'path' => [
				'd' => true,
				'fill' => true,
				'stroke' => true,
				'stroke-width' => true,
				'transform' => true,
				'stroke-linecap' => true,
				'stroke-linejoin' => true,
				'id' => true,
				'class' => true,
				'style' => true
			],
			'rect' => [
				'x' => true,
				'y' => true,
				'width' => true, 
				'height' => true,
				'fill' => true,
				'stroke' => true,
				'stroke-width' => true,
				'transform' => true,
				'id' => true,
				'class' => true,
				'rx' => true,
				'ry' => true,
				'style' => true
			],
			'circle' => [
				'cx' => true,
				'cy' => true,
				'r' => true,
				'fill' => true,
				'stroke' => true,
				'stroke-width' => true,
				'id' => true,
				'class' => true,
				'style' => true
			],
			'g' => [
				'fill' => true,
				'id' => true,
				'transform' => true,
				'class' => true,
				'style' => true
			],
			'ellipse' => [
				'cx' => true,
				'cy' => true,
				'rx' => true,
				'ry' => true,
				'fill' => true,
				'stroke' => true,
				'stroke-width' => true,
				'style' => true
			],
			'line' => [
				'x1' => true,
				'y1' => true,
				'x2' => true,
				'y2' => true,
				'stroke' => true,
				'stroke-width' => true,
				'style' => true
			],
			'polygon' => [
				'points' => true,
				'fill' => true,
				'stroke' => true,
				'stroke-width' => true,
				'style' => true
			],
			'polyline' => [
				'points' => true,
				'fill' => true,
				'stroke' => true,
				'stroke-width' => true,
				'style' => true
			],
			'text' => [
				'x' => true,
				'y' => true,
				'font-family' => true,
				'font-size' => true,
				'fill' => true,
				'stroke' => true,
				'stroke-width' => true,
				'style' => true
			],
			'tspan' => [
				'x' => true,
				'y' => true,
				'dx' => true,
				'dy' => true,
				'fill' => true,
				'stroke' => true,
				'stroke-width' => true,
				'style' => true
			],
			'defs' => [
				'style' => true
			],
			'clipPath' => [
				'id' => true,
				'style' => true
			],
			'use' => [
				'xlink:href' => true,
				'href' => true,
				'x' => true,
				'y' => true,
				'width' => true,
				'height' => true,
				'style' => true
			]
		];
	}
}