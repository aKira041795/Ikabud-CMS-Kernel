<?php
/**
 * Functions which enhance the theme by hooking into WordPress
 *
 * @package zakra
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'zakra_pingback_header' ) ) :

	/**
	 * Add a pingback url auto-discovery header for single posts, pages, or attachments.
	 */
	function zakra_pingback_header() {

		if ( is_singular() && pings_open() ) {

			echo '<link rel="pingback" href="', esc_url( get_bloginfo( 'pingback_url' ) ), '">';
		}
	}
endif;

add_action( 'wp_head', 'zakra_pingback_header' );


if ( ! function_exists( 'zakra_css_class' ) ) :

	/**
	 * Adds css classes to elements dynamically.
	 *
	 * @param string $tag Filter tag name.
	 *
	 * TODO: deprecate this function to Zakra_Dynamic_Filter
	 *
	 * @return string CSS classes.
	 */
	function zakra_css_class( $tag, $echo = true ) {

		// Get list of css classes in array for the `$tag` aka element.
		$classes = Zakra_Dynamic_Filter::filter_via_tag( $tag );

		// Filter for the element classes.
		$classes = apply_filters( $tag, $classes );

		// Remove duplicate classes if any.
		$classes = array_unique( $classes );

		// Output in string format.
		if ( true === $echo ) {

			echo esc_attr( join( ' ', $classes ) );
		} else {

			return join( ' ', $classes );
		}
	}
endif;

if ( ! function_exists( 'zakra_primary_menu_class' ) ) :

	/**
	 * Adds css classes into primary menu
	 *
	 * TODO: Add it to Zakra_Dynamic_Filter::css_class_list > zakra_nav_class and delete this function.
	 * TODO: It's being used in Zakra Pro, use zakra_nav_class dynamic filter instead
	 *
	 * @param string $class css classname.
	 */
	function zakra_primary_menu_class( $class = '' ) {

		$classes = array();
		$classes = array_map( 'esc_attr', $classes );
		$classes = apply_filters( 'zakra_primary_menu_class', $classes, $class );
		$classes = array_unique( $classes );

		echo join( ' ', $classes ); // WPCS: XSS ok.
	}
endif;

if ( ! function_exists( 'zakra_footer_class' ) ) :

	/**
	 * Adds css classes into the footer
	 *
	 * TODO: Add it to Zakra_Dynamic_Filter::css_class_list and delete this function.
	 *
	 * @param string $class css classname.
	 */
	function zakra_footer_class( $class = '' ) {

		$classes = array();
		$classes = array_map( 'esc_attr', $classes );
		$classes = apply_filters( 'zakra_footer_class', $classes, $class );
		$classes = array_unique( $classes );

		echo join( ' ', $classes ); // WPCS: XSS ok.
	}
endif;

if ( ! function_exists( 'zakra_footer_widget_container_class' ) ) :

	/**
	 * Adds css classes into the footer widget area
	 *
	 * TODO: Add it to Zakra_Dynamic_Filter::css_class_list and delete this function.
	 * TODO: It's being used in Zakra Pro, use zakra_nav_class dynamic filter instead
	 *
	 * @param string $class css classname.
	 */
	function zakra_footer_widget_container_class( $class = '' ) {

		$classes = array();
		$classes = array_map( 'esc_attr', $classes );
		$classes = apply_filters( 'zakra_footer_widget_container_class', $classes, $class );
		$classes = array_unique( $classes );

		echo join( ' ', $classes ); // WPCS: XSS ok.
	}
endif;

if ( ! function_exists( 'zakra_footer_bar_classes' ) ) :

	/**
	 * Adds css classes into the footer bar
	 *
	 * TODO: Add it to Zakra_Dynamic_Filter::css_class_list and delete this function.
	 *
	 * @param string $class css classname.
	 */
	function zakra_footer_bar_classes( $class = '' ) {

		$classes = array();
		$classes = array_map( 'esc_attr', $classes );
		$classes = apply_filters( 'zakra_footer_bar_class', $classes, $class );
		$classes = array_unique( $classes );

		echo join( ' ', $classes ); // WPCS: XSS ok.
	}
endif;

if ( ! function_exists( 'zakra_sidebar_class' ) ) :

	/**
	 * Adds css classes into the sidebar
	 *
	 * TODO: Add it to Zakra_Dynamic_Filter::css_class_list and delete this function.
	 * TODO: It's being used in Zakra Pro, use zakra_nav_class dynamic filter instead
	 *
	 * @param string $class css classname.
	 */
	function zakra_sidebar_class( $class = '' ) {

		$classes = array();
		$classes = array_map( 'esc_attr', $classes );
		$classes = apply_filters( 'zakra_sidebar_class', $classes, $class );
		$classes = array_unique( $classes );

		echo join( ' ', $classes ); // WPCS: XSS ok.
	}
endif;

