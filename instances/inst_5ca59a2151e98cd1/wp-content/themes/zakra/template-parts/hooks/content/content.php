<?php
/**
 * Content area hooks.
 *
 * @package zakra
 *
 * TODO: @since
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/*========================================= Hooks > Content ==========================================*/

if ( ! function_exists( 'zakra_main_start' ) ) :

	/**
	 * Page main section starts.
	 */
	function zakra_main_start() {
		?>
		<div id="zak-content" class="zak-content">
		<?php
	}
endif;

add_action( 'zakra_action_before_content', 'zakra_main_start' );


if ( ! function_exists( 'zakra_content_start' ) ) :

	/**
	 * Container starts.
	 */
	function zakra_content_start() {
		?>
			<div class="zak-container">
				<div class="zak-row">
		<?php
	}
endif;

add_action( 'zakra_action_before_content', 'zakra_content_start', 20 );


if ( ! function_exists( 'zakra_content_end' ) ) :

	/**
	 * Container ends.
	 */
	function zakra_content_end() {
		?>
				</div> <!-- /.row -->
			</div> <!-- /.zak-container-->
		</div> <!-- /#zak-content-->
		<?php
	}
endif;

add_action( 'zakra_action_after_content', 'zakra_content_end' );

if ( ! function_exists( 'zakra_content_loop' ) ) {

	/**
	 * Main content loop.
	 *
	 * @return void
	 *
	 * TODO: @since.
	 */
	function zakra_content_loop() {

		if ( have_posts() ) {

			if ( 'page-header' !== zakra_page_title_position() && ! is_home() ) {

				zakra_page_title();
			}

			do_action( 'zakra_before_posts_the_loop' );

			$classes = apply_filters( 'zakra_content_loop_class', array( 'zak-posts' ) );
			$classes = implode( ' ', array_unique( $classes ) )
			?>

			<div class="<?php echo esc_attr( $classes ); ?>">

				<?php
				/* Start the Loop */
				while ( have_posts() ) {

					the_post();

					get_template_part( 'template-parts/content', '' );
				}
				?>

			</div> <!-- /.zak-posts -->

			<?php

			do_action( 'zakra_after_posts_the_loop' );
		} else {

			get_template_part( 'template-parts/content', 'none' );
		}
	}

	add_action( 'zakra_content', 'zakra_content_loop' );
	add_action( 'zakra_content_search', 'zakra_content_loop' );
}

if ( ! function_exists( 'zakra_archive_description' ) ) {

	/**
	 * Archive description.
	 */
	function zakra_archive_description() {

		$category_enable = get_theme_mod( 'zakra_enable_category_description', false );

		if ( is_archive() && $category_enable ) {
			the_archive_description( '<div class="zak-archive-description">', '</div>' );
		}
	}

	add_action( 'zakra_before_posts_the_loop', 'zakra_archive_description' );
}
