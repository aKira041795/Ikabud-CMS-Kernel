<?php
/**
 * Template part for displaying post in post listing.
 *
 * @link https://codex.wordpress.org/Template_Hierarchy
 *
 * @package     Bloghash
 * @author      Peregrine Themes
 * @since       1.0.0
 */

?>

<?php do_action( 'bloghash_before_article' ); ?>

<article id="post-<?php the_ID(); ?>" <?php post_class( 'bloghash-article' ); ?><?php bloghash_schema_markup( 'article' ); ?>>

	<?php
	$bloghash_blog_entry_format = get_post_format();

	if ( 'quote' === $bloghash_blog_entry_format ) {
		get_template_part( 'template-parts/entry/format/media', $bloghash_blog_entry_format );
	} else {

		$bloghash_blog_entry_elements = bloghash_get_blog_entry_elements();

		if ( ! empty( $bloghash_blog_entry_elements ) ) {
			foreach ( $bloghash_blog_entry_elements as $bloghash_element ) {
				get_template_part( 'template-parts/entry/entry', $bloghash_element );
			}
		}
	}
	?>

</article><!-- #post-<?php the_ID(); ?> -->

<?php do_action( 'bloghash_after_article' ); ?>
