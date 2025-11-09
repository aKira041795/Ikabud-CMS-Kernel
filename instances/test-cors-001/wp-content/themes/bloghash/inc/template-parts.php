<?php

/**
 * Template parts.
 *
 * @package     Bloghash
 * @author      Peregrine Themes
 * @since       1.0.0
 */

/**
 * Do not allow direct script access.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the meta tag to the site header.
 *
 * @since 1.0.0
 */
function bloghash_meta_viewport() {
	echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
}
add_action( 'wp_head', 'bloghash_meta_viewport', 1 );

/**
 * Add a pingback url auto-discovery header for singularly identifiable articles.
 *
 * @since 1.0.0
 */
function bloghash_pingback_header() {
	if ( is_singular() && pings_open() ) {
		printf( '<link rel="pingback" href="%s">' . "\n", esc_url( get_bloginfo( 'pingback_url' ) ) );
	}
}
add_action( 'wp_head', 'bloghash_pingback_header' );

/**
 * Adds the meta tag for website accent color.
 *
 * @since 1.0.0
 */
function bloghash_meta_theme_color() {

	$color = bloghash_option( 'accent_color' );

	if ( $color ) {
		printf( '<meta name="theme-color" content="%s">', esc_attr( $color ) );
	}
}
add_action( 'wp_head', 'bloghash_meta_theme_color' );

/**
 * Outputs the theme top bar area.
 *
 * @since 1.0.0
 */
function bloghash_topbar_output() {

	if ( ! bloghash_is_top_bar_displayed() ) {
		return;
	}

	get_template_part( 'template-parts/topbar/topbar' );
}
add_action( 'bloghash_header', 'bloghash_topbar_output', 10 );

/**
 * Outputs the top bar widgets.
 *
 * @since 1.0.0
 * @param string $location Widget location in top bar.
 */
function bloghash_topbar_widgets_output( $location ) {

	do_action( 'bloghash_top_bar_widgets_before_' . $location );

	$bloghash_top_bar_widgets = bloghash_option( 'top_bar_widgets' );

	if ( is_array( $bloghash_top_bar_widgets ) && ! empty( $bloghash_top_bar_widgets ) ) {
		foreach ( $bloghash_top_bar_widgets as $widget ) {

			if ( ! isset( $widget['values'] ) ) {
				continue;
			}

			if ( $location !== $widget['values']['location'] ) {
				continue;
			}

			if ( function_exists( 'bloghash_top_bar_widget_' . $widget['type'] ) ) {

				$classes   = array();
				$classes[] = 'bloghash-topbar-widget__' . esc_attr( $widget['type'] );
				$classes[] = 'bloghash-topbar-widget';

				if ( isset( $widget['values']['visibility'] ) && $widget['values']['visibility'] ) {
					$classes[] = 'bloghash-' . esc_attr( $widget['values']['visibility'] );
				}

				$classes = apply_filters( 'bloghash_topbar_widget_classes', $classes, $widget );
				$classes = trim( implode( ' ', $classes ) );

				printf( '<div class="%s">', esc_attr( $classes ) );
				call_user_func( 'bloghash_top_bar_widget_' . $widget['type'], $widget['values'] );
				printf( '</div><!-- END .bloghash-topbar-widget -->' );
			}
		}
	}

	do_action( 'bloghash_top_bar_widgets_after_' . $location );
}
add_action( 'bloghash_topbar_widgets', 'bloghash_topbar_widgets_output' );

/**
 * Outputs the theme header area.
 *
 * @since 1.0.0
 */
function bloghash_header_output() {

	if ( ! bloghash_is_header_displayed() ) {
		return;
	}

	get_template_part( 'template-parts/header/base' );
}
add_action( 'bloghash_header', 'bloghash_header_output', 20 );

/**
 * Outputs the header widgets in Header Widget Locations.
 *
 * @since 1.0.0
 * @param string $locations Widget location.
 */
