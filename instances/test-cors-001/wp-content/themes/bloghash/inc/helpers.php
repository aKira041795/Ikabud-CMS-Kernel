<?php

/**
 * Frontend helper functions used throught the theme.
 *
 * @package BlogHash
 * @author  Peregrine Themes
 * @since   1.0.0
 */

/**
 * Do not allow direct script access.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns current page URL.
 *
 * @since 1.0.0
 * @return string, current page URL.
 */
function bloghash_current_url() {
	global $wp;
	return home_url( add_query_arg( array(), $wp->request ) );
}

/**
 * Returns site URL.
 *
 * @since 1.0.0
 * @return string, current page URL.
 */
function bloghash_get_site_url() {
	return apply_filters( 'bloghash_site_url', home_url( '/' ) );
}

/**
 * Returns site title.
 *
 * @since 1.0.0
 * @return string, current page URL.
 */
function bloghash_get_site_title() {
	return apply_filters( 'bloghash_site_title', get_bloginfo( 'name' ) );
}

/**
 * Returns site description.
 *
 * @since 1.0.0
 * @return string, current page URL.
 */
function bloghash_get_site_description() {
	return apply_filters( 'bloghash_site_description', get_bloginfo( 'description' ) );
}

if ( ! function_exists( 'bloghash_the_title' ) ) {

	/**
	 * Wrapper function for bloghash_get_the_title().
	 *
	 * @since 1.0.0
	 * @param string $before  Optional. Content to prepend to the title.
	 * @param string $after   Optional. Content to append to the title.
	 * @param int    $post_id Optional, default to 0. Post id.
	 * @param bool   $echo    Optional, default to true. Whether to display or return.
	 * @return string|void    String if $echo parameter is false.
	 */
	function bloghash_the_title( $before = '', $after = '', $post_id = 0, $echo = true ) {

		$title  = bloghash_get_the_title( $post_id );
		$before = apply_filters( 'bloghash_the_title_before', $before );
		$after  = apply_filters( 'bloghash_the_title_after', $after );

		$title = $before . $title . $after;

		if ( $echo ) {
			echo wp_kses( $title, bloghash_get_allowed_html_tags() );
		} else {
			return $title;
		}
	}
}

if ( ! function_exists( 'bloghash_get_the_title' ) ) {

	/**
	 * Get page title. Adds support for non-singular pages.
	 *
	 * @since 1.0.0
	 * @param int  $post_id Optional, default to 0. Post id.
	 * @param bool $echo    Optional, default to false. Whether to display or return.
	 * @return string|void  String if $echo parameter is false.
	 */
	function bloghash_get_the_title( $post_id = 0, $echo = false ) {

		$title = '';

		if ( $post_id || is_singular() ) {
			$title = get_the_title( $post_id );

			if ( function_exists( 'yith_wcwl_is_wishlist_page' ) && yith_wcwl_is_wishlist_page() ) {

				// Retireve wishlist title.
				$wishlist_title = get_option( 'yith_wcwl_wishlist_title' ) ? get_option( 'yith_wcwl_wishlist_title' ) : __( 'Wishlist', 'bloghash' );

				// Yith wishlist title.
				$title = apply_filters( 'bloghash_yith_wishlist_title', esc_html( $wishlist_title ) );
			}
		} else {
			if ( is_front_page() && is_home() ) {
				// Homepage.
				$title = apply_filters( 'bloghash_home_page_title', esc_html__( 'Home', 'bloghash' ) );
			} elseif ( is_home() ) {
				// Blog page.
				$title = apply_filters( 'bloghash_blog_page_title', get_the_title( get_option( 'page_for_posts', true ) ) );
			} elseif ( is_404() ) {
				// 404 page - title always display.
				$title = apply_filters( 'bloghash_404_page_title', esc_html__( 'This page doesn&rsquo;t seem to exist.', 'bloghash' ) );
			} elseif ( is_search() ) {
				// Search page - title always display.
				/* translators: 1: search string */
				$title = apply_filters( 'bloghash_search_page_title', sprintf( __( 'Search results for: %s', 'bloghash' ), get_search_query() ) );
			} elseif ( class_exists( 'WooCommerce' ) && is_shop() ) {
				// Woocommerce.
				$title = woocommerce_page_title( false );
			} elseif ( is_author() ) {
				// Author post archive.
				$title = apply_filters( 'bloghash_author_page_title', esc_html__( 'Posts by', 'bloghash' ) . ' ' . esc_html( get_the_author() ) );
			} elseif ( is_category() || is_tag() || is_tax() ) {
				// Category, tag and custom taxonomy archive.
				$title = single_term_title( '', false );
			} elseif ( is_archive() ) {
				// Archive.
				$title = get_the_archive_title();
			}
		}
		if ( $echo ) {
			echo wp_kses( $title, bloghash_get_allowed_html_tags() );
		} else {
			return $title;
		}
	}
}

if ( ! function_exists( 'bloghash_get_the_id' ) ) {

	/**
	 * Get post ID.
	 *
	 * @since  1.0.0
	 * @return string Current post/page ID.
	 */
	function bloghash_get_the_id() {

		$post_id = 0;

		if ( is_home() && 'page' === get_option( 'show_on_front' ) ) {
			$post_id = get_option( 'page_for_posts' );
		} elseif ( is_front_page() && 'page' === get_option( 'show_on_front' ) ) {
			$post_id = get_option( 'page_on_front' );
		} elseif ( is_singular() ) {
			$post_id = get_the_ID();
		}

		return apply_filters( 'bloghash_get_the_id', $post_id );
	}
}

if ( ! function_exists( 'bloghash_get_the_description' ) ) {

	/**
	 * Get page description. Adds support for non-singular pages.
	 *
	 * @since 1.0.0
	 * @param int  $post_id Optional, default to 0. Post id.
	 * @param bool $echo    Optional, default to false. Whether to display or return.
	 * @return string|void  String if $echo parameter is false.
	 */
	function bloghash_get_the_description( $post_id = 0, $echo = false ) {

		$description = '';

		if ( $post_id ) {
			// @todo: take from meta..
			$description = get_the_excerpt( $post_id );
		} elseif ( is_search() ) {
			global $wp_query;
			$found_posts = $wp_query->found_posts;

			if ( $found_posts > 0 ) {
				// Translators: $s number of found results.
				$description = sprintf( _n( '%s result found', '%s results found', $found_posts, 'bloghash' ), number_format_i18n( $found_posts ) );
			} else {
				$description = esc_html__( 'No results found', 'bloghash' );
			}
		} elseif ( is_author() ) {
			$description = '';
		} else {
			$description = get_the_archive_description();
		}

		if ( $echo ) {
			echo esc_html( $description );
		} else {
			return $description;
		}
	}
}

