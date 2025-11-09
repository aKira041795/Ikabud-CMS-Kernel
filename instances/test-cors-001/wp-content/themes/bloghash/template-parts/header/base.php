<?php
/**
 * The base template for displaying theme header area.
 *
 * @see https://developer.wordpress.org/themes/basics/template-files/#template-partials
 *
 * @package     Bloghash
 * @author      Peregrine Themes
 * @since       1.0.0
 */

?>
<?php do_action( 'bloghash_before_header' ); ?>
<div id="bloghash-header" <?php bloghash_header_classes(); ?>>
	<?php do_action( 'bloghash_header_content' ); ?>
</div><!-- END #bloghash-header -->
<?php do_action( 'bloghash_after_header' ); ?>
