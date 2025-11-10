<?php
/**
 * The template for displaying scroll to top button.
 *
 * @package     Bloghash
 * @author      Peregrine Themes
 * @since       1.0.0
 */

?>

<a href="#" id="bloghash-scroll-top" class="bloghash-smooth-scroll" title="<?php esc_attr_e( 'Scroll to Top', 'bloghash' ); ?>" <?php bloghash_scroll_top_classes(); ?>>
	<span class="bloghash-scroll-icon" aria-hidden="true">
		<?php echo bloghash()->icons->get_svg( 'arrow-up', array( 'class' => 'top-icon' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php echo bloghash()->icons->get_svg( 'arrow-up' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</span>
	<span class="screen-reader-text"><?php esc_html_e( 'Scroll to Top', 'bloghash' ); ?></span>
</a><!-- END #bloghash-scroll-to-top -->
