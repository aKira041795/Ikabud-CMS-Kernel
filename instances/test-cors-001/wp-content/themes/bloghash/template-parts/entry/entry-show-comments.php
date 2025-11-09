<?php
/**
 * Template part for displaying ”Show Comments” button.
 *
 * @package     Bloghash
 * @author      Peregrine Themes
 * @since       1.0.0
 */

// Do not show if the post is password protected.
if ( post_password_required() ) {
	return;
}

$bloghash_comment_count = get_comments_number();
$bloghash_comment_title = esc_html__( 'Leave a Comment', 'bloghash' );

if ( $bloghash_comment_count > 0 ) {
	/* translators: %s is comment count */
	$bloghash_comment_title = esc_html( sprintf( _n( 'Show %s Comment', 'Show %s Comments', $bloghash_comment_count, 'bloghash' ), $bloghash_comment_count ) );
}

?>
<a href="#" id="bloghash-comments-toggle" class="bloghash-btn btn-large btn-fw btn-left-icon">
	<?php echo bloghash()->icons->get_svg( 'chat' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	<span><?php echo $bloghash_comment_title; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
</a>
