<?php
/**
 * BlogHash Pro Features section in Customizer.
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

if ( ! class_exists( 'Bloghash_Customizer_Pro_Features' ) ) :
	/**
	 * Bloghash PYML section in Customizer.
	 */
	class Bloghash_Customizer_Pro_Features {

		/**
		 * Primary class constructor.
		 *
		 * @since 1.0.0
		 */
		public function __construct() {

			/**
			 * Registers our custom options in Customizer.
			 */
			add_filter( 'bloghash_customizer_options', array( $this, 'register_options' ) );
		}

		/**
		 * Registers our custom options in Customizer.
		 *
		 * @since 1.0.0
		 * @param array $options Array of customizer options.
		 */
		public function register_options( $options ) {
			// Pro features section
			$options['section']['bloghash_section_bloghash_pro'] = array(
				'title'    => esc_html__( 'View Pro Features', 'bloghash' ),
				'priority' => 0,
			);

			$options['setting']['bloghash_section_bloghash_pro_features'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'sanitize_text_field',
				'control'           => array(
					'type'       => 'bloghash-pro',
					'section'    => 'bloghash_section_bloghash_pro',
					'screenshot' => apply_filters( 'bloghash_pro_theme_screenshot', esc_url( get_template_directory_uri() ) . '/assets/images/bloghash-lapi.webp' ),
					'features'   => apply_filters(
						'bloghash_pro_theme_features',
						array(
							esc_html_x( 'All starter sites included', 'pro feature' , 'bloghash' ),
							esc_html_x( 'Advance header layout options', 'pro feature' , 'bloghash' ),
							esc_html_x( 'Advance FrontPage slider layouts', 'pro feature' , 'bloghash' ),
							esc_html_x( 'Unlimited \'Advertisement\' widgets', 'pro feature' , 'bloghash' ),
							esc_html_x( 'Option to ad AdSesne code in advertisement widget', 'pro feature' , 'bloghash' ),
							esc_html_x( 'Body and H1 to H6 typography options', 'pro feature' , 'bloghash' ),
							esc_html_x( 'Primary, seconday and text buttons color and typography options', 'pro feature' , 'bloghash' ),
							esc_html_x( 'Post advance features', 'pro feature' , 'bloghash' ),
							esc_html_x( '\'Post Like ❤️\' feature', 'pro feature' , 'bloghash' ),
							esc_html_x( 'Ajax load more posts', 'pro feature' , 'bloghash' ),
							esc_html_x( 'Infinite load posts', 'pro feature' , 'bloghash' ),
							esc_html_x( 'Unlimited \'Featured links\' + some additional features', 'pro feature' , 'bloghash' ),
							esc_html_x( 'Meta category options', 'pro feature' , 'bloghash' ),
							esc_html_x( 'Site layouts options e.g. Boxed, Framed etc', 'pro feature' , 'bloghash' ),
							esc_html_x( 'Archive layout options', 'pro feature' , 'bloghash' ),
							esc_html_x( 'Advance color scheme', 'pro feature' , 'bloghash' ),
							esc_html_x( 'Author widgets', 'pro feature' , 'bloghash' ),
							esc_html_x( 'Title design settings', 'pro feature' , 'bloghash' ),
							esc_html_x( 'Masonry grid & multi post options', 'pro feature' , 'bloghash' ),
							esc_html_x( 'Full width Post/Page options', 'pro feature' , 'bloghash' ),
							esc_html_x( 'Single Post/Page layout options', 'pro feature' , 'bloghash' ),
							esc_html_x( 'Footer advance features', 'pro feature' , 'bloghash' ),
							esc_html_x( 'Footer widgets options', 'pro feature' , 'bloghash' ),
							esc_html_x( 'Call to action / Pre-Footer', 'pro feature' , 'bloghash' ),
							esc_html_x( 'Site width manage options', 'pro feature' , 'bloghash' ),
							esc_html_x( 'Parallax footer', 'pro feature' , 'bloghash' ),
							esc_html_x( 'Site pre-loader', 'pro feature' , 'bloghash' ),
							esc_html_x( 'SEO Meta', 'pro feature' , 'bloghash' ),
							esc_html_x( 'AMP compatibility', 'pro feature' , 'bloghash' ),
							esc_html_x( 'Coming soon/Maintenance mode option', 'pro feature' , 'bloghash' ),
							esc_html_x( 'Regular premium updates', 'pro feature' , 'bloghash' ),
							esc_html_x( 'Quick support', 'pro feature' , 'bloghash' ),
							esc_html_x( 'And much more...', 'pro feature' , 'bloghash' ),
						)
					),
				),
			);

			return $options;
		}

	}
endif;
new Bloghash_Customizer_Pro_Features();
