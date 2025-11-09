<?php
/**
 * The header for our theme.
 *
 * @link https://developer.wordpress.org/themes/basics/template-files/#template-partials
 *
 * @package     Bloghash
 * @author      Peregrine Themes
 * @since       1.0.0
 */

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?><?php bloghash_schema_markup( 'html' ); ?> <?php echo bloghash_option( 'dark_mode' ) ? 'data-darkmode="dark"' : ''; ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<link rel="profile" href="http://gmpg.org/xfn/11">
	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>

<?php wp_body_open(); ?>

<?php do_action( 'bloghash_before_page_wrapper' ); ?>
<div id="page" class="hfeed site">
	<a class="skip-link screen-reader-text" href="#main"><?php esc_html_e( 'Skip to content', 'bloghash' ); ?></a>

	<?php
	if ( get_header_image() ) {
		the_custom_header_markup();
	}
	?>

	<?php do_action( 'bloghash_before_masthead', 'before_header' ); ?>

	<header id="masthead" class="site-header" role="banner"<?php bloghash_masthead_atts(); ?><?php bloghash_schema_markup( 'header' ); ?>>
		<?php do_action( 'bloghash_header' ); ?>
		<?php do_action( 'bloghash_page_header' ); ?>
	</header><!-- #masthead .site-header -->

	<?php do_action( 'bloghash_after_masthead', 'after_header' ); ?>

	<?php do_action( 'bloghash_before_main' ); ?>
		<div id="main" class="site-main">

			<?php do_action( 'bloghash_main_start' ); ?>
