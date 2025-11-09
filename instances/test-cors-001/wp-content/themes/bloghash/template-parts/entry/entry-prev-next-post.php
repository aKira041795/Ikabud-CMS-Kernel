<?php
/**
 * Template part for displaying Previous/Next Post section.
 *
 * @package     Bloghash
 * @author      Peregrine Themes
 * @since       1.0.0
 */

// Do not show if post is password protected.
if ( post_password_required() ) {
	return;
}

$bloghash_next_post = get_next_post();
$bloghash_prev_post = get_previous_post();

// Return if there are no other posts.
if ( empty( $bloghash_next_post ) && empty( $bloghash_prev_post ) ) {
	return;
}
?>

<?php do_action( 'bloghash_entry_before_prev_next_posts' ); ?>
<section class="post-nav" role="navigation">
	<h2 class="screen-reader-text"><?php esc_html_e( 'Post navigation', 'bloghash' ); ?></h2>

	<?php

	// Previous post link.
	previous_post_link(
		'<div class="nav-previous"><h6 class="nav-title">' . wp_kses( __( 'Previous Post', 'bloghash' ), bloghash_get_allowed_html_tags( 'button' ) ) . '</h6>%link</div>',
		sprintf(
			'<div class="nav-content">%1$s <span>%2$s</span></div>',
			bloghash_get_post_thumbnail( $bloghash_prev_post, array( 75, 75 ) ),
			'%title'
		)
	);

	// Next post link.
	next_post_link(
		'<div class="nav-next"><h6 class="nav-title">' . wp_kses( __( 'Next Post', 'bloghash' ), bloghash_get_allowed_html_tags( 'button' ) ) . '</h6>%link</div>',
		sprintf(
			'<div class="nav-content"><span>%2$s</span> %1$s</div>',
			bloghash_get_post_thumbnail( $bloghash_next_post, array( 75, 75 ) ),
			'%title'
		)
	);

	?>

</section>
<?php do_action( 'bloghash_entry_after_prev_next_posts' ); ?>