/**
 * Checks to see if we're on the homepage or not.
 *
 * @since 1.0.0
 * @return boolean, if current page is front page.
 */
function bloghash_is_frontpage() {
	return ( is_front_page() && ! is_home() );
}

/**
 * Check if schema is enabled.
 *
 * @since 1.0.0
 * @return boolean
 */
function bloghash_is_schema_enabled() {

	$enabled = bloghash_option( 'enable_schema' );

	return apply_filters( 'bloghash_is_schema_enabled', $enabled );
}

/**
 * Check if a custom logo has been uploaded.
 *
 * @since 1.0.0
 * @return boolean
 */
function bloghash_has_logo() {

	if ( has_custom_logo() ) {
		return true;
	}

	return false;
}

/**
 * Get sidebar name.
 *
 * @since 1.0.0
 * @return string|boolean
 */
function bloghash_get_sidebar() {

	$sidebar = 'sidebar-1';

	$sidebar = apply_filters( 'bloghash_sidebar_name', $sidebar );

	if ( ! is_active_sidebar( $sidebar ) && ! current_user_can( 'edit_theme_options' ) ) {
		return false;
	}

	return $sidebar;
}

/**
 * Get site layout (content layout) position.
 *
 * @since  1.0.0
 * @param  object|int|null $post Optional. The post to check. If not supplied, defaults to the current post if used in the loop.
 * @return string, site layout.
 */
function bloghash_get_site_layout( $post = null ) {

	// Default site layout from Customizer.
	$default_site_layout = apply_filters( 'bloghash_default_site_layout', bloghash_option( 'site_layout' ), $post );
	$site_layout         = $default_site_layout;

	$post = is_null( $post ) ? bloghash_get_the_id() : $post;

	if ( $post ) {

		$post = get_post( $post );

		// Singular pages have meta settings for content layout.
		if ( ! empty( $post ) ) {
			$site_layout = get_post_meta( $post->ID, 'bloghash_content_layout', true );

			if ( empty( $site_layout ) ) {
				$site_layout = $default_site_layout;
			}
		}
	}

	return apply_filters( 'bloghash_site_layout', $site_layout );
}

/**
 * Get sidebar layout position.
 *
 * @since  1.0.0
 * @param  object|int|null $post Optional. The post to check. If not supplied, defaults to the current post if used in the loop.
 * @return string, Sidebar layout position.
 */
function bloghash_get_sidebar_position( $post = null ) {

	// Default sidebar position.
	if ( is_singular( 'post' ) ) {
		$default_sidebar_position = bloghash_option( 'single_post_sidebar_position' );
	} elseif ( is_singular( 'page' ) ) {
		$default_sidebar_position = bloghash_option( 'single_page_sidebar_position' );
	} elseif ( is_archive() || is_search() ) {
		$default_sidebar_position = bloghash_option( 'archive_sidebar_position' );
	} else {
		$default_sidebar_position = bloghash_option( 'sidebar_position' );
	}

	if ( empty( $default_sidebar_position ) || 'default' === $default_sidebar_position ) {
		$default_sidebar_position = bloghash_option( 'sidebar_position' );
	}

	$post = is_null( $post ) ? bloghash_get_the_id() : $post;

	$default_sidebar_position = apply_filters( 'bloghash_default_sidebar_position', $default_sidebar_position, $post );

	$sidebar_position = $default_sidebar_position;

	// Get meta settings if page is set.
	if ( $post ) {

		$post = get_post( $post );

		// Singular pages have meta settings for sidebar position.
		if ( ! empty( $post ) ) {
			$sidebar_position = get_post_meta( $post->ID, 'bloghash_sidebar_position', true );

			if ( empty( $sidebar_position ) ) {
				$sidebar_position = $default_sidebar_position;
			}
		}
	}

	// Force no sidebar on 404 pages.
	if ( is_404() ) {
		$sidebar_position = 'no-sidebar';
	}

	return apply_filters( 'bloghash_sidebar_position', $sidebar_position, $post );
}

/**
 * Check if sidebar is displayed.
 *
 * @since 1.0.0
 * @param int $post Optional. The post ID to check. If not supplied, defaults to the current post if used in the loop.
 * @return boolean Sidebar displayed.
 */
function bloghash_is_sidebar_displayed( $post = null ) {

	$position  = bloghash_get_sidebar_position( $post );
	$displayed = true;

	if ( 'no-sidebar' === $position || '' === $position || false === $position ) {
		$displayed = false;
	}

	if ( ! bloghash_get_sidebar() ) {
		$displayed = false;
	}

	return apply_filters( 'bloghash_is_sidebar_displayed', $displayed );
}

/**
 * Check if sidebar is displayed.
 *
 * @since 1.0.0
 * @return string Article feed layout slug.
 */
function bloghash_get_article_feed_layout() {

	$layout = '';

	if ( is_front_page() || is_home() || is_archive() || is_search() ) {
		$layout = bloghash_option( 'blog_layout' );
	}

	return apply_filters( 'bloghash_article_feed_layout', $layout );
}

/**
 * Get ordered array of single post page sections.
 *
 * @since 1.0.0
 * @param int $post_id Optional. The post ID to check. If not supplied, defaults to the current post if used in the loop.
 * @return array, Section IDs for the single post layout.
 */
function bloghash_get_single_post_elements( $post_id = null ) {

	if ( ! $post_id ) {
		$post_id = get_the_ID();
	}

	$post_format = get_post_format( $post_id );

	$options  = bloghash_get_page_elements( 'single_post_elements' );
	$elements = array();

	if ( 'quote' !== $post_format ) {

		$layout = bloghash_option( 'single_title_position' );
		$layout = apply_filters( 'bloghash_single_title_position', $layout );

		if ( 'in-content' === $layout ) {

			if ( bloghash_show_post_thumbnail( $post_id ) && in_array( 'thumb', $options, true ) ) {
				$elements[] = 'thumbnail';
			}

			if ( in_array( 'category', $options, true ) ) {
				$elements[] = 'category';
			}

			$elements[] = 'header';
			$elements[] = 'meta';

		}

		$elements[] = 'content';
		$elements[] = 'content-footer';
	}

	if ( in_array( 'about-author', $options, true ) ) {
		$elements[] = 'about-author';
	}

	if ( in_array( 'prev-next-post', $options, true ) ) {
		$elements[] = 'prev-next-post';
	}

	$elements = apply_filters( 'bloghash_single_content_elements', $elements );

	return $elements;
}

