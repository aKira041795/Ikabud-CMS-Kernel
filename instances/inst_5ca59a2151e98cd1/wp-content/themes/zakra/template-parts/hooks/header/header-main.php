<?php
/**
 * Header main hooks.
 *
 * @package zakra
 *
 * TODO: @since
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/*========================================= Hooks > Header Main ==========================================*/

if ( ! function_exists( 'zakra_header_main' ) ) :

	/**
	 * Render HTML for Group One HTML containing various components according to customizer options.
	 *
	 * @return void
	 * @since  1.4.7
	 *
	 */
	function zakra_header_main() {

		get_template_part( 'template-parts/header/header', 'main' );
	}
endif;

add_action( 'zakra_action_header_main', 'zakra_header_main' );


if ( ! function_exists( 'zakra_before_header_main' ) ) :

	/**
	 * Before header main.
	 */
	function zakra_before_header_main() {
		?>
		<div class="zak-main-header">
			<div class="<?php zakra_css_class( 'zakra_header_main_container_class' ); ?>">
				<div class="zak-row">
		<?php
	}
endif;

add_action( 'zakra_action_before_header_main', 'zakra_before_header_main', 10 );


if ( ! function_exists( 'zakra_after_header_main' ) ) :

	/**
	 * After header main.
	 */
	function zakra_after_header_main() {
		?>
				</div> <!-- /.zak-row -->
			</div> <!-- /.zak-container -->
		</div> <!-- /.zak-main-header -->
		<?php
	}
endif;

add_action( 'zakra_action_after_header_main', 'zakra_after_header_main', 10 );


if ( ! function_exists( 'zakra_header_main_site_branding' ) ) :

	/**
	 * Site branding.
	 */
	function zakra_header_main_site_branding() {

		get_template_part( 'template-parts/header/site-branding/site', 'branding' );
	}
endif;

add_action( 'zakra_header_block_one', 'zakra_header_main_site_branding', 10 );


if ( ! function_exists( 'zakra_change_logo_attr' ) ) :

	/**
	 * Change the logo image attr while retina logo is set.
	 * @param $attr
	 * @param $attachment
	 * @param $size
	 *
	 * @return mixed
	 */
	function zakra_change_logo_attr( $attr, $attachment, $size ) {

		$custom_logo = get_theme_mod( 'custom_logo' );
		$retina_logo = get_theme_mod( 'zakra_retina_logo' );
		if ( $custom_logo && $retina_logo && isset( $attr['class'] ) && 'custom-logo' === $attr['class'] ) {
			$custom_logo_src = wp_get_attachment_image_src( $custom_logo, 'full' );
			if ( ! $custom_logo_src ) {
				return $attr;
			}       $custom_logo_url = $custom_logo_src[0];
			if ( is_numeric( $retina_logo ) ) {
				$retina_logo_attachment = wp_get_attachment_image_src( $retina_logo, 'full' );
				if ( isset( $retina_logo_attachment[0] ) ) {
					$retina_logo_src = $retina_logo_attachment[0];
				}
			} else {
				$retina_logo_id         = attachment_url_to_postid( $retina_logo );
				$retina_logo_attachment = wp_get_attachment_image_src( $retina_logo_id, 'full' );
				if ( isset( $retina_logo_attachment[0] ) ) {
							$retina_logo_src = $retina_logo_attachment[0];
				}
			}         if ( isset( $retina_logo_src ) ) {
				$attr['srcset'] = $custom_logo_url . ' 1x, ' . $retina_logo_src . ' 2x';
			}
		}    return $attr;
	}
endif;

add_filter( 'wp_get_attachment_image_attributes', 'zakra_change_logo_attr', 10, 3 );
