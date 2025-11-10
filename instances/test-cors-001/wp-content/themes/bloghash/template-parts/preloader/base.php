<?php
/**
 * The template for displaying page preloader.
 *
 * @see https://developer.wordpress.org/themes/basics/template-files/#template-partials
 *
 * @package BlogHash
 * @author Peregrine Themes
 * @since   1.0.0
 */

?>

<div id="bloghash-preloader"<?php bloghash_preloader_classes(); ?>>
	<?php get_template_part( 'template-parts/preloader/preloader', bloghash_option( 'preloader_style' ) ); ?>
</div><!-- END #bloghash-preloader -->