function bloghash_header_widgets( $locations ) {

	$locations   = (array) $locations;
	$all_widgets = (array) bloghash_option( 'header_widgets' );

	bloghash_header_widget_output( $locations, $all_widgets );
}
add_action( 'bloghash_header_widget_location', 'bloghash_header_widgets', 1 );

/**
 * Outputs the header widgets in Header Navigation Widget Locations.
 *
 * @since 1.0.0
 * @param string $locations Widget location.
 */
function bloghash_header_navigation_widgets( $locations ) {

	$locations   = (array) $locations;
	$all_widgets = (array) bloghash_option( 'header_navigation_widgets' );

	bloghash_header_widget_output( $locations, $all_widgets );
}
add_action( 'bloghash_header_navigation_widget_location', 'bloghash_header_navigation_widgets', 1 );

/**
 * Outputs the content of theme header.
 *
 * @since 1.0.0
 */
function bloghash_header_content_output() {

	// Get the selected header layout from Customizer.
	$header_layout = bloghash_option( 'header_layout' );

	?>
	<div id="bloghash-header-inner">
		<?php

		// Load header layout template.
		get_template_part( 'template-parts/header/header', $header_layout );

		?>
	</div><!-- END #bloghash-header-inner -->
	<?php
}
add_action( 'bloghash_header_content', 'bloghash_header_content_output' );

/**
 * Outputs the main footer area.
 *
 * @since 1.0.0
 */
function bloghash_footer_output() {

	if ( ! bloghash_is_footer_displayed() ) {
		return;
	}

	get_template_part( 'template-parts/footer/base' );
}
add_action( 'bloghash_footer', 'bloghash_footer_output', 20 );

/**
 * Outputs the copyright area.
 *
 * @since 1.0.0
 */
function bloghash_copyright_bar_output() {

	if ( ! bloghash_is_copyright_bar_displayed() ) {
		return;
	}

	get_template_part( 'template-parts/footer/copyright/copyright' );
}
add_action( 'bloghash_footer', 'bloghash_copyright_bar_output', 30 );

/**
 * Outputs the copyright widgets.
 *
 * @since 1.0.0
 * @param string $location Widget location in copyright.
 */
function bloghash_copyright_widgets_output( $location ) {

	do_action( 'bloghash_copyright_widgets_before_' . $location );

	$bloghash_widgets = bloghash_option( 'copyright_widgets' );

	if ( is_array( $bloghash_widgets ) && ! empty( $bloghash_widgets ) ) {
		foreach ( $bloghash_widgets as $widget ) {

			if ( ! isset( $widget['values'] ) ) {
				continue;
			}

			if ( isset( $widget['values'], $widget['values']['location'] ) && $location !== $widget['values']['location'] ) {
				continue;
			}

			if ( function_exists( 'bloghash_copyright_widget_' . $widget['type'] ) ) {

				$classes   = array();
				$classes[] = 'bloghash-copyright-widget__' . esc_attr( $widget['type'] );
				$classes[] = 'bloghash-copyright-widget';

				if ( isset( $widget['values']['visibility'] ) && $widget['values']['visibility'] ) {
					$classes[] = 'bloghash-' . esc_attr( $widget['values']['visibility'] );
				}

				$classes = apply_filters( 'bloghash_copyright_widget_classes', $classes, $widget );
				$classes = trim( implode( ' ', $classes ) );

				printf( '<div class="%s">', esc_attr( $classes ) );
				call_user_func( 'bloghash_copyright_widget_' . $widget['type'], $widget['values'] );
				printf( '</div><!-- END .bloghash-copyright-widget -->' );
			}
		}
	}

	do_action( 'bloghash_copyright_widgets_after_' . $location );
}
add_action( 'bloghash_copyright_widgets', 'bloghash_copyright_widgets_output' );

/**
 * Outputs the theme sidebar area.
 *
 * @since 1.0.0
 */
