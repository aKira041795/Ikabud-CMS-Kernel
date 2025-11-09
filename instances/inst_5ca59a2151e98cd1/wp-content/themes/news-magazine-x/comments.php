<?php

// if password is required
if ( post_password_required() ) {
	return;
}

// if post has comments
if ( have_comments() ) : ?>

	<h4  class="comment-title">
		<?php
		comments_number( esc_html__( '0 Comments', 'news-magazine-x' ), esc_html__( 'One Comment', 'news-magazine-x' ), esc_html__( '% Comments', 'news-magazine-x' ) );
		?>
	</h4>
	
	<ul class="commentslist" >
		<?php wp_list_comments( 'callback=newsx_comments_callback' ); ?>
	</ul>

	<?php if ( get_comment_pages_count() > 1 && get_option( 'page_comments' ) ) : ?>
	<div class="comments-nav-section">
		<div class="comments-pagination newsx-flex">				
			<div class="default-prev">
			<?php  previous_comments_link( newsx_get_svg_icon('chevron-left') . esc_html__( 'Older Comments', 'news-magazine-x' )  ); ?>
			</div>

			<div class="default-next">
				<?php  next_comments_link( esc_html__( 'Newer Comments', 'news-magazine-x' ) . newsx_get_svg_icon('chevron-right') ); ?>
			</div>
		</div>
	</div>

<?php
	endif;

// have_comments()
endif;

// Form
comment_form([
	'title_reply' => esc_html__( 'Leave a Reply', 'news-magazine-x' ),
	'title_reply_before' => '<h4 id="reply-title" class="comment-reply-title">',
	'title_reply_after' => '</h4>',
	'comment_field' => '<p class="comment-form-comment"><label for="comment">' . esc_html__( 'Comment', 'news-magazine-x' ) . '</label><textarea name="comment" id="comment" placeholder="'. esc_html__( 'Comment', 'news-magazine-x' ) .'" cols="45" rows="8"  maxlength="65525" required="required" spellcheck="false"></textarea></p>',
	'label_submit' => esc_html__( 'Post Comment', 'news-magazine-x' )
]);

function newsx_comments_callback ( $comment, $args, $depth ) {
	$_GLOBAL['comment'] = $comment;

	if (get_comment_type() == 'pingback' || get_comment_type() == 'trackback' ) : ?>
		
	<li class="pingback" id="comment-<?php comment_ID(); ?>">
		<article <?php comment_class('entry-comments'); ?> >
			<div class="comment-content">
				<div class="comment-meta">
					<div class="newsx-flex">
						<span class="comment-author"><?php esc_html_e( 'Pingback:', 'news-magazine-x' ); ?></span>
						
						<div class="newsx-flex">
							<a class="comment-date" href=" <?php echo esc_url( get_comment_link() ); ?> "><?php comment_date( get_option('date_format') ); esc_html_e( '&nbsp;at&nbsp;', 'news-magazine-x' ); comment_time( get_option('time_format') ); ?></a>
							<?php echo edit_comment_link( esc_html__('[Edit]', 'news-magazine-x' ) ); ?>
						</div>
					</div>
				</div>
				<div class="comment-text">			
				<?php comment_author_link(); ?>
				</div>
			</div>
		</article>

	<?php elseif ( get_comment_type() == 'comment' ) : ?>

	<li id="comment-<?php comment_ID(); ?>">
		
		<article <?php comment_class( 'entry-comments' ); ?> >					
			<div class="comment-avatar">
				<?php echo get_avatar( $comment, 55 ); ?>
			</div>
			<div class="comment-content">
				<div class="comment-meta">
					<div class="newsx-flex">
						<span class="comment-author"><?php comment_author_link(); ?></span>
						<div class="newsx-flex">
							<a class="comment-date" href=" <?php echo esc_url( get_comment_link() ); ?> "><?php comment_date( get_option('date_format') ); esc_html_e( '&nbsp;at&nbsp;', 'news-magazine-x' ); comment_time( get_option('time_format') ); ?></a>
							<?php echo edit_comment_link( esc_html__('[Edit]', 'news-magazine-x' ) ); ?>
						</div>
					</div>

					<?php

					comment_reply_link(array_merge( $args, [
						'reply_text' => esc_html__('Reply', 'news-magazine-x'),
						'depth' => $depth,
						'max_depth' => $args['max_depth']
					] ) );
					
					?>
				</div>

				<div class="comment-text">
					<?php if ( $comment->comment_approved == '0' ) : ?>
					<p class="awaiting-moderation"><?php esc_html_e( 'Your comment is awaiting moderation.', 'news-magazine-x' ); ?></p>
					<?php endif; ?>
					<?php comment_text(); ?>
				</div>
			</div>
			
		</article>

	<?php endif;
}