<?php
/**
 * Header file in case of the elementor way
 *
 * @since 1.0
 */
?>

<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="profile" href="https://gmpg.org/xfn/11">
	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php
	if ( function_exists( 'wp_body_open' ) ) {
		wp_body_open();
	}
	do_action( 'elespare_hf_header' );
?>

<div id="ele-page" class="ele-hf-site">
<?php
do_action( 'elespare_hf_get_header' );
do_action( 'elespare_hf_get_header_wrapper' ); ?>