function bloghash_sidebar_output() {

	if ( bloghash_is_sidebar_displayed() ) {
		get_sidebar();
	}
}
add_action( 'bloghash_sidebar', 'bloghash_sidebar_output' );

/**
 * Outputs the back to top button.
 *
 * @since 1.0.0
 */
function bloghash_back_to_top_output() {

	if ( ! bloghash_option( 'scroll_top' ) ) {
		return;
	}

	get_template_part( 'template-parts/misc/back-to-top' );
}
add_action( 'bloghash_after_page_wrapper', 'bloghash_back_to_top_output' );

/**
 * Outputs the cursor dot.
 *
 * @since 1.0.0
 */
function bloghash_cursor_dot_output() {

	if ( ! bloghash_option( 'enable_cursor_dot' ) ) {
		return;
	}

	get_template_part( 'template-parts/misc/cursor-dot' );
}
add_action( 'bloghash_after_page_wrapper', 'bloghash_cursor_dot_output' );

/**
 * Outputs the theme page content.
 *
 * @since 1.0.0
 */
function bloghash_page_header_template() {

	do_action( 'bloghash_before_page_header' );

	if ( bloghash_is_page_header_displayed() ) {
		if ( is_singular( 'post' ) ) {
			get_template_part( 'template-parts/header-page-title-single' );
		} else {
			get_template_part( 'template-parts/header-page-title' );
		}
	}

	do_action( 'bloghash_after_page_header' );
}
add_action( 'bloghash_page_header', 'bloghash_page_header_template' );


/**
 * Outputs the theme Ticker News content.
 *
 * @since 1.0.0
 */
function bloghash_blog_ticker() {

	if ( ! bloghash_is_ticker_displayed() ) {
		return;
	}

	do_action( 'bloghash_before_ticker' );

	// Enqueue Bloghash Marquee script.
	if ( 'one-ticker' === bloghash_option( 'ticker_type' ) ) {
		wp_enqueue_script( 'bloghash-marquee' );
	}

	?>
	<div id="ticker">
		<?php get_template_part( 'template-parts/ticker/ticker' ); ?>
	</div><!-- END #ticker -->
	<?php

	do_action( 'bloghash_after_ticker' );
}
add_action( 'bloghash_after_masthead', 'bloghash_blog_ticker', 29 );


/**
 * Outputs the theme blog hero content.
 *
 * @since 1.0.0
 */
function bloghash_blog_hero() {

	if ( ! bloghash_is_hero_displayed() ) {
		return;
	}

	// Hero type.
	$hero_type = bloghash_option( 'hero_type' );

	do_action( 'bloghash_before_hero' );

	// Enqueue Bloghash Slider script.
	wp_enqueue_script( 'bloghash-slider' );

	?>
	<div id="hero">
		<?php
			get_template_part( 'template-parts/hero/hero', $hero_type );
		?>
	</div><!-- END #hero -->
	<?php

	do_action( 'bloghash_after_hero' );
}
add_action( 'bloghash_after_masthead', 'bloghash_blog_hero', 30 );


/**
 * Outputs the theme Blog Featured Links content.
 *
 * @since 1.0.0
 */
function bloghash_blog_featured_links() {

	if ( ! bloghash_is_featured_links_displayed() ) {
		return;
	}

	// Featured links type.
	$bloghash_featured_links_type = bloghash_option( 'featured_links_type' );

	$bloghash_featured_links = bloghash_option( 'featured_links' );

	// No items found.
	if ( ! $bloghash_featured_links ) {
		return;
	}

	$features = array();

	foreach ( $bloghash_featured_links as $bloghash_featured_link ) {
		$features[] = array(
			'link'  => $bloghash_featured_link['link'],
			'image' => $bloghash_featured_link['image'],
		);
	}

	do_action( 'bloghash_before_featured_links' );

	?>
	<div id="featured_links">
		<?php get_template_part( 'template-parts/featured-links/featured-links', $bloghash_featured_links_type, array( 'features' => $features ) ); ?>
	</div><!-- END #featured_links -->
	<?php

	do_action( 'bloghash_after_featured_links' );
}
add_action( 'bloghash_after_masthead', 'bloghash_blog_featured_links', 31 );