/**
 * Check if single post element is displayed.
 *
 * @since  1.0.0
 * @param  string $element Element name.
 * @return boolean          Element is enabled or not.
 */
function bloghash_single_post_displays( $element ) {

	$options = bloghash_get_page_elements( 'single_post_elements' );

	return in_array( $element, $options, true );
}

/**
 * Get ordered array of blog entry elements.
 *
 * @since 1.0.0
 * @return array, Element IDs for the blog entry.
 */
function bloghash_get_blog_entry_elements() {

	$elements = bloghash_get_page_elements( 'blog_entry_elements' );

	return apply_filters( 'bloghash_blog_entry_elements', $elements );
}

/**
 * Get ordered array of page elements.
 *
 * @since 1.0.0
 * @param string  $id           Customizer setting ID.
 * @param boolean $enabled_only Return only enabled/visible elements.
 * @return array  $elements     Array of element IDs for the blog entry.
 */
function bloghash_get_page_elements( $id, $enabled_only = true ) {

	$elements = bloghash_option( $id );

	if ( empty( $elements ) ) {
		return array();
	}

	if ( $enabled_only ) {
		return array_keys( $elements, true, true );
	}

	return array_keys( $elements );
}

/**
 * Get ordered array of meta elements.
 *
 * @since 1.0.0
 * @param boolean $enabled_only Return only enabled/visible elements.
 * @return array  $elements     Element IDs for the blog entry.
 */
function bloghash_get_entry_meta_elements( $enabled_only = true ) {

	$elements = array();

	if ( is_single() ) {
		$elements = bloghash_option( 'single_post_meta_elements' );
	} else {
		$elements = bloghash_option( 'blog_entry_meta_elements' );
	}

	if ( $enabled_only ) {
		$elements = array_keys( $elements, true, true );
	} else {
		$elements = array_keys( $elements );
	}

	return apply_filters( 'bloghash_entry_meta_elements', $elements );
}

/**
 * Get ordered array of meta elements.
 *
 * @since 1.0.0
 * @param boolean $enabled_only Return only enabled/visible elements.
 * @return array  $elements     Element IDs for the blog entry.
 */
function bloghash_get_hero_entry_meta_elements( $enabled_only = true ) {

	$elements = array();

	if ( bloghash_is_hero_displayed() ) {
		$elements = bloghash_option( 'hero_entry_meta_elements' );
	}

	if ( $enabled_only ) {
		$elements = array_keys( $elements, true, true );
	} else {
		$elements = array_keys( $elements );
	}

	return apply_filters( 'bloghash_hero_entry_meta_elements', $elements );
}

/**
 * Check if at least one entry meta element is enabled.
 *
 * @since  1.0.0
 * @return boolean Has elements or not.
 */
function bloghash_has_entry_meta_elements() {

	$elements = bloghash_get_entry_meta_elements();

	return ! empty( $elements );
}

/**
 * Wrap comment submit button with span tag.
 *
 * @since 1.0.0
 * @param string $submit_button Comment content.
 * @param array  $args          Button arguments.
 */
function bloghash_filter_comment_form_submit_button( $submit_button, $args ) {
	return '<span class="bloghash-submit-form-button">' . $submit_button . '</span>'; // phpcs:ignore
}
add_filter( 'comment_form_submit_button', 'bloghash_filter_comment_form_submit_button', 10, 2 );

/**
 * Filter excerpt length.
 *
 * @since 1.0.0
 * @param int $length Word count for excerpt.
 * @return int
 */
function bloghash_excerpt_length( $length ) {
	return intval( bloghash_option( 'excerpt_length' ) );
}
add_filter( 'excerpt_length', 'bloghash_excerpt_length' );

/**
 * Filter excerpt more.
 *
 * @since 1.0.0
 * @param  string $more More indicator excerpt.
 * @return string
 */
function bloghash_excerpt_more( $more ) {
	return bloghash_option( 'excerpt_more' );
}
add_filter( 'excerpt_more', 'bloghash_excerpt_more' );

/**
 * Determines if post thumbnail can be displayed.
 *
 * @since 1.0.0
 * @param int $post_id Optional. The post ID to check. If not supplied, defaults to the current post if used in the loop.
 * @return boolean, Thumbnail displayed.
 */
function bloghash_show_post_thumbnail( $post_id = null ) {

	$post_id = is_null( $post_id ) ? bloghash_get_the_id() : $post_id;

	$display = ! post_password_required( $post_id ) && ! is_attachment( $post_id ) && has_post_thumbnail( $post_id );

	if ( get_post_meta( $post_id, 'bloghash_disable_thumbnail', true ) ) {
		$display = false;
	}

	return apply_filters( 'bloghash_show_post_thumbnail', $display );
}