if ( ! function_exists( 'zakra_get_title' ) ) :

	/**
	 * Returns page title.
	 *
	 * @return string
	 */
	function zakra_get_title() {

		if ( is_archive() ) {

			if ( is_author() ) :

				/**
				 * Queue the first post, that way we know
				 * what author we're dealing with (if that is the case).
				 */
				the_post();

				$author_title = apply_filters( 'zakra_author_title_prefix', 'Author :' );

				/* translators: %1$s: Author prefix, %2$s: Author. */
				$page_title = sprintf( esc_html__( '%1$s %2$s', 'zakra' ), $author_title, '<span class="vcard">' . get_the_author() . '</span>' );

				/**
				 * Since we called the_post() above, we need to
				 * rewind the loop back to the beginning that way
				 * we can run the loop properly, in full.
				 */
				rewind_posts();
			elseif ( is_post_type_archive() ) :

				$page_title = post_type_archive_title( '', false );
			elseif ( is_day() ) :

				$day_title = apply_filters( 'zakra_day_title_prefix', 'Day:' );

				/* translators: %1$s: Day prefix, %2$s: Day. */
				$page_title = sprintf( esc_html__( '%1$s %2$s', 'zakra' ), $day_title, '<span>' . get_the_date() . '</span>' );
			elseif ( is_month() ) :

				$month_title = apply_filters( 'zakra_month_title_prefix', 'Month:' );

				/* translators: %1$s: Month prefix, %2$s: Month. */
				$page_title = sprintf( esc_html__( '%1$s %2$s', 'zakra' ), $month_title, '<span>' . get_the_date( 'F Y' ) . '</span>' );
			elseif ( is_year() ) :

				$year_title = apply_filters( 'zakra_year_title_prefix', 'Year:' );

				/* translators: %1$s: Year prefix, %2$s: Year. */
				$page_title = sprintf( esc_html__( '%1$s %2$s', 'zakra' ), $year_title, '<span>' . get_the_date( 'Y' ) . '</span>' );
			elseif ( zakra_is_woocommerce_active() && function_exists( 'is_woocommerce' ) && is_woocommerce() ) :

				$page_title = woocommerce_page_title( false );
			else :

				$page_title = single_cat_title( '', false );
			endif;
		} elseif ( is_404() ) {

			// Page header.
			if ( 'page-header' === zakra_page_title_position() ) {

				$page_title = esc_html__( 'Page Not Found', 'zakra' );
			} else { // Content area.

				$page_title = esc_html__( 'Oops! That page can&rsquo;t be found.', 'zakra' );
			}
		} elseif ( is_search() ) {

			$page_title = esc_html__( 'Search Results', 'zakra' );
		} elseif ( is_page() ) {

			$page_title = get_the_title();
		} elseif ( is_single() ) {

			$page_title = get_the_title();
		} elseif ( is_home() ) {

			$queried_id = get_option( 'page_for_posts' );
			$page_title = get_the_title( $queried_id );
		} else {

			$page_title = '';
		}

		return apply_filters( 'zakra_title', $page_title );
	}
endif;


if ( ! function_exists( 'zakra_entry_title' ) ) :

	/**
	 * Generate title for page, post, archive.
	 */
	function zakra_entry_title() {

		if ( 'page-header' !== zakra_page_title_position() ) {

			if ( is_singular() ) :

				the_title( '<h1 class="entry-title zak-page-content__title">', '</h1>' );
			elseif ( is_archive() ) :

				the_archive_title( '<h1 class="page-title zak-page-content__title">', '</h1>' );
			elseif ( is_404() ) :
				?>
				<h1 class="page-title zak-page-content__title"><?php esc_html_e( 'Oops! That page can&rsquo;t be found.', 'zakra' ); ?></h1>
			<?php
			else :

				the_title( '<h2 class="entry-title zak-page-content__title"><a href="' . esc_url( get_permalink() ) . '" rel="bookmark">', '</a></h2>' );
			endif;
		}
	}
endif;

