<?php
/**
 * Template part for displaying entry footer.
 *
 * @link https://codex.wordpress.org/Template_Hierarchy
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

?>

<?php do_action( 'bloghash_before_entry_footer' ); ?>
<footer class="entry-footer">
	<?php

	// Allow text to be filtered.
	$bloghash_read_more_text = bloghash_option( 'blog_read_more' );

	?>
	<a href="<?php echo esc_url( bloghash_entry_get_permalink() ); ?>" class="bloghash-btn btn-text-1"><span><?php echo esc_html( $bloghash_read_more_text ); ?></span></a>
</footer>
<?php do_action( 'bloghash_after_entry_footer' ); ?>
