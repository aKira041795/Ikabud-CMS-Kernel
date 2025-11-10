<?php
/**
 * Template part for displaying entry category.
 *
 * @package     Bloghash
 * @author      Peregrine Themes
 * @since       1.0.0
 */

?>

<div class="post-category">

	<?php
	do_action( 'bloghash_before_post_category' );

	if ( is_singular() ) {
		bloghash_entry_meta_category( ' ', false );
	} else {
		if ( 'blog-horizontal' === bloghash_get_article_feed_layout() || 'blog-layout-2' === bloghash_get_article_feed_layout() ) {
			bloghash_entry_meta_category( ' ', false, 3 );
		} else {
			bloghash_entry_meta_category( ', ', false, 3 );
		}
	}

	do_action( 'bloghash_after_post_category' );
	?>

</div>
