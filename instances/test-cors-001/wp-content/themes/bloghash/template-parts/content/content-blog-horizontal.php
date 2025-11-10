<?php
/**
 * Template part for displaying blog post - horizontal.
 *
 * @link https://codex.wordpress.org/Template_Hierarchy
 *
 * @package     Bloghash
 * @author      Peregrine Themes
 * @since       1.0.0
 */
$class_no_media = ! has_post_thumbnail() ? 'no-entry-media' : '';
?>

<?php do_action( 'bloghash_before_article' ); ?>

<article id="post-<?php the_ID(); ?>" <?php post_class( array( 'bloghash-article', esc_attr( $class_no_media ) ) ); ?><?php bloghash_schema_markup( 'article' ); ?>>

	<?php
	$bloghash_blog_entry_format = get_post_format();

	if ( 'quote' === $bloghash_blog_entry_format ) {
		get_template_part( 'template-parts/entry/format/media', $bloghash_blog_entry_format );
	} else {

		$bloghash_classes     = array();
		$bloghash_classes[]   = 'bloghash-blog-entry-wrapper';
		$bloghash_thumb_align = bloghash_option( 'blog_image_position' );
		$bloghash_thumb_align = apply_filters( 'bloghash_horizontal_blog_image_position', $bloghash_thumb_align );
		$bloghash_classes[]   = 'bloghash-thumb-' . $bloghash_thumb_align;
		$bloghash_classes     = implode( ' ', $bloghash_classes );
		?>

		<div class="<?php echo esc_attr( $bloghash_classes ); ?>">
			<?php get_template_part( 'template-parts/entry/entry-thumbnail' ); ?>

			<div class="bloghash-entry-content-wrapper">

				<?php
				if ( bloghash_option( 'blog_horizontal_post_categories' ) ) {
					get_template_part( 'template-parts/entry/entry-category' );
				}

				get_template_part( 'template-parts/entry/entry-header' );
				get_template_part( 'template-parts/entry/entry-summary' );


				if ( bloghash_option( 'blog_horizontal_read_more' ) ) {
					get_template_part( 'template-parts/entry/entry-summary-footer' );
				}

				get_template_part( 'template-parts/entry/entry-meta' );
				?>
			</div>
		</div>

	<?php } ?>

</article><!-- #post-<?php the_ID(); ?> -->

<?php do_action( 'bloghash_after_article' ); ?>