/**
 * Outputs the theme Blog PYML content.
 *
 * @since 1.0.0
 */
function bloghash_blog_pyml() {

	if ( ! bloghash_is_pyml_displayed() ) {
		return;
	}

	$pyml_type = bloghash_option( 'pyml_type' );

	do_action( 'bloghash_before_pyml' );

	?>
	<div id="pyml">
		<?php get_template_part( 'template-parts/pyml/pyml', $pyml_type ); ?>
	</div><!-- END #pyml -->
	<?php

	do_action( 'bloghash_after_pyml' );
}
add_action( 'bloghash_after_container', 'bloghash_blog_pyml', 32 );


/**
 * Outputs the theme Body Animation.
 *
 * @since 1.0.0
 */
function bloghash_body_animation() {

	$body_animation_option = bloghash_option( 'body_animation' );

	if ( '0' === $body_animation_option ) {
		return;
	}

	do_action( 'bloghash_before_body_animation' );
	?>
	<?php if ( '1' === $body_animation_option ) : ?>
	<div class="bloghash-glassmorphism">
		<span class="block one"></span>
		<span class="block two"></span>
	</div>
		<?php
	endif;
	do_action( 'bloghash_after_body_animation' );
}
add_action( 'bloghash_main_end', 'bloghash_body_animation', 33 );

function bloghash_blog_heading_content() {

	if ( $blog_heading = bloghash_option( 'blog_heading' ) ) {
		echo '<div id="bloghash-blog-heading">';
		echo wp_kses( $blog_heading, bloghash_get_allowed_html_tags() );
		echo '</div>';
	}
}
add_action( 'bloghash_blog_heading', 'bloghash_blog_heading_content' );

/**
 * Outputs the queried articles.
 *
 * @since 1.0.0
 */
function bloghash_content() {
	global $wp_query;
	$bloghash_blog_layout        = bloghash_option( 'blog_masonry' ) ? 'masonries' : '';
	$bloghash_blog_layout_column = 12;

	if ( bloghash_option( 'blog_layout' ) != 'blog-horizontal' ) :
		$bloghash_blog_layout_column = bloghash_option( 'blog_layout_column' );
	endif;

	if ( have_posts() ) :

		if ( is_home() ) {
			do_action( 'bloghash_blog_heading' );
		}
		echo '<div class="bloghash-flex-row g-4 ' . $bloghash_blog_layout . '">';

		$ads_info = bloghash_algorithm_to_push_ads_in_archive();
		$count    = 0;
		while ( have_posts() ) :
			the_post();

			if ( is_array( $ads_info ) && ! is_null( $ads_info['ads_to_render'] ) ) :
				if ( in_array( $wp_query->current_post, $ads_info['random_numbers'] ) ) :
					echo '<div class="col-md-' . $bloghash_blog_layout_column . ' col-sm-' . $bloghash_blog_layout_column . ' col-xs-12">';
					bloghash_random_post_archive_advertisement_part( is_array( $ads_info['ads_to_render'] ) ? $ads_info['ads_to_render'][ $count ] : $ads_info['ads_to_render'] );
					echo '</div>';
					$count++;
				endif;
			endif;

			echo '<div class="col-md-' . $bloghash_blog_layout_column . ' col-sm-' . $bloghash_blog_layout_column . ' col-xs-12">';
			get_template_part( 'template-parts/content/content', bloghash_get_article_feed_layout() );
			echo '</div>';
		endwhile;
		echo '</div>';
		bloghash_pagination();

	else :
		get_template_part( 'template-parts/content/content', 'none' );
	endif;
}
add_action( 'bloghash_content', 'bloghash_content' );
add_action( 'bloghash_content_archive', 'bloghash_content' );
add_action( 'bloghash_content_search', 'bloghash_content' );