if ( ! function_exists( 'bloghash_get_video_from_post' ) ) :

	/**
	 * Get video HTML markup from post content.
	 *
	 * @since 1.0.0
	 * @param  number $post_id Post id.
	 * @return mixed
	 */
	function bloghash_get_video_from_post( $post_id = null ) {

		$post    = get_post( $post_id );
		$content = do_shortcode( apply_filters( 'the_content', $post->post_content ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$embeds  = apply_filters( 'bloghash_get_post_video', get_media_embedded_in_content( $content ) );

		if ( empty( $embeds ) ) {
			return '';
		}

		// Return first embedded item that is a video format.
		foreach ( $embeds as $embed ) {
			if ( strpos( $embed, 'video' ) || strpos( $embed, 'youtube' ) || strpos( $embed, 'vimeo' ) ) {
				return $embed;
			}
		}
	}
endif;

if ( ! function_exists( 'bloghash_get_audio_from_post' ) ) :

	/**
	 * Get audio HTML markup from post content.
	 *
	 * @since 1.0.0
	 * @param  number $post_id Post id.
	 * @return mixed
	 */
	function bloghash_get_audio_from_post( $post_id = null ) {

		$post    = get_post( $post_id );
		$content = do_shortcode( apply_filters( 'the_content', $post->post_content ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$embeds  = apply_filters( 'bloghash_get_post_audio', get_media_embedded_in_content( $content ) );

		if ( empty( $embeds ) ) {
			return '';
		}

		// check what is the first embed containg audio tag, or soundlcoud.
		foreach ( $embeds as $embed ) {
			if ( strpos( $embed, 'audio' ) || strpos( $embed, 'soundcloud' ) ) {
				return '<span class="bloghash-post-audio-wrapper">' . $embed . '</span>';
			}
		}
	}
endif;

if ( ! function_exists( 'bloghash_get_post_gallery' ) ) :
	/**
	 * A get_post_gallery() polyfill for Gutenberg.
	 *
	 * @since 1.0.0
	 * @param object|int|null $post Optional. The post to check. If not supplied, defaults to the current post if used in the loop.
	 * @param boolean         $html Return gallery HTML or array of gallery items.
	 * @return string|array   The gallery html or array of gallery items.
	 */
	function bloghash_get_post_gallery( $post = 0, $html = false ) {

		// Get gallery shortcode.
		$gallery = get_post_gallery( $post, $html );

		// Already found a gallery so lets quit.
		if ( $gallery ) {
			return $gallery;
		}

		// Check the post exists.
		$post = get_post( $post );
		if ( ! $post ) {
			return;
		}

		// Not using Gutenberg so let's quit.
		if ( ! function_exists( 'has_blocks' ) ) {
			return;
		}

		// Not using blocks so let's quit.
		if ( ! has_blocks( $post->post_content ) ) {
			return;
		}

		/**
		 * Search for gallery blocks and then, if found, return the
		 * first gallery block.
		 */
		$pattern = '/<!--\ wp:gallery.*-->([\s\S]*?)<!--\ \/wp:gallery -->/i';
		preg_match_all( $pattern, $post->post_content, $the_galleries );

		// Check a gallery was found and if so change the gallery html.
		if ( ! empty( $the_galleries[1] ) ) {
			$gallery_html = reset( $the_galleries[1] );

			if ( $html ) {
				$gallery = $gallery_html;
			} else {
				$srcs = array();
				$ids  = array();

				preg_match_all( '#src=([\'"])(.+?)\1#is', $gallery_html, $src, PREG_SET_ORDER );
				if ( ! empty( $src ) ) {
					foreach ( $src as $s ) {
						$srcs[] = $s[2];
					}
				}

				preg_match_all( '#data-id=([\'"])(.+?)\1#is', $gallery_html, $id, PREG_SET_ORDER );
				if ( ! empty( $id ) ) {
					foreach ( $id as $i ) {
						$ids[] = $i[2];
					}
				}

				$gallery = array(
					'ids' => implode( ',', $ids ),
					'src' => $srcs,
				);
			}
		}

		return $gallery;
	}
endif;

if ( ! function_exists( 'bloghash_get_image_from_post' ) ) :

	/**
	 * Get image HTML markup from post content.
	 *
	 * @since 1.0.0
	 * @param object|int|null $post Optional. The post to check. If not supplied, defaults to the current post if used in the loop.
	 * @param boolean         $html Return image HTML or array of image items.
	 * @return mixed
	 */
	function bloghash_get_image_from_post( $post = null, $html = true ) {

		// Check the post exists.
		$post = get_post( $post );
		if ( ! $post ) {
			return;
		}

		$attachment_id = null;

		// Using Blocks, check if wp:image exists.
		if ( function_exists( 'has_blocks' ) && has_blocks( $post->post_content ) ) {

			/**
			 * Search for image blocks.
			 */
			$pattern = '/<!--\ wp:image.*"id"\s*:\s*([0-9]+).*-->/i';
			preg_match( $pattern, $post->post_content, $the_images );

			// Check if an image was found.
			if ( ! empty( $the_images[1] ) ) {
				$attachment_id = absint( $the_images[1] );
			}
		}

		// Nothing found, check if images added through Add Media.
		if ( ! $attachment_id ) {

			/**
			 * Search for img tags in the content.
			 */
			$pattern = '/<img.*wp-image-([0-9]+).*>/';
			preg_match( $pattern, $post->post_content, $the_images );

			// Check if an image was found.
			if ( ! empty( $the_images[0] ) ) {
				$attachment_id = absint( $the_images[0] );
			}
		}

		// Still nothing was found, check for attached images.
		if ( ! $attachment_id ) {

			$the_images = get_attached_media( 'image', $post->ID );

			if ( ! empty( $the_images ) ) {
				$image         = reset( $the_images );
				$attachment_id = $image->ID;
			}
		}

		// Check if an image was found.
		if ( $attachment_id ) {

			if ( $html ) {
				$atts = array(
					'alt' => get_the_title( $post->ID ),
				);

				if ( bloghash_get_schema_markup( 'image' ) ) {
					$atts['itemprop'] = 'image';
				}

				return wp_get_attachment_image( $attachment_id, 'full', false, $atts );
			} else {
				return wp_get_attachment_url( $attachment_id );
			}
		}

		return false;
	}
endif;

if ( ! function_exists( 'bloghash_get_post_thumbnail' ) ) :

	/**
	 * Get post thumbnail markup.
	 *
	 * @since 1.0.0
	 * @param int|WP_Post  $post Optional. Post ID or WP_Post object.  Default is global `$post`.
	 * @param string|array $size Optional. Image size to use. Accepts any valid image size, or
	 *                           an array of width and height values in pixels (in that order).
	 *                           Default 'post-thumbnail'.
	 * @param boolean      $caption Optional. Display image caption.
	 * @return string The post thumbnail image tag.
	 */
	function bloghash_get_post_thumbnail( $post = null, $size = 'post-thumbnail', $caption = false ) {

		$attachment_id  = get_post_thumbnail_id( $post );
		$attachment_alt = trim(strip_tags(get_post_meta($attachment_id, '_wp_attachment_image_alt', true))); // phpcs:ignore
		$attachment_alt = empty( $attachment_alt ) ? get_the_title( $post ) : $attachment_alt;

		$atts = array(
			'alt' => $attachment_alt,
		);

		if ( bloghash_get_schema_markup( 'image' ) ) {
			$atts['itemprop'] = 'image';
		}

		$size = apply_filters( 'bloghash_post_thumbnail_default_size', $size );
		$atts = apply_filters( 'bloghash_post_thumbnail_default_size', $atts );

		$html = get_the_post_thumbnail( $post, $size, $atts );

		if ( $caption ) {

			$caption = wp_get_attachment_caption( $attachment_id );

			if ( ! empty( $caption ) ) {
				$caption = '<div class="post-thumb-caption">' . wp_kses( $caption, bloghash_get_allowed_html_tags( 'button' ) ) . '</div>';
			}

			$html .= $caption;
		}

		return apply_filters( 'bloghash_post_thumbnail_html', $html, $post, $attachment_id, $size, $atts );
	}
endif;

if ( ! function_exists( 'bloghash_entry_get_permalink' ) ) :
	/**
	 * Get permalink for one post entry.
	 *
	 * @since 1.0.0
	 * @param int|WP_Post $post Optional. Post ID or WP_Post object.  Default is global `$post`.
	 * @return string
	 */
	function bloghash_entry_get_permalink( $post = null ) {

		$permalink = '';

		if ( 'link' === get_post_format( $post ) ) {
			$permalink = get_url_in_content( get_the_content( $post ) );
		} else {
			$permalink = get_permalink( $post );
		}

		return apply_filters( 'bloghash_entry_permalink', $permalink );
	}
endif;

/**
 * Determines breadcrumbs are displayed.
 *
 * @since 1.0.0
 *
 * @param  int $post_id Optional. The post ID to check.
 * @return boolean, Breadcrumbs displayed.
 */
function bloghash_has_breadcrumbs( $post_id = 0 ) {

	if ( ! $post_id ) {
		$post_id = bloghash_get_the_id();
	}

	$display = bloghash_option( 'breadcrumbs_enable' );

	if ( $display && bloghash_is_section_disabled( bloghash_option( 'breadcrumbs_hide_on' ), $post_id ) ) {
		$display = false;
	}

	if ( $display && $post_id && get_post_meta( $post_id, 'bloghash_disable_breadcrumbs', true ) ) {
		$display = false;
	}

	return apply_filters( 'bloghash_has_breadcrumbs', $display, $post_id );
}

/**
 * Determines if page header breadcrumbs are displayed.
 *
 * @since 1.0.0
 *
 * @param  int $post_id Optional. The post ID to check.
 * @return boolean, Breadcrumbs displayed.
 */
function bloghash_page_header_has_breadcrumbs( $post_id = 0 ) {

	return bloghash_has_breadcrumbs( $post_id ) && 'in-page-header' === bloghash_option( 'breadcrumbs_position' );
}

/**
 * Determines if page header title & description are displayed.
 *
 * @since 1.0.0
 *
 * @param  int $post_id Optional. The post ID to check.
 * @return boolean, Title & description displayed.
 */
function bloghash_page_header_has_title( $post_id = 0 ) {

	if ( ! $post_id ) {
		$post_id = bloghash_get_the_id();
	}

	$display = true;

	if ( is_singular( 'post' ) && ! in_array( bloghash_option( 'single_title_position' ), array( 'in-page-header' ), true ) ) {
		$display = false;
	}

	// Disabled in post meta settings.
	if ( get_post_meta( $post_id, 'bloghash_disable_page_title', true ) ) {
		$display = false;
	}

	// Finally, check if title string is empty.
	if ( $display ) {
		$title = apply_filters( 'bloghash_page_header_title', bloghash_get_the_title() );

		if ( ! $title ) {
			$display = false;
		}
	}

	return apply_filters( 'bloghash_page_header_has_title', $display );
}

/**
 * Determines if comments are displayed.
 *
 * @since 1.0.0
 * @return boolean, true if comments are displayed.
 */
function bloghash_comments_displayed() {

	$display = true;

	/*
	 * Return false if comments are closed and there are no comments already posted.
	 */
	if ( ! is_singular() || ( ! comments_open() && ! get_comments_number() ) || ! post_type_supports( get_post_type(), 'comments' ) ) {
		$display = false;
	}

	/*
	 * If the current post is protected by a password and
	 * the visitor has not yet entered the password we will
	 * return early without loading the comments.
	 */
	if ( post_password_required() ) {
		return false;
	}

	return apply_filters( 'bloghash_display_comments', $display );
}

/**
 * Determines if comments toggle is displayed.
 *
 * @since 1.0.0
 * @return boolean, true if comments toggle is displayed.
 */
function bloghash_comments_toggle_displayed() {

	$return = bloghash_option( 'single_toggle_comments' );

	return apply_filters( 'bloghash_display_comments_toggle', $return );
}

/**
 * Add attributes to Masthead.
 *
 * @since 1.0.0
 * @param array $atts Attributes array.
 * @param int   $post_id Optional. The post ID to check. If not supplied, defaults to the current post if used in the loop.
 * @return void|string
 */
function bloghash_masthead_atts( $atts = array(), $post_id = '' ) {

	if ( is_singular( 'post' ) && 'in-page-header' === bloghash_option( 'single_title_position' ) && bloghash_is_header_transparent( $post_id ) ) {
		if ( bloghash_show_post_thumbnail( $post_id ) && bloghash_single_post_displays( 'thumb' ) ) {
			$atts['style']  = isset( $atts['style'] ) ? $atts['style'] : '';
			$atts['style'] .= 'background-image: url(' . wp_get_attachment_image_url( get_post_thumbnail_id( $post_id ), 'full' ) . ');';
		}
	}

	$atts = apply_filters( 'bloghash_masthead_atts', $atts, $post_id );

	if ( ! empty( $atts ) ) {

		$output = '';

		foreach ( $atts as $att => $content ) {
			$output .= sanitize_title( $att ) . '="' . esc_attr( $content ) . '"';
		}

		$output = empty( $output ) ? '' : ' ' . $output;

		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

/**
 * Add classes to Header.
 *
 * @since 1.0.0
 * @param array $classes Classes array.
 * @return void
 */
function bloghash_header_classes( $classes = array() ) {

	// Optional wide header container.
	if ( 'full-width' === bloghash_option( 'header_container_width' ) ) {
		$classes[] = 'bloghash-container__wide';
	}

	$classes = apply_filters( 'bloghash_header_classes', $classes );

	if ( ! empty( $classes ) ) {
		$classes = trim( implode( ' ', $classes ) );
		echo 'class="' . esc_attr( $classes ) . '"';
	}
}


/**
 * Add classes to Top Bar.
 *
 * @since 1.0.0
 * @param array $classes Classes array.
 * @return void
 */
function bloghash_top_bar_classes( $classes = array() ) {

	// Optional wide top bar container.
	if ( 'full-width' === bloghash_option( 'top_bar_container_width' ) ) {
		$classes[] = 'bloghash-container__wide';
	}

	// Top Bar visibility.
	$top_bar_visibility = bloghash_option( 'top_bar_visibility' );

	if ( 'all' !== $top_bar_visibility ) {
		$classes[] = 'bloghash-' . $top_bar_visibility;
	}

	$classes = apply_filters( 'bloghash_top_bar_classes', $classes );

	if ( ! empty( $classes ) ) {
		$classes = trim( implode( ' ', $classes ) );
		echo 'class="' . esc_attr( $classes ) . '"';
	}
}

/**
 * Add classes to Page Header.
 *
 * @since 1.0.0
 * @param array $classes Classes array.
 * @return void
 */
function bloghash_page_header_classes( $classes = array() ) {

	$classes[] = 'page-header';

	// Background image.
	if ( is_singular( 'post' ) && 'in-page-header' === bloghash_option( 'single_title_position' ) ) {
		$classes[] = 'bloghash-page-title-has-bg-img';
	}

	if ( bloghash_page_header_has_title() ) {
		$classes[] = 'bloghash-has-page-title';
	}

	if ( bloghash_page_header_has_breadcrumbs() ) {
		$classes[] = 'bloghash-has-breadcrumbs';
	}

	$classes = apply_filters( 'bloghash_page_header_classes', $classes );

	if ( ! empty( $classes ) ) {
		$classes = trim( implode( ' ', $classes ) );
		echo 'class="' . esc_attr( $classes ) . '"';
	}
}

/**
 * Add attributes to Page Header.
 *
 * @since 1.0.0
 * @param array $atts Array of additional attributes.
 * @param int   $post_id Optional. The post ID to check. If not supplied, defaults to the current post if used in the loop.
 * @return void
 */
function bloghash_page_header_atts( $atts = array(), $post_id = '' ) {

	if ( is_singular( 'post' ) && 'in-page-header' === bloghash_option( 'single_title_position' ) && ! bloghash_is_header_transparent( $post_id ) ) {
		if ( bloghash_show_post_thumbnail( $post_id ) && bloghash_single_post_displays( 'thumb' ) ) {
			$atts['style']  = isset( $atts['style'] ) ? $atts['style'] : '';
			$atts['style'] .= 'background-image: url(' . wp_get_attachment_image_url( get_post_thumbnail_id( $post_id ), 'full' ) . ');';
		}
	}

	$atts = apply_filters( 'bloghash_page_header_atts', $atts, $post_id );

	if ( ! empty( $atts ) ) {

		$output = '';

		foreach ( $atts as $att => $content ) {
			$output .= sanitize_title( $att ) . '="' . esc_attr( $content ) . '"';
		}

		$output = empty( $output ) ? '' : ' ' . $output;

		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

/**
 * Add classes to Scroll Top Button.
 *
 * @since 1.0.0
 * @param array $classes Classes array.
 * @return void
 */
function bloghash_scroll_top_classes( $classes = array() ) {

	// Scroll Top visibility.
	$scroll_top_visibility = bloghash_option( 'scroll_top_visibility' );

	if ( 'all' !== $scroll_top_visibility ) {
		$classes[] = 'bloghash-' . $scroll_top_visibility;
	}

	$classes = apply_filters( 'bloghash_scroll_top_classes', $classes );

	if ( ! empty( $classes ) ) {
		$classes = trim( implode( ' ', $classes ) );
		echo 'class="' . esc_attr( $classes ) . '"';
	}
}

/**
 * Add classes to Page Preloader.
 *
 * @since 1.0.0
 * @return void
 */
function bloghash_preloader_classes() {

	$classes = array();

	// Page Preloader visibility.
	$preloader_visibility = bloghash_option( 'preloader_visibility' );

	if ( 'all' !== $preloader_visibility ) {
		$classes[] = 'bloghash-' . $preloader_visibility;
	}

	$classes = apply_filters( 'bloghash_preloader_classes', $classes );

	if ( ! empty( $classes ) ) {
		$classes = trim( implode( ' ', $classes ) );
		echo ' class="' . esc_attr( $classes ) . '"';
	}
}

/**
 * Add classes to Main Footer.
 *
 * @since 1.0.0
 * @param array $classes Classes array.
 * @return void
 */
function bloghash_footer_classes( $classes = array() ) {

	// Main Footer visibility.
	$footer_visibility = bloghash_option( 'footer_visibility' );

	if ( 'all' !== $footer_visibility ) {
		$classes[] = 'bloghash-' . $footer_visibility;
	}

	$classes = apply_filters( 'bloghash_footer_classes', $classes );

	if ( ! empty( $classes ) ) {
		$classes = trim( implode( ' ', $classes ) );
		echo 'class="' . esc_attr( $classes ) . '"';
	}
}

/**
 * Get footer widgets column count.
 *
 * @since 1.0.0
 * @return integer Number of footer columns
 */
function bloghash_get_footer_column_count() {

	$count = 4;

	return apply_filters( 'bloghash_footer_column_count', $count );
}

/**
 * Get footer widgets column count.
 *
 * @since 1.0.0
 * @param string $layout Footer layout.
 * @return array Classes array
 */
function bloghash_get_footer_column_class( $layout = 'layout-1' ) {

	$classes = array(
		'layout-1' => array(
			'col-xs-12 col-sm-6 stretch-xs col-md-3',
			'col-xs-12 col-sm-6 stretch-xs col-md-3',
			'col-xs-12 col-sm-6 stretch-xs col-md-3',
			'col-xs-12 col-sm-6 stretch-xs col-md-3',
		),
		'layout-2' => array(
			'col-xs-12 col-sm-6 stretch-xs col-md-4',
			'col-xs-12 col-sm-6 stretch-xs col-md-4',
			'col-xs-12 col-sm-6 stretch-xs col-md-4',
		),
		'layout-3' => array(
			'col-xs-12 col-sm-6 stretch-xs col-md-8',
			'col-xs-12 col-sm-6 stretch-xs col-md-4',
		),
		'layout-4' => array(
			'col-xs-12 col-sm-6 stretch-xs col-md-4',
			'col-xs-12 col-sm-6 stretch-xs col-md-8',
		),
		'layout-5' => array(
			'col-xs-12 col-sm-6 stretch-xs col-md-6',
			'col-xs-12 col-sm-6 stretch-xs col-md-3',
			'col-xs-12 col-sm-6 stretch-xs col-md-3',
		),
		'layout-6' => array(
			'col-xs-12 col-sm-6 stretch-xs col-md-3',
			'col-xs-12 col-sm-6 stretch-xs col-md-3',
			'col-xs-12 col-sm-6 stretch-xs col-md-6',
		),
		'layout-7' => array(
			'col-xs-12 col-sm-6 stretch-xs col-md-6',
			'col-xs-12 col-sm-6 stretch-xs col-md-6',
		),
		'layout-8' => array(
			'col-xs-12 col-sm-12 stretch-xs col-md-12',
		),
	);

	$classes = apply_filters( 'bloghash_footer_column_classes', $classes, $layout );

	$classes = isset( $classes[ $layout ] ) ? $classes[ $layout ] : array();

	$align_center = bloghash_option( 'footer_widgets_align_center' );

	if ( $align_center && ! empty( $classes ) ) {
		foreach ( $classes as $key => $column_class ) {
			$classes[ $key ] = $column_class . ' center-text';
		}
	}

	return $classes;
}

/**
 * Add classes to Copyright bar.
 *
 * @since 1.0.0
 * @param array $classes Classes array.
 * @return void
 */
function bloghash_copyright_classes( $classes = array() ) {

	// Copyright visibility.
	$visibility = bloghash_option( 'copyright_visibility' );

	if ( 'all' !== $visibility ) {
		$classes[] = 'bloghash-' . $visibility;
	}

	// Copyright separator style.
	$separator = bloghash_option( 'copyright_separator' );
	if ( $separator && 'none' !== $separator ) {
		$classes[] = $separator;
	}

	$classes = apply_filters( 'bloghash_copyright_classes', $classes );

	if ( ! empty( $classes ) ) {
		$classes = trim( implode( ' ', $classes ) );
		echo 'class="' . esc_attr( $classes ) . '"';
	}
}

/**
 * Adds custom classes to the array of body classes.
 *
 * @since 1.0.0
 * @param array $classes Classes for the body element.
 * @return array
 */
function bloghash_body_classes( $classes ) {

	// Topbar separator styles.
	if ( bloghash_option( 'top_bar_widgets_separator' ) ) {
		$classes[] = 'bloghash-topbar__separators-' . bloghash_option( 'top_bar_widgets_separator' );
	}

	// Mobile.
	if ( wp_is_mobile() ) {
		$classes[] = 'bloghash-is-mobile';
	}

	// Site layout.
	$classes[] = 'bloghash-layout__' . bloghash_get_site_layout();

	// Post card border.
	if ( bloghash_is_blog_card_border_displayed() ) {
		$classes[] = 'bloghash-layout__boxed-separated';
	}

	// Post card border.
	if ( bloghash_option( 'blog_card_shadow' ) && bloghash_is_blog_card_border_displayed() ) {
		$classes[] = 'bloghash-layout-shadow';
	}

	// Header related styles.
	if ( bloghash_is_header_displayed() ) {

		// Header layout.
		$classes[] = 'bloghash-header-' . bloghash_option( 'header_layout' );

		// Menu item hover animation.
		$classes[] = 'bloghash-menu-animation-' . bloghash_option( 'main_nav_hover_animation' );

		// Header widgets separator.
		$classes[] = 'bloghash-header__separators-' . bloghash_option( 'header_widgets_separator' );
	}

	// Blog style.
	if ( is_front_page() || is_home() || is_archive() || is_search() ) {

		$bloghash_article_feed_layout = bloghash_get_article_feed_layout();

		if ( '' !== $bloghash_article_feed_layout ) {
			$classes[] = 'bloghash-' . bloghash_get_article_feed_layout();
		}
	}

	// Single post.
	if ( is_singular( 'post' ) ) {

		$title_position = bloghash_option( 'single_title_position' );
		$classes[]      = 'bloghash-single-title-' . $title_position;

		// Narrow content for single post.
		if ( 'narrow' === bloghash_option( 'single_content_width' ) ) {
			$classes[] = 'narrow-content';
		}
	}

	$title_alignment = is_single() ? bloghash_option( 'single_title_alignment' ) : bloghash_option( 'page_header_alignment' );
	$classes[]       = 'bloghash-page-title-align-' . $title_alignment;

	// Has comments.
	if ( is_singular() && comments_open() ) {
		$classes[] = 'comments-open';
	}

	// RTL.
	if ( is_rtl() ) {
		$classes[] = 'bloghash-is-rtl';
	}

	// Sidebar.
	if ( bloghash_is_sidebar_displayed() ) {

		$classes[] = 'bloghash-has-sidebar';

		// Sticky sidebar.
		$sidebar_sticky = bloghash_option( 'sidebar_sticky' );

		if ( $sidebar_sticky ) {
			$classes[] = 'bloghash-sticky-' . $sidebar_sticky;
		}

		// Sidebar style.
		$classes[] = 'bloghash-sidebar-style-' . bloghash_option( 'sidebar_style' );

		// Sidebar position.
		$classes[] = 'bloghash-sidebar-position__' . bloghash_get_sidebar_position();

	} else {

		// No sidebar.
		$classes[] = 'bloghash-no-sidebar';
	}

	// Entry media hover style.
	$classes[] = 'entry-media-hover-style-1';

	// Show/Hide Comments button.
	if ( bloghash_comments_displayed() && bloghash_comments_toggle_displayed() ) {
		$classes[] = 'bloghash-has-comments-toggle';
	}

	// Copyright layout.
	if ( bloghash_is_copyright_bar_displayed() ) {
		$classes[] = 'bloghash-copyright-' . bloghash_option( 'copyright_layout' );
	}

	// Section Heading Style.
	$headingStyle = absint( bloghash_option( 'section_heading_style' ) );
	$classes[]    = 'is-section-heading-init-s' . $headingStyle;

	// Footer Widget Heading Style.
	$footerWidgetHeadingStyle = absint( bloghash_option( 'footer_widget_heading_style' ) );
	$classes[]                = 'is-footer-heading-init-s' . $footerWidgetHeadingStyle;

	// Custom input fields design.
	if ( bloghash_option( 'custom_input_style' ) ) {
		$classes[] = 'bloghash-input-supported';
	}

	// Blog Image Wrap.
	if ( bloghash_option( 'blog_image_wrap' ) ) {
		$classes[] = 'bloghash-blog-image-wrap';
	}

	// Validate comment form.
	$classes[] = 'validate-comment-form';

	// Menu accessibility support.
	$classes[] = 'bloghash-menu-accessibility';

	return $classes;
}
add_filter( 'body_class', 'bloghash_body_classes' );

/**
 * Modifies the default Read More link. Do not show if "Read More" button (from Customizer) is enabled.
 *
 * @since  1.0.0
 * @return Modified read more HTML.
 */
function bloghash_modify_read_more_link() {

	$has_read_more = in_array( 'summary-footer', bloghash_get_blog_entry_elements(), true );
	$class         = $has_read_more ? ' bloghash-hide' : '';

	return '<footer class="entry-footer' . esc_attr( $class ) . '"><a class="bloghash-btn btn-text-1" href="' . esc_url( get_the_permalink() ) . '" role="button"><span>' . esc_html__( 'Continue Reading', 'bloghash' ) . '</span></a></footer>';
}
add_filter( 'the_content_more_link', 'bloghash_modify_read_more_link' );

/**
 * Insert dynamic text into content.
 *
 * @since 1.0.0
 * @param string $content Text to be modified.
 * @return string Modified text.
 */
function bloghash_dynamic_strings( $content ) {

	$content = str_replace( '{{the_year}}', date_i18n( 'Y' ), $content );
	$content = str_replace( '{{the_date}}', date_i18n( get_option( 'date_format' ) ), $content );
	$content = str_replace( '{{site_title}}', get_bloginfo( 'name' ), $content );
	$content = str_replace( '{{theme_link}}', '<a href="https://wordpress.org/themes/bloghash/" class="imprint" target="_blank" rel="noopener noreferrer">Bloghash WordPress Theme</a>', $content );

	if ( false !== strpos( $content, '{{current_user}}' ) ) {
		$current_user = wp_get_current_user();
		$content      = str_replace( '{{current_user}}', apply_filters( 'bloghash_logged_out_user_name', $current_user->display_name ), $content );
	}

	return apply_filters( 'bloghash_parse_dynamic_strings', $content );
}
add_filter( 'bloghash_dynamic_strings', 'bloghash_dynamic_strings' );

/**
 * Add headers for IE to override IE's Compatibility View Settings
 *
 * @since 1.0.0
 * @param array $headers The list of headers to be sent.
 */
function bloghash_x_ua_compatible_headers( $headers ) {
	$headers['X-UA-Compatible'] = 'IE=edge';
	return $headers;
}
add_filter( 'wp_headers', 'bloghash_x_ua_compatible_headers' );

/**
 * Removes parentheses from widget category count.
 *
 * @since 1.0.0
 * @param array $variable The filtered variable.
 */
function bloghash_cat_count_filter( $variable ) {
	$variable = str_replace( '(', '<span> ', $variable );
	$variable = str_replace( ')', ' </span>', $variable );

	return $variable;
}
add_filter( 'wp_list_categories', 'bloghash_cat_count_filter' );

/**
 * Removes parentheses from widget archive count.
 *
 * @since 1.0.0
 * @param array $variable The filtered variable.
 */
function bloghash_arc_count_filter( $variable ) {
	$variable = str_replace( '(', '<span>', $variable );
	$variable = str_replace( ')', '</span>', $variable );

	return $variable;
}
add_filter( 'get_archives_link', 'bloghash_arc_count_filter' );

/**
 * Add descriptions on menu dropdowns.
 *
 * @since 1.0.0
 * @param string $item_output HTML output for the menu item.
 * @param object $item menu item object.
 * @param int    $depth depth in menu structure.
 * @param object $args arguments passed to wp_nav_menu().
 * @return string $item_output
 */
function bloghash_header_menu_desc( $item_output, $item, $depth, $args ) {

	if ( $item->description ) {
		$item_output = str_replace( '</span>', '<span class="description">' . $item->description . '</span></span>', $item_output );
	}

	return $item_output;
}
add_filter( 'walker_nav_menu_start_el', 'bloghash_header_menu_desc', 10, 4 );


if ( ! function_exists( 'bloghash_algorithm_to_push_ads_in_archive' ) ) :
	/**
	 * Algorithm to push ads into archive
	 *
	 * @since 1.0.0
	 */
	function bloghash_algorithm_to_push_ads_in_archive() {
		global $wp_query;

		$ad_widgets = array_filter(
			bloghash_option( 'ad_widgets' ),
			function( $widget ) {
				return isset( $widget['values']['display_area'] ) && in_array( 'random_post_archives', $widget['values']['display_area'] );
			}
		);

		$archive_ads_number = count( $ad_widgets );

		if ( $archive_ads_number <= 0 || ! is_numeric( $archive_ads_number ) ) {
			return false;
		}

		$max_number_of_pages = absint( $wp_query->max_num_pages );
		$paged               = absint( ( get_query_var( 'paged' ) == 0 ) ? 0 : ( get_query_var( 'paged' ) - 1 ) );
		$count               = 1;
		$ads_id              = 0;
		$loop_var            = 0;
		for ( $i = $archive_ads_number; $i > 0; $i-- ) :
			if ( $count <= $max_number_of_pages ) :
				$ads_to_render_in_a_single_page = ceil( $i / $max_number_of_pages );
				$ads_to_render_by_page[]        = ceil( $i / $max_number_of_pages );
				$ads_to_render                  = array();
				if ( $ads_to_render_in_a_single_page > 1 ) :
					$to_loop = $ads_id + $ads_to_render_in_a_single_page;
					for ( $j = $ads_id; $j < $to_loop; $j++ ) :
						if ( ! in_array( $ads_id, $ads_to_render ) ) {
							$ads_to_render[] = $ads_id;
						}
						$ads_id++;
					endfor;
					$ads_to_render_in_current_page[ $loop_var ] = $ads_to_render;
				else :
					$ads_to_render_in_current_page[ $loop_var ] = $ads_id;
					$ads_id++;
				endif;
				$count++;
				$loop_var++;
			endif;
		endfor;
		$current_page_count  = absint( $wp_query->post_count );
		$ads_of_current_page = $ads_to_render_in_current_page[ $paged ] ?? null;
		$ads_count           = is_array( $ads_of_current_page ) ? sizeof( $ads_of_current_page ) : 1;
		$random_numbers      = array();
		for ( $i = 0; $i < $ads_count; $i++ ) :
			if ( ! in_array( $i, $random_numbers ) ) :
				$random_numbers[] = rand( 0, ( $current_page_count - 1 ) );
			else :
				$random_numbers[] = rand( 0, ( $current_page_count - 1 ) );
			endif;
		endfor;
		return array(
			'random_numbers' => $random_numbers,
			'ads_to_render'  => $ads_of_current_page,
		);
	}


 endif;
