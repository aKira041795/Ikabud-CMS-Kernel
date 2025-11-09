<?php
/**
 * Template for Single post
 *
 * @link https://codex.wordpress.org/Template_Hierarchy
 *
 * @package BlogHash
 * @author Peregrine Themes
 * @since   1.0.0
 */

?>

<?php do_action( 'bloghash_before_article' ); ?>

<article id="post-<?php the_ID(); ?>" <?php post_class( 'bloghash-article' ); ?><?php bloghash_schema_markup( 'article' ); ?>>

	<?php
	if ( 'quote' === get_post_format() ) {
		get_template_part( 'template-parts/entry/format/media', 'quote' );
	}

	$bloghash_single_post_elements = bloghash_get_single_post_elements();

	if ( ! empty( $bloghash_single_post_elements ) ) {
		foreach ( $bloghash_single_post_elements as $bloghash_element ) {

			if ( 'content' === $bloghash_element ) {
				do_action( 'bloghash_before_single_content', 'before_post_content' );
				get_template_part( 'template-parts/entry/entry', $bloghash_element );
				do_action( 'bloghash_after_single_content', 'after_post_content' );
			} else {
				get_template_part( 'template-parts/entry/entry', $bloghash_element );
			}
		}
	}
	?>

</article><!-- #post-<?php the_ID(); ?> -->

<?php do_action( 'bloghash_after_article' ); ?>
