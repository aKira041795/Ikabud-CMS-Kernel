<?php
/**
 * Template part for displaying page layout in page.php
 *
 * @link https://codex.wordpress.org/Template_Hierarchy
 *
 * @package     Bloghash
 * @author      Peregrine Themes
 * @since       1.0.0
 */

?>

<article id="post-<?php the_ID(); ?>" <?php post_class(); ?><?php bloghash_schema_markup( 'article' ); ?>>

<?php
if ( bloghash_show_post_thumbnail() ) {
	get_template_part( 'template-parts/entry/format/media', 'page' );
}
?>

<div class="entry-content bloghash-entry">
	<?php
	do_action( 'bloghash_before_page_content' );

	the_content();

	do_action( 'bloghash_after_page_content' );
	?>
</div><!-- END .entry-content -->

<?php bloghash_link_pages(); ?>

</article><!-- #post-<?php the_ID(); ?> -->