/**
 * Outputs the theme single content.
 *
 * @since 1.0.0
 */
function bloghash_content_singular() {

	if ( have_posts() ) :
		while ( have_posts() ) :
			the_post();

			if ( is_singular( 'post' ) ) {
				do_action( 'bloghash_content_single' );
			} else {
				do_action( 'bloghash_content_page' );
			}

		endwhile;
	else :
		get_template_part( 'template-parts/content/content', 'none' );
	endif;
}
add_action( 'bloghash_content_singular', 'bloghash_content_singular' );


/**
 * Outputs the theme 404 page content.
 *
 * @since 1.0.0
 */
function bloghash_404_page_content() {

	get_template_part( 'template-parts/content/content', '404' );
}
add_action( 'bloghash_content_404', 'bloghash_404_page_content' );

/**
 * Outputs the theme page content.
 *
 * @since 1.0.0
 */
function bloghash_content_page() {

	get_template_part( 'template-parts/content/content', 'page' );
}
add_action( 'bloghash_content_page', 'bloghash_content_page' );

/**
 * Outputs the theme single post content.
 *
 * @since 1.0.0
 */
function bloghash_content_single() {

	get_template_part( 'template-parts/content/content', 'single' );
}
add_action( 'bloghash_content_single', 'bloghash_content_single' );

/**
 * Outputs the comments template.
 *
 * @since 1.0.0
 */
function bloghash_output_related_posts() {

	if ( 'post' == get_post_type() ) {
		get_template_part( 'template-parts/related-posts/related', 'posts' );
	}
}
add_action( 'bloghash_after_singular', 'bloghash_output_related_posts' );

/**
 * Outputs the comments template.
 *
 * @since 1.0.0
 */
function bloghash_output_comments() {
	comments_template();
}
add_action( 'bloghash_after_singular', 'bloghash_output_comments' );

/**
 * Outputs the theme archive page info.
 *
 * @since 1.0.0
 */
function bloghash_archive_info() {

	// Author info.
	if ( is_author() ) {
		get_template_part( 'template-parts/entry/entry', 'about-author' );
	}
}
add_action( 'bloghash_before_content', 'bloghash_archive_info' );

/**
 * Outputs more posts button to author description box.
 *
 * @since 1.0.0
 */
function bloghash_add_author_posts_button() {
	if ( ! is_author() ) {
		get_template_part( 'template-parts/entry/entry', 'author-posts-button' );
	}
}
add_action( 'bloghash_entry_after_author_description', 'bloghash_add_author_posts_button' );

/**
 * Outputs Comments Toggle button.
 *
 * @since 1.0.0
 */
function bloghash_comments_toggle() {

	if ( bloghash_comments_toggle_displayed() ) {
		get_template_part( 'template-parts/entry/entry-show-comments' );
	}
}
add_action( 'bloghash_before_comments', 'bloghash_comments_toggle' );

/**
 * Outputs Page Preloader.
 *
 * @since 1.0.0
 */
function bloghash_preloader() {

	if ( ! bloghash_is_preloader_displayed() ) {
		return;
	}

	get_template_part( 'template-parts/preloader/base' );
}
add_action( 'bloghash_before_page_wrapper', 'bloghash_preloader' );

/**
 * Outputs breadcrumbs after header.
 *
 * @since  1.0.0
 * @return void
 */
function bloghash_breadcrumb_after_header_output() {

	if ( 'below-header' === bloghash_option( 'breadcrumbs_position' ) && bloghash_has_breadcrumbs() ) {

		$alignment = 'bloghash-text-align-' . bloghash_option( 'breadcrumbs_alignment' );

		$args = array(
			'container_before' => '<div class="bloghash-breadcrumbs"><div class="bloghash-container ' . $alignment . '">',
			'container_after'  => '</div></div>',
		);

		bloghash_breadcrumb( $args );
	}
}
add_action( 'bloghash_main_start', 'bloghash_breadcrumb_after_header_output' );

