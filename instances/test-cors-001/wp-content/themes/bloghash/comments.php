<?php
/**
 * The template for displaying comments.
 *
 * This is the template that displays the area of the page that contains both the current comments
 * and the comment form.
 *
 * @link https://codex.wordpress.org/Template_Hierarchy
 *
 * @package     Bloghash
 * @author      Peregrine Themes
 * @since       1.0.0
 */

/*
 * Return if comments are not meant to be displayed.
 */
if ( ! bloghash_comments_displayed() ) {
	return;
}

?>
<?php do_action( 'bloghash_before_comments' ); ?>
<section id="comments" class="comments-area">

	<div class="comments-title-wrapper center-text">
		<h3 class="comments-title">
			<?php

			// Get comments number.
			$bloghash_comments_count = get_comments_number();

			if ( 0 === intval( $bloghash_comments_count ) ) {
				$bloghash_comments_title = esc_html__( 'Comments', 'bloghash' );
			} else {
				/* translators: %s Comment number */
				$bloghash_comments_title = sprintf( _n( '%s Comment', '%s Comments', $bloghash_comments_count, 'bloghash' ), number_format_i18n( $bloghash_comments_count ) );
			}

			// Apply filters to the comments count.
			$bloghash_comments_title = apply_filters( 'bloghash_comments_count', $bloghash_comments_title );

			echo wp_kses( $bloghash_comments_title, bloghash_get_allowed_html_tags() );
			?>
		</h3><!-- END .comments-title -->

		<?php
		if ( ! have_comments() ) {
			$bloghash_no_comments_title = apply_filters( 'bloghash_no_comments_text', esc_html__( 'No comments yet. Why don&rsquo;t you start the discussion?', 'bloghash' ) );
			?>
			<p class="no-comments"><?php echo esc_html( $bloghash_no_comments_title ); ?></p>
		<?php } ?>
	</div>

	<ol class="comment-list">
		<?php

		// List comments.
		wp_list_comments(
			array(
				'callback'    => 'bloghash_comment',
				'avatar_size' => apply_filters( 'bloghash_comment_avatar_size', 50 ),
				'reply_text'  => __( 'Reply', 'bloghash' ),
			)
		);
		?>
	</ol>

	<?php
	// If comments are closed and there are comments, let's leave a note.
	if ( ! comments_open() && get_comments_number() && post_type_supports( get_post_type(), 'comments' ) ) :
		?>
		<p class="comments-closed center-text"><?php esc_html_e( 'Comments are closed', 'bloghash' ); ?></p>
	<?php endif; ?>

	<?php
	the_comments_pagination(
		array(
			'prev_text' => '<span class="screen-reader-text">' . __( 'Previous', 'bloghash' ) . '</span>',
			'next_text' => '<span class="screen-reader-text">' . __( 'Next', 'bloghash' ) . '</span>',
		)
	);
	?>

	<?php
	comment_form(
		array(
			/* translators: %1$s opening anchor tag, %2$s closing anchor tag */
			'must_log_in'   => '<p class="must-log-in">' . sprintf( esc_html__( 'You must be %1$slogged in%2$s to post a comment.', 'bloghash' ), '<a href="' . wp_login_url( apply_filters( 'the_permalink', get_permalink() ) ) . '">', '</a>' ) . '</p>', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			'logged_in_as'  => '<p class="logged-in-as">' . esc_html__( 'Logged in as', 'bloghash' ) . ' <a href="' . esc_url( admin_url( 'profile.php' ) ) . '">' . $user_identity . '</a> <a href="' . wp_logout_url( get_permalink() ) . '" title="' . esc_html__( 'Log out of this account', 'bloghash' ) . '">' . esc_html__( 'Log out?', 'bloghash' ) . '</a></p>',
			'class_submit'  => 'bloghash-btn primary-button',
			'comment_field' => '<p class="comment-textarea"><textarea name="comment" id="comment" cols="44" rows="8" class="textarea-comment" placeholder="' . esc_html__( 'Write a comment&hellip;', 'bloghash' ) . '" required="required"></textarea></p>',
			'id_submit'     => 'comment-submit',
		)
	);
	?>

</section><!-- #comments -->
<?php do_action( 'bloghash_after_comments' ); ?>
