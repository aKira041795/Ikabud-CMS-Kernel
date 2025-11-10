<?php
/**
 * Site branding template file.
 *
 * @package zakra
 *
 * TODO: @since.
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
?>

<div class="site-branding">
	<?php

	// Check for meta logo.
	$meta_logo_id = ! is_home() ? intval( get_post_meta( zakra_get_post_id(), 'zakra_logo', true ) ) : '';

	if ( $meta_logo_id ) {

		$meta_logo_attr = array(
			'class'    => 'zak-logo',
			'itemprop' => 'logo',
		);

		// @codingStandardsIgnoreStart
		$meta_logo = apply_filters(
			'zakra_meta_logo',
			zakra_get_image_by_id( $meta_logo_id, $meta_logo_attr, get_bloginfo( 'name', 'display' ) )
		); // WPCS: CSRF ok.
		// @codingStandardsIgnoreEnd

		echo sprintf(
			'<a href="%1$s" class="zak-logo-link" rel="home" itemprop="url">%2$s</a>',
			esc_url( home_url( '/' ) ),
			$meta_logo
		);
	} else {

		do_action( 'zakra_mobile_logo' );

		the_custom_logo();
	}
	?>
	<div class="site-info-wrap">
		<?php
		// Front page with the latest posts.
		$html_tag = ( is_front_page() && is_home() ) ? 'h1' : 'p';
		?>

		<<?php echo esc_attr( $html_tag ); ?> class="site-title ">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home"><?php bloginfo( 'name' ); ?></a>
	</<?php echo esc_attr( $html_tag ); ?>>


	<?php
		$zakra_description = get_bloginfo( 'description' );

		if ( $zakra_description || is_customize_preview() ) :
			?>
			<p class="site-description "><?php echo esc_html( $zakra_description ); /* WPCS: xss ok. */ ?></p>
		<?php endif;
	?>
</div>
</div><!-- .site-branding -->
