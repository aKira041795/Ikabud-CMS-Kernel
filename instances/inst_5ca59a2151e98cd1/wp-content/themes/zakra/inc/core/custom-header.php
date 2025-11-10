<?php
/**
 * Implementation of the Custom Header feature
 *
 *
 * @link    https://developer.wordpress.org/themes/functionality/custom-headers/
 *
 * @package zakra
 *
 * TODO: @since.
 */

/**
 * Set up the WordPress core custom header feature.
 *
 * @uses zakra_header_style()
 */
function zakra_custom_header_setup() {

	add_theme_support(
		'custom-header',
		apply_filters(
			'zakra_custom_header_args',
			array(
				'default-image'      => '',
				'default-text-color' => '000000',
				'width'              => 1500,
				'height'             => 500,
				'flex-height'        => true,
				'flex-width'         => true,
				'video'              => true,
				'wp-head-callback'   => 'zakra_header_style',
			)
		)
	);
}

add_action( 'after_setup_theme', 'zakra_custom_header_setup' );

if ( ! function_exists( 'zakra_header_style' ) ) :

	/**
	 * Styles the header image and text displayed on the blog.
	 *
	 * @see zakra_custom_header_setup().
	 */
	function zakra_header_style() {

		// If we get this far, we have custom styles. Let's do this.
		?>

		<style type="text/css">
			<?php
			// Has the text been hidden?
			if ( empty( get_theme_mod('zakra_enable_site_identity', true) ) ) :
				?>
            .site-title {
                position: absolute;
                clip: rect(1px, 1px, 1px, 1px);
            }

			<?php endif; ?>

			<?php
			// Has the text been hidden?
			if ( empty( get_theme_mod('zakra_enable_site_tagline', true) ) ) :
				?>
            .site-description {
                position: absolute;
                clip: rect(1px, 1px, 1px, 1px);
            }

			<?php endif; ?>
		</style>

		<?php
	}
endif;
