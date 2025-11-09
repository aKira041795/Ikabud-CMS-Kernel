<?php
/**
 * Template part for displaying content of Bloghash Canvas [Fullwidth] page template.
 *
 * @link https://codex.wordpress.org/Template_Hierarchy
 *
 * @package BlogHash
 * @author Peregrine Themes
 * @since   1.0.0
 */

?>
<article id="post-<?php the_ID(); ?>" <?php post_class(); ?><?php bloghash_schema_markup( 'article' ); ?>>
	<div class="entry-content bloghash-entry bloghash-fullwidth-entry">
		<?php
		do_action( 'bloghash_before_page_content' );

		the_content();

		do_action( 'bloghash_after_page_content' );
		?>
	</div><!-- END .entry-content -->
</article><!-- #post-<?php the_ID(); ?> -->
