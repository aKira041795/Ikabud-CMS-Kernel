<?php
/**
 * Zakra content area functions to be hooked.
 *
 * @package zakra
 */

if ( ! function_exists( 'zakra_post_readmore' ) ) :

	/**
	 * Post read more HTML.
	 *
	 * @param string $readmore_alignment CSS class.
	 */
	function zakra_post_readmore( $readmore_alignment ) {
		?>
		<div class="<?php zakra_css_class( 'zakra_read_more_wrapper_class' ); ?> zak-<?php echo esc_attr( $readmore_alignment ); ?>">

			<a href="<?php the_permalink(); ?>" class="entry-button">

				<?php echo apply_filters( 'zakra_read_more_text', esc_html__( 'Read More', 'zakra' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php zakra_get_icon( 'arrow-right-long' ); ?>

			</a>
		</div> <!-- /.zak-entry-footer -->
		<?php
	}
endif;

if ( ! function_exists( 'zakra_get_sidebar' ) ) {

	function zakra_get_sidebar( $sidebar ) {

		$current_layout = zakra_get_current_layout();

		$sidebar_meta              = get_post_meta( zakra_get_post_id(), 'zakra_sidebar', true );
		$customizer_sidebar_layout = get_theme_mod( 'zakra_page_sidebar_layout', 'right' );

		if ( 'customizer' === $sidebar_meta || 'default' === $sidebar_meta ) {
			if ( 'right' === $customizer_sidebar_layout ) {
				$sidebar = 'sidebar-right';
			} elseif ( 'left' === $customizer_sidebar_layout ) {
				$sidebar = 'sidebar-left';
			}
			return $sidebar;
		}

		if ( $sidebar_meta ) {
			return $sidebar_meta;
		} else { //phpcs:ignore Universal.ControlStructures.DisallowLonelyIf.Found
			if ( 'zak-site-layout--left' === $current_layout ) {
				return 'sidebar-left';
			}
		}

		return $sidebar;
	}
}

if ( ! function_exists( 'zakra_set_posts_per_page' ) ) {

	function zakra_set_posts_per_page( $query ) {

		if ( $query->is_search() && ! is_admin() ) {
			$posts_per_page = get_theme_mod( 'zakra_search_results_posts_per_page', array( 'size' => 10 ) );
			if ( is_array( $posts_per_page ) && isset( $posts_per_page['size'] ) ) {
				$query->set( 'posts_per_page', $posts_per_page['size'] );
			} else {
				$query->set( 'posts_per_page', 10 );
			}
		}
	}
}