if ( ! function_exists( 'zakra_get_current_layout' ) ) :

	/**
	 * Get the current layout of the page
	 *
	 * @return string layout.
	 */
	function zakra_get_current_layout() {

		$individual_layout = get_post_meta( zakra_get_post_id(), 'zakra_sidebar_layout', true );

		$default_layout    = get_theme_mod( 'zakra_default_sidebar_layout', 'right' );
		$wc_default_layout = get_theme_mod( 'zakra_woocommerce_default_sidebar_layout', 'right' );

		if ( ! empty( $individual_layout ) && 'customizer' !== $individual_layout ) {

			$layout = $individual_layout;
		} elseif ( apply_filters( 'zakra_pro_current_layout', '' ) ) {

			$layout = apply_filters( 'zakra_pro_current_layout', '' );
		} else {
			switch ( true ) {

				case ( is_singular( 'page' ) || is_404() ):
					if ( zakra_is_woocommerce_active() && ( is_checkout() || is_cart() || is_account_page() ) ) {

						$page_layout = get_theme_mod( 'zakra_woocommerce_sidebar_layout', 'right' );
						$layout      = 'default' !== $page_layout ? $page_layout : $wc_default_layout;
					} else {

						$page_layout = get_theme_mod( 'zakra_page_sidebar_layout', 'right' );
						$layout      = 'default' !== $page_layout ? $page_layout : $default_layout;
					}

					break;

				case ( is_singular() ):
					if ( zakra_is_woocommerce_active() && is_product() ) { // WC single product.

						$page_layout = get_theme_mod( 'zakra_woocommerce_single_product_sidebar_layout', 'right' );
						$layout      = 'default' !== $page_layout ? $page_layout : $wc_default_layout;
					} else {

						$page_layout = get_theme_mod( 'zakra_post_sidebar_layout', 'right' );
						$layout      = 'default' !== $page_layout ? $page_layout : $default_layout;
					}

					break;

				case ( is_archive() || is_home() ):
					if ( zakra_is_woocommerce_active() && is_woocommerce() ) {

						$page_layout = get_theme_mod( 'zakra_woocommerce_sidebar_layout', 'right' );
						$layout      = 'default' !== $page_layout ? $page_layout : $wc_default_layout;
					} else {

						$page_layout = get_theme_mod( 'zakra_archive_sidebar_layout', 'right' );
						$layout      = 'default' !== $page_layout ? $page_layout : $default_layout;
					}

					break;

				case ( ! is_archive() || ! is_home() || ! is_singular() || ! is_404() || ! is_singular( 'page' ) ):
					$page_layout = get_theme_mod( 'zakra_others_sidebar_layout', 'default' );
					$layout      = 'default' !== $page_layout ? $page_layout : $default_layout;

					break;

				default:
					$layout = get_theme_mod( 'zakra_default_sidebar_layout', 'right' );
			}
		}

		$layout = 'zak-site-layout--' . $layout;

		return apply_filters( 'zakra_current_layout', $layout );
	}
endif;

if ( ! function_exists( 'zakra_insert_mod_hatom_data' ) ) :

	/**
	 * Adding the support for the entry-title tag for Google Rich Snippets.
	 *
	 * @param string $content The post content.
	 *
	 * @return string hatom data.
	 */
	function zakra_insert_mod_hatom_data( $content ) {

		$title = get_the_title();

		if ( is_single() && 'page-header' === zakra_page_title_position() ) {

			$content .= '<div class="extra-hatom"><span class="entry-title">' . $title . '</span></div>';
		}

		return $content;

	}

	add_filter( 'the_content', 'zakra_insert_mod_hatom_data' );

endif;

if ( ! function_exists( 'zakra_get_image_by_id' ) ) :

	/**
	 * Get image HTML by id.
	 *
	 * @param int $id ID of the logo image attachment.
	 * @param int $attr HTML alt for image.
	 * @param int $default_alt Default alt value.
	 */
	function zakra_get_image_by_id( $id, $attr, $default_alt = '' ) {

		// Get image alt.
		$image_alt = get_post_meta( zakra_get_post_id(), '_wp_attachment_image_alt', true );

		if ( empty( $image_alt ) && ! empty( $default_alt ) ) {
			$attr[ 'alt' ] = $default_alt;
		}

		return wp_get_attachment_image( $id, 'full', false, $attr );

	}

endif;


if ( ! function_exists( 'zakra_responsive_oembeds' ) ) :

	/**
	 * Adds a responsive embed wrapper around oEmbed content.
	 *
	 * @param string $html oEmbed markup.
	 * @param string $url URL to embed.
	 *
	 * @return string
	 */
	function zakra_responsive_oembeds( $html, $url ) {

		$hosts = apply_filters(
			'zakra_oembed_responsive_hosts',
			array(
				'vimeo.com',
				'youtube.com',
				'dailymotion.com',
				'flickr.com',
				'hulu.com',
				'kickstarter.com',
				'vine.co',
				'soundcloud.com',
				'youtu.be',
			)
		);

		if ( zakra_strpos( $url, $hosts ) ) {

			$html = ( '' !== $html ) ? '<div class="zak-oembed-container">' . $html . '</div>' : '';
		}

		return $html;
	}
endif;

add_filter( 'embed_oembed_html', 'zakra_responsive_oembeds', 99, 4 );

if ( ! function_exists( 'zakra_change_search_field_placeholder' ) ) :

	/**
	 * Replace 'Search...' with custom placeholder text.
	 *
	 * TODO: @param string $form The search form HTML output.
	 *
	 * @return string
	 * @since.
	 *
	 */
	function zakra_change_search_field_placeholder( $form ) {

		return str_replace( 'Search &hellip;', 'Type & hit enter...', $form );
	}
endif;

add_filter( 'get_search_form', 'zakra_change_search_field_placeholder' );