/**
 * Outputs breadcumbs in page header.
 *
 * @since  1.0.0
 * @return void
 */
function bloghash_breadcrumb_page_header_output() {

	if ( bloghash_page_header_has_breadcrumbs() ) {

		if ( is_singular( 'post' ) ) {
			$args = array(
				'container_before' => '<div class="bloghash-container bloghash-breadcrumbs">',
				'container_after'  => '</div>',
			);
		} else {
			$args = array(
				'container_before' => '<div class="bloghash-breadcrumbs">',
				'container_after'  => '</div>',
			);
		}

		bloghash_breadcrumb( $args );
	}
}
add_action( 'bloghash_page_header_end', 'bloghash_breadcrumb_page_header_output' );

/**
 * Output the main navigation template.
 */
function bloghash_main_navigation_template() {
	get_template_part( 'template-parts/header/navigation' );
}

/**
 * Output the Header logo template.
 */
function bloghash_header_logo_template() {
	get_template_part( 'template-parts/header/logo' );
}

function bloghash_about_button() {
	$button_widgets = bloghash_option( 'about_widgets' );

	if ( empty( $button_widgets ) ) {
		return;
	}
	foreach ( $button_widgets as $widget ) {
		call_user_func( 'bloghash_about_widget_' . $widget['type'], $widget['values'] );
	}
}

function bloghash_cta_widgets() {
	$widgets = bloghash_option( 'cta_widgets' );

	if ( empty( $widgets ) ) {
		return;
	}
	foreach ( $widgets as $widget ) {
		call_user_func( 'bloghash_cta_widget_' . $widget['type'], $widget['values'] );
	}
}

function bloghash_advertisement_part( $arg = '' ) {

	if ( $arg === '' ) {
		return;
	}

	$ad_widgets = bloghash_option( 'ad_widgets' );

	// get all array elements from $ad_widgets in which 'display_area' key has value $arg = 'before_post_content'
	$arr_widgets = array_filter(
		$ad_widgets,
		function( $widget ) use ( $arg ) {
			return isset( $widget['values']['display_area'] ) && in_array( $arg, $widget['values']['display_area'] );
		}
	);

	if ( ! empty( $arr_widgets ) ) :
		foreach ( $arr_widgets as $widget ) {
			if ( function_exists( 'bloghash_ad_widget_' . $widget['type'] ) ) {
				$classes   = array();
				$classes[] = 'bloghash-ad-widget__' . esc_attr( $widget['type'] );
				$classes[] = 'bloghash-ad-widget';

				if ( isset( $widget['values']['visibility'] ) && $widget['values']['visibility'] ) {
					$classes[] = 'bloghash-' . esc_attr( $widget['values']['visibility'] );
				}

				$classes = apply_filters( 'bloghash_ad_widget_classes', $classes, $widget );
				$classes = trim( implode( ' ', $classes ) );

				printf( '<div class="%s">', esc_attr( $classes ) );
				call_user_func( 'bloghash_ad_widget_' . $widget['type'], $widget['values'] );
				printf( '</div>' );
			}
		}
	endif;

}
add_action( 'bloghash_before_single_content', 'bloghash_advertisement_part', 10, 1 );
add_action( 'bloghash_after_single_content', 'bloghash_advertisement_part', 10, 1 );
add_action( 'bloghash_before_masthead', 'bloghash_advertisement_part', 10, 1 );
add_action( 'bloghash_after_masthead', 'bloghash_advertisement_part', 10, 1 );
add_action( 'bloghash_before_colophon', 'bloghash_advertisement_part', 10, 1 );
add_action( 'bloghash_after_colophon', 'bloghash_advertisement_part', 10, 1 );
add_action( 'bloghash_header_4_ad', 'bloghash_advertisement_part', 10, 1 );
add_action( 'bloghash_before_content_area', 'bloghash_advertisement_part', 10, 1 );
