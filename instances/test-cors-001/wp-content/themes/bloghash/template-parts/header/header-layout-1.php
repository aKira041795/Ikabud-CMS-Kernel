<?php
/**
 * The template for displaying header layout 1.
 *
 * @package BlogHash
 * @author Peregrine Themes
 * @since   1.0.0
 */

?>

<div class="bloghash-container bloghash-header-container">

	<?php
	bloghash_header_logo_template();
	?>

	<span class="bloghash-header-element bloghash-mobile-nav">
		<?php bloghash_hamburger( bloghash_option( 'main_nav_mobile_label' ), 'bloghash-primary-nav' ); ?>
	</span>

	<?php
	bloghash_main_navigation_template();
	do_action( 'bloghash_header_widget_location', array( 'left', 'right' ) );
	?>

</div><!-- END .bloghash-container -->